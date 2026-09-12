<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsServerSentEvents;
use App\Services\DomainAvailabilityService;
use App\Services\TldRepository;
use App\Support\DomainName;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkDomainCheckController extends Controller
{
    use StreamsServerSentEvents;

    public function __construct(
        private readonly DomainAvailabilityService $availability,
        private readonly TldRepository $tldRepository,
    ) {}

    public function check(Request $request): StreamedResponse
    {
        $request->validate([
            'domains' => ['required', 'string', 'max:5000'],
        ]);

        $raw = str_replace(["\r\n", "\r"], "\n", $request->string('domains'));
        $lines = array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $raw))));

        $domains = [];
        foreach ($lines as $line) {
            $line = strtolower(DomainName::toAscii($line));
            $line = preg_replace('#^https?://#', '', $line);
            $line = preg_replace('#^www\.#', '', $line);
            // Must look like name.tld (at least one dot, valid label characters)
            if (preg_match('/^[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9][a-z0-9\-]*)+$/', $line)) {
                $domains[] = $line;
            }
        }

        $domains = array_unique(array_slice($domains, 0, 50));

        if (empty($domains)) {
            return $this->sseStream(fn () => null);
        }

        return $this->sseStream(function () use ($domains): void {
            $total   = count($domains);
            $checked = 0;

            foreach ($domains as $fullDomain) {
                $split = $this->tldRepository->splitDomain($fullDomain);

                if ($split === null) {
                    continue;
                }

                $this->availability->streamCheck(
                    $split['name'],
                    [$split['tld']],
                    function (string $resolvedTld, string $status) use ($fullDomain, &$checked, $total): void {
                        $checked++;
                        $this->push('data: '.json_encode([
                            'domain' => $fullDomain,
                            'status' => $status,
                            'checked' => $checked,
                            'total' => $total,
                        ]));
                    }
                );
            }
        });
    }
}

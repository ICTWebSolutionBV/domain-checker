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

        // Normalised domain => the lines the client sent that reduce to it. A
        // pasted line can carry an invisible character (a zero-width space
        // from a document) that normalising strips; answering under the
        // normalised name left the client's row spinning forever.
        $domains = [];
        foreach ($lines as $line) {
            $domain = strtolower(DomainName::toAscii($line));
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = preg_replace('#^www\.#', '', $domain);
            // Must look like name.tld (at least one dot, valid label characters)
            if (preg_match('/^[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9][a-z0-9\-]*)+$/', $domain)) {
                $domains[$domain][] = $line;
            }
        }

        $domains = array_slice($domains, 0, 50);

        if (empty($domains)) {
            return $this->sseStream(fn () => null);
        }

        return $this->sseStream(function () use ($domains): void {
            $total = array_sum(array_map('count', $domains));
            $checked = 0;

            foreach ($domains as $fullDomain => $sentLines) {
                $split = $this->tldRepository->splitDomain($fullDomain);

                if ($split === null) {
                    continue;
                }

                // A pasted line can be a subdomain (blog.google.com, or a URL
                // the stripping above did not fully reduce). Nobody can
                // register a subdomain, and a registry has no record of one
                // either, so asking about it literally returns "available" --
                // the worst possible answer. Ask about the registrable domain
                // and say which one we asked about.
                $nameLabels = explode('.', $split['name']);
                $registrable = end($nameLabels).'.'.$split['tld'];

                $this->availability->streamCheck(
                    (string) end($nameLabels),
                    [$split['tld']],
                    function (string $resolvedTld, string $status) use ($fullDomain, $sentLines, $registrable, &$checked, $total): void {
                        foreach ($sentLines as $sentLine) {
                            $checked++;

                            $payload = [
                                // Keyed on the line the client sent: the front
                                // end maps results back by that exact string.
                                'domain' => $sentLine,
                                'status' => $status,
                                'checked' => $checked,
                                'total' => $total,
                            ];

                            if ($registrable !== $fullDomain) {
                                $payload['checked_domain'] = $registrable;
                            }

                            $this->push('data: '.json_encode($payload));
                        }
                    }
                );
            }
        });
    }
}

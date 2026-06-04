<?php

namespace App\Http\Controllers;

use App\Services\DomainAvailabilityService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkDomainCheckController extends Controller
{
    public function __construct(
        private readonly DomainAvailabilityService $availability,
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
            $line = strtolower($line);
            $line = preg_replace('#^https?://#', '', $line);
            $line = preg_replace('#^www\.#', '', $line);
            // Must look like name.tld (at least one dot, valid label characters)
            if (preg_match('/^[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9][a-z0-9\-]*)+$/', $line)) {
                $domains[] = $line;
            }
        }

        $domains = array_unique(array_slice($domains, 0, 50));

        if (empty($domains)) {
            return response()->stream(function () {
                echo "data: {\"done\":true}\n\n";
                ob_flush();
                flush();
            }, 200, $this->sseHeaders());
        }

        return response()->stream(function () use ($domains) {
            set_time_limit(0);

            $total   = count($domains);
            $checked = 0;

            foreach ($domains as $fullDomain) {
                $parts = explode('.', $fullDomain, 2);
                $name  = $parts[0];
                $tld   = $parts[1];

                $this->availability->streamCheck(
                    $name,
                    [$tld],
                    function (string $resolvedTld, string $status) use ($fullDomain, &$checked, $total): void {
                        $checked++;
                        echo 'data: '.json_encode([
                            'domain'  => $fullDomain,
                            'status'  => $status,
                            'checked' => $checked,
                            'total'   => $total,
                        ])."\n\n";
                        ob_flush();
                        flush();
                    }
                );
            }

            echo "data: {\"done\":true}\n\n";
            ob_flush();
            flush();
        }, 200, $this->sseHeaders());
    }

    private function sseHeaders(): array
    {
        return [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ];
    }
}

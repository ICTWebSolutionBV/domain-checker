<?php

namespace App\Http\Controllers\Concerns;

use App\Services\DomainAvailabilityService;
use App\Services\TldRepository;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The availability streams. The public checker and the partner API both serve
 * them, and the partner API promises the exact same lines; one implementation
 * keeps the two from drifting apart.
 */
trait StreamsDomainVerdicts
{
    use StreamsServerSentEvents;

    /** The most TLDs one check asks about: the full IANA list fits. */
    private const MAX_TLDS = 1500;

    /**
     * One name across a list of TLDs, a frame per verdict as it resolves.
     *
     * @param  array<string>  $tlds  lowercase, deduplicated, valid labels
     */
    private function verdictStream(DomainAvailabilityService $availability, string $domain, array $tlds): StreamedResponse
    {
        $tlds = array_slice($tlds, 0, self::MAX_TLDS);

        if ($tlds === []) {
            return $this->sseStream(fn () => null);
        }

        return $this->sseStream(function () use ($availability, $domain, $tlds): void {
            $total = count($tlds);
            $checked = 0;

            // streamCheck sends all RTR commands at once (pipelined), emitting each
            // result via the callback as the server responds — no per-batch blocking.
            $availability->streamCheck(
                $domain,
                $tlds,
                function (string $tld, string $status) use (&$checked, $total): void {
                    $checked++;
                    $this->push('data: '.json_encode(['tld' => $tld, 'status' => $status, 'checked' => $checked, 'total' => $total]));
                }
            );
        });
    }

    /**
     * A list of full domains, a frame per line the client sent.
     *
     * @param  array<string, array<string>>  $domains  normalised domain => the lines sent that reduce to it
     * @param  array<string>  $invalid  lines answered as 'invalid' without a lookup (the partner API only)
     */
    private function bulkVerdictStream(
        DomainAvailabilityService $availability,
        TldRepository $tldRepository,
        array $domains,
        array $invalid = [],
    ): StreamedResponse {
        return $this->sseStream(function () use ($availability, $tldRepository, $domains, $invalid): void {
            $total = count($invalid) + array_sum(array_map('count', $domains));
            $checked = 0;

            foreach ($invalid as $sentLine) {
                $checked++;
                $this->push('data: '.json_encode(['domain' => $sentLine, 'status' => 'invalid', 'checked' => $checked, 'total' => $total]));
            }

            foreach ($domains as $fullDomain => $sentLines) {
                $split = $tldRepository->splitDomain($fullDomain);

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

                $availability->streamCheck(
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

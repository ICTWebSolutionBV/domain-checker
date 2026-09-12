<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TldRepository
{
    /**
     * Per-instance memos. findRdapServer() used to cost a database read plus an
     * unserialize of the 62 KB bootstrap plus an O(590) scan on every call --
     * 0.64 ms x 1500 TLDs, and 1500 extra cache queries, for a full-list run.
     *
     * @var array<string, string>|null
     */
    private ?array $rdapServers = null;

    /** @var array<string>|null */
    private ?array $allTlds = null;

    public function getPopularTlds(): array
    {
        return config('domain-checker.popular_tlds', []);
    }

    public function getAllTlds(): array
    {
        return $this->allTlds ??= Cache::remember('iana_tld_list', config('domain-checker.cache.tld_list_ttl'), function () {
            try {
                $response = Http::timeout(10)->get(config('domain-checker.iana_tld_list_url'));

                if (! $response->successful()) {
                    return $this->getPopularTlds();
                }

                $tlds = array_filter(
                    array_map('strtolower', explode("\n", trim($response->body()))),
                    fn ($line) => ! empty($line) && ! str_starts_with($line, '#') && ! str_contains($line, '--'),
                );

                return array_values($tlds);
            } catch (\Exception $e) {
                Log::warning('Failed to fetch IANA TLD list', ['error' => $e->getMessage()]);

                return $this->getPopularTlds();
            }
        });
    }

    /**
     * Split "blog.google.com" into name "blog.google" + TLD "com".
     *
     * explode('.', $d, 2) used to be enough for "example.nl", but for any
     * deeper name it produced TLD "google.com", which no registry knows and
     * which therefore came back as available.
     *
     * @return array{name: string, tld: string}|null
     */
    public function splitDomain(string $fullDomain): ?array
    {
        $labels = explode('.', strtolower(trim($fullDomain, '. ')));

        if (count($labels) < 2) {
            return null;
        }

        $known = array_flip($this->getAllTlds() ?: $this->getPopularTlds());

        // Longest suffix first: "google.com" before "com", so a registry that
        // really does sell a multi-label suffix would win.
        for ($i = 1; $i < count($labels); $i++) {
            $candidate = implode('.', array_slice($labels, $i));

            if (isset($known[$candidate])) {
                return [
                    'name' => implode('.', array_slice($labels, 0, $i)),
                    'tld' => $candidate,
                ];
            }
        }

        // Unknown suffix (or the IANA list is unreachable): fall back to the
        // last label, which is at least a plausible TLD.
        return [
            'name' => implode('.', array_slice($labels, 0, -1)),
            'tld' => (string) end($labels),
        ];
    }

    public function getRdapBootstrap(): array
    {
        return Cache::remember('rdap_bootstrap', config('domain-checker.cache.bootstrap_ttl'), function () {
            try {
                $response = Http::timeout(10)->get(config('domain-checker.rdap_bootstrap_url'));

                if (! $response->successful()) {
                    return [];
                }

                return $response->json('services', []);
            } catch (\Exception $e) {
                Log::warning('Failed to fetch RDAP bootstrap', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }

    public function findRdapServer(string $tld): ?string
    {
        return $this->rdapServers()[strtolower($tld)] ?? null;
    }

    /**
     * The bootstrap inverted into tld => server, built once and memoised.
     *
     * Entries with an empty server are dropped: rtrim('', '/') used to be
     * returned as a server, which produced the relative URL "/domain/x.com".
     * Guzzle threw while the pool was being built and every TLD in that chunk
     * degraded to 'unknown'.
     *
     * @return array<string, string>
     */
    private function rdapServers(): array
    {
        return $this->rdapServers ??= Cache::remember(
            'rdap_server_map',
            config('domain-checker.cache.bootstrap_ttl'),
            function (): array {
                $map = [];

                foreach ($this->getRdapBootstrap() as $service) {
                    [$tlds, $servers] = $service;
                    $server = rtrim((string) ($servers[0] ?? ''), '/');

                    if ($server === '') {
                        continue;
                    }

                    foreach ($tlds as $serviceTld) {
                        $map[strtolower($serviceTld)] = $server;
                    }
                }

                return $map;
            },
        );
    }
}

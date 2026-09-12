<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TldRepository
{
    public function getPopularTlds(): array
    {
        return config('domain-checker.popular_tlds', []);
    }

    public function getAllTlds(): array
    {
        return Cache::remember('iana_tld_list', config('domain-checker.cache.tld_list_ttl'), function () {
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
        $tld = strtolower($tld);
        $services = $this->getRdapBootstrap();

        foreach ($services as $service) {
            [$tlds, $servers] = $service;
            foreach ($tlds as $serviceTld) {
                if (strtolower($serviceTld) === $tld) {
                    return rtrim($servers[0] ?? '', '/');
                }
            }
        }

        return null;
    }
}

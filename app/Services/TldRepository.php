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

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RdapService
{
    public function __construct(private readonly TldRepository $tldRepository) {}

    /**
     * Check domain availability via RDAP.
     * Returns 'available', 'taken', or null (RDAP not supported for this TLD).
     */
    public function check(string $domain, string $tld): ?string
    {
        $server = $this->tldRepository->findRdapServer($tld);

        if (! $server) {
            return null;
        }

        try {
            $url = "{$server}/domain/{$domain}.{$tld}";
            $response = Http::timeout(config('domain-checker.timeouts.rdap', 5))
                ->withoutVerifying()
                ->get($url);

            if ($response->status() === 404) {
                return 'available';
            }

            if ($response->status() === 200) {
                return 'taken';
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('RDAP check failed', ['domain' => "{$domain}.{$tld}", 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Check multiple domains concurrently using Http::pool().
     *
     * @param  array<string>  $tlds
     * @return array<string, string|null>
     */
    public function checkBatch(string $domain, array $tlds): array
    {
        $servers = [];
        foreach ($tlds as $tld) {
            $servers[$tld] = $this->tldRepository->findRdapServer($tld);
        }

        $tldsWithServer = array_filter($tlds, fn ($tld) => $servers[$tld] !== null);

        if (empty($tldsWithServer)) {
            return array_fill_keys($tlds, null);
        }

        $timeout = config('domain-checker.timeouts.rdap', 5);

        try {
            $responses = Http::pool(function ($pool) use ($domain, $tldsWithServer, $servers, $timeout) {
                foreach ($tldsWithServer as $tld) {
                    $url = "{$servers[$tld]}/domain/{$domain}.{$tld}";
                    $pool->as($tld)->timeout($timeout)->withoutVerifying()->get($url);
                }
            });
        } catch (\Exception $e) {
            Log::debug('RDAP pool failed', ['error' => $e->getMessage()]);
            $responses = [];
        }

        $results = [];
        foreach ($tlds as $tld) {
            if (! isset($servers[$tld]) || $servers[$tld] === null) {
                $results[$tld] = null;
                continue;
            }

            $response = $responses[$tld] ?? null;

            if ($response === null) {
                $results[$tld] = null;
            } elseif ($response instanceof \Throwable) {
                $results[$tld] = null;
            } elseif ($response->status() === 404) {
                $results[$tld] = 'available';
            } elseif ($response->status() === 200) {
                $results[$tld] = 'taken';
            } else {
                $results[$tld] = null;
            }
        }

        return $results;
    }
}

<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RealtimeRegisterService
{
    /**
     * Returns true when an API key is available (DB setting or env fallback).
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey());
    }

    /**
     * Check a batch of TLDs concurrently via the Realtime Register domains/check API.
     * Returns ['com' => 'available'|'taken'|null, ...] — null means unsupported or error.
     *
     * @param  array<string>  $tlds
     * @return array<string, string|null>
     */
    public function checkBatch(string $domain, array $tlds): array
    {
        $apiKey  = $this->apiKey();
        $baseUrl = rtrim($this->baseUrl(), '/');
        $timeout = config('domain-checker.timeouts.realtime_register', 5);

        try {
            $responses = Http::pool(function ($pool) use ($domain, $tlds, $apiKey, $baseUrl, $timeout) {
                foreach ($tlds as $tld) {
                    $pool->as($tld)
                        ->timeout($timeout)
                        ->withHeader('Authorization', "ApiKey {$apiKey}")
                        ->acceptJson()
                        ->get("{$baseUrl}/v2/domains/{$domain}.{$tld}/check");
                }
            });
        } catch (\Exception $e) {
            Log::debug('RTR pool failed', ['error' => $e->getMessage()]);

            return array_fill_keys($tlds, null);
        }

        $results = [];

        foreach ($tlds as $tld) {
            $response = $responses[$tld] ?? null;

            if ($response === null || $response instanceof \Throwable) {
                $results[$tld] = null;
                continue;
            }

            if (! $response->successful()) {
                Log::debug('RTR check failed', [
                    'domain' => "{$domain}.{$tld}",
                    'status' => $response->status(),
                ]);
                $results[$tld] = null;
                continue;
            }

            $data = $response->json();

            if (! isset($data['available'])) {
                $results[$tld] = null;
                continue;
            }

            $results[$tld] = $data['available'] ? 'available' : 'taken';
        }

        return $results;
    }

    private function apiKey(): string
    {
        return Setting::get('realtime_register_api_key', config('domain-checker.realtime_register.api_key', '')) ?? '';
    }

    private function baseUrl(): string
    {
        return Setting::get('realtime_register_base_url', config('domain-checker.realtime_register.base_url', 'https://api.yoursrs.com')) ?? 'https://api.yoursrs.com';
    }
}

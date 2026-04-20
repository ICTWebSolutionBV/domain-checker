<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class WhoisService
{
    private const IANA_WHOIS = 'whois.iana.org';

    private const NOT_FOUND_PATTERNS = [
        'no match',
        'not found',
        'no entries found',
        'no data found',
        'status: free',
        'domain not found',
        'object does not exist',
        'this domain name has not been registered',
        '% no entries found',
        'no information available',
        'available',
    ];

    /**
     * Check domain availability via WHOIS.
     * Returns 'available', 'taken', or 'unknown'.
     */
    public function check(string $domain, string $tld): string
    {
        $server = $this->findWhoisServer($tld);

        if (! $server) {
            return 'unknown';
        }

        $response = $this->query($server, "{$domain}.{$tld}");

        if ($response === null) {
            return 'unknown';
        }

        return $this->parseAvailability($response);
    }

    private function findWhoisServer(string $tld): ?string
    {
        $ianaResponse = $this->query(self::IANA_WHOIS, $tld);

        if (! $ianaResponse) {
            return null;
        }

        if (preg_match('/whois:\s+(\S+)/i', $ianaResponse, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function query(string $server, string $query): ?string
    {
        $timeout = config('domain-checker.timeouts.whois', 8);

        try {
            $socket = @fsockopen($server, 43, $errno, $errstr, $timeout);

            if (! $socket) {
                return null;
            }

            stream_set_timeout($socket, $timeout);
            fwrite($socket, "{$query}\r\n");

            $response = '';
            while (! feof($socket)) {
                $chunk = fread($socket, 4096);
                if ($chunk === false) {
                    break;
                }
                $response .= $chunk;
            }

            fclose($socket);

            return $response ?: null;
        } catch (\Exception $e) {
            Log::debug('WHOIS query failed', ['server' => $server, 'query' => $query, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function parseAvailability(string $response): string
    {
        $lower = strtolower($response);

        foreach (self::NOT_FOUND_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 'available';
            }
        }

        if (str_contains($lower, 'domain:') || str_contains($lower, 'registrar:') || str_contains($lower, 'creation date:')) {
            return 'taken';
        }

        return 'unknown';
    }
}

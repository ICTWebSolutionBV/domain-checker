<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class WhoisService
{
    private const IANA_WHOIS = 'whois.iana.org';

    private const NOT_FOUND_PATTERNS = [
        '/\bno match\b/i',
        '/\bnot found\b/i',
        '/\bno entries found\b/i',
        '/\bno data found\b/i',
        '/\bstatus:\s*free\b/i',
        '/\bdomain not found\b/i',
        '/\bobject does not exist\b/i',
        '/\bthis domain name has not been registered\b/i',
        '/%\s*no entries found\b/i',
        '/\bno information available\b/i',
        '/\bdomain is available\b/i',
        '/\bavailable for registration\b/i',
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

        if (str_contains($lower, 'domain:') || str_contains($lower, 'registrar:') || str_contains($lower, 'creation date:')) {
            return 'taken';
        }

        foreach (self::NOT_FOUND_PATTERNS as $pattern) {
            if (preg_match($pattern, $response)) {
                return 'available';
            }
        }

        return 'unknown';
    }
}

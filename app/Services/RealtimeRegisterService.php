<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Realtime Register IsProxy service.
 *
 * Uses the IsProxy socket protocol (is.yoursrs.com:2001) to check domain
 * availability. All IS commands for a batch are pipelined over a single
 * TLS connection — the server processes them in parallel and sends back
 * asynchronous responses, making this faster than individual RDAP requests.
 *
 * Protocol flow:
 *   1. TCP connect → STARTTLS → TLS upgrade
 *   2. LOGIN <apikey>  →  "100 Login ok"
 *   3. IS <domain.tld> (send N commands without waiting)
 *   4. Read N responses (arrive as server processes them)
 *   5. QUIT
 */
class RealtimeRegisterService
{
    private const RESPONSE_PATTERN = '#^([\-\w.]+)\s+(available|not available|invalid domain|error)#i';

    // -------------------------------------------------------------------------

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey());
    }

    /**
     * Check a batch of TLDs via IsProxy.
     * Returns ['com' => 'available'|'taken'|null, ...]
     * null = unsupported TLD or connection error (fall back to RDAP/WHOIS).
     *
     * @param  array<string>  $tlds
     * @return array<string, string|null>
     */
    public function checkBatch(string $domain, array $tlds): array
    {
        $apiKey = $this->apiKey();

        if (empty($apiKey)) {
            return array_fill_keys($tlds, null);
        }

        $socket = $this->connect();

        if ($socket === null) {
            return array_fill_keys($tlds, null);
        }

        try {
            return $this->runChecks($socket, $apiKey, $domain, $tlds);
        } finally {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Open a plain TCP socket and upgrade to TLS via STARTTLS.
     * Returns the socket resource on success, or null on failure.
     *
     * @return resource|null
     */
    private function connect(): mixed
    {
        $host    = $this->host();
        $port    = $this->port();
        $timeout = config('domain-checker.timeouts.realtime_register', 10);

        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );

        if (! $socket) {
            Log::debug('IsProxy TCP connect failed', ['host' => $host, 'error' => $errstr]);

            return null;
        }

        stream_set_timeout($socket, $timeout);

        // Upgrade to TLS
        fwrite($socket, "STARTTLS\r\n");
        $line = $this->readLine($socket);

        if (! str_starts_with($line, '100')) {
            Log::debug('IsProxy STARTTLS failed', ['response' => $line]);
            fclose($socket);

            return null;
        }

        // Set SSL options on the stream before upgrading
        stream_context_set_option($socket, 'ssl', 'verify_peer', true);
        stream_context_set_option($socket, 'ssl', 'verify_peer_name', true);

        if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            // Retry without peer verification (some environments block cert check)
            stream_context_set_option($socket, 'ssl', 'verify_peer', false);
            stream_context_set_option($socket, 'ssl', 'verify_peer_name', false);

            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                Log::debug('IsProxy TLS upgrade failed');
                fclose($socket);

                return null;
            }
        }

        return $socket;
    }

    /**
     * Login, pipeline all IS commands, read back all responses.
     *
     * @param  resource      $socket
     * @param  array<string> $tlds
     * @return array<string, string|null>
     */
    private function runChecks(mixed $socket, string $apiKey, string $domain, array $tlds): array
    {
        // Authenticate
        fwrite($socket, "LOGIN {$apiKey}\r\n");
        $loginLine = $this->readLine($socket);

        if (! str_starts_with($loginLine, '100')) {
            Log::debug('IsProxy login failed', ['response' => $loginLine]);

            return array_fill_keys($tlds, null);
        }

        // Build a lookup map so we can match responses back to TLDs
        // The response includes the full domain name, e.g. "example.com available"
        $domainLower = strtolower($domain);
        $pending     = [];  // fullDomain → tld
        foreach ($tlds as $tld) {
            $pending["{$domainLower}.{$tld}"] = $tld;
        }

        // Pipeline: send all IS commands without waiting for responses
        foreach (array_keys($pending) as $fullDomain) {
            fwrite($socket, "IS {$fullDomain}\r\n");
        }

        // Read responses until we have all results or the socket times out
        $results  = [];
        $deadline = time() + config('domain-checker.timeouts.realtime_register', 10);

        while (count($results) < count($tlds) && time() < $deadline) {
            $line = $this->readLine($socket);

            if ($line === '' || $line === false) {
                break;
            }

            if (preg_match(self::RESPONSE_PATTERN, $line, $m)) {
                $responseDomain = strtolower($m[1]);
                $responseStatus = strtolower($m[2]);

                if (isset($pending[$responseDomain])) {
                    $tld            = $pending[$responseDomain];
                    $results[$tld]  = match ($responseStatus) {
                        'available'    => 'available',
                        'not available' => 'taken',
                        default        => null,
                    };
                }
            }
        }

        // Any TLD we didn't get a response for → null (RDAP/WHOIS fallback)
        foreach ($tlds as $tld) {
            if (! array_key_exists($tld, $results)) {
                $results[$tld] = null;
            }
        }

        return $results;
    }

    /** Read a single CRLF-terminated line from the socket. */
    private function readLine(mixed $socket): string|false
    {
        $line = fgets($socket, 4096);

        return $line !== false ? rtrim($line, "\r\n") : false;
    }

    private function apiKey(): string
    {
        return Setting::get('realtime_register_api_key', config('domain-checker.realtime_register.api_key', '')) ?? '';
    }

    private function host(): string
    {
        return Setting::get('realtime_register_host', config('domain-checker.realtime_register.host', 'is.yoursrs.com')) ?? 'is.yoursrs.com';
    }

    private function port(): int
    {
        return (int) config('domain-checker.realtime_register.port', 2001);
    }
}

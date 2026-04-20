<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Realtime Register IsProxy service.
 *
 * Opens ONE TLS socket per request, logs in once, and keeps it alive.
 *
 * Key optimisation: pipelineStream() sends ALL IS commands in one shot,
 * then reads responses as the server processes them in parallel — total
 * time ≈ slowest single check, not N × avg check.  Each result is
 * delivered via a callback so the caller can flush SSE events immediately.
 */
class RealtimeRegisterService
{
    private const RESPONSE_PATTERN = '#^([\-\w.]+)\s+(available|not available|invalid domain|error)#i';

    /** Persistent socket reused for the lifetime of this service instance */
    private mixed $socket = null;
    private bool $loggedIn = false;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey());
    }

    /**
     * Send ALL IS commands at once, then call $onResult($tld, $status) for
     * each response as it arrives (server processes in parallel).
     *
     * Returns the list of TLDs that could not be resolved (null/error) so the
     * caller can fall back to RDAP / WHOIS for those.
     *
     * @param  array<string>                      $tlds
     * @param  callable(string, string): void     $onResult
     * @return array<string>  TLDs that need a fallback check
     */
    public function pipelineStream(string $domain, array $tlds, callable $onResult): array
    {
        if (! $this->isConfigured() || ! $this->ensureConnected()) {
            return $tlds; // everything needs a fallback
        }

        $domainLower = strtolower($domain);

        // Map fullDomain → tld so we can match async responses
        $pending = [];
        foreach ($tlds as $tld) {
            $pending["{$domainLower}.{$tld}"] = $tld;
        }

        // Fire all IS commands without waiting
        foreach (array_keys($pending) as $fullDomain) {
            fwrite($this->socket, "IS {$fullDomain}\r\n");
        }

        // Read responses as they arrive; call $onResult for each valid one
        $resolved = [];
        $fallback  = [];
        // Give 90 s for the full list — server-side parallel processing is fast
        $deadline  = time() + 90;

        while (count($resolved) + count($fallback) < count($tlds) && time() < $deadline) {
            $line = $this->readLine($this->socket);

            if ($line === false || $line === '') {
                // Socket died — mark remaining as fallback
                $this->socket   = null;
                $this->loggedIn = false;
                break;
            }

            if (preg_match(self::RESPONSE_PATTERN, $line, $m)) {
                $responseDomain = strtolower($m[1]);
                $responseStatus = strtolower($m[2]);

                if (isset($pending[$responseDomain])) {
                    $tld    = $pending[$responseDomain];
                    $status = match ($responseStatus) {
                        'available'     => 'available',
                        'not available' => 'taken',
                        default         => null,
                    };

                    if ($status !== null) {
                        $resolved[$tld] = true;
                        $onResult($tld, $status);
                    } else {
                        $fallback[] = $tld;
                    }
                }
            }
        }

        // Any TLDs we never got a response for → fallback
        foreach ($tlds as $tld) {
            if (! isset($resolved[$tld]) && ! in_array($tld, $fallback)) {
                $fallback[] = $tld;
            }
        }

        return $fallback;
    }

    /**
     * Blocking batch check (used when streaming is not needed, e.g. cache fill).
     *
     * @param  array<string>  $tlds
     * @return array<string, string|null>
     */
    public function checkBatch(string $domain, array $tlds): array
    {
        $results  = [];
        $fallback = $this->pipelineStream($domain, $tlds, function (string $tld, string $status) use (&$results) {
            $results[$tld] = $status;
        });

        foreach ($fallback as $tld) {
            $results[$tld] = null;
        }

        return $results;
    }

    public function __destruct()
    {
        if ($this->socket) {
            @fwrite($this->socket, "QUIT\r\n");
            @fclose($this->socket);
            $this->socket   = null;
            $this->loggedIn = false;
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function ensureConnected(): bool
    {
        if ($this->socket && $this->loggedIn && ! feof($this->socket)) {
            return true;
        }

        if ($this->socket) {
            @fclose($this->socket);
            $this->socket   = null;
            $this->loggedIn = false;
        }

        $this->socket = $this->openSocket();

        if ($this->socket === null) {
            return false;
        }

        fwrite($this->socket, "LOGIN {$this->apiKey()}\r\n");
        $response = $this->readLine($this->socket);

        if (! str_starts_with((string) $response, '100')) {
            Log::debug('IsProxy login failed', ['response' => $response]);
            @fclose($this->socket);
            $this->socket = null;

            return false;
        }

        $this->loggedIn = true;

        return true;
    }

    /** @return resource|null */
    private function openSocket(): mixed
    {
        $host    = $this->host();
        $port    = $this->port();
        $timeout = config('domain-checker.timeouts.realtime_register', 10);

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

        if (! $socket) {
            Log::debug('IsProxy TCP connect failed', ['host' => $host, 'error' => $errstr]);

            return null;
        }

        stream_set_timeout($socket, 90); // long read timeout for large batches

        fwrite($socket, "STARTTLS\r\n");
        $line = $this->readLine($socket);

        if (! str_starts_with((string) $line, '100')) {
            Log::debug('IsProxy STARTTLS failed', ['response' => $line]);
            fclose($socket);

            return null;
        }

        stream_context_set_option($socket, 'ssl', 'verify_peer', true);
        stream_context_set_option($socket, 'ssl', 'verify_peer_name', true);

        if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
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

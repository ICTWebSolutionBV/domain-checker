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
     * @param  array<string>  $tlds
     * @param  callable(string, string): void  $onResult
     * @return array<string> TLDs that need a fallback check
     */
    public function pipelineStream(string $domain, array $tlds, callable $onResult): array
    {
        if (! $this->isConfigured() || ! $this->ensureConnected()) {
            return $tlds; // everything needs a fallback
        }

        $domainLower = strtolower($domain);

        // Map fullDomain → tld so we can match async responses. Two entries can
        // collapse into one (a duplicate TLD), which is exactly why the read
        // loop below has to be driven by what is still outstanding rather than
        // by a count of the TLDs we were asked about: one command was sent, one
        // reply comes back, and counting to 2 used to park the request in
        // fgets() until the 90 s socket timeout.
        $pending = [];
        foreach ($tlds as $tld) {
            $pending["{$domainLower}.{$tld}"] = $tld;
        }

        $resolved = $this->exchange($pending, $onResult);

        // Everything still outstanding falls back, including the TLDs of any
        // reply that never arrived. No second pass over $tlds: a duplicate TLD
        // resolved under its one command must not also be reported as pending.
        $fallback = [];
        foreach ($tlds as $tld) {
            if (! isset($resolved[$tld])) {
                $fallback[$tld] = true;
            }
        }

        return array_keys($fallback);
    }

    public function __destruct()
    {
        if ($this->socket) {
            @fwrite($this->socket, "QUIT\r\n");
            $this->dropSocket();
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Send the IS burst and read the replies in one select-driven loop.
     *
     * Three things had to change here. The writes were unchecked, so a peer
     * that had gone away raised "fwrite(): Send of N bytes failed with errno=32
     * Broken pipe" — a warning Laravel rethrows as an ErrorException, which
     * truncated the SSE stream instead of falling back to RDAP. The burst also
     * wrote N commands before reading anything, which only works while the
     * whole burst fits in our send buffer plus the peer's receive window: a
     * socket pair blocks after 278 commands, and nothing was draining the
     * replies the peer was trying to write back. And the reads were blocking
     * fgets() calls with the deadline checked only between them, so one missing
     * reply parked the request for the 90 s socket timeout.
     *
     * Interleaving the two directions under one stream_select() with a
     * wall-clock deadline fixes all three: neither side can block the other,
     * every write result is inspected, and the loop ends when the set we are
     * actually waiting for is empty.
     *
     * @param  array<string, string>  $pending  fullDomain => tld, consumed as replies land
     * @param  callable(string, string): void  $onResult
     * @return array<string, true> the TLDs that got a verdict
     */
    private function exchange(array $pending, callable $onResult): array
    {
        $resolved = [];
        $deadline = microtime(true) + max(1, (int) config('domain-checker.realtime_register_budget', 60));
        $readTimeout = max(1, (int) config('domain-checker.realtime_register_read_timeout', 5));

        $outbox = '';
        foreach (array_keys($pending) as $fullDomain) {
            $outbox .= "IS {$fullDomain}\r\n";
        }

        // Non-blocking for the duration: stream_select() decides when to move,
        // so neither fwrite() nor fread() can park the request.
        stream_set_blocking($this->socket, false);
        $inbox = '';
        $lastProgress = microtime(true);

        while ($pending !== [] && microtime(true) < $deadline) {
            $read = [$this->socket];
            $write = $outbox !== '' ? [$this->socket] : [];
            $except = [];

            $ready = @stream_select($read, $write, $except, 0, 200_000);

            if ($ready === false) {
                $this->dropSocket();

                return $resolved;
            }

            if ($write !== []) {
                $bytes = @fwrite($this->socket, substr($outbox, 0, 8192));

                if ($bytes === false) {
                    // Broken pipe: hand the rest back as a fallback.
                    $this->dropSocket();

                    return $resolved;
                }

                if ($bytes > 0) {
                    $outbox = substr($outbox, $bytes);
                    $lastProgress = microtime(true);
                }
            }

            if ($read !== []) {
                $chunk = @fread($this->socket, 8192);

                if (($chunk === false || $chunk === '') && feof($this->socket)) {
                    $this->dropSocket(); // real EOF — the rest is a fallback

                    return $resolved;
                }

                if (is_string($chunk) && $chunk !== '') {
                    $inbox .= $chunk;
                    $lastProgress = microtime(true);

                    while (($newline = strpos($inbox, "\n")) !== false) {
                        $line = rtrim(substr($inbox, 0, $newline), "\r\n");
                        $inbox = substr($inbox, $newline + 1);

                        $this->consumeLine($line, $pending, $resolved, $onResult);
                    }
                }
            }

            // Nothing moving in either direction for a whole read timeout: the
            // remaining replies are not coming, so stop waiting for them.
            if ($outbox === '' && microtime(true) - $lastProgress > $readTimeout) {
                break;
            }
        }

        // The socket outlives this call, and the login/handshake reads expect it
        // the way they left it.
        if ($this->socket) {
            stream_set_blocking($this->socket, true);
        }

        return $resolved;
    }

    /**
     * @param  array<string, string>  $pending
     * @param  array<string, true>  $resolved
     * @param  callable(string, string): void  $onResult
     */
    private function consumeLine(string $line, array &$pending, array &$resolved, callable $onResult): void
    {
        if ($line === '' || ! preg_match(self::RESPONSE_PATTERN, $line, $m)) {
            return; // banners, keep-alives, framing we do not know
        }

        $responseDomain = strtolower($m[1]);

        if (! isset($pending[$responseDomain])) {
            return; // a reply for something we are not waiting for
        }

        $tld = $pending[$responseDomain];
        unset($pending[$responseDomain]);

        $status = match (strtolower($m[2])) {
            'available' => 'available',
            'not available' => 'taken',
            default => null,
        };

        if ($status === null) {
            return; // 'error' / 'invalid domain' — let RDAP try
        }

        $resolved[$tld] = true;
        $onResult($tld, $status);
    }

    private function ensureConnected(): bool
    {
        if ($this->socket && $this->loggedIn && ! feof($this->socket)) {
            return true;
        }

        if ($this->socket) {
            $this->dropSocket();
        }

        $this->socket = $this->openSocket();

        if ($this->socket === null) {
            return false;
        }

        if (@fwrite($this->socket, "LOGIN {$this->apiKey()}\r\n") === false) {
            $this->dropSocket();

            return false;
        }

        $response = $this->readLine($this->socket);

        if (! str_starts_with((string) $response, '100')) {
            Log::debug('IsProxy login failed', ['response' => $response]);
            $this->dropSocket();

            return false;
        }

        $this->loggedIn = true;

        return true;
    }

    /** @return resource|null */
    private function openSocket(): mixed
    {
        $host = $this->host();
        $port = $this->port();
        $timeout = config('domain-checker.timeouts.realtime_register', 10);

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

        if (! $socket) {
            Log::debug('IsProxy TCP connect failed', ['host' => $host, 'error' => $errstr]);

            return null;
        }

        stream_set_timeout($socket, max(1, (int) config('domain-checker.realtime_register_read_timeout', 5)));

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
            Log::debug('IsProxy TLS upgrade failed');
            fclose($socket);

            return null;
        }

        return $socket;
    }

    /**
     * Close the socket for real. Setting the property to null on its own leaked
     * the descriptor for the rest of the request, and left __destruct() with
     * nothing to close.
     */
    private function dropSocket(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->loggedIn = false;
    }

    private function readLine(mixed $socket): string|false
    {
        $line = @fgets($socket, 4096);

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

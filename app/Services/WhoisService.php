<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WhoisService
{
    private const IANA_WHOIS = 'whois.iana.org';

    /**
     * Explicit statements that the registry has no record of the domain.
     * These are checked FIRST: registries answer an available domain with an
     * echo of the query ("Domain: example.be") plus a free/available status,
     * so any "looks registered" heuristic has to run after this list.
     */
    private const NOT_FOUND_PATTERNS = [
        '/\bno match\b/i',
        '/\bnot found\b/i',
        '/\bno entries found\b/i',
        '/\bno data found\b/i',
        '/\bstatus:\s*(free|available)\b/i',
        '/\bdomain not found\b/i',
        '/\bobject does not exist\b/i',
        '/\b(this domain name |domain )?has not been registered\b/i',
        '/%\s*no entries found\b/i',
        '/\bno information available\b/i',
        '/\bdomain is available\b/i',
        '/\bavailable for registration\b/i',
        '/\bnothing found for this query\b/i',
        '/\bno object found\b/i',
    ];

    /**
     * Phrases that contain a NOT_FOUND phrase but mean the opposite
     * ("this domain name is not available for registration"). They are removed
     * from the response before the not-found patterns run, so a negated
     * sentence can never be read as proof that a domain is free — the
     * expensive direction of a wrong answer.
     */
    private const NEGATED_AVAILABILITY_PATTERNS = [
        '/\b(is |are )?(not|no longer)\s+available for registration\b/i',
        '/\b(is |are )?not\s+available\b/i',
        '/\bcannot be registered\b/i',
    ];

    /**
     * Fields that only appear for a domain that actually exists. Deliberately
     * excludes a bare "domain:" line: every registry echoes the queried name,
     * including in its "this name is free" answer.
     */
    private const REGISTERED_PATTERNS = [
        '/^\s*registrar:/im',
        '/^\s*registrant:/im',
        '/^\s*holder:/im',
        '/^\s*created(\s|:)/im',
        '/^\s*creation date:/im',
        '/^\s*registered(\s|:)/im',
        '/^\s*expir(y|es|ation)[^:]*:/im',
        '/^\s*(name ?server|nserver):/im',
        '/\bstatus:\s*(active|connect|ok|clienttransferprohibited|serverdeleteprohibited|registered|not available)\b/i',
    ];

    /**
     * Check domain availability via WHOIS.
     * Returns 'available', 'taken', or 'unknown'.
     */
    public function check(string $domain, string $tld): string
    {
        $status = 'unknown';

        $this->streamCheck($domain, [$tld], function (string $t, string $s) use (&$status): void {
            $status = $s;
        });

        return $status;
    }

    /**
     * Check many TLDs concurrently, calling $onResult($tld, $status) as each
     * registry answers.
     *
     * WHOIS used to be walked one TLD at a time, and every check paid *two*
     * sequential socket round-trips (IANA for the server, then the registry).
     * On the full IANA list that was 181 TLDs x ~380 ms = over a minute of
     * strictly serial waiting, during which the SSE stream emitted nothing. The
     * sockets are independent, so they are now driven together with
     * stream_select() under a bounded concurrency and a wall-clock budget.
     *
     * @param  array<string>  $tlds
     * @param  callable(string, string): void  $onResult
     */
    public function streamCheck(string $domain, array $tlds, callable $onResult): void
    {
        $tlds = array_values(array_unique($tlds));

        if ($tlds === []) {
            return;
        }

        $servers = $this->findWhoisServers($tlds);

        $jobs = [];

        foreach ($tlds as $tld) {
            $server = $servers[$tld] ?? null;

            if ($server === null) {
                // No port-43 service for this TLD -- an honest non-answer, and
                // there is nothing to wait for, so emit it now.
                $onResult($tld, 'unknown');

                continue;
            }

            $jobs[$tld] = ['server' => $server, 'query' => "{$domain}.{$tld}"];
        }

        if ($jobs === []) {
            return;
        }

        $this->queryMany($jobs, function (string $tld, ?string $response) use ($onResult): void {
            $onResult($tld, $response === null ? 'unknown' : $this->parseAvailability($response));
        });
    }

    /**
     * Resolve each TLD's authoritative WHOIS server via IANA.
     *
     * Cached: the mapping changes maybe once a year, while an uncached lookup
     * costs a second socket round-trip on every single check and gets us
     * throttled by whois.iana.org on a full-list run — after which every TLD
     * on the WHOIS path silently degrades to 'unknown'. The cache is read in
     * one batch and the misses are resolved concurrently, so a cold full-list
     * run pays one IANA wave instead of 181 serial round-trips.
     *
     * @param  array<string>  $tlds
     * @return array<string, string|null>
     */
    private function findWhoisServers(array $tlds): array
    {
        $ttl = (int) config('domain-checker.cache.whois_server_ttl', 86400);

        $keys = [];
        foreach ($tlds as $tld) {
            $keys[$tld] = "whois_server:{$tld}";
        }

        $cached = Cache::many(array_values($keys));

        $servers = [];
        $jobs = [];

        foreach ($tlds as $tld) {
            $hit = $cached[$keys[$tld]] ?? null;

            if ($hit !== null) {
                $servers[$tld] = $hit === '' ? null : $hit;

                continue;
            }

            $jobs[$tld] = ['server' => self::IANA_WHOIS, 'query' => $tld];
        }

        if ($jobs === []) {
            return $servers;
        }

        $found = [];
        $missing = [];

        $this->queryMany($jobs, function (string $tld, ?string $response) use (&$found, &$missing, &$servers): void {
            $server = $this->parseIanaWhoisServer($response);
            $servers[$tld] = $server;

            if ($server === null) {
                $missing["whois_server:{$tld}"] = '';
            } else {
                $found["whois_server:{$tld}"] = $server;
            }
        });

        if ($found !== []) {
            Cache::putMany($found, $ttl);
        }

        // A miss gets a short TTL, so a throttled IANA does not pin a whole day
        // of 'unknown' onto every WHOIS-backed TLD.
        if ($missing !== []) {
            Cache::putMany($missing, 300);
        }

        return $servers;
    }

    /**
     * Anchor to the line and require a dotted hostname. IANA leaves the field
     * empty for TLDs with no port-43 service (.uk since Nominet moved to RDAP),
     * and an unanchored \s+ then walks past the newline and captures the next
     * field name -- we spent those lookups calling fsockopen('status:', 43).
     */
    private function parseIanaWhoisServer(?string $response): ?string
    {
        if ($response === null) {
            return null;
        }

        if (preg_match('/^whois:[ \t]*([a-z0-9][a-z0-9.-]*\.[a-z]{2,})[ \t]*\r?$/im', $response, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return null;
    }

    /**
     * Run many port-43 queries at once, calling $onResponse($key, $body) as each
     * socket finishes. $body is null when the server never answered.
     *
     * Every socket is bounded three ways: the connect timeout, an idle timeout
     * per socket, and a wall-clock budget for the wave. The last one is what
     * stops the old `while (! feof($socket))` shape, where a server trickling
     * one byte just under the read timeout could hold the loop open forever.
     *
     * @param  array<string, array{server: string, query: string}>  $jobs
     * @param  callable(string, string|null): void  $onResponse
     */
    private function queryMany(array $jobs, callable $onResponse): void
    {
        $timeout = (int) config('domain-checker.timeouts.whois', 8);
        $concurrency = max(1, (int) config('domain-checker.concurrency.whois', 24));
        $maxBytes = (int) config('domain-checker.whois_max_response', 65536);
        $port = (int) config('domain-checker.whois_port', 43);
        $deadline = microtime(true) + (int) config('domain-checker.whois_wave_budget', 30);

        $queue = $jobs;

        /**
         * key => [socket, phase ('connecting'|'reading'), pending write, buffer,
         * idle expiry]
         *
         * @var array<string, array<string, mixed>> $open
         */
        $open = [];

        $finish = function (string $key, ?string $body) use (&$open, $onResponse): void {
            if (isset($open[$key])) {
                @fclose($open[$key]['socket']);
                unset($open[$key]);
            }

            $onResponse($key, $body);
        };

        while (($queue !== [] || $open !== []) && microtime(true) < $deadline) {
            // Top up to the concurrency ceiling. The connect is asynchronous, so
            // a slow registry delays only its own socket instead of every socket
            // queued behind it.
            while ($queue !== [] && count($open) < $concurrency) {
                $key = array_key_first($queue);
                $job = $queue[$key];
                unset($queue[$key]);

                $socket = @stream_socket_client(
                    "tcp://{$job['server']}:{$port}",
                    $errno,
                    $errstr,
                    $timeout,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                );

                if (! $socket) {
                    Log::debug('WHOIS connect failed', ['server' => $job['server'], 'error' => $errstr]);
                    $onResponse($key, null);

                    continue;
                }

                stream_set_blocking($socket, false);

                $open[$key] = [
                    'socket' => $socket,
                    'phase' => 'connecting',
                    'write' => "{$job['query']}\r\n",
                    'buffer' => '',
                    'expires' => microtime(true) + $timeout,
                ];
            }

            if ($open === []) {
                continue;
            }

            $read = $write = [];

            foreach ($open as $key => $state) {
                if ($state['phase'] === 'connecting') {
                    $write[$key] = $state['socket'];
                } else {
                    $read[$key] = $state['socket'];
                }
            }

            $except = [];
            $wait = max(0.05, min(1.0, $deadline - microtime(true)));
            $ready = @stream_select($read, $write, $except, 0, min(999_999, (int) ($wait * 1_000_000)));

            if ($ready === false) {
                break;
            }

            foreach ($write as $key => $socket) {
                // Writable means the connect either completed or failed; fwrite
                // tells us which.
                $bytes = @fwrite($socket, $open[$key]['write']);

                if ($bytes === false || $bytes === 0) {
                    $finish($key, null);

                    continue;
                }

                $open[$key]['write'] = substr($open[$key]['write'], $bytes);
                $open[$key]['expires'] = microtime(true) + $timeout;

                if ($open[$key]['write'] === '') {
                    $open[$key]['phase'] = 'reading';
                }
            }

            foreach ($read as $key => $socket) {
                $chunk = @fread($socket, 8192);

                if ($chunk === false || $chunk === '') {
                    if (feof($socket)) {
                        // The registry said everything it was going to say.
                        $finish($key, $open[$key]['buffer'] !== '' ? $open[$key]['buffer'] : null);
                    }

                    continue;
                }

                $open[$key]['buffer'] .= $chunk;
                $open[$key]['expires'] = microtime(true) + $timeout;

                if (strlen($open[$key]['buffer']) >= $maxBytes) {
                    $finish($key, substr($open[$key]['buffer'], 0, $maxBytes));
                }
            }

            // Idle sockets: whatever arrived is all we are going to get.
            $now = microtime(true);
            foreach ($open as $key => $state) {
                if ($state['expires'] <= $now) {
                    $finish($key, $state['buffer'] !== '' ? $state['buffer'] : null);
                }
            }
        }

        // Budget spent: report what is still open with whatever it sent, and
        // close every descriptor rather than leaving it to the end of the
        // request.
        foreach ($open as $key => $state) {
            $finish($key, $state['buffer'] !== '' ? $state['buffer'] : null);
        }

        foreach (array_keys($queue) as $key) {
            $onResponse($key, null);
        }
    }

    private function parseAvailability(string $response): string
    {
        // Drop negated phrasings first, so "not available for registration"
        // cannot be mistaken for the registry saying the name is free.
        $scrubbed = preg_replace(self::NEGATED_AVAILABILITY_PATTERNS, ' ', $response) ?? $response;

        foreach (self::NOT_FOUND_PATTERNS as $pattern) {
            if (preg_match($pattern, $scrubbed)) {
                return 'available';
            }
        }

        foreach (self::REGISTERED_PATTERNS as $pattern) {
            if (preg_match($pattern, $response)) {
                return 'taken';
            }
        }

        return 'unknown';
    }
}

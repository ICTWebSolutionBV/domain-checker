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

    /**
     * Resolve a TLD's authoritative WHOIS server via IANA.
     *
     * Cached: the mapping changes maybe once a year, while an uncached lookup
     * costs a second socket round-trip on every single check and gets us
     * throttled by whois.iana.org on a full-list run — after which every TLD
     * on the WHOIS path silently degrades to 'unknown'.
     */
    private function findWhoisServer(string $tld): ?string
    {
        $ttl = (int) config('domain-checker.cache.whois_server_ttl', 86400);

        $server = Cache::remember(
            "whois_server:{$tld}",
            $ttl,
            function () use ($tld): string {
                $ianaResponse = $this->query(self::IANA_WHOIS, $tld);

                // Anchor to the line and require a dotted hostname. IANA leaves
                // the field empty for TLDs with no port-43 service (.uk since
                // Nominet moved to RDAP), and an unanchored \s+ then walks past
                // the newline and captures the next field name -- we spent those
                // lookups calling fsockopen('status:', 43).
                if ($ianaResponse && preg_match('/^whois:[ \t]*([a-z0-9][a-z0-9.-]*\.[a-z]{2,})[ \t]*\r?$/im', $ianaResponse, $matches)) {
                    return strtolower(trim($matches[1]));
                }

                // Cache the miss too, but see below: a failed lookup gets a
                // short TTL so a throttled IANA does not poison a whole day.
                return '';
            },
        );

        if ($server === '') {
            Cache::put("whois_server:{$tld}", '', 300);

            return null;
        }

        return $server;
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

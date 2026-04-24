<?php

namespace App\Services;

/**
 * HTTP/3 availability checker.
 *
 * Runs a sequence of checks against a host and emits each result via callback
 * so the caller can stream them to the client (SSE) as they complete.
 *
 * Checks performed (in order):
 *  1. DNS A + AAAA resolution
 *  2. IPv6 availability
 *  3. HTTPS reachability + connect time
 *  4. TLS 1.3 (required for HTTP/3)
 *  5. HTTP/2 (typically required for HTTP/3)
 *  6. Alt-Svc header advertisement (h3=":443" etc.)
 *  7. Direct HTTP/3 QUIC connection (when curl is built with QUIC support)
 */
class Http3CheckService
{
    /**
     * Run all checks and call $emit(array $event) for each result.
     *
     * Event shapes
     * ─────────────────────────────────────────────────────────
     * { type:'host',  hostname:string, url:string }
     * { type:'check', key:string, status:'pass'|'fail'|'warn'|'info',
     *                 label:string, detail:string }
     * { type:'done',  result:'supported'|'not_supported'|'error',
     *                 h3:bool, summary:string }
     */
    public function check(string $input, callable $emit): void
    {
        $parsed = $this->parseInput($input);

        if (! $parsed) {
            $emit(['type' => 'done', 'result' => 'error', 'h3' => false, 'summary' => 'Invalid hostname — please enter a valid domain or URL.']);

            return;
        }

        ['hostname' => $hostname, 'url' => $url] = $parsed;
        $emit(['type' => 'host', 'hostname' => $hostname, 'url' => $url]);

        // ── 1 + 2: DNS ────────────────────────────────────────────────────
        if (! $this->checkDns($hostname, $emit)) {
            $emit(['type' => 'done', 'result' => 'error', 'h3' => false, 'summary' => "Could not resolve {$hostname}."]);

            return;
        }

        // ── 3 + 4: HTTPS + TLS ────────────────────────────────────────────
        if (! $this->checkTls($hostname, $emit)) {
            $emit(['type' => 'done', 'result' => 'error', 'h3' => false, 'summary' => "Cannot reach {$hostname} over HTTPS."]);

            return;
        }

        // ── 5 + 6: HTTP/2 + Alt-Svc ──────────────────────────────────────
        $h3Advertised = $this->checkHttp2AndAltSvc($url, $emit);

        // ── 7: Direct HTTP/3 QUIC ─────────────────────────────────────────
        $h3Connected = $this->checkHttp3($url, $emit);

        // ── Verdict ───────────────────────────────────────────────────────
        $h3Supported = $h3Connected || $h3Advertised;

        $emit([
            'type'    => 'done',
            'result'  => $h3Supported ? 'supported' : 'not_supported',
            'h3'      => $h3Supported,
            'summary' => match (true) {
                $h3Connected  => 'HTTP/3 connection confirmed via QUIC',
                $h3Advertised => 'HTTP/3 supported — advertised via Alt-Svc header',
                default       => 'HTTP/3 is not supported on this host',
            },
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Individual checks
    // ─────────────────────────────────────────────────────────────────────

    private function checkDns(string $hostname, callable $emit): bool
    {
        $a    = @dns_get_record($hostname, DNS_A)    ?: [];
        $aaaa = @dns_get_record($hostname, DNS_AAAA) ?: [];

        $ipv4 = array_column($a, 'ip');
        $ipv6 = array_column($aaaa, 'ipv6');
        $all  = array_merge($ipv4, $ipv6);

        if (empty($all)) {
            $emit(['type' => 'check', 'key' => 'dns', 'status' => 'fail', 'label' => 'DNS Resolution', 'detail' => 'Hostname could not be resolved']);

            return false;
        }

        $preview = implode(', ', array_slice($all, 0, 3));
        if (count($all) > 3) {
            $preview .= ' +' . (count($all) - 3) . ' more';
        }
        $emit(['type' => 'check', 'key' => 'dns', 'status' => 'pass', 'label' => 'DNS Resolution', 'detail' => $preview]);

        if ($ipv6) {
            $ipv6Detail = $ipv6[0] . (count($ipv6) > 1 ? ' +' . (count($ipv6) - 1) . ' more' : '');
            $emit(['type' => 'check', 'key' => 'ipv6', 'status' => 'pass', 'label' => 'IPv6 (AAAA)', 'detail' => $ipv6Detail]);
        } else {
            $emit(['type' => 'check', 'key' => 'ipv6', 'status' => 'warn', 'label' => 'IPv6 (AAAA)', 'detail' => 'No AAAA record — IPv4 only (HTTP/3 still works over IPv4)']);
        }

        return true;
    }

    private function checkTls(string $hostname, callable $emit): bool
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'SNI_enabled'      => true,
                'peer_name'        => $hostname,
            ],
        ]);

        $t0   = microtime(true);
        $sock = @stream_socket_client(
            "tls://{$hostname}:443",
            $errno, $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );
        $ms = (int) round((microtime(true) - $t0) * 1000);

        if (! $sock) {
            $emit(['type' => 'check', 'key' => 'https', 'status' => 'fail', 'label' => 'HTTPS', 'detail' => $errstr ?: 'Connection refused']);
            $emit(['type' => 'check', 'key' => 'tls13', 'status' => 'fail', 'label' => 'TLS 1.3', 'detail' => 'Could not establish TLS connection']);

            return false;
        }

        $crypto   = stream_get_meta_data($sock)['crypto'] ?? [];
        $protocol = $crypto['protocol']    ?? 'Unknown';
        $cipher   = $crypto['cipher_name'] ?? '';
        fclose($sock);

        $isTls13 = stripos($protocol, '1.3') !== false;

        $emit(['type' => 'check', 'key' => 'https', 'status' => 'pass', 'label' => 'HTTPS', 'detail' => "Connected in {$ms} ms"]);
        $emit([
            'type'   => 'check',
            'key'    => 'tls13',
            'status' => $isTls13 ? 'pass' : 'warn',
            'label'  => 'TLS 1.3',
            'detail' => $cipher ? "{$protocol} / {$cipher}" : $protocol,
        ]);

        return true;
    }

    private function checkHttp2AndAltSvc(string $url, callable $emit): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 DomainChecker/1.0 HTTP3-Probe',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => '',
        ]);

        $body        = curl_exec($ch);
        $info        = curl_getinfo($ch);
        $errno       = curl_errno($ch);
        $versionUsed = $this->curlHttpVersion($ch);
        curl_close($ch);

        if ($errno || $body === false) {
            $emit(['type' => 'check', 'key' => 'http2',  'status' => 'warn', 'label' => 'HTTP/2',             'detail' => 'Could not check — connection error']);
            $emit(['type' => 'check', 'key' => 'altsvc', 'status' => 'fail', 'label' => 'Alt-Svc (h3) Header', 'detail' => 'Could not retrieve response headers']);

            return false;
        }

        // CURL_HTTP_VERSION_2_0 = 3
        $http2 = ($versionUsed === CURL_HTTP_VERSION_2_0);
        $emit([
            'type'   => 'check',
            'key'    => 'http2',
            'status' => $http2 ? 'pass' : 'warn',
            'label'  => 'HTTP/2',
            'detail' => $http2 ? 'Supported' : 'Not supported (HTTP/3 usually requires HTTP/2)',
        ]);

        // Extract Alt-Svc from response headers
        $altSvc = $this->extractAltSvc($body, (int) $info['header_size']);

        $h3Advertised = (bool) preg_match('/\bh3\b/i', $altSvc);

        if ($h3Advertised) {
            $emit(['type' => 'check', 'key' => 'altsvc', 'status' => 'pass', 'label' => 'Alt-Svc (h3) Header', 'detail' => $altSvc]);
        } elseif ($altSvc) {
            $emit(['type' => 'check', 'key' => 'altsvc', 'status' => 'warn', 'label' => 'Alt-Svc (h3) Header', 'detail' => "Present but no h3 entry: {$altSvc}"]);
        } else {
            $emit(['type' => 'check', 'key' => 'altsvc', 'status' => 'fail', 'label' => 'Alt-Svc (h3) Header', 'detail' => 'No Alt-Svc header found']);
        }

        return $h3Advertised;
    }

    private function checkHttp3(string $url, callable $emit): bool
    {
        if (! defined('CURL_HTTP_VERSION_3')) {
            $emit(['type' => 'check', 'key' => 'http3', 'status' => 'info', 'label' => 'HTTP/3 Direct (QUIC)', 'detail' => 'curl not compiled with QUIC — using Alt-Svc advertisement as proof']);

            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_3,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 DomainChecker/1.0 HTTP3-Probe',
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $t0    = microtime(true);
        curl_exec($ch);
        $ms    = (int) round((microtime(true) - $t0) * 1000);
        $info  = curl_getinfo($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $versionUsed = $this->curlHttpVersion($ch);
        curl_close($ch);

        // Confirmed HTTP/3: no error, got a valid response, version = 30
        if (! $errno && $info['http_code'] >= 100 && $versionUsed === CURL_HTTP_VERSION_3) {
            $emit(['type' => 'check', 'key' => 'http3', 'status' => 'pass', 'label' => 'HTTP/3 Direct (QUIC)', 'detail' => "Connected via QUIC in {$ms} ms (HTTP/{$this->versionLabel($versionUsed)})"]);

            return true;
        }

        // Connected but server fell back to a lower version
        if (! $errno && $info['http_code'] >= 100) {
            $emit(['type' => 'check', 'key' => 'http3', 'status' => 'warn', 'label' => 'HTTP/3 Direct (QUIC)', 'detail' => 'Attempted HTTP/3 but server negotiated ' . $this->versionLabel($versionUsed)]);

            return false;
        }

        // Connection failed
        $detail = $error ?: 'QUIC connection failed';
        $emit(['type' => 'check', 'key' => 'http3', 'status' => 'fail', 'label' => 'HTTP/3 Direct (QUIC)', 'detail' => $detail]);

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function parseInput(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $input)) {
            $input = 'https://' . $input;
        }

        $parts = parse_url($input);
        if (empty($parts['host'])) {
            return null;
        }

        $hostname = strtolower(rtrim($parts['host'], '.'));

        // Basic hostname validation
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $hostname)) {
            return null;
        }

        return [
            'hostname' => $hostname,
            'url'      => 'https://' . $hostname . '/',
        ];
    }

    private function extractAltSvc(string $rawResponse, int $headerSize): string
    {
        $headers  = substr($rawResponse, 0, $headerSize);
        $altSvc   = '';

        foreach (explode("\n", $headers) as $line) {
            $line = ltrim($line);
            if (stripos($line, 'alt-svc:') === 0) {
                $altSvc = trim(substr($line, 8));
            }
        }

        return $altSvc;
    }

    /** Returns the CURLINFO_HTTP_VERSION value for the last request. */
    private function curlHttpVersion(mixed $ch): int
    {
        if (defined('CURLINFO_HTTP_VERSION')) {
            return (int) curl_getinfo($ch, CURLINFO_HTTP_VERSION);
        }

        return 0;
    }

    /** Human-readable label for a CURL_HTTP_VERSION_* constant. */
    private function versionLabel(int $version): string
    {
        return match ($version) {
            1       => '1.0',
            2       => '1.1',
            3       => '2',
            30      => '3',
            default => 'Unknown',
        };
    }
}

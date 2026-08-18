<?php

namespace App\Services;

class RedirectCheckService
{
    private const MAX_HOPS = 20;

    private const TIMEOUT = 15;

    public function __construct(private readonly PublicNetworkGuard $networkGuard) {}

    public static function userAgentOptions(): array
    {
        return [
            ['key' => 'default',        'group' => 'DEFAULT',          'label' => 'DEFAULT (ToolBot)'],
            ['key' => 'chrome120',      'group' => 'BROWSER',          'label' => 'BROWSER - Chrome 120'],
            ['key' => 'chrome34',       'group' => 'BROWSER',          'label' => 'BROWSER - Chrome 34'],
            ['key' => 'firefox121',     'group' => 'BROWSER',          'label' => 'BROWSER - Firefox 121'],
            ['key' => 'firefox36',      'group' => 'BROWSER',          'label' => 'BROWSER - Firefox 3.6'],
            ['key' => 'ie10',           'group' => 'BROWSER',          'label' => 'BROWSER - Internet Explorer 10'],
            ['key' => 'ie9',            'group' => 'BROWSER',          'label' => 'BROWSER - Internet Explorer 9'],
            ['key' => 'ie8',            'group' => 'BROWSER',          'label' => 'BROWSER - Internet Explorer 8'],
            ['key' => 'opera12',        'group' => 'BROWSER',          'label' => 'BROWSER - Opera 12.00'],
            ['key' => 'safari504',      'group' => 'BROWSER',          'label' => 'BROWSER - Safari 5.0.4'],
            ['key' => 'ipad',           'group' => 'MOBILEDEVICE',     'label' => 'MOBILEDEVICE - iPad'],
            ['key' => 'iphone5',        'group' => 'MOBILEDEVICE',     'label' => 'MOBILEDEVICE - iPhone 5'],
            ['key' => 'iphone4',        'group' => 'MOBILEDEVICE',     'label' => 'MOBILEDEVICE - iPhone 4'],
            ['key' => 'android23',      'group' => 'MOBILEDEVICE',     'label' => 'MOBILEDEVICE - Android 2.3'],
            ['key' => 'kindlefire',     'group' => 'MOBILEDEVICE',     'label' => 'MOBILEDEVICE - Kindle Fire'],
            ['key' => 'windowsphone7',  'group' => 'MOBILEDEVICE',     'label' => 'MOBILEDEVICE - Windows Phone 7'],
            ['key' => 'nexus5',         'group' => 'MOBILEDEVICE',     'label' => 'MOBILEDEVICE - Nexus 5 (Android Phone)'],
            ['key' => 'googlebot',      'group' => 'SEARCHBOT',        'label' => 'SEARCHBOT - Googlebot'],
            ['key' => 'googleadsbot',   'group' => 'SEARCHBOT',        'label' => 'SEARCHBOT - GoogleAdsBot'],
            ['key' => 'googlebotmobile', 'group' => 'SEARCHBOT',        'label' => 'SEARCHBOT - Googlebot-Mobile Smartphone'],
            ['key' => 'bingbot',        'group' => 'SEARCHBOT',        'label' => 'SEARCHBOT - BingBot'],
            ['key' => 'yahoobot',       'group' => 'SEARCHBOT',        'label' => 'SEARCHBOT - Yahoobot'],
            ['key' => 'yandexbot',      'group' => 'SEARCHBOT',        'label' => 'SEARCHBOT - YandexBot'],
            ['key' => 'baiduspider',    'group' => 'SEARCHBOT',        'label' => 'SEARCHBOT - BaiduSpider'],
        ];
    }

    private static function agentString(string $key): string
    {
        return match ($key) {
            'chrome120' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'chrome34' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/34.0.1847.131 Safari/537.36',
            'firefox121' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            'firefox36' => 'Mozilla/5.0 (Windows NT 5.1; rv:3.6) Gecko/20100101 Firefox/3.6',
            'ie10' => 'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; Trident/6.0)',
            'ie9' => 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)',
            'ie8' => 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1; Trident/4.0)',
            'opera12' => 'Opera/9.80 (Windows NT 6.1; WOW64) Presto/2.12.388 Version/12.00',
            'safari504' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_7_3) AppleWebKit/534.55.3 (KHTML, like Gecko) Version/5.0.4 Safari/534.55.3',
            'ipad' => 'Mozilla/5.0 (iPad; CPU OS 7_0 like Mac OS X) AppleWebKit/537.51.1 (KHTML, like Gecko) Version/7.0 Mobile/11A465 Safari/9537.53',
            'iphone5' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 9_0 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Version/9.0 Mobile/13A344 Safari/601.1',
            'iphone4' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 7_0 like Mac OS X) AppleWebKit/537.51.1 (KHTML, like Gecko) Version/7.0 Mobile/11A465 Safari/9537.53',
            'android23' => 'Mozilla/5.0 (Linux; U; Android 2.3.6; en-us; Nexus S Build/GRK39F) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1',
            'kindlefire' => 'Mozilla/5.0 (Linux; U; Android 2.3.4; en-us; Kindle Fire Build/GINGERBREAD) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1',
            'windowsphone7' => 'Mozilla/5.0 (compatible; MSIE 9.0; Windows Phone OS 7.5; Trident/5.0; IEMobile/9.0)',
            'nexus5' => 'Mozilla/5.0 (Linux; Android 4.4.2; Nexus 5 Build/KOT49H) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/34.0.1847.114 Mobile Safari/537.36',
            'googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'googleadsbot' => 'AdsBot-Google (+http://www.google.com/adsbot.html)',
            'googlebotmobile' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'bingbot' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'yahoobot' => 'Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)',
            'yandexbot' => 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
            'baiduspider' => 'Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)',
            default => 'Mozilla/5.0 (compatible; DomainCheckerBot/1.0)',
        };
    }

    /**
     * @return array{hops: list<array{url: string, status: int, status_text: string, headers: list<array{name: string, value: string}>}>, error: string|null}
     */
    public function check(string $url, string $userAgentKey): array
    {
        $ua = self::agentString($userAgentKey);
        $hops = [];
        $currentUrl = $this->networkGuard->normalizeHttpUrl($url);
        $error = null;

        if ($currentUrl === null) {
            return ['hops' => [], 'error' => 'Please enter a valid public HTTP or HTTPS URL.'];
        }

        for ($i = 0; $i < self::MAX_HOPS; $i++) {
            $target = $this->networkGuard->inspectHttpUrl($currentUrl);

            if ($target === null) {
                $error = 'URL resolves to a blocked or private network target.';
                break;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $target['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => $target['curl_resolve'],
            ]);

            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $errMsg = curl_error($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($errno !== 0 || $response === false) {
                $error = $errMsg ?: 'Connection failed';
                break;
            }

            $rawHeaders = substr((string) $response, 0, $headerSize);
            $headers = $this->parseHeaders($rawHeaders);
            $location = $this->findHeader($headers, 'location');

            $hops[] = [
                'url' => $currentUrl,
                'status' => $statusCode,
                'status_text' => $this->statusText($statusCode),
                'headers' => $headers,
            ];

            if ($statusCode < 300 || $statusCode >= 400 || $location === null) {
                break;
            }

            $currentUrl = $this->resolveUrl($currentUrl, $location);
        }

        return ['hops' => $hops, 'error' => $error];
    }

    /** @return list<array{name: string, value: string}> */
    private function parseHeaders(string $raw): array
    {
        // Handle 100-continue by using the last HTTP section
        $sections = preg_split('/\r?\n\r?\n/', trim($raw)) ?: [];
        $block = '';
        foreach (array_reverse($sections) as $section) {
            if (str_starts_with(ltrim($section), 'HTTP/')) {
                $block = $section;
                break;
            }
        }
        if ($block === '') {
            $block = end($sections) ?: '';
        }

        $headers = [];
        foreach (explode("\n", $block) as $line) {
            $line = rtrim($line, "\r");
            if (str_starts_with($line, 'HTTP/')) {
                continue;
            }
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[] = ['name' => trim($name), 'value' => trim($value)];
        }

        return $headers;
    }

    /** @param list<array{name: string, value: string}> $headers */
    private function findHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $h) {
            if (strtolower($h['name']) === $name) {
                return $h['value'];
            }
        }

        return null;
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (preg_match('#^https?://#i', $relative)) {
            return $relative;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $relative)) {
            return $relative;
        }

        $p = parse_url($base);
        $scheme = ($p['scheme'] ?? 'https');
        $host = ($p['host'] ?? '');
        $port = isset($p['port']) ? ':'.$p['port'] : '';

        if (str_starts_with($relative, '//')) {
            return $scheme.':'.$relative;
        }
        if (str_starts_with($relative, '/')) {
            return $scheme.'://'.$host.$port.$relative;
        }

        $dir = rtrim(dirname($p['path'] ?? '/'), '/');

        return $scheme.'://'.$host.$port.$dir.'/'.$relative;
    }

    private function statusText(int $code): string
    {
        return match ($code) {
            200 => 'OK', 201 => 'Created', 204 => 'No Content',
            301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
            307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 410 => 'Gone',
            500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
            default => '',
        };
    }
}

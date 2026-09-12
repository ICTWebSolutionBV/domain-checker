<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response headers.
 *
 * The app shipped without any of these: admin pages were framable, and the
 * default referrer policy leaked full URLs -- including a password-reset
 * token -- to every third-party host the page fetches from.
 *
 * No Content-Security-Policy yet on purpose: the theme bootstrap runs inline
 * to avoid a flash of the wrong theme, so a useful CSP needs a nonce pass
 * through the Blade shell first.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Has to happen before the view renders: the Blade shell reads this
        // nonce for the theme script and for @routes.
        Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        if ($this->isHtml($response)) {
            $headers['Content-Security-Policy'] = $this->contentSecurityPolicy();
        }

        foreach ($headers as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    /**
     * The policy is deliberately readable rather than clever.
     *
     * - script-src has no 'unsafe-inline' and no 'unsafe-eval': the Vue
     *   templates are compiled at build time, so nothing needs to evaluate
     *   strings at runtime.
     * - style-src and font-src name no third party: the webfont is served
     *   from our own build output, so nothing about rendering this page
     *   depends on a host we do not control.
     * - connect-src is 'self' because every check streams from our own SSE
     *   endpoints; the registry traffic happens server-side.
     */
    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();

        // The bundle is not necessarily served from the origin the visitor is
        // on: Vite emits absolute URLs built from ASSET_URL or APP_URL, so a
        // CDN -- or a proxied hostname in front of a dev server -- puts the
        // assets on a different origin than 'self'. Leaving that out renders a
        // blank page with every script and stylesheet refused.
        $assetOrigins = $this->assetOrigins();
        $script = trim("'self' 'nonce-{$nonce}' ".$assetOrigins);
        $style = trim("'self' 'nonce-{$nonce}' ".$assetOrigins);
        $font = trim("'self' ".$assetOrigins);
        $img = trim("'self' data: ".$assetOrigins);
        $connect = trim("'self' ".$assetOrigins);

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "font-src {$font}",
            "img-src {$img}",
            "connect-src {$connect}",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
        ]);
    }

    /**
     * Origins the asset bundle can legitimately come from, space separated.
     */
    private function assetOrigins(): string
    {
        $origins = [];

        foreach ([config('app.asset_url'), config('app.url')] as $url) {
            $parts = parse_url((string) $url);

            if (empty($parts['scheme']) || empty($parts['host'])) {
                continue;
            }

            $origin = $parts['scheme'].'://'.$parts['host'];

            if (! empty($parts['port'])) {
                $origin .= ':'.$parts['port'];
            }

            $origins[$origin] = true;
        }

        return implode(' ', array_keys($origins));
    }

    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');
    }
}

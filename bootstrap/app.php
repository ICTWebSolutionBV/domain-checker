<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoHtmlCache;
use App\Http\Middleware\SuperAdminMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust the proxy for scheme/port/client-IP, but NOT for the host: with
        // X-Forwarded-Host trusted, anyone could make the app generate links on
        // a host they control, and a password-reset mail would then carry a
        // valid token to the attacker's server.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        // Second lock on the same door: refuse requests whose Host header is not
        // this application. APP_URL's host (plus its subdomains) only.
        $middleware->trustHosts(at: fn () => array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
        ]));

        $middleware->encryptCookies(except: ['theme']);

        // Public read-only SSE endpoints — no CSRF needed
        $middleware->validateCsrfTokens(except: ['check', 'bulk-check', 'http3/check', 'ip/lookup']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            NoHtmlCache::class,
        ]);

        $middleware->alias([
            'admin'       => AdminMiddleware::class,
            'super_admin' => SuperAdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

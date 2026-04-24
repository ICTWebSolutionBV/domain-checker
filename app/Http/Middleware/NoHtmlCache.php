<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tell browsers (and any CDN in front of us) never to cache the Inertia HTML
 * shell or Inertia JSON partial responses.
 *
 * Hashed static assets under /build/* are served directly by the web server
 * and don't flow through this middleware, so they keep their aggressive
 * long-term caching behavior. Only the tiny HTML/JSON responses that
 * reference those hashes are marked no-cache, which means every deploy
 * reaches users on their very next request.
 */
class NoHtmlCache
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Don't override anything already explicitly set (SSE endpoints, etc.)
        if (! $response->headers->has('Cache-Control') || $response->headers->get('Cache-Control') === 'no-cache, private') {
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}

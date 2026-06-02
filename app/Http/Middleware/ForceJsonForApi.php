<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force the Accept header to application/json for /api/* requests so that
 * Laravel's auth / validation paths always return JSON, never a 302 redirect
 * to "/". Without this, Swagger UI's default Accept header (star-slash-star)
 * triggers the web-style redirect on validation failures, which surfaces as
 * a confusing HTML page in API consumers.
 */
class ForceJsonForApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            $request->headers->set('Accept', 'application/json');
        }
        return $next($request);
    }
}

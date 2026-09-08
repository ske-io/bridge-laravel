<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Refuses anything reaching the platform's own routes from outside.
 *
 * The adapter already answers 404 for these paths and strips the marking header
 * from inbound traffic, so this is the second of two locks rather than the only
 * one. It exists because the first one lives in a different process: if the
 * application is ever fronted by something else, this still holds.
 *
 * A missing or wrong header is answered as though the route did not exist,
 * which is what the adapter does too — telling a caller that these paths are
 * here and merely forbidden is more than they need to know.
 */
final class EnsureSkeInternal
{
    public const HEADER = 'X-Ske-Internal';

    public function handle(Request $request, Closure $next): Response
    {
        if (! hash_equals('true', (string) $request->header(self::HEADER, ''))) {
            // Thrown rather than abort()'d: the helper resolves the application
            // from the container, which would make this reachable only from a
            // booted app. The framework's handler renders this identically.
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}

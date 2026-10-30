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
 * The container's entry process already answers 404 for these paths and strips
 * the marking header from inbound traffic, so this is the second of two locks
 * rather than the only one. It exists because the first one lives in a
 * different process: if the application is ever fronted by something else,
 * this still holds.
 *
 * A missing or wrong header is answered as though the route did not exist,
 * which is what the entry process does too — telling a caller that these paths
 * are here and merely forbidden is more than they need to know.
 */
final class EnsureSkeInternal
{
    public const HEADER = 'X-Ske-Internal';

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header(self::HEADER, '');

        if (! is_string($header) || ! hash_equals('true', $header)) {
            // Thrown rather than abort()'d: the helper resolves the application
            // from the container, which would make this reachable only from a
            // booted app. The framework's handler renders this identically.
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}

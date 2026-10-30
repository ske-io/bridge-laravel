<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Symfony\Component\HttpFoundation\Response;

final class CorrelateRequests
{
    public const HEADER = 'X-Request-Id';

    public function __construct(
        private readonly LogManager $log,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header(self::HEADER);

        if (is_string($requestId) && $requestId !== '') {
            $this->log->withContext(['request_id' => $requestId]);
        }

        return $next($request);
    }
}

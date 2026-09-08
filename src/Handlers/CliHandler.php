<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Handlers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ske\Bridge\Laravel\Console\CommandRunner;

/**
 * The route behind `ske run`. Nothing but shape: the work is CommandRunner's.
 */
final readonly class CliHandler
{
    public function __construct(
        private CommandRunner $runner,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        $result = $this->runner->run(
            (string) ($payload['command'] ?? ''),
            is_array($payload['args'] ?? null) ? $payload['args'] : [],
        );

        return response()->json($result->toArray());
    }
}

<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Handlers;

use Illuminate\Http\JsonResponse;
use Ske\Bridge\Laravel\Console\CommandRunner;

/**
 * The route the platform's timer calls once a minute.
 */
final readonly class SchedulerHandler
{
    public function __construct(
        private CommandRunner $runner,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->runner->run('schedule:run')->toArray());
    }
}

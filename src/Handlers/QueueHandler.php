<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Handlers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ske\Bridge\Laravel\Queue\JobOutcome;
use Ske\Bridge\Laravel\Queue\QueueRunner;

/**
 * The route the platform calls with a batch of queue messages.
 *
 * Answers with one outcome per record so the adapter — which holds the receipt
 * handles — can settle each message on the broker.
 */
final readonly class QueueHandler
{
    public function __construct(
        private QueueRunner $runner,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        $outcomes = $this->runner->process(
            is_array($payload['records'] ?? null) ? $payload['records'] : [],
            is_array($payload['options'] ?? null) ? $payload['options'] : [],
        );

        return response()->json([
            'processed' => count($outcomes),
            'outcomes' => array_map(fn (JobOutcome $o): array => $o->toArray(), $outcomes),
        ]);
    }
}

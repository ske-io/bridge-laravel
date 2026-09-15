<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bus\PendingDispatch;
use Ske\Bridge\Laravel\Console\CommandRunner;
use Ske\Bridge\Laravel\Handlers\SchedulerHandler;

final class SchedulerKernel implements Kernel
{
    public ?string $lastCommand = null;

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $output = '',
    ) {}

    /** @param array<string, mixed> $parameters */
    public function call($command, array $parameters = [], $outputBuffer = null): int
    {
        $this->lastCommand = $command;

        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function bootstrap(): void {}

    public function handle($input, $output = null): int { return 0; }

    public function terminate($input, $status): void {}

    /** @param array<string, mixed> $parameters */
    public function queue($command, array $parameters = []): PendingDispatch
    {
        throw new RuntimeException('not implemented');
    }

    /** @return array<string, Command> */
    public function all(): array { return []; }
}

it('runs schedule:run', function () {
    $kernel = new SchedulerKernel(exitCode: 0, output: "No scheduled commands are ready to run.\n");
    $handler = new SchedulerHandler(new CommandRunner($kernel));

    $response = $handler();

    expect($kernel->lastCommand)->toBe('schedule:run')
        ->and($response->getData(true))->toBe([
            'exit_code' => 0,
            'output' => "No scheduled commands are ready to run.\n",
        ]);
});

it('reports a failed schedule run', function () {
    $kernel = new SchedulerKernel(exitCode: 1, output: 'scheduler error');
    $handler = new SchedulerHandler(new CommandRunner($kernel));

    $response = $handler();

    expect($response->getData(true)['exit_code'])->toBe(1)
        ->and($response->getData(true)['output'])->toBe('scheduler error');
});

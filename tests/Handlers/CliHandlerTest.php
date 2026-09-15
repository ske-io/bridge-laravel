<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ske\Bridge\Laravel\Console\CommandRunner;
use Ske\Bridge\Laravel\Handlers\CliHandler;

final class CliKernel implements Kernel
{
    public ?string $lastCommand = null;

    /** @var array<string, mixed> */
    public array $lastParameters = [];

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $output = '',
    ) {}

    /** @param array<string, mixed> $parameters */
    public function call($command, array $parameters = [], $outputBuffer = null): int
    {
        $this->lastCommand = $command;
        $this->lastParameters = $parameters;

        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function bootstrap(): void {}

    public function handle($input, $output = null): int
    {
        return 0;
    }

    public function terminate($input, $status): void {}

    /** @param array<string, mixed> $parameters */
    public function queue($command, array $parameters = []): PendingDispatch
    {
        throw new RuntimeException('not implemented');
    }

    /** @return array<string, Command> */
    public function all(): array
    {
        return [];
    }
}

/** @param array<string, mixed> $payload */
function cliRequest(array $payload): Request
{
    return Request::create('/__ske/cli', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], (string) json_encode($payload));
}

it('passes the command and arguments to the runner', function () {
    $kernel = new CliKernel(exitCode: 0, output: "done\n");
    $handler = new CliHandler(new CommandRunner($kernel));

    $response = $handler(cliRequest(['command' => 'migrate', 'args' => ['--force']]));

    expect($kernel->lastCommand)->toBe('migrate')
        ->and($kernel->lastParameters)->toBe(['--force'])
        ->and($response->getData(true))->toBe(['exit_code' => 0, 'output' => "done\n"]);
});

it('defaults to an empty command and args when missing', function () {
    $kernel = new CliKernel(exitCode: 1, output: 'No command given.');
    $handler = new CliHandler(new CommandRunner($kernel));

    $response = $handler(cliRequest([]));

    expect($response->getData(true)['exit_code'])->toBe(1)
        ->and($response->getData(true)['output'])->toBe('No command given.');
});

it('ignores non-array args', function () {
    $kernel = new CliKernel;
    $handler = new CliHandler(new CommandRunner($kernel));

    $handler(cliRequest(['command' => 'test', 'args' => 'not-an-array']));

    expect($kernel->lastParameters)->toBe([]);
});

it('returns a JSON response with the exit code', function () {
    $handler = new CliHandler(new CommandRunner(new CliKernel(exitCode: 1, output: 'error')));

    $response = $handler(cliRequest(['command' => 'fail']));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['exit_code'])->toBe(1);
});

<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Ske\Bridge\Laravel\Console\CommandResult;
use Ske\Bridge\Laravel\Console\CommandRunner;

/**
 * A stand-in console kernel that records what it was asked to run.
 *
 * The point of injecting the kernel is that this is possible at all: the
 * previous bridge reached for the Artisan facade, so none of this was reachable
 * without booting an application, and none of it was tested.
 */
final class RecordingKernel implements Kernel
{
    public ?string $lastCommand = null;

    /** @var array<mixed> */
    public array $lastParameters = [];

    public int $calls = 0;

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $output = '',
        private readonly ?Throwable $throws = null,
    ) {}

    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        $this->calls++;
        $this->lastCommand = $command;
        $this->lastParameters = $parameters;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->exitCode;
    }

    public function output()
    {
        return $this->output;
    }

    public function bootstrap() {}

    public function handle($input, $output = null) {}

    public function terminate($input, $status) {}

    public function queue($command, array $parameters = []) {}

    public function all()
    {
        return [];
    }
}

it('passes arguments as data rather than a command string', function () {
    // The previous bridge ran escapeshellarg() over each argument and glued
    // them onto the command. No shell is involved, so Artisan's parser took the
    // quotes literally and every option arrived as a positional argument.
    $kernel = new RecordingKernel;

    (new CommandRunner($kernel))->run('queue:work', ['--queue' => 'high', '--tries' => 3]);

    expect($kernel->lastCommand)->toBe('queue:work')
        ->and($kernel->lastParameters)->toBe(['--queue' => 'high', '--tries' => 3]);
});

it('reports the exit code and output', function () {
    $runner = new CommandRunner(new RecordingKernel(exitCode: 3, output: "migrated\n"));

    $result = $runner->run('migrate');

    expect($result)->toBeInstanceOf(CommandResult::class)
        ->and($result->exitCode)->toBe(3)
        ->and($result->output)->toBe("migrated\n")
        ->and($result->succeeded())->toBeFalse()
        ->and($result->toArray())->toBe(['exit_code' => 3, 'output' => "migrated\n"]);
});

it('accepts a command written the way a person types it', function (string $given) {
    $kernel = new RecordingKernel;

    (new CommandRunner($kernel))->run($given);

    expect($kernel->lastCommand)->toBe('migrate:fresh');
})->with([
    'bare' => 'migrate:fresh',
    'artisan prefix' => 'artisan migrate:fresh',
    'php artisan prefix' => 'php artisan migrate:fresh',
    'padded' => '  php   artisan   migrate:fresh  ',
    'capitalised binary' => 'PHP ARTISAN migrate:fresh',
]);

it('answers with a failure rather than throwing when the command blows up', function () {
    // The adapter is waiting on an HTTP response; an exception escaping here
    // would become a 500 with no exit code for the caller to act on.
    $runner = new CommandRunner(new RecordingKernel(throws: new RuntimeException('no such command')));

    $result = $runner->run('nope');

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toBe('no such command');
});

it('refuses an empty command without troubling the kernel', function (string $given) {
    $kernel = new RecordingKernel;

    $result = (new CommandRunner($kernel))->run($given);

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toBe('No command given.')
        ->and($kernel->calls)->toBe(0);
})->with(['', '   ', 'php artisan', 'artisan ']);

it('leaves a command that merely starts with those letters alone', function () {
    $kernel = new RecordingKernel;

    (new CommandRunner($kernel))->run('artisanal:report');

    expect($kernel->lastCommand)->toBe('artisanal:report');
});

<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Console;

use Illuminate\Contracts\Console\Kernel;
use Throwable;

/**
 * Runs one Artisan command and reports how it went.
 *
 * The CLI and scheduler routes are the same operation with a different
 * command, so they share this rather than each carrying their own copy of the
 * try/exit-code/output shape.
 *
 * The kernel is injected rather than reached for through the Artisan facade,
 * which is what lets this be tested without booting an application.
 */
final readonly class CommandRunner
{
    public function __construct(
        private Kernel $kernel,
    ) {}

    /**
     * @param  list<string>|array<string, mixed>  $arguments  passed as data, never
     *                                                        interpolated into the
     *                                                        command string
     */
    public function run(string $command, array $arguments = []): CommandResult
    {
        $command = self::withoutBinaryPrefix($command);

        if ($command === '') {
            return CommandResult::failed('No command given.');
        }

        try {
            $exitCode = $this->kernel->call($command, $arguments);

            return new CommandResult($exitCode, $this->kernel->output());
        } catch (Throwable $e) {
            return CommandResult::failed($e->getMessage());
        }
    }

    /**
     * Accepts what a person would type.
     *
     * `ske run "php artisan migrate"` is the natural thing to write, and the
     * platform passes the command through verbatim, so the prefix is stripped
     * here rather than being rejected as a bad command.
     */
    private static function withoutBinaryPrefix(string $command): string
    {
        return trim((string) preg_replace('/^\s*(php\s+)?artisan\b\s*/i', '', trim($command)));
    }
}

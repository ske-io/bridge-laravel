<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Console;

/**
 * How a console command went, in the shape the platform reports back.
 *
 * A command that could not be run at all is not an exception here: the
 * adapter is waiting on an HTTP response and needs an exit code either way,
 * so a failure to start is reported the same as a non-zero exit.
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}

    public static function failed(string $message): self
    {
        return new self(exitCode: 1, output: $message);
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * @return array{exit_code: int, output: string}
     */
    public function toArray(): array
    {
        return ['exit_code' => $this->exitCode, 'output' => $this->output];
    }
}

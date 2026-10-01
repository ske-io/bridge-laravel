<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Runtime;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use RuntimeException;

/**
 * Laravel's maintenance mode, driven by the platform. SKE puts an environment
 * into maintenance by redeploying it with MAINTENANCE_MODE=true, so every
 * instance sees the same state. Laravel's own PreventRequestsDuringMaintenance
 * then answers with the application's 503 page, and the scheduler skips tasks
 * not marked evenInMaintenanceMode(), exactly as after `artisan down`.
 *
 * `artisan down` and `artisan up` can't work here: each instance has its own
 * filesystem and is replaced on the next deploy.
 */
final class PlatformMaintenanceMode implements MaintenanceMode
{
    public function activate(array $payload): void
    {
        throw new RuntimeException('Turn on maintenance mode for this environment in the SKE dashboard instead.');
    }

    public function deactivate(): void
    {
        throw new RuntimeException('Turn off maintenance mode for this environment in the SKE dashboard instead.');
    }

    public function active(): bool
    {
        return Environment::get('MAINTENANCE_MODE') === 'true';
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return [];
    }
}

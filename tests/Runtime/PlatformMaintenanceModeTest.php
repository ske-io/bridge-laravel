<?php

declare(strict_types=1);

use Ske\Bridge\Laravel\Runtime\PlatformMaintenanceMode;

it('is active only while the platform sets MAINTENANCE_MODE=true', function (?string $value, bool $active) {
    withSkeEnvVariable('MAINTENANCE_MODE', function () use ($value, $active) {
        if ($value !== null) {
            $_SERVER['MAINTENANCE_MODE'] = $value;
        }

        expect((new PlatformMaintenanceMode)->active())->toBe($active);
    });
})->with([
    'unset' => [null, false],
    'true' => ['true', true],
    'false' => ['false', false],
]);

it('carries no maintenance payload', function () {
    expect((new PlatformMaintenanceMode)->data())->toBe([]);
});

it('refuses artisan down and up, which cannot reach other instances', function (string $method, array $arguments) {
    (new PlatformMaintenanceMode)->{$method}(...$arguments);
})->with([
    'down' => ['activate', [[]]],
    'up' => ['deactivate', []],
])->throws(RuntimeException::class, 'SKE dashboard');

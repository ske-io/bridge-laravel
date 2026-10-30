<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Ske\Bridge\Laravel\Runtime\PlatformMaintenanceMode;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sends a request through Laravel's own PreventRequestsDuringMaintenance with
 * the platform's maintenance mode switched on, restoring the container after.
 *
 * @param  array<string, string>  $cookies
 */
function duringMaintenance(?string $secret, string $path, array $cookies = []): Response
{
    $container = Container::getInstance();
    $response = null;

    withSkeEnvVariable('MAINTENANCE_MODE', function () use ($secret, $path, $cookies, &$response) {
        withSkeEnvVariable('MAINTENANCE_MODE_SECRET', function () use ($secret, $path, $cookies, &$response) {
            $_SERVER['MAINTENANCE_MODE'] = 'true';

            if ($secret !== null) {
                $_SERVER['MAINTENANCE_MODE_SECRET'] = $secret;
            }

            $request = Request::create($path, 'GET', cookies: $cookies);
            $app = new Application(sys_get_temp_dir());
            $app->instance('config', new Repository(['session' => ['path' => '/', 'domain' => null]]));
            $app->instance(MaintenanceMode::class, new PlatformMaintenanceMode);

            $redirector = new Redirector(new UrlGenerator(new RouteCollection, $request));
            $redirector->setSession(new Store('test', new ArraySessionHandler(1)));
            $app->instance('redirect', $redirector);

            try {
                $response = (new PreventRequestsDuringMaintenance($app))
                    ->handle($request, fn (): Response => new Response('open'));
            } catch (HttpException $e) {
                $response = new Response('closed', $e->getStatusCode());
            }
        });
    });

    Container::setInstance($container);

    return $response;
}

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

it('carries no maintenance payload without a bypass secret', function () {
    withSkeEnvVariable('MAINTENANCE_MODE_SECRET', function () {
        expect((new PlatformMaintenanceMode)->data())->toBe([]);
    });
});

it('carries MAINTENANCE_MODE_SECRET as the bypass secret', function () {
    withSkeEnvVariable('MAINTENANCE_MODE_SECRET', function () {
        $_SERVER['MAINTENANCE_MODE_SECRET'] = 'let-me-in';

        expect((new PlatformMaintenanceMode)->data())->toBe(['secret' => 'let-me-in']);
    });
});

it('closes the application to everyone without a bypass secret', function () {
    expect(duringMaintenance(null, '/let-me-in')->getStatusCode())->toBe(503);
});

it('lets a visitor of /{secret} through with a bypass cookie', function () {
    $response = duringMaintenance('let-me-in', '/let-me-in');
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'laravel_maintenance');

    expect($response->getStatusCode())->toBe(302)
        ->and($cookie)->not->toBeNull()
        ->and(MaintenanceModeBypassCookie::isValid((string) $cookie->getValue(), 'let-me-in'))->toBeTrue();
});

it('keeps letting through a visitor with a valid bypass cookie', function () {
    $cookie = (string) collect(duringMaintenance('let-me-in', '/let-me-in')->headers->getCookies())
        ->firstWhere(fn ($cookie) => $cookie->getName() === 'laravel_maintenance')
        ?->getValue();

    expect(duringMaintenance('let-me-in', '/dashboard', ['laravel_maintenance' => $cookie])->getContent())->toBe('open')
        ->and(duringMaintenance('let-me-in', '/dashboard')->getStatusCode())->toBe(503)
        ->and(duringMaintenance('let-me-in', '/wrong-secret')->getStatusCode())->toBe(503);
});

it('refuses artisan down and up, which cannot reach other instances', function (string $method, array $arguments) {
    (new PlatformMaintenanceMode)->{$method}(...$arguments);
})->with([
    'down' => ['activate', [[]]],
    'up' => ['deactivate', []],
])->throws(RuntimeException::class, 'SKE dashboard');

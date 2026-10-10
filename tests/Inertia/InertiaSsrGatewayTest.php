<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Inertia\Ssr\Gateway;
use Inertia\Ssr\HttpGateway;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Ske\Bridge\Laravel\Inertia\InertiaSsrGateway;
use Ske\Bridge\Laravel\SkeServiceProvider;

beforeEach()->skip(
    fn (): bool => ! class_exists(HttpGateway::class),
    'Install inertiajs/inertia-laravel to run the optional SSR integration tests.',
);

/** @param Closure(Application): void $callback */
function withSsrApplication(Closure $callback): void
{
    $server = $_SERVER;
    $env = $_ENV;
    $container = Container::getInstance();
    $variables = ['SKE_PROJECT_TYPE', 'SKE_ENVIRONMENT', 'SKE_SSR_ENABLED', 'SKE_SSR_TIMEOUT', 'INERTIA_SSR_URL', 'AWS_ACCESS_KEY_ID', 'AWS_SES_REGION'];
    $process = array_map(fn (string $key): string|false => getenv($key), $variables);

    foreach ($variables as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }

    try {
        $_SERVER['SKE_ENVIRONMENT'] = 'testing';
        $_SERVER['SKE_SSR_ENABLED'] = 'true';
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository(['inertia' => ['ssr' => [
            'enabled' => true,
            'url' => 'http://127.0.0.1:13714',
            'ensure_bundle_exists' => false,
        ]]]));
        $app->instance('files', new Filesystem);
        $app->instance('router', new Router(new Dispatcher($app), $app));
        $app->instance('request', Request::create('/'));
        $app->instance('events', new Dispatcher($app));
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        Http::preventStrayRequests();
        $callback($app);
    } finally {
        $_SERVER = $server;
        $_ENV = $env;

        foreach ($variables as $index => $key) {
            is_string($process[$index]) ? putenv("{$key}={$process[$index]}") : putenv($key);
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($container);
    }
}

function bootSsrBridge(Application $app): void
{
    $provider = new SkeServiceProvider($app);
    $provider->register();
    $provider->boot();
}

it('binds the platform gateway to both Inertia interfaces after provider registration', function () {
    withSsrApplication(function (Application $app): void {
        $app->bind(Gateway::class, HttpGateway::class);
        bootSsrBridge($app);

        expect($app->make(Gateway::class))->toBeInstanceOf(InertiaSsrGateway::class)
            ->and($app->make(Gateway::class))->toBe($app->make(HttpGateway::class));
    });
});

it('returns rendered HTML and bounds the SSR request timeout', function (?string $timeout, float $expected) {
    withSsrApplication(function (Application $app) use ($timeout, $expected): void {
        if ($timeout !== null) {
            $_SERVER['SKE_SSR_TIMEOUT'] = $timeout;
        }

        Http::fake(function (ClientRequest $request, array $options) use ($expected) {
            expect($request->url())->toBe('http://127.0.0.1:13714/render')
                ->and($request['component'])->toBe('Home')
                ->and($options['timeout'])->toBe($expected)
                ->and($options['connect_timeout'])->toBe($expected);

            return Http::response(['head' => ['<title>Home</title>'], 'body' => '<div id="app"><h1>Server rendered</h1></div>']);
        });

        $response = (new InertiaSsrGateway)->dispatch(['component' => 'Home', 'url' => '/', 'props' => []]);

        expect($response?->head)->toBe('<title>Home</title>')
            ->and($response?->body)->toContain('<h1>Server rendered</h1>');
    });
})->with([
    'default' => [null, 1.0],
    'configured' => ['0.25', 0.25],
    'invalid nonpositive value' => ['0', 1.0],
]);

it('logs a client-render fallback for renderer errors without leaking page props', function (string $failure) {
    withSsrApplication(function (Application $app) use ($failure): void {
        $handler = new TestHandler;
        $app->instance('log', new Logger('ssr', [$handler]));
        $app->make('config')->set('inertia.ssr.throw_on_error', true);

        Http::fake(function () use ($failure) {
            if ($failure === 'timeout') {
                throw new ConnectionException('SSR render timed out.');
            }

            return $failure === 'http'
                ? Http::response(['error' => 'window is not defined'], 500)
                : Http::response(['head' => [], 'body' => ['invalid']]);
        });

        expect((new InertiaSsrGateway)->dispatch(['component' => 'Home', 'url' => '/', 'props' => ['secret' => 'private']]))->toBeNull();

        $records = $handler->getRecords();
        expect($records)->toHaveCount(1)
            ->and($records[0]->message)->toBe('Inertia SSR failed; using client-side rendering.')
            ->and($records[0]->context)->toMatchArray(['component' => 'Home', 'url' => '/'])
            ->and($records[0]->context['reason'])->toBeString();
        expect(array_key_exists('props', $records[0]->context))->toBeFalse();
    });
})->with(['timeout', 'http', 'invalid response']);

it('keeps Inertia conditional disabling and excluded paths', function () {
    withSsrApplication(function (Application $app): void {
        $gateway = new InertiaSsrGateway;

        if (version_compare(InstalledVersions::getVersion('inertiajs/inertia-laravel') ?? '0', '3.0', '<')) {
            $app->make('config')->set('inertia.ssr.enabled', false);
        } else {
            $gateway->except('/');
        }

        expect($gateway->dispatch(['component' => 'Home']))->toBeNull();
        Http::assertNothingSent();

        if (version_compare(InstalledVersions::getVersion('inertiajs/inertia-laravel') ?? '0', '3.0', '>=')) {
            $gateway = new InertiaSsrGateway;
            $gateway->disable(fn (): bool => true);
            expect($gateway->dispatch(['component' => 'Home']))->toBeNull();
            Http::assertNothingSent();
        }
    });
});

it('keeps Inertia request configurators while enforcing the platform timeout', function () {
    withSsrApplication(function (Application $app): void {
        $gateway = new InertiaSsrGateway;

        if (version_compare(InstalledVersions::getVersion('inertiajs/inertia-laravel') ?? '0', '3.4.0', '<')) {
            expect($gateway)->toBeInstanceOf(HttpGateway::class);

            return;
        }

        $gateway->configureRequestUsing(fn ($request) => $request->withHeaders(['X-Renderer-Token' => 'configured'])->timeout(30));

        Http::fake(function (ClientRequest $request, array $options) {
            expect($request->hasHeader('X-Renderer-Token', 'configured'))->toBeTrue()
                ->and($options['timeout'])->toBe(1.0);

            return Http::response(['head' => [], 'body' => '<div id="app">SSR</div>']);
        });

        expect($gateway->dispatch(['component' => 'Home']))->not->toBeNull();
    });
});

it('rejects incompatible application SSR configuration', function (array $configuration) {
    withSsrApplication(function (Application $app) use ($configuration): void {
        $app->make('config')->set('inertia.ssr', $configuration);

        expect(fn () => bootSsrBridge($app))->toThrow(RuntimeException::class, 'must honor');
    });
})->with([
    'disabled' => [['enabled' => false, 'url' => 'http://127.0.0.1:13714']],
    'wrong URL' => [['enabled' => true, 'url' => 'http://remote-renderer:13714']],
]);

it('disables SSR in a worker role without starting or binding the renderer', function () {
    withSsrApplication(function (Application $app): void {
        $_SERVER['SKE_SSR_ENABLED'] = 'false';
        $app->bind(Gateway::class, HttpGateway::class);
        bootSsrBridge($app);

        expect($app->make('config')->get('inertia.ssr.enabled'))->toBeFalse()
            ->and($app->make(Gateway::class))->not->toBeInstanceOf(InertiaSsrGateway::class);
    });
});

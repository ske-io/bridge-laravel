<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('boots without Inertia unless SSR is explicitly enabled', function (?string $enabled, bool $shouldFail) {
    // A fresh process ensures none of the SSR suite's Inertia classes are loaded.
    // Remove its autoload mappings to model an application without the package.
    $script = <<<'PHP'
    $loader = require getcwd().'/vendor/autoload.php';
    $loader->unregister();
    $withoutInertia = new Composer\Autoload\ClassLoader;
    foreach ($loader->getPrefixesPsr4() as $prefix => $paths) {
        if (! str_starts_with($prefix, 'Inertia\\')) {
            $withoutInertia->setPsr4($prefix, $paths);
        }
    }
    $withoutInertia->addClassMap(array_filter(
        $loader->getClassMap(),
        fn (string $class): bool => ! str_starts_with($class, 'Inertia\\'),
        ARRAY_FILTER_USE_KEY,
    ));
    $withoutInertia->register();
    if (class_exists(Inertia\Ssr\HttpGateway::class) || interface_exists(Inertia\Ssr\Gateway::class)) {
        throw new RuntimeException('Inertia must not be available in this test.');
    }

    $_SERVER['SKE_ENVIRONMENT'] = 'testing';
    $app = new Illuminate\Foundation\Application(sys_get_temp_dir());
    $app->instance('config', new Illuminate\Config\Repository);
    $app->instance('files', new Illuminate\Filesystem\Filesystem);
    $app->instance('router', new Illuminate\Routing\Router(new Illuminate\Events\Dispatcher($app), $app));
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);

    $provider = new Ske\Bridge\Laravel\SkeServiceProvider($app);
    $provider->register();
    try {
        $provider->boot();
        echo 'booted';
    } catch (RuntimeException $exception) {
        echo $exception->getMessage();
        exit(1);
    }
    PHP;

    $process = new Process([PHP_BINARY], dirname(__DIR__, 2), [
        'SKE_ENVIRONMENT' => false,
        'SKE_PROJECT_TYPE' => false,
        'SKE_SSR_ENABLED' => $enabled ?? false,
        'AWS_ACCESS_KEY_ID' => false,
        'AWS_SES_REGION' => false,
    ], '<?php '.$script);
    $process->run();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe($shouldFail ? 1 : 0)
        ->and($process->getOutput())->toBe($shouldFail
            ? 'Inertia SSR is enabled, but inertiajs/inertia-laravel is not installed.'
            : 'booted');
})->with([
    'ordinary Laravel application' => [null, false],
    'SSR disabled' => ['false', false],
    'SSR explicitly enabled' => ['true', true],
]);

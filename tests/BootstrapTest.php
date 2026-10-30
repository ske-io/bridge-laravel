<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Runs bootstrap.php in a fresh process from a directory holding a .env, on
 * the platform, and reports whether the .env survived and $_ENV was filled.
 *
 * @return array{dotenv: bool, published: bool}
 */
function runBootstrap(bool $withLaravel): array
{
    $directory = sys_get_temp_dir().'/ske-bootstrap-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/.env', "APP_KEY=stale\n");

    $vendor = var_export(dirname(__DIR__).'/vendor', true);
    $bootstrap = var_export(dirname(__DIR__).'/bootstrap.php', true);

    // vendor/autoload.php runs bootstrap.php itself. Without Laravel, the
    // script builds the same autoloader minus the Illuminate namespaces and
    // runs bootstrap.php against it, modelling an application that is not
    // a Laravel application.
    $script = $withLaravel
        ? "require {$vendor}.'/autoload.php';"
        : <<<PHP
        require {$vendor}.'/composer/ClassLoader.php';
        require {$vendor}.'/composer/InstalledVersions.php';
        \$loader = new Composer\Autoload\ClassLoader({$vendor});
        foreach (require {$vendor}.'/composer/autoload_psr4.php' as \$prefix => \$paths) {
            if (! str_starts_with(\$prefix, 'Illuminate\\\\')) {
                \$loader->setPsr4(\$prefix, \$paths);
            }
        }
        \$loader->register();
        if (class_exists(Illuminate\Foundation\Application::class)) {
            throw new RuntimeException('Laravel must not be available in this test.');
        }
        require {$bootstrap};
        PHP;

    $process = new Process([PHP_BINARY, '-d', 'variables_order=GPCS'], $directory, [
        'SKE_ENVIRONMENT' => 'testing',
        'SKE_PROJECT_TYPE' => false,
        'SKE_TEST_BOOTSTRAP' => 'from-platform',
        'LAMBDA_TASK_ROOT' => false,
        'LARAVEL_ENV_ENCRYPTION_KEY' => false,
    ], '<?php '.$script.' echo json_encode(["published" => ($_ENV["SKE_TEST_BOOTSTRAP"] ?? null) === "from-platform"]);');
    $process->run();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);

    $dotenv = is_file($directory.'/.env');

    if ($dotenv) {
        unlink($directory.'/.env');
    }

    rmdir($directory);

    /** @var array{published: bool} $result */
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    return ['dotenv' => $dotenv, 'published' => $result['published']];
}

it('prepares the environment in a Laravel application on the platform', function () {
    expect(runBootstrap(withLaravel: true))->toBe(['dotenv' => false, 'published' => true]);
});

it('does nothing in an application that is not a Laravel application', function () {
    expect(runBootstrap(withLaravel: false))->toBe(['dotenv' => true, 'published' => false]);
});

<?php

declare(strict_types=1);

use Ske\Bridge\Laravel\Runtime\Environment;

/**
 * Runs a test with a clean slate for one variable in all three sources, and
 * restores whatever was there afterwards.
 */
function withSkeEnvVariable(string $key, Closure $callback): void
{
    $originalEnv = $_ENV;
    $originalServer = $_SERVER;
    $originalProcess = getenv($key);

    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);

    try {
        $callback();
    } finally {
        $_ENV = $originalEnv;
        $_SERVER = $originalServer;
        is_string($originalProcess) ? putenv("{$key}={$originalProcess}") : putenv($key);
    }
}

// --- get() ---------------------------------------------------------------

it('reads a variable from $_SERVER, where php-fpm puts the environment', function () {
    withSkeEnvVariable('SKE_TEST_VAR', function () {
        $_SERVER['SKE_TEST_VAR'] = 'from-server';

        expect(Environment::get('SKE_TEST_VAR'))->toBe('from-server');
    });
});

it('reads a variable from $_ENV', function () {
    withSkeEnvVariable('SKE_TEST_VAR', function () {
        $_ENV['SKE_TEST_VAR'] = 'from-env';

        expect(Environment::get('SKE_TEST_VAR'))->toBe('from-env');
    });
});

it('falls back to the process environment when neither superglobal carries it', function () {
    // The case that matters on a deployment: variables_order without "E"
    // leaves $_ENV empty, and a console or queue worker started by the adapter
    // has no request to fill $_SERVER from.
    withSkeEnvVariable('SKE_TEST_VAR', function () {
        putenv('SKE_TEST_VAR=from-process');

        expect(Environment::get('SKE_TEST_VAR'))->toBe('from-process');
    });
});

it('returns null for a variable that is not set anywhere', function () {
    withSkeEnvVariable('SKE_TEST_VAR', function () {
        expect(Environment::get('SKE_TEST_VAR'))->toBeNull();
    });
});

it('treats an empty value as unset', function () {
    withSkeEnvVariable('SKE_TEST_VAR', function () {
        $_SERVER['SKE_TEST_VAR'] = '';

        expect(Environment::get('SKE_TEST_VAR'))->toBeNull();
    });
});

it('prefers $_SERVER over $_ENV and the process environment', function () {
    withSkeEnvVariable('SKE_TEST_VAR', function () {
        putenv('SKE_TEST_VAR=from-process');
        $_ENV['SKE_TEST_VAR'] = 'from-env';
        $_SERVER['SKE_TEST_VAR'] = 'from-server';

        expect(Environment::get('SKE_TEST_VAR'))->toBe('from-server');
    });
});

// --- all() ---------------------------------------------------------------

it('merges all three sources with $_SERVER winning', function () {
    withSkeEnvVariable('SKE_TEST_VAR', function () {
        withSkeEnvVariable('SKE_TEST_OTHER', function () {
            putenv('SKE_TEST_VAR=from-process');
            putenv('SKE_TEST_OTHER=only-in-process');
            $_SERVER['SKE_TEST_VAR'] = 'from-server';

            $all = Environment::all();

            expect($all['SKE_TEST_VAR'])->toBe('from-server')
                ->and($all['SKE_TEST_OTHER'])->toBe('only-in-process');
        });
    });
});

it('skips the request data $_SERVER carries alongside the environment', function () {
    $original = $_SERVER;

    try {
        $_SERVER['SKE_TEST_ARRAY'] = ['not', 'an', 'environment', 'variable'];

        expect(Environment::all())->not->toHaveKey('SKE_TEST_ARRAY');
    } finally {
        $_SERVER = $original;
    }
});

// --- publish() --------------------------------------------------------------

it('copies process env vars into $_ENV', function () {
    withSkeEnvVariable('SKE_TEST_PUBLISH', function () {
        putenv('SKE_TEST_PUBLISH=platform-value');

        expect($_ENV)->not->toHaveKey('SKE_TEST_PUBLISH');

        Environment::publish();

        expect($_ENV['SKE_TEST_PUBLISH'])->toBe('platform-value');
    });
});

it('overwrites $_ENV with the process value as the authoritative source', function () {
    withSkeEnvVariable('SKE_TEST_PUBLISH', function () {
        $_ENV['SKE_TEST_PUBLISH'] = 'stale-dotenv-value';
        putenv('SKE_TEST_PUBLISH=from-process');

        Environment::publish();

        expect($_ENV['SKE_TEST_PUBLISH'])->toBe('from-process');
    });
});

// --- stripDotenv() ----------------------------------------------------------

it('deletes .env from the working directory', function () {
    $dir = sys_get_temp_dir().'/ske-test-'.uniqid();
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/.env', 'APP_KEY=stale');

    $original = (string) getcwd();
    chdir($dir);

    try {
        Environment::stripDotenv();
        expect(file_exists($dir.'/.env'))->toBeFalse();
    } finally {
        chdir($original);
        if (file_exists($dir.'/.env')) {
            unlink($dir.'/.env');
        }
        @rmdir($dir);
    }
});

it('is a no-op when no .env exists', function () {
    $dir = sys_get_temp_dir().'/ske-test-'.uniqid();
    @mkdir($dir, 0777, true);

    $original = (string) getcwd();
    chdir($dir);

    try {
        Environment::stripDotenv();
        expect(file_exists($dir.'/.env'))->toBeFalse();
    } finally {
        chdir($original);
        @rmdir($dir);
    }
});

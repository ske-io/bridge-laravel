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

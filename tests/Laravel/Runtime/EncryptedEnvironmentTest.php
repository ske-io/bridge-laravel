<?php

declare(strict_types=1);

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Env;
use Ske\Bridge\Laravel\Runtime\EncryptedEnvironment;
use Ske\Bridge\Runtime\Environment;

const ENCRYPTED_VARIABLES = ['LARAVEL_ENV_ENCRYPTION_KEY', 'SKE_TEST_ENC_MAIL', 'SKE_TEST_ENC_DB_HOST', 'SKE_TEST_ENC_QUOTED'];

/**
 * Runs a callback in a fresh directory, with the variables these tests touch
 * cleared from all three sources and Laravel's environment repository rebuilt,
 * then puts everything back.
 *
 * @param  Closure(string): void  $callback
 */
function withEncryptedEnvironment(Closure $callback): void
{
    $server = $_SERVER;
    $env = $_ENV;
    $process = array_map(fn (string $key): string|false => getenv($key), ENCRYPTED_VARIABLES);
    $directory = sys_get_temp_dir().'/ske-bridge-env-'.bin2hex(random_bytes(6));
    mkdir($directory);

    foreach (ENCRYPTED_VARIABLES as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }

    Env::enablePutenv();

    try {
        $callback($directory);
    } finally {
        $_SERVER = $server;
        $_ENV = $env;
        foreach (ENCRYPTED_VARIABLES as $i => $key) {
            is_string($process[$i]) ? putenv("{$key}={$process[$i]}") : putenv($key);
        }
        Env::enablePutenv();
        array_map('unlink', glob("{$directory}/.env*") ?: []);
        rmdir($directory);
    }
}

/** What `php artisan env:encrypt --env=production --key=...` writes. */
function encryptEnvironment(string $directory, string $contents, string $key): void
{
    file_put_contents(
        "{$directory}/.env.production.encrypted",
        (new Encrypter(base64_decode(substr($key, 7)), 'AES-256-CBC'))->encrypt($contents),
    );
}

function environmentKey(): string
{
    return 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));
}

it('names the file as env:encrypt --env does', function () {
    expect(EncryptedEnvironment::fileName('production'))->toBe('.env.production.encrypted');
});

it('loads the decrypted values for the environment', function () {
    withEncryptedEnvironment(function (string $directory) {
        $key = environmentKey();
        $_SERVER['LARAVEL_ENV_ENCRYPTION_KEY'] = $key;
        encryptEnvironment($directory, "SKE_TEST_ENC_MAIL=smtp\nSKE_TEST_ENC_QUOTED=\"two words\" # note\n", $key);

        EncryptedEnvironment::load($directory, 'production');

        expect(Environment::get('SKE_TEST_ENC_MAIL'))->toBe('smtp')
            ->and(Environment::get('SKE_TEST_ENC_QUOTED'))->toBe('two words')
            ->and($_ENV['SKE_TEST_ENC_MAIL'])->toBe('smtp')
            ->and(getenv('SKE_TEST_ENC_MAIL'))->toBe('smtp')
            ->and(env('SKE_TEST_ENC_MAIL'))->toBe('smtp');
    });
});

it('reads a file encrypted with --readable', function () {
    withEncryptedEnvironment(function (string $directory) {
        $key = environmentKey();
        $_SERVER['LARAVEL_ENV_ENCRYPTION_KEY'] = $key;
        $encrypter = new Encrypter(base64_decode(substr($key, 7)), 'AES-256-CBC');
        file_put_contents(
            "{$directory}/.env.production.encrypted",
            'SKE_TEST_ENC_MAIL='.$encrypter->encryptString('smtp')."\n"
            .'SKE_TEST_ENC_QUOTED='.$encrypter->encryptString('"two words"')."\n",
        );

        EncryptedEnvironment::load($directory, 'production');

        expect(Environment::get('SKE_TEST_ENC_MAIL'))->toBe('smtp')
            ->and(Environment::get('SKE_TEST_ENC_QUOTED'))->toBe('two words');
    });
});

it('never overrides a variable that is already set', function () {
    withEncryptedEnvironment(function (string $directory) {
        $key = environmentKey();
        $_SERVER['LARAVEL_ENV_ENCRYPTION_KEY'] = $key;
        putenv('SKE_TEST_ENC_DB_HOST=db.platform.internal');
        Environment::publish();
        encryptEnvironment($directory, "SKE_TEST_ENC_DB_HOST=127.0.0.1\nSKE_TEST_ENC_MAIL=smtp\n", $key);

        EncryptedEnvironment::load($directory, 'production');

        expect(Environment::get('SKE_TEST_ENC_DB_HOST'))->toBe('db.platform.internal')
            ->and(getenv('SKE_TEST_ENC_DB_HOST'))->toBe('db.platform.internal')
            ->and(env('SKE_TEST_ENC_DB_HOST'))->toBe('db.platform.internal')
            ->and(Environment::get('SKE_TEST_ENC_MAIL'))->toBe('smtp');
    });
});

it('loads only the file of the environment it is given', function () {
    withEncryptedEnvironment(function (string $directory) {
        $key = environmentKey();
        $_SERVER['LARAVEL_ENV_ENCRYPTION_KEY'] = $key;
        encryptEnvironment($directory, "SKE_TEST_ENC_MAIL=smtp\n", $key);

        EncryptedEnvironment::load($directory, 'staging');

        expect(Environment::get('SKE_TEST_ENC_MAIL'))->toBeNull();
    });
});

it('does nothing without a key', function () {
    withEncryptedEnvironment(function (string $directory) {
        encryptEnvironment($directory, "SKE_TEST_ENC_MAIL=smtp\n", environmentKey());

        EncryptedEnvironment::load($directory, 'production');

        expect(Environment::get('SKE_TEST_ENC_MAIL'))->toBeNull();
    });
});

it('does nothing without a file', function () {
    withEncryptedEnvironment(function (string $directory) {
        $_SERVER['LARAVEL_ENV_ENCRYPTION_KEY'] = environmentKey();

        EncryptedEnvironment::load($directory, 'production');

        expect(Environment::get('SKE_TEST_ENC_MAIL'))->toBeNull();
    });
});

it('fails loudly with a key that does not decrypt the file', function (string $key, string $reason) {
    withEncryptedEnvironment(function (string $directory) use ($key, $reason) {
        $_SERVER['LARAVEL_ENV_ENCRYPTION_KEY'] = $key;
        encryptEnvironment($directory, "SKE_TEST_ENC_MAIL=smtp\n", environmentKey());

        expect(fn () => EncryptedEnvironment::load($directory, 'production'))
            ->toThrow(RuntimeException::class, "Could not decrypt .env.production.encrypted with LARAVEL_ENV_ENCRYPTION_KEY: {$reason}");

        expect(Environment::get('SKE_TEST_ENC_MAIL'))->toBeNull();
    });
})->with([
    'another key' => [fn () => environmentKey(), 'The MAC is invalid.'],
    'not a key' => ['base64:c2hvcnQ=', 'Unsupported cipher or incorrect key length.'],
]);

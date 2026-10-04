<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Runtime;

use Dotenv\Dotenv;
use Dotenv\Loader\Loader;
use Dotenv\Parser\Lines;
use Dotenv\Parser\Parser;
use Dotenv\Store\StringStore;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Env;
use RuntimeException;

/**
 * Laravel's encrypted environment file, the one `php artisan env:encrypt
 * --env={environment}` writes, loaded on the platform.
 *
 * It holds the variables that don't fit the function's environment, so only
 * LARAVEL_ENV_ENCRYPTION_KEY has to be set there. The file is decrypted with
 * Laravel's own Encrypter and loaded through Laravel's own environment
 * repository, which never overrides a variable that is already set: the values
 * the platform sets and the environment's Secrets stay on top.
 *
 * It runs from bootstrap.php, before the framework reads its environment, so
 * config sees the values whether it is built at boot or cached by a process
 * that ran this first.
 */
final class EncryptedEnvironment
{
    public const KEY = 'LARAVEL_ENV_ENCRYPTION_KEY';

    /** The cipher `env:encrypt` uses unless told otherwise. */
    private const CIPHER = 'AES-256-CBC';

    /**
     * Loads .env.{environment}.encrypted from $directory. Nothing happens when
     * the key is not set or the environment has no such file.
     *
     * @throws RuntimeException when the key can't decrypt the file
     */
    public static function load(string $directory, string $environment): void
    {
        $key = Environment::get(self::KEY);
        $file = rtrim($directory, '/').'/'.self::fileName($environment);

        if ($key === null || ! is_file($file)) {
            return;
        }

        $values = self::decrypt((string) file_get_contents($file), $key, basename($file));

        (new Dotenv(new StringStore($values), new Parser, new Loader, Env::getRepository()))->load();
    }

    /** The name `env:encrypt --env={environment}` gives the file. */
    public static function fileName(string $environment): string
    {
        return ".env.{$environment}.encrypted";
    }

    private static function decrypt(string $contents, string $key, string $name): string
    {
        try {
            $encrypter = new Encrypter(
                str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key,
                self::CIPHER,
            );

            return self::isWholeFile($contents)
                ? (string) $encrypter->decrypt($contents)
                : self::decryptReadable($contents, $encrypter);
        } catch (DecryptException|RuntimeException $e) {
            throw new RuntimeException(
                "Could not decrypt {$name} with ".self::KEY.": {$e->getMessage()} "
                .'Set '.self::KEY.' to the key env:encrypt printed for this file.',
                previous: $e,
            );
        }
    }

    /**
     * A file encrypted whole is a single payload; one written with
     * `env:encrypt --readable` keeps each name in plain text instead.
     */
    private static function isWholeFile(string $contents): bool
    {
        $payload = json_decode((string) base64_decode(trim($contents), true), true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }

    /** Decrypts each NAME=value line of a file written with --readable. */
    private static function decryptReadable(string $contents, Encrypter $encrypter): string
    {
        $result = '';

        foreach (Lines::process(preg_split('/\r\n|\r|\n/', $contents) ?: []) as $entry) {
            $pos = strpos($entry, '=');

            if ($pos !== false) {
                $result .= substr($entry, 0, $pos).'='.$encrypter->decryptString(substr($entry, $pos + 1))."\n";
            }
        }

        return $result;
    }
}

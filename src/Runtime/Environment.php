<?php

declare(strict_types=1);

namespace Ske\Bridge\Runtime;

/**
 * Reads the process environment without the framework.
 *
 * The bridge's earliest code runs before the framework exists — before `env()`,
 * before Dotenv, before the container — so it has to read the environment
 * itself, and which of the three sources actually carries a variable depends
 * on the SAPI and on php.ini:
 *
 * - php-fpm imports the process environment into `$_SERVER` (the CGI SAPI
 *   "considers the environment part of the server variables"), but only when
 *   the pool keeps it — a pool with `clear_env = yes`, the php-fpm default,
 *   hands the worker nothing.
 * - `$_ENV` is populated only when `variables_order` contains "E", which the
 *   production php.ini does not.
 * - `getenv()` reads the real process environment, which is what a worker
 *   started by the container's entry process (or a console command)
 *   inherits.
 *
 * Reading all three is the only way to see a variable the platform set,
 * whichever entry point is running.
 */
final class Environment
{
    /**
     * Every variable visible to this process, later sources winning.
     *
     * `$_SERVER` comes last because under php-fpm it is the authoritative
     * per-request view: it carries both the imported process environment and
     * anything the web server passed for this request.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $env = [];

        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ($source as $key => $value) {
                // $_SERVER also carries request data ($_SERVER['argv'] among
                // it), so anything that is not a plain scalar is not an
                // environment variable and is skipped.
                if (is_string($key) && is_scalar($value)) {
                    $env[$key] = (string) $value;
                }
            }
        }

        return $env;
    }

    /**
     * Copies the process environment into $_ENV.
     *
     * PHP only populates $_ENV when variables_order contains "E", and the
     * serversideup/php base image ships GPCS (no E).  On a platform where env
     * vars carry database credentials, cache endpoints and every other secret,
     * an empty $_ENV has two consequences:
     *
     *  1. Laravel's env() — which reads $_ENV first — returns null for every
     *     platform-set variable.
     *  2. Dotenv's "immutable" loader sees no collision in $_ENV and writes
     *     every value from a stale .env, effectively overriding the platform.
     *
     * Copying the real process environment into $_ENV before the framework
     * boots fixes both: env() finds the platform value, and Dotenv skips any
     * key that is already present.
     */
    public static function publish(): void
    {
        /** @var array<string, string> $env */
        $env = getenv();

        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }
    }

    /**
     * Removes .env from the project root so Dotenv cannot load it.
     *
     * A Docker image built with `COPY . /var/task` may carry a .env that the
     * developer committed or that the .dockerignore did not exclude.  On the
     * platform every variable comes from the service's env-var configuration,
     * and a stale .env can only override them with wrong values.
     *
     * The image filesystem is ephemeral — nothing survives the container — so
     * deleting the file has no lasting effect.
     */
    public static function stripDotenv(): void
    {
        $candidates = array_filter([
            getcwd(),
            getenv('LAMBDA_TASK_ROOT') ?: null,
            '/var/task',
        ]);

        foreach ($candidates as $dir) {
            $path = $dir.'/.env';
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * One variable, or null when it is unset or empty.
     *
     * An empty value is treated as absent: an unset Lambda environment
     * variable and one set to "" mean the same thing to a deployment.
     */
    public static function get(string $key): ?string
    {
        foreach ([$_SERVER, $_ENV] as $source) {
            if (isset($source[$key]) && is_scalar($source[$key]) && (string) $source[$key] !== '') {
                return (string) $source[$key];
            }
        }

        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

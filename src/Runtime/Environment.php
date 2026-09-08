<?php

declare(strict_types=1);

namespace Ske\Bridge\Laravel\Runtime;

/**
 * Reads the process environment without the framework.
 *
 * The bridge's earliest code runs before Laravel exists — before `env()`,
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
 *   started by the adapter (or a console command) inherits.
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

<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;

/**
 * One variable, real server environment first. Under a web SAPI a real variable is visible only through getenv()
 * (PHP's default variables_order has no E, and $_SERVER does not carry it), so getenv() comes first; the values
 * phpdotenv loads from the files are in $_ENV / $_SERVER (and getenv, through the PutenvAdapter).
 */
function envValue(string $key): ?string
{
    foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
        if (is_string($value)) {
            return $value;
        }
    }

    return null;
}

/** A phpdotenv loader that can see real server variables (PutenvAdapter) and never overwrites them (immutable). */
function dotenv(string $root, string $file): Dotenv
{
    $repository = RepositoryBuilder::createWithDefaultAdapters()->addAdapter(PutenvAdapter::class)->immutable()->make();

    return Dotenv::create($repository, $root, $file);
}

/**
 * Two-pass env loading: `.env` gives APP_ENV, then `.env.{APP_ENV}` adds the rest.
 * The loader is immutable, so real server env vars always win.
 */
function loadEnv(string $root): void
{
    dotenv($root, '.env')->safeLoad();
    dotenv($root, '.env.' . (envValue('APP_ENV') ?? 'local'))->safeLoad();
    dotenv($root, '.env')->required([
        'APP_ENV', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS',
    ]);
}

/** The only reader of environment variables. Everything else gets typed values. */
function settings(string $root): array
{
    $e = static fn(string $k, string $default = ''): string => envValue($k) ?? $default;
    $bool = static fn(string $k, string $d = 'false'): bool => filter_var($e($k, $d), FILTER_VALIDATE_BOOL);
    $int = static fn(string $k, int $d): int => (int) $e($k, (string) $d);

    $env = $e('APP_ENV', 'local');

    return [
        'root' => $root,
        'env' => $env,
        'debug' => $bool('APP_DEBUG'),
        'url' => rtrim($e('APP_URL'), '/'),
        'db' => [
            'host' => $e('DB_HOST'),
            'port' => $int('DB_PORT', 3306),
            'name' => $e('DB_NAME'),
            'user' => $e('DB_USER'),
            'pass' => $e('DB_PASS'),
            'migrate_user' => $e('DB_MIGRATE_USER'),
            'migrate_pass' => $e('DB_MIGRATE_PASS'),
            'ssl_ca' => $e('DB_SSL_CA'),
        ],
        'session' => [
            'name' => $e('SESSION_NAME', 'inkwell_sid'),
            'secure' => $bool('SESSION_SECURE'),
            'lifetime' => $int('SESSION_LIFETIME', 7200),
        ],
        'password' => [
            'regex' => $e('PASSWORD_REGEX'),
            'hint' => $e('PASSWORD_HINT'),
        ],
        'login' => [
            'free_attempts' => $int('LOGIN_FREE_ATTEMPTS', 5),
            'ip_free_attempts' => $int('LOGIN_IP_FREE_ATTEMPTS', 20),
            'base_seconds' => $int('LOGIN_BACKOFF_BASE_SECONDS', 60),
            'max_seconds' => $int('LOGIN_BACKOFF_MAX_SECONDS', 7200),
        ],
        'upload' => [
            'max_bytes' => $int('UPLOAD_MAX_BYTES', 3145728),
            'mimes' => array_values(array_filter(array_map('trim', explode(',', $e('UPLOAD_ALLOWED_MIMES'))))),
            'dir' => $root . '/public/uploads',
            'prefix' => '/uploads/',
            'default_cover' => $e('DEFAULT_COVER', '/assets/default-cover.jpg'),
        ],
        'cache' => [
            'driver' => $e('CACHE_DRIVER', 'filesystem'),
            'ttl' => $int('CACHE_TTL', 86400),
        ],
        'twig_cache' => match (strtolower($e('TWIG_CACHE', 'false'))) {
            'false', '', '0' => false,
            'true', '1' => $root . '/var/cache/twig',
            default => $root . '/' . ltrim($e('TWIG_CACHE'), '/'),
        },
        'posts_per_page' => $int('POSTS_PER_PAGE', 10),
        'comment_max_length' => $int('COMMENT_MAX_LENGTH', 2000),
        'admin' => [
            'email' => $e('ADMIN_EMAIL'),
            'password' => $e('ADMIN_PASSWORD'),
        ],
    ];
}

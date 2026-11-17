<?php
/**
 * Utilidades para generar el archivo .env.
 *
 * Se usa .env.example como base (así se conservan todas las claves del
 * proyecto) y solo se reemplazan los valores indicados. La APP_KEY se
 * genera siempre con random_bytes(), sin depender de vendor/ ni artisan.
 */
declare(strict_types=1);

function deploy_env_template(): string
{
    return <<<'ENV'
APP_NAME=Laravel
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=

BROADCAST_DRIVER=log
CACHE_STORE=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

ENV;
}

/**
 * Formatea un valor para .env. Los valores simples van sin comillas; el
 * resto entre comillas para que espacios, # y : no rompan el parseo.
 */
function deploy_env_quote(string $value): string
{
    $value = str_replace(["\r", "\n", "\0"], '', $value);
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9_.\-\/:@,+=]+$/', $value)) {
        return $value;
    }

    if (!str_contains($value, "'")) {
        return "'" . $value . "'";
    }

    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

/**
 * Un valor es representable si no combina comilla simple y $ a la vez:
 * en ese caso phpdotenv interpolarla la variable dentro de las comillas.
 */
function deploy_env_representable(string $value): bool
{
    return !(str_contains($value, "'") && str_contains($value, '$'));
}

function deploy_env_set(string $text, string $key, string $value): string
{
    $line    = $key . '=' . deploy_env_quote($value);
    $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

    if (preg_match($pattern, $text)) {
        return (string) preg_replace_callback($pattern, static fn() => $line, $text, 1);
    }

    $text = rtrim($text, "\n");
    return ($text === '' ? '' : $text . "\n") . $line . "\n";
}

/**
 * Reemplaza los valores dados y genera una APP_KEY nueva.
 */
function deploy_env_generate(string $content, array $values): string
{
    foreach ($values as $key => $value) {
        if (!preg_match('/^[A-Z0-9_]+$/', (string) $key)) {
            continue;
        }
        $content = deploy_env_set($content, (string) $key, (string) $value);
    }

    return deploy_env_set($content, 'APP_KEY', 'base64:' . base64_encode(random_bytes(32)));
}

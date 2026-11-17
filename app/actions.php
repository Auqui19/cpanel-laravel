<?php
/**
 * Lista blanca de acciones. Nada fuera de esta tabla se puede ejecutar.
 *
 * `danger` pide confirmación en el navegador.
 * `shell`  indica si la acción necesita ejecutar un binario externo.
 *          Si el hosting bloquea exec/proc_open, solo se pueden usar las
 *          que tienen equivalente vía Artisan (ver deploy_action_artisan()).
 */
declare(strict_types=1);

function deploy_actions(): array
{
    return [
        'doctor' => [
            'label' => 'Doctor',
            'hint'  => 'PHP, composer, artisan, permisos, .env y APP_KEY',
            'group' => 'Diagnóstico',
            'danger' => false,
            'shell'  => true,
        ],
        'vendor' => [
            'label' => 'Instalar vendor',
            'hint'  => 'composer install --no-dev --optimize-autoloader',
            'group' => 'Dependencias',
            'danger' => false,
            'shell'  => true,
        ],
        'dump' => [
            'label' => 'Dump autoload',
            'hint'  => 'composer dump-autoload -o',
            'group' => 'Dependencias',
            'danger' => false,
            'shell'  => true,
        ],
        'key' => [
            'label' => 'Generar APP_KEY',
            'hint'  => 'Solo si falta en .env',
            'group' => 'Configuración',
            'danger' => false,
            'shell'  => false,
        ],
        'status' => [
            'label' => 'Estado migraciones',
            'hint'  => 'artisan migrate:status',
            'group' => 'Base de datos',
            'danger' => false,
            'shell'  => false,
        ],
        'migrate' => [
            'label' => 'Migrar BD',
            'hint'  => 'artisan migrate --force',
            'group' => 'Base de datos',
            'danger' => true,
            'shell'  => false,
        ],
        'seed' => [
            'label' => 'Ejecutar seeders',
            'hint'  => 'artisan db:seed --force',
            'group' => 'Base de datos',
            'danger' => true,
            'shell'  => false,
        ],
        'storage' => [
            'label' => 'storage:link',
            'hint'  => 'Enlaza public/storage con storage/app/public',
            'group' => 'Archivos y caché',
            'danger' => false,
            'shell'  => false,
        ],
        'cache' => [
            'label' => 'Cachear todo',
            'hint'  => 'config + route + view',
            'group' => 'Archivos y caché',
            'danger' => false,
            'shell'  => false,
        ],
        'clear' => [
            'label' => 'Limpiar cachés',
            'hint'  => 'artisan optimize:clear',
            'group' => 'Archivos y caché',
            'danger' => false,
            'shell'  => false,
        ],
        'view' => [
            'label' => 'Limpiar vistas',
            'hint'  => 'artisan view:clear',
            'group' => 'Archivos y caché',
            'danger' => false,
            'shell'  => false,
        ],
        'down' => [
            'label' => 'Modo mantenimiento',
            'hint'  => 'artisan down',
            'group' => 'Mantenimiento',
            'danger' => true,
            'shell'  => false,
        ],
        'up' => [
            'label' => 'Salir de mantenimiento',
            'hint'  => 'artisan up',
            'group' => 'Mantenimiento',
            'danger' => false,
            'shell'  => false,
        ],
        'update' => [
            'label' => 'Actualizar todo',
            'hint'  => 'vendor + migrate + limpiar',
            'group' => 'Mantenimiento',
            'danger' => true,
            'shell'  => true,
        ],
    ];
}

/**
 * Equivalente por Artisan para cuando no hay shell disponible.
 * Devuelve [comando, parámetros] o null si la acción necesita composer.
 */
function deploy_action_artisan(string $action): ?array
{
    return match ($action) {
        'key'     => ['key:generate', ['--force' => true]],
        'status'  => ['migrate:status', []],
        'migrate' => ['migrate', ['--force' => true]],
        'seed'    => ['db:seed', ['--force' => true]],
        'storage' => ['storage:link', []],
        'cache'   => ['optimize', []],
        'clear'   => ['optimize:clear', []],
        'view'    => ['view:clear', []],
        'down'    => ['down', []],
        'up'      => ['up', []],
        default   => null,
    };
}

function deploy_action_instruction(string $action, array $settings): string
{
    $php = $settings['php_bin'] ?: 'php';
    $app = $settings['app_path'] ?: '/ruta/al/proyecto';
    $composer = $settings['composer'] ?: 'composer.phar';

    return match ($action) {
        'vendor' => "cd $app && $php -d memory_limit=-1 $composer install --no-dev --optimize-autoloader --no-interaction",
        'dump'   => "cd $app && $php -d memory_limit=-1 $composer dump-autoload -o",
        'doctor' => "cd $app && $php -v && $php $composer --version && $php artisan --version",
        default  => "cd $app && $php artisan " . $action,
    };
}

<?php
/**
 * Configuración del despliegue: detección automática, validación estricta
 * y generación de data/settings.env para que lo lea run.sh.
 *
 * settings.env es la única frontera entre PHP y bash. Se escribe con
 * escapeshellarg() y solo después de validar cada valor, así que no puede
 * inyectarse nada desde la web.
 */
declare(strict_types=1);

function deploy_settings_file(): string
{
    return DEPLOY_DATA . '/settings.php';
}

function deploy_settings(): array
{
    $saved = read_php(deploy_settings_file(), []);
    return array_merge([
        'cpanel_user' => '',
        'app_path'    => '',
        'php_bin'     => '',
        'composer'    => '',
        'updated_at'  => '',
    ], is_array($saved) ? $saved : []);
}

function deploy_detect_user(): string
{
    if (deploy_fn_enabled('posix_geteuid') && deploy_fn_enabled('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (!empty($pw['name'])) {
            return (string) $pw['name'];
        }
    }

    $owner = @get_current_user();
    if (is_string($owner) && $owner !== '' && $owner !== 'root') {
        return $owner;
    }

    $home = (string) getenv('HOME');
    return $home !== '' ? basename($home) : '';
}

function deploy_detect_php(): array
{
    $found = [];

    $patterns = [
        '/opt/cpanel/ea-php*/root/usr/bin/php',
        '/opt/alt/php*/usr/bin/php',
        '/usr/local/bin/ea-php*',
        '/usr/local/bin/php',
        '/usr/bin/php',
    ];

    foreach ($patterns as $pattern) {
        foreach ((array) @glob($pattern, GLOB_NOSORT) as $path) {
            if (is_file($path) && is_executable($path)) {
                $found[$path] = true;
            }
        }
    }

    if (defined('PHP_BINDIR')) {
        $candidate = PHP_BINDIR . '/php';
        if (is_file($candidate) && is_executable($candidate)) {
            $found[$candidate] = true;
        }
    }

    $list = array_keys($found);
    sort($list, SORT_NATURAL);
    return $list;
}

function deploy_detect_composer(): string
{
    $candidates = [DEPLOY_ROOT . '/composer.phar'];

    $home = (string) getenv('HOME');
    if ($home !== '') {
        $candidates[] = $home . '/composer.phar';
        $candidates[] = $home . '/composer';
    }

    $candidates[] = '/usr/local/bin/composer';
    $candidates[] = '/usr/bin/composer';

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return '';
}

function deploy_detect_apps(string $user): array
{
    $home = $user !== '' ? '/home/' . $user : (string) getenv('HOME');
    if ($home === '') {
        return [];
    }

    $found = [];
    foreach ([$home . '/*/artisan', $home . '/*/*/artisan', $home . '/*/*/*/artisan'] as $pattern) {
        foreach ((array) @glob($pattern, GLOB_NOSORT) as $artisan) {
            $dir = dirname($artisan);
            if (is_file($dir . '/artisan')) {
                $found[$dir] = (int) @filemtime($dir . '/artisan');
            }
        }
    }

    arsort($found);
    return array_keys($found);
}

function deploy_detect(): array
{
    $user = deploy_detect_user();

    return [
        'cpanel_user' => $user,
        'app_path'    => '',
        'php_bin'     => deploy_detect_php(),
        'composer'    => deploy_detect_composer(),
        'apps'        => deploy_detect_apps($user),
    ];
}

/**
 * Elige el binario PHP por defecto: el mismo que usa el subdominio
 * (PHP_BINDIR) y, si no está, el primer ea-php disponible.
 */
function deploy_pick_php(array $bins): string
{
    if (!$bins) {
        return '';
    }

    $own = PHP_BINDIR . '/php';
    if (in_array($own, $bins, true)) {
        return $own;
    }

    $tag = 'php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
    foreach ($bins as $bin) {
        if (str_contains(basename($bin), $tag)) {
            return $bin;
        }
    }

    foreach ($bins as $bin) {
        if (str_contains($bin, 'ea-php')) {
            return $bin;
        }
    }

    return $bins[0];
}

/**
 * Deja la configuración lista sin intervención: detecta la cuenta, el primer
 * proyecto Laravel, el PHP del subdominio y composer. Solo guarda si el
 * resultado pasa la validación.
 */
function deploy_settings_autoresolve(): array
{
    static $resolved = false;

    $settings = deploy_settings();
    if ($resolved) {
        return $settings;
    }
    $resolved = true;

    $detect = deploy_detect();
    $user   = $detect['cpanel_user'];

    // Sin un usuario de cPanel válido no se puede inferir nada seguro.
    if ($user === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $user)) {
        return $settings;
    }

    $changed = false;

    if ($settings['cpanel_user'] !== $user) {
        $settings['cpanel_user'] = $user;
        $changed = true;
    }

    $keepPath = $settings['app_path'] !== ''
        && str_starts_with($settings['app_path'], '/home/' . $user . '/')
        && is_file($settings['app_path'] . '/artisan');

    if (!$keepPath) {
        $pick = $detect['apps'][0] ?? '';
        if ($pick !== $settings['app_path']) {
            $settings['app_path'] = $pick;
            $changed = true;
        }
    }

    if ($settings['php_bin'] === '' || !in_array($settings['php_bin'], $detect['php_bin'], true)) {
        $pick = deploy_pick_php($detect['php_bin']);
        if ($pick !== '' && $pick !== $settings['php_bin']) {
            $settings['php_bin'] = $pick;
            $changed = true;
        }
    }

    if ($settings['composer'] === '' || !is_file($settings['composer'])) {
        if ($detect['composer'] !== '') {
            $settings['composer'] = $detect['composer'];
            $changed = true;
        }
    }

    if ($changed && deploy_settings_valid($settings) === []) {
        deploy_settings_save($settings);
    }

    return deploy_settings();
}

function deploy_settings_valid(array $s): array
{
    $errors = [];

    $user     = (string) ($s['cpanel_user'] ?? '');
    $app      = rtrim((string) ($s['app_path'] ?? ''), '/');
    $php      = (string) ($s['php_bin'] ?? '');
    $composer = (string) ($s['composer'] ?? '');

    if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $user)) {
        $errors[] = 'Usuario de cPanel inválido (solo minúsculas, números y guion bajo).';
    }

    if ($app === '') {
        $errors[] = 'Falta la ruta del proyecto Laravel.';
    } elseif ($user !== '' && !str_starts_with($app, '/home/' . $user . '/')) {
        $errors[] = 'La ruta debe empezar por /home/' . $user . '/';
    } elseif (str_contains($app, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $app)) {
        $errors[] = 'La ruta contiene caracteres no permitidos.';
    } elseif (!is_dir($app)) {
        $errors[] = 'La ruta no existe: ' . $app;
    } elseif (!is_file($app . '/artisan')) {
        $errors[] = 'No se encontró el archivo artisan en ' . $app;
    }

    $phpOk = in_array($php, deploy_detect_php(), true)
        || (is_file($php) && is_executable($php) && preg_match('#/(php|ea-php[0-9]+)$#', $php));

    if (!$phpOk) {
        $errors[] = 'Elegí un binario PHP válido (ej. /opt/cpanel/ea-php82/root/usr/bin/php).';
    }

    if ($composer === '' || !is_file($composer)) {
        $errors[] = 'No se encontró composer.phar.';
    } elseif (!preg_match('#(^|/)composer(\.phar)?$#', $composer)) {
        $errors[] = 'El archivo indicado no parece ser composer.';
    }

    return $errors;
}

/**
 * Guarda settings.php y settings.env. Devuelve la lista de errores.
 */
function deploy_settings_save(array $s): array
{
    $clean = [
        'cpanel_user' => is_string($s['cpanel_user'] ?? null) ? trim($s['cpanel_user']) : '',
        'app_path'    => is_string($s['app_path'] ?? null) ? rtrim(trim($s['app_path']), '/') : '',
        'php_bin'     => is_string($s['php_bin'] ?? null) ? trim($s['php_bin']) : '',
        'composer'    => is_string($s['composer'] ?? null) ? trim($s['composer']) : '',
    ];

    // El usuario no se puede elegir: siempre es el dueño de esta cuenta.
    // Solo se fuerza si el detectado tiene forma de usuario de cPanel.
    $detectedUser = deploy_detect_user();
    if ($detectedUser !== '' && preg_match('/^[a-z][a-z0-9_]{0,31}$/', $detectedUser)) {
        $clean['cpanel_user'] = $detectedUser;
    }

    $errors = deploy_settings_valid($clean);
    if ($errors) {
        return $errors;
    }

    $clean['updated_at'] = date('c');

    if (!write_php(deploy_settings_file(), $clean)) {
        return ['No se pudo escribir data/settings.php (permisos).'];
    }

    $env = 'CP_USER=' . escapeshellarg($clean['cpanel_user']) . "\n"
        . 'APP=' . escapeshellarg($clean['app_path']) . "\n"
        . 'PHP=' . escapeshellarg($clean['php_bin']) . "\n"
        . 'COMPOSER=' . escapeshellarg($clean['composer']) . "\n"
        . 'APP_UPDATED=' . escapeshellarg($clean['updated_at']) . "\n";

    $envFile = DEPLOY_DATA . '/settings.env';
    if (@file_put_contents($envFile, $env, LOCK_EX) === false) {
        return ['No se pudo escribir data/settings.env (permisos).'];
    }

    @chmod($envFile, 0600);
    return [];
}

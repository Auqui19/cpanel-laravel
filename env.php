<?php
/**
 * Genera el archivo .env del proyecto Laravel.
 *
 * Nunca sobrescribe: si .env ya existe, no hace nada.
 * Base: .env.example del proyecto (o una plantilla mínima si no existe).
 * APP_KEY: se genera siempre.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

function env_back(string $message, bool $ok = false): void
{
    if ($ok) {
        $_SESSION['flash'] = $message;
    } else {
        $_SESSION['flash_error'] = $message;
    }

    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/env.php'))), '/');
    header('Location: ' . ($dir === '' ? '/' : $dir . '/'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

if (auth_user() === null) {
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/env.php'))), '/');
    header('Location: ' . ($dir === '' ? '/' : $dir . '/'));
    exit;
}

if (!csrf_ok($_POST['csrf'] ?? null)) {
    env_back('Token de seguridad inválido. Recargá la página.');
}

$settings = deploy_settings_autoresolve();
$settingsErrors = deploy_settings_valid($settings);

if ($settingsErrors) {
    env_back('Configuración incompleta: ' . implode(' ', $settingsErrors));
}

$app         = $settings['app_path'];
$envFile     = $app . '/.env';
$exampleFile = $app . '/.env.example';

if (is_file($envFile)) {
    env_back('Ya existe .env: el panel no lo sobrescribe. Borralo a mano si querés regenerarlo.');
}

$base = is_file($exampleFile) ? (string) @file_get_contents($exampleFile) : '';
if (trim($base) === '') {
    $base = deploy_env_template();
}
if (!str_ends_with($base, "\n")) {
    $base .= "\n";
}

$mode   = post_str('mode', 'custom');
$values = [];

if ($mode === 'custom') {
    $env = post_str('app_env', 'production');
    $url = trim(post_str('app_url'));
    $port = trim(post_str('db_port'));

    $values = [
        'APP_ENV'    => in_array($env, ['production', 'local'], true) ? $env : 'production',
        'APP_DEBUG'  => !empty($_POST['app_debug']) ? 'true' : 'false',
        'APP_URL'    => preg_match('#^https?://[A-Za-z0-9._:\-]+/?$#', $url) ? $url : '',
        'DB_HOST'    => trim(post_str('db_host')),
        'DB_PORT'    => ctype_digit($port) && (int) $port > 0 && (int) $port < 65536 ? $port : '',
        'DB_DATABASE' => trim(post_str('db_database')),
        'DB_USERNAME' => trim(post_str('db_username')),
        'DB_PASSWORD' => post_str('db_password'),
    ];

    // Los campos vacíos conservan lo que ya trae .env.example.
    $values = array_filter($values, static fn($v) => $v !== '');

    foreach ($values as $key => $value) {
        if (!deploy_env_representable((string) $value)) {
            env_back('El valor de ' . $key . ' combina comilla simple y «$», no se puede escribir con seguridad. '
                . 'Dejalo vacío y editá .env a mano.');
        }
    }
}

$content = deploy_env_generate($base, $values);

if (@file_put_contents($envFile, $content, LOCK_EX) === false) {
    env_back('No se pudo escribir ' . $envFile . ' (permisos de la carpeta del proyecto).');
}
@chmod($envFile, 0600);

env_back('Se creó .env con APP_KEY incluida. Siguiente paso: «Migrar BD».', true);

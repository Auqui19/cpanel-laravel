<?php
/**
 * Arranque común del panel.
 *
 * El document root del subdominio es la raíz de esta carpeta:
 *   /home/USUARIO/public_html/deploy.dominio.com/
 *
 * Por eso los archivos privados viven en app/ y data/ (bloqueados con
 * .htaccess) en lugar de estar fuera del docroot. Los datos sensibles se
 * guardan como .php que devuelven un array: si el .htaccess fallara y el
 * servidor los ejecutara, no imprimen nada.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('Este panel requiere PHP 8.0 o superior. Version actual: ' . PHP_VERSION);
}

define('DEPLOY_APP', __DIR__);
define('DEPLOY_ROOT', dirname(__DIR__));
define('DEPLOY_DATA', DEPLOY_ROOT . '/data');
define('DEPLOY_JOBS', DEPLOY_DATA . '/jobs');
define('DEPLOY_LOGS', DEPLOY_DATA . '/logs');

foreach ([DEPLOY_DATA, DEPLOY_JOBS, DEPLOY_LOGS] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
}

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store, max-age=0');
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
}

function deploy_cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = DEPLOY_APP . '/config.php';
        $loaded = is_file($file) ? require $file : [];
        $cfg = (is_array($loaded) ? $loaded : []) + [
            'name' => 'Panel de despliegue Laravel',
        ];
    }
    return $cfg;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Lee un campo POST garantizando string (evita warnings con arrays). */
function post_str(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? null;
    return is_string($value) ? $value : $default;
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function read_php(string $file, $default = null)
{
    if (!is_file($file)) {
        return $default;
    }
    $data = require $file;
    return is_array($data) ? $data : $default;
}

function write_php(string $file, array $data): bool
{
    $tmp  = $file . '.tmp';
    $code = "<?php\n// Archivo generado por el panel. No editar a mano.\nreturn "
        . var_export($data, true) . ";\n";

    if (@file_put_contents($tmp, $code, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function deploy_fn_enabled(string $fn): bool
{
    static $disabled = null;
    if ($disabled === null) {
        $list = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $disabled = array_filter(array_map('strtolower', $list));
    }
    return function_exists($fn) && !in_array(strtolower($fn), $disabled, true);
}

require_once DEPLOY_APP . '/auth.php';
require_once DEPLOY_APP . '/settings.php';
require_once DEPLOY_APP . '/envfile.php';
require_once DEPLOY_APP . '/actions.php';
require_once DEPLOY_APP . '/exec.php';

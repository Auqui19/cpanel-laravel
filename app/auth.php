<?php
/**
 * Autenticación del panel.
 *
 * Credenciales en app/config.php:
 *
 *   'users' => [
 *       'admin' => 'mi-clave',
 *       // o un hash de PHP: password_hash('mi-clave', PASSWORD_DEFAULT)
 *       'otro'  => '$2y$10$...',
 *   ],
 *
 * Si el valor empieza con $2y$, $2a$, $2b$ o $argon2 se verifica con
 * password_verify(); si no, se compara como texto plano.
 */
declare(strict_types=1);

function auth_users(): array
{
    $cfg   = deploy_cfg();
    $users = $cfg['users'] ?? [];

    if (!is_array($users) || $users === []) {
        if (!empty($cfg['user']) && isset($cfg['pass'])) {
            $users = [(string) $cfg['user'] => (string) $cfg['pass']];
        }
    }

    $clean = [];
    foreach ($users as $user => $secret) {
        if (is_string($user) && $user !== '' && is_string($secret) && $secret !== '') {
            $clean[$user] = $secret;
        }
    }

    return $clean;
}

function auth_configured(): bool
{
    foreach (auth_users() as $user => $secret) {
        if ($user !== 'CAMBIA_ESTO' && $secret !== 'CAMBIA_ESTO') {
            return true;
        }
    }
    return false;
}

function auth_user(): ?string
{
    if (empty($_SESSION['user'])) {
        return null;
    }

    $last = (int) ($_SESSION['last'] ?? 0);

    // Sesión inactiva por más de 12 horas.
    if ($last > 0 && $last < time() - 43200) {
        auth_logout();
        return null;
    }

    $_SESSION['last'] = time();
    return (string) $_SESSION['user'];
}

function auth_check(string $user, string $password): bool
{
    $users = auth_users();

    if (!isset($users[$user])) {
        // Comparación inútil para no revelar por tiempo si el usuario existe.
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        return false;
    }

    $stored = $users[$user];

    if (preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $stored)) {
        return password_verify($password, $stored);
    }

    return hash_equals($stored, $password);
}

function auth_attempt(string $user, string $password): string
{
    if (!auth_configured()) {
        return 'Todavía no configuraste el usuario y la contraseña en app/config.php.';
    }

    if (auth_blocked()) {
        return 'Demasiados intentos fallidos. Esperá 10 minutos.';
    }

    if ($user === '' || $password === '') {
        return 'Completá usuario y contraseña.';
    }

    if (!auth_check($user, $password)) {
        usleep(600000);
        auth_fail();
        return 'Usuario o contraseña incorrectos.';
    }

    auth_login($user);
    return '';
}

function auth_login(string $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['last'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    auth_clear_fails();
}

function auth_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

// ---------------------------------------------------------------- intentos

function auth_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function auth_fails_file(): string
{
    return DEPLOY_DATA . '/fails.php';
}

function auth_fails(): array
{
    $fails = read_php(auth_fails_file(), []);
    if (!is_array($fails)) {
        return [];
    }

    $now = time();
    foreach ($fails as $ip => $entry) {
        if (!is_array($entry) || (int) ($entry['t'] ?? 0) < $now - 600) {
            unset($fails[$ip]);
        }
    }

    return $fails;
}

function auth_blocked(): bool
{
    $fails = auth_fails();
    return (int) ($fails[auth_ip()]['n'] ?? 0) >= 5;
}

function auth_fail(): void
{
    $fails = auth_fails();
    $ip    = auth_ip();

    $fails[$ip] = [
        'n' => (int) ($fails[$ip]['n'] ?? 0) + 1,
        't' => time(),
    ];

    write_php(auth_fails_file(), $fails);
}

function auth_clear_fails(): void
{
    @unlink(auth_fails_file());
}

// ------------------------------------------------------------------- CSRF

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_ok($token): bool
{
    return is_string($token) && $token !== '' && hash_equals(csrf(), $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

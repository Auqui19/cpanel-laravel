<?php
/**
 * Lanza una acción. Responde en milisegundos: el trabajo sigue en segundo
 * plano y el navegador hace polling a ajax.php.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

if (auth_user() === null) {
    json_out(['ok' => false, 'error' => 'Sesión expirada. Volvé a entrar.'], 401);
}

if (!csrf_ok($_POST['csrf'] ?? null)) {
    json_out(['ok' => false, 'error' => 'Token de seguridad inválido. Recargá la página.'], 419);
}

$action  = post_str('action');
$actions = deploy_actions();

if (!isset($actions[$action])) {
    json_out(['ok' => false, 'error' => 'Acción no permitida.'], 400);
}

$settings = deploy_settings_autoresolve();
$errors   = deploy_settings_valid($settings);
if ($errors) {
    json_out(['ok' => false, 'error' => 'Configuración incompleta: ' . implode(' ', $errors)], 409);
}

$running = deploy_running();
if ($running) {
    json_out([
        'ok'    => false,
        'error' => 'Ya hay una tarea en curso (' . $running['action'] . '). Esperá a que termine.',
        'job'   => $running['id'] ?? null,
    ], 409);
}

$id = bin2hex(random_bytes(6));

deploy_job_write($id, [
    'id'      => $id,
    'action'  => $action,
    'status'  => 'running',
    'exit'    => 0,
    'ms'      => 0,
    'pid'     => 0,
    'started' => time(),
    'ended'   => 0,
]);

$mode = deploy_exec_mode();

if ($mode !== 'none' && deploy_spawn($id, $action)) {
    json_out(['ok' => true, 'id' => $id, 'mode' => 'async']);
}

// Sin shell: solo se puede ejecutar Artisan dentro del request.
$mapping = deploy_action_artisan($action);

if ($mapping === null) {
    deploy_job_write($id, [
        'id'      => $id,
        'action'  => $action,
        'status'  => 'error',
        'exit'    => 127,
        'ms'      => 0,
        'pid'     => 0,
        'started' => time(),
        'ended'   => time(),
    ]);

    json_out([
        'ok'    => false,
        'error' => 'Tu hosting bloquea exec/proc_open. Esta acción necesita shell. '
            . 'Comando a ejecutar manualmente: ' . deploy_action_instruction($action, $settings),
        'id'    => $id,
    ], 503);
}

@set_time_limit(0);
@ignore_user_abort(true);

$result = deploy_artisan_inline($action, DEPLOY_LOGS . '/' . $id . '.log');

deploy_job_write($id, [
    'id'      => $id,
    'action'  => $action,
    'status'  => $result['exit'] === 0 ? 'done' : 'error',
    'exit'    => $result['exit'],
    'ms'      => $result['ms'],
    'pid'     => getmypid() ?: 0,
    'started' => time() - (int) round($result['ms'] / 1000),
    'ended'   => time(),
]);

json_out(['ok' => true, 'id' => $id, 'mode' => 'inline', 'exit' => $result['exit']]);

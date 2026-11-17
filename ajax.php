<?php
/**
 * API del panel: estado, trozos de log y cancelación.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (auth_user() === null) {
    json_out(['ok' => false, 'error' => 'Sesión expirada.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---------- Cancelar ----------
if ($method === 'POST' && isset($_POST['cancel'])) {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        json_out(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
    }
    $id = is_string($_POST['cancel']) ? $_POST['cancel'] : '';
    json_out(['ok' => deploy_cancel($id)]);
}

// ---------- Estado general ----------
if (isset($_GET['state'])) {
    $settings = deploy_settings_autoresolve();
    json_out([
        'ok'         => true,
        'running'    => deploy_running(),
        'last'       => deploy_jobs(1)[0] ?? null,
        'jobs'       => deploy_jobs(12),
        'mode'       => deploy_exec_mode(),
        'configured' => deploy_settings_valid($settings) === [],
    ]);
}

// ---------- Log de un trabajo ----------
if (isset($_GET['job'])) {
    $id = is_string($_GET['job']) ? $_GET['job'] : '';
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
    }

    $file  = DEPLOY_LOGS . '/' . $id . '.log';
    $size  = is_file($file) ? (int) filesize($file) : 0;
    $since = isset($_GET['since']) && is_numeric($_GET['since']) ? max(0, (int) $_GET['since']) : 0;

    if ($since > $size) {
        $since = 0;
    }

    // En reconexiones con logs grandes mostramos solo la cola.
    if ($since === 0 && $size > 262144) {
        $since = $size - 262144;
    }

    $text = '';
    if ($size > 0) {
        $handle = @fopen($file, 'rb');
        if ($handle) {
            fseek($handle, $since);
            $text = (string) stream_get_contents($handle, 131072);
            fclose($handle);
        }
    }

    $job = deploy_job_read($id) ?: [];

    json_out([
        'ok'     => true,
        'status' => $job['status'] ?? 'unknown',
        'exit'   => $job['exit'] ?? null,
        'ms'     => $job['ms'] ?? 0,
        'pid'    => $job['pid'] ?? 0,
        'offset' => $since + strlen($text),
        'total'  => $size,
        'text'   => $text,
    ]);
}

// ---------- Descargar log ----------
if (isset($_GET['download'])) {
    $id = is_string($_GET['download']) ? $_GET['download'] : '';
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        http_response_code(400);
        exit('ID inválido');
    }

    $file = DEPLOY_LOGS . '/' . $id . '.log';
    if (!is_file($file)) {
        http_response_code(404);
        exit('Sin log');
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="deploy-' . $id . '.log"');
    readfile($file);
    exit;
}

json_out(['ok' => false, 'error' => 'Petición no reconocida.'], 400);

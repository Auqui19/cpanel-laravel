<?php
/**
 * Ejecución de trabajos.
 *
 * Nivel 1/2: se lanza run.sh DESACOPLADO del request (nohup + setsid), así
 *            el trabajo sobrevive aunque el navegador o PHP-FPM corten.
 * Nivel 3:   si el hosting bloquea exec/proc_open, se ejecuta Artisan dentro
 *            del propio request (sin log en vivo) para las acciones que lo
 *            permiten.
 */
declare(strict_types=1);

function deploy_exec_mode(): string
{
    if (deploy_fn_enabled('proc_open')) {
        return 'proc_open';
    }
    if (deploy_fn_enabled('exec')) {
        return 'exec';
    }
    if (deploy_fn_enabled('shell_exec')) {
        return 'shell_exec';
    }
    return 'none';
}

function deploy_mode_label(string $mode): string
{
    return match ($mode) {
        'proc_open'  => 'Shell directo (proc_open)',
        'exec'       => 'Shell directo (exec)',
        'shell_exec' => 'Shell limitado (shell_exec)',
        default      => 'Sin shell: solo acciones Artisan',
    };
}

function deploy_spawn(string $id, string $action): bool
{
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        return false;
    }

    $script = DEPLOY_ROOT . '/run.sh';
    if (!is_file($script)) {
        return false;
    }

    $nohup  = is_executable('/usr/bin/nohup') ? '/usr/bin/nohup' : (is_executable('/bin/nohup') ? '/bin/nohup' : 'nohup');
    $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : (is_executable('/bin/setsid') ? '/bin/setsid' : '');
    $bash   = is_executable('/bin/bash') ? '/bin/bash' : '/usr/bin/bash';

    $inner = $nohup . ' ' . ($setsid !== '' ? $setsid . ' ' : '')
        . $bash . ' ' . escapeshellarg($script) . ' '
        . escapeshellarg($id) . ' ' . escapeshellarg($action)
        . ' >/dev/null 2>&1 &';

    if (deploy_fn_enabled('proc_open')) {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $process = @proc_open([$bash, '-c', $inner], $descriptors, $pipes);
        if (is_resource($process)) {
            proc_close($process);
            return true;
        }
    }

    if (deploy_fn_enabled('exec')) {
        @exec($inner);
        return true;
    }

    if (deploy_fn_enabled('shell_exec')) {
        @shell_exec($inner);
        return true;
    }

    return false;
}

function deploy_job_write(string $id, array $data): void
{
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        return;
    }
    $file = DEPLOY_JOBS . '/' . $id . '.json';
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($file, 0600);
}

function deploy_job_read(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        return null;
    }
    $file = DEPLOY_JOBS . '/' . $id . '.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function deploy_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (deploy_fn_enabled('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

/**
 * Devuelve el trabajo en curso (o null). Si un trabajo quedó "running" pero
 * su proceso ya no existe, lo marca como perdido para no bloquear el panel.
 */
function deploy_running(): ?array
{
    foreach ((array) @glob(DEPLOY_JOBS . '/*.json', GLOB_NOSORT) as $file) {
        $job = json_decode((string) @file_get_contents($file), true);
        if (!is_array($job) || ($job['status'] ?? '') !== 'running') {
            continue;
        }

        $pid     = (int) ($job['pid'] ?? 0);
        $started = (int) ($job['started'] ?? 0);

        // Recién lanzado: el script todavía no escribió su PID.
        if ($pid === 0 && $started > time() - 10) {
            return $job;
        }

        if (deploy_pid_alive($pid)) {
            return $job;
        }

        $job['status'] = 'lost';
        $job['exit']   = -1;
        $job['ended']  = time();
        @file_put_contents($file, json_encode($job, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return null;
}

/**
 * Historial: últimos trabajos, más recientes primero.
 */
function deploy_jobs(int $limit = 15): array
{
    $jobs = [];
    foreach ((array) @glob(DEPLOY_JOBS . '/*.json', GLOB_NOSORT) as $file) {
        $job = json_decode((string) @file_get_contents($file), true);
        if (is_array($job) && isset($job['id'])) {
            $jobs[] = $job;
        }
    }

    usort($jobs, static fn(array $a, array $b) => ((int) ($b['started'] ?? 0)) <=> ((int) ($a['started'] ?? 0)));

    return array_slice($jobs, 0, $limit);
}

function deploy_cancel(string $id): bool
{
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        return false;
    }

    $job = deploy_job_read($id);
    $pid = (int) ($job['pid'] ?? 0);

    if ($pid <= 0 || !deploy_pid_alive($pid)) {
        return false;
    }

    if (deploy_fn_enabled('posix_kill')) {
        @posix_kill(-$pid, 15);
        @posix_kill($pid, 15);
        return true;
    }

    if (deploy_fn_enabled('exec')) {
        @exec('kill -TERM -- -' . (int) $pid . ' 2>/dev/null; kill -TERM ' . (int) $pid . ' 2>/dev/null');
        return true;
    }

    return false;
}

/**
 * Ejecuta una acción vía Artisan dentro del request. Solo se usa cuando el
 * hosting bloquea exec/proc_open y la acción no necesita composer.
 *
 * @return array{exit:int, ms:int, output:string}
 */
function deploy_artisan_inline(string $action, string $logFile): array
{
    $start = microtime(true);
    $s     = deploy_settings();
    $app   = $s['app_path'];

    $mapping = deploy_action_artisan($action);
    if ($mapping === null) {
        return ['exit' => 127, 'ms' => 0, 'output' => "Accion no disponible sin shell.\n"];
    }

    [$command, $params] = $mapping;

    $output = '';
    $exit   = 1;

    ob_start();
    try {
        $cwd = getcwd();
        chdir($app);

        require $app . '/vendor/autoload.php';
        $laravel = require $app . '/bootstrap/app.php';
        $kernel  = $laravel->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        $buffer = new Symfony\Component\Console\Output\BufferedOutput();
        $exit   = (int) $kernel->call($command, $params, $buffer);
        $output = $buffer->fetch();

        if ($cwd !== false) {
            chdir($cwd);
        }
    } catch (Throwable $e) {
        $output .= "\nERROR: " . $e->getMessage() . "\n";
        $exit = 1;
        @ob_end_clean();
    }

    $stray = (string) ob_get_clean();
    if ($stray !== '') {
        $output .= $stray;
    }

    $ms  = (int) round((microtime(true) - $start) * 1000);
    $log = "=== " . gmdate('Y-m-d H:i:s') . " UTC :: " . $action . " (modo PHP, sin shell) ===\n"
        . "+ artisan " . $command . "\n"
        . $output . "\n"
        . "→ exit " . $exit . "\n";

    @file_put_contents($logFile, $log, LOCK_EX);

    return ['exit' => $exit, 'ms' => $ms, 'output' => $output];
}

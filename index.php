<?php
/**
 * Panel de despliegue Laravel.
 *
 * Docroot del subdominio = esta carpeta:
 *   /home/USUARIO/public_html/deploy.tudominio.com/
 *
 * Credenciales en app/config.php ('users').
 * Diseño: Basecoat CSS (estilo Vega).
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$cfg      = deploy_cfg();
$flash    = (string) ($_SESSION['flash'] ?? '');
$flashErr = (string) ($_SESSION['flash_error'] ?? '');
$error    = '';
unset($_SESSION['flash'], $_SESSION['flash_error']);

function go(): void
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    header('Location: ' . (is_string($path) && $path !== '' ? $path : '/'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = post_str('form');

    if ($form === 'login') {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            $error = 'La sesión expiró. Recargá la página e intentá otra vez.';
        } else {
            $error = auth_attempt(post_str('usuario'), post_str('clave'));
            if ($error === '') {
                go();
            }
        }
    } elseif ($form === 'logout') {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Token de seguridad inválido. Recargá la página.';
            go();
        }
        auth_logout();
        go();
    } elseif (auth_user() !== null) {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Token de seguridad inválido. Recargá la página.';
            go();
        }

        if ($form === 'project') {
            $errs = deploy_settings_save([
                'cpanel_user' => $_POST['cpanel_user'] ?? '',
                'app_path'    => $_POST['app_path'] ?? '',
                'php_bin'     => $_POST['php_bin'] ?? '',
                'composer'    => $_POST['composer'] ?? '',
            ]);
            if ($errs) {
                $_SESSION['flash_error'] = implode(' ', $errs);
            } else {
                $_SESSION['flash'] = 'Proyecto cambiado.';
            }
            go();
        }

        if ($form === 'settings') {
            $errs = deploy_settings_save($_POST);
            if ($errs) {
                $_SESSION['flash_error'] = implode(' ', $errs);
            } else {
                $_SESSION['flash'] = 'Ajustes guardados.';
            }
            go();
        }

        if ($form === 'redetect') {
            @unlink(DEPLOY_DATA . '/settings.php');
            @unlink(DEPLOY_DATA . '/settings.env');
            go();
        }
    }
}

$logged = auth_user() !== null;

if ($logged) {
    $settings       = deploy_settings_autoresolve();
    $settingsErrors = deploy_settings_valid($settings);
    $detect         = deploy_detect();
    $phpBins        = $detect['php_bin'];
    $apps           = $detect['apps'];

    if ($settings['app_path'] !== '' && !in_array($settings['app_path'], $apps, true)) {
        array_unshift($apps, $settings['app_path']);
    }

    $formUser     = $settings['cpanel_user'] !== '' ? $settings['cpanel_user'] : $detect['cpanel_user'];
    $formApp      = $settings['app_path'];
    $formPhp      = $settings['php_bin'];
    $formComposer = $settings['composer'];

    if ($formPhp !== '' && !in_array($formPhp, $phpBins, true)) {
        array_unshift($phpBins, $formPhp);
    }

    $mode    = deploy_exec_mode();
    $running = deploy_running();
    $jobs    = deploy_jobs(12);
    $actions = deploy_actions();

    $groups = [];
    foreach ($actions as $id => $meta) {
        $groups[$meta['group']][$id] = $meta;
    }

    $currentLabel = $settings['app_path'] !== '' ? basename($settings['app_path']) : 'sin proyecto';

    $envExists  = !$settingsErrors && is_file($settings['app_path'] . '/.env');
    $envExample = !$settingsErrors && is_file($settings['app_path'] . '/.env.example');

    $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host       = preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) ? $host : 'localhost';
    $defaultUrl = $scheme . '://' . $host;

    $currentUser = (string) auth_user();
}

function job_badge(array $job): string
{
    $map = [
        'done'    => ['st-ok', 'OK'],
        'warn'    => ['st-warn', 'CON AVISOS'],
        'error'   => ['st-bad', 'ERROR'],
        'timeout' => ['st-bad', 'TIMEOUT'],
        'busy'    => ['st-run', 'OCUPADO'],
        'lost'    => ['st-bad', 'PERDIDO'],
        'running' => ['st-run', 'EN CURSO'],
    ];
    $status = (string) ($job['status'] ?? '');
    [$cls, $label] = $map[$status] ?? ['st-warn', strtoupper($status)];

    return '<span class="badge ' . $cls . '" data-variant="outline">' . h($label) . '</span>';
}

function job_time(array $job): string
{
    $ms = (int) ($job['ms'] ?? 0);
    return $ms > 0 ? number_format($ms / 1000, 1, '.', '') . 's' : '—';
}
?><!doctype html>
<html lang="es" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($cfg['name']) ?></title>
<link rel="icon" type="image/webp" href="assets/astrostudio.webp">
<link rel="apple-touch-icon" href="assets/astrostudio.webp">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/basecoat-css@1.1.0/dist/basecoat-rhea.cdn.min.css" />
<script src="https://cdn.jsdelivr.net/npm/basecoat-css@1.1.0/dist/js/all.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<style type="text/tailwindcss">
  @theme inline {
    --color-background: var(--background);
    --color-foreground: var(--foreground);
    --color-card: var(--card);
    --color-card-foreground: var(--card-foreground);
    --color-primary: var(--primary);
    --color-primary-foreground: var(--primary-foreground);
    --color-secondary: var(--secondary);
    --color-secondary-foreground: var(--secondary-foreground);
    --color-muted: var(--muted);
    --color-muted-foreground: var(--muted-foreground);
    --color-accent: var(--accent);
    --color-accent-foreground: var(--accent-foreground);
    --color-destructive: var(--destructive);
    --color-border: var(--border);
    --color-input: var(--input);
    --color-ring: var(--ring);
  }

  /* Tailwind resetea los bordes a currentColor: los devolvemos al tema */
  @layer base {
    *, ::before, ::after { border-color: var(--border); }
  }
</style>
<link rel="stylesheet" href="assets/panel.css">
</head>
<body<?= $logged ? ' data-csrf="' . h(csrf()) . '" data-shell="' . ($mode !== 'none' ? '1' : '0') . '"' : ' class="login-page"' ?>>
<?php if (!$logged): ?>
  <div class="wrap login-wrap">
    <div class="login-logos">
      <img class="login-astro" src="assets/astrostudio.webp" alt="AstroStudio">
      <span class="login-plus" aria-hidden="true">+</span>
      <svg class="login-laravel" viewBox="0 0 256 264" role="img" aria-label="Laravel">
        <path d="m255.9 59.6.1 1.1v56.6c0 1.4-.8 2.8-2 3.5l-47.6 27.4v54.2c0 1.4-.7 2.8-2 3.5l-99.1 57-.7.4-.3.1c-.7.2-1.4.2-2.1 0l-.4-.1-.6-.3L2 206c-1.3-.8-2.1-2.2-2.1-3.6V32.7l.1-1.1.2-.4.3-.6.2-.4.4-.5.4-.3c.2 0 .3-.2.5-.3L51.6.6c1.3-.8 2.9-.8 4.1 0L105.3 29c.2 0 .3.2.4.3l.5.3c0 .2.2.4.3.5l.3.4.3.6.1.4.2 1v106l41.2-23.7V60.7c0-.4 0-.7.2-1l.1-.4.3-.7.3-.3.3-.5.5-.3.4-.4 49.6-28.5c1.2-.7 2.8-.7 4 0L254 57l.5.4.4.3.4.5.2.3c.2.2.2.5.3.7l.2.3Zm-8.2 55.3v-47l-17.3 10-24 13.7v47l41.3-23.7Zm-49.5 85v-47l-23.6 13.5-67.2 38.4v47.5l90.8-52.3ZM8.2 39.9V200l90.9 52.3v-47.5l-47.5-26.9-.4-.4c-.2 0-.3-.1-.4-.3l-.4-.4-.3-.4-.2-.5-.2-.5v-.6l-.2-.5V63.6L25.6 49.8l-17.3-10Zm45.5-31L12.4 32.8l41.3 23.7 41.2-23.7L53.7 8.9ZM75 157.3l24-13.8V39.8l-17.3 10-24 13.8v103.6l17.3-10ZM202.3 36.9 161 60.7l41.3 23.8 41.3-23.8-41.3-23.8Zm-4.1 54.7-24-13.8-17.3-10v47l24 13.9 17.3 10v-47Zm-95 106 60.6-34.5 30.2-17.3-41.2-23.8-47.5 27.4L62 174.3l41.2 23.3Z" fill="#FF2D20"/>
      </svg>
    </div>

    <h1 class="login-title"><?= h($cfg['name']) ?></h1>

    <?php if ($error !== ''): ?>
      <p class="login-error"><?= h($error) ?></p>
    <?php endif; ?>

    <?php if (!auth_configured()): ?>
      <p class="login-hint">
        Todavía no hay credenciales.<br>
        Editá <code>app/config.php</code> y definí
        <code>'users' => ['usuario' => 'clave']</code>.
      </p>
    <?php else: ?>
      <form method="post" class="login-form">
        <input type="hidden" name="form" value="login">
        <?= csrf_field() ?>
        <div role="group" class="field">
          <label class="sr-only" for="usuario">Usuario</label>
          <input id="usuario" class="input" type="text" name="usuario"
                 placeholder="Usuario" required autofocus
                 autocomplete="username" autocapitalize="none" spellcheck="false">
        </div>
        <div role="group" class="field">
          <label class="sr-only" for="clave">Contraseña</label>
          <input id="clave" class="input" type="password" name="clave"
                 placeholder="Contraseña" required autocomplete="current-password">
        </div>
        <button class="btn" type="submit">Entrar</button>
      </form>
    <?php endif; ?>
  </div>

  <?php else: ?>

    <aside id="sidebar" class="sidebar" data-side="left">
      <nav aria-label="Navegación del panel">
        <header class="sidebar-brand">
          <img src="assets/astrostudio.webp" alt="AstroStudio">
          <span class="sidebar-plus" aria-hidden="true">+</span>
          <svg viewBox="0 0 256 264" aria-hidden="true">
            <path d="m255.9 59.6.1 1.1v56.6c0 1.4-.8 2.8-2 3.5l-47.6 27.4v54.2c0 1.4-.7 2.8-2 3.5l-99.1 57-.7.4-.3.1c-.7.2-1.4.2-2.1 0l-.4-.1-.6-.3L2 206c-1.3-.8-2.1-2.2-2.1-3.6V32.7l.1-1.1.2-.4.3-.6.2-.4.4-.5.4-.3c.2 0 .3-.2.5-.3L51.6.6c1.3-.8 2.9-.8 4.1 0L105.3 29c.2 0 .3.2.4.3l.5.3c0 .2.2.4.3.5l.3.4.3.6.1.4.2 1v106l41.2-23.7V60.7c0-.4 0-.7.2-1l.1-.4.3-.7.3-.3.3-.5.5-.3.4-.4 49.6-28.5c1.2-.7 2.8-.7 4 0L254 57l.5.4.4.3.4.5.2.3c.2.2.2.5.3.7l.2.3Zm-8.2 55.3v-47l-17.3 10-24 13.7v47l41.3-23.7Zm-49.5 85v-47l-23.6 13.5-67.2 38.4v47.5l90.8-52.3ZM8.2 39.9V200l90.9 52.3v-47.5l-47.5-26.9-.4-.4c-.2 0-.3-.1-.4-.3l-.4-.4-.3-.4-.2-.5-.2-.5v-.6l-.2-.5V63.6L25.6 49.8l-17.3-10Zm45.5-31L12.4 32.8l41.3 23.7 41.2-23.7L53.7 8.9ZM75 157.3l24-13.8V39.8l-17.3 10-24 13.8v103.6l17.3-10ZM202.3 36.9 161 60.7l41.3 23.8 41.3-23.8-41.3-23.8Zm-4.1 54.7-24-13.8-17.3-10v47l24 13.9 17.3 10v-47Zm-95 106 60.6-34.5 30.2-17.3-41.2-23.8-47.5 27.4L62 174.3l41.2 23.3Z" fill="#FF2D20"/>
          </svg>
        </header>

        <section class="scrollbar-sm">
          <div role="group" aria-labelledby="nav-panel">
            <h3 id="nav-panel">Panel</h3>
            <ul>
              <li>
                <a href="#proyecto">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>
                  <span>Proyecto</span>
                </a>
              </li>
              <li>
                <a href="#env">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>
                  <span>.env</span>
                </a>
              </li>
              <li>
                <a href="#acciones">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>
                  <span>Acciones</span>
                </a>
              </li>
              <li>
                <a href="#consola">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/></svg>
                  <span>Consola</span>
                </a>
              </li>
              <li>
                <a href="history.php">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>
                  <span>Historial</span>
                </a>
              </li>
            </ul>
          </div>
        </section>

        <footer class="sidebar-footer-actions">
          <form method="post">
            <input type="hidden" name="form" value="logout">
            <?= csrf_field() ?>
            <button class="btn" data-variant="ghost" data-size="sm"
                    type="submit" aria-label="Cerrar sesión" title="Cerrar sesión">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-logout"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M9 12h12l-3 -3" /><path d="M18 15l3 -3" /></svg>
            
              <span>Cerrar sesión</span>
            </button>
          </form>
        </footer>
      </nav>
    </aside>

    <main>
      <header class="topbar">
        <div class="topbar-inner">
          <button type="button" class="btn sidebar-toggle" data-variant="outline" data-size="icon-sm"
                  aria-label="Abrir menú" onclick="document.getElementById('sidebar')?.toggle()">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
          </button>
          <div class="chips">
            <span class="chip">Sesión: <b><?= h($currentUser) ?></b></span>
            <span class="chip">Cuenta: <b><?= h($settings['cpanel_user'] !== '' ? $settings['cpanel_user'] : 'no detectada') ?></b></span>
            <span class="chip">Proyecto: <b><?= h($currentLabel) ?></b></span>
            <span class="chip">Modo: <b><?= h(deploy_mode_label($mode)) ?></b></span>
            <?php if ($running): ?>
              <span class="chip">En curso: <b><?= h($actions[$running['action']]['label'] ?? $running['action']) ?></b></span>
            <?php elseif ($jobs): ?>
              <span class="chip">Última: <b><?= h($actions[$jobs[0]['action']]['label'] ?? $jobs[0]['action']) ?></b> <?= job_badge($jobs[0]) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </header>

      <div class="wrap">

    <?php if ($flash !== ''): ?>
      <div class="alert"><section><?= h($flash) ?></section></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="alert" data-variant="destructive"><section><?= h($flashErr) ?></section></div>
    <?php endif; ?>

    <?php if ($settingsErrors): ?>
      <div class="alert" data-variant="destructive">
        <h2>Falta configurar</h2>
        <section>
          <?= h(implode(' ', $settingsErrors)) ?>
          <?php if (!$apps): ?>
            <p>No se detectaron proyectos con <code>artisan</code> dentro de <code>/home/<?= h($formUser) ?></code>. Escribí la ruta a mano abajo.</p>
          <?php endif; ?>
        </section>
      </div>
    <?php endif; ?>

    <?php if ($mode === 'none'): ?>
      <div class="alert" data-variant="destructive">
        <h2>Sin shell</h2>
        <section>
          Tu hosting bloquea <code>exec</code> y <code>proc_open</code>.
          Funcionan las acciones Artisan (migrar, cachear, limpiar, storage, mantenimiento).
          «Instalar vendor» y «Dump autoload» necesitan shell.
        </section>
      </div>
    <?php endif; ?>

    <!-- Proyecto -->
    <div class="card" id="proyecto">
      <header>
        <h2>Proyecto a gestionar</h2>
        <p>Detectados <?= (int) count($apps) ?> proyecto(s) Laravel en <code>/home/<?= h($formUser) ?></code>.</p>
      </header>
      <section>
        <?php if ($apps): ?>
          <form method="post" class="row">
            <input type="hidden" name="form" value="project">
            <?= csrf_field() ?>
            <input type="hidden" name="cpanel_user" value="<?= h($formUser) ?>">
            <input type="hidden" name="php_bin" value="<?= h($formPhp) ?>">
            <input type="hidden" name="composer" value="<?= h($formComposer) ?>">
            <div class="grow field">
              <label for="app_pick">Ruta del proyecto</label>
              <select id="app_pick" class="select" name="app_path">
                <?php foreach ($apps as $app): ?>
                  <option value="<?= h($app) ?>" <?= $app === $formApp ? 'selected' : '' ?>><?= h($app) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn" type="submit">Usar este</button>
          </form>
        <?php endif; ?>

        <details class="panel" <?= $settingsErrors ? 'open' : '' ?>>
          <summary>Ajustes manuales y detección</summary>
          <form method="post">
            <input type="hidden" name="form" value="settings">
            <?= csrf_field() ?>
            <div class="grid2">
              <div class="field">
                <label for="cpanel_user">Usuario de cPanel</label>
                <input id="cpanel_user" class="input" name="cpanel_user" value="<?= h($formUser) ?>" required pattern="[a-z][a-z0-9_]{0,31}" readonly>
                <div class="hint">Detectado automáticamente. No editable.</div>
              </div>
              <div class="field">
                <label for="php_bin">Binario PHP del proyecto</label>
                <input id="php_bin" class="input" name="php_bin" value="<?= h($formPhp) ?>" required list="phpbins"
                       placeholder="/opt/cpanel/ea-php82/root/usr/bin/php">
                <datalist id="phpbins">
                  <?php foreach ($phpBins as $bin): ?><option value="<?= h($bin) ?>"></option><?php endforeach; ?>
                </datalist>
                <div class="hint">Detectados: <?= (int) count($phpBins) ?>.</div>
              </div>
            </div>

            <div class="field">
              <label for="app_path">Ruta absoluta del proyecto (debe contener artisan)</label>
              <input id="app_path" class="input" name="app_path" value="<?= h($formApp) ?>" required list="apps"
                     placeholder="/home/<?= h($formUser) ?>/erp_backend">
              <datalist id="apps">
                <?php foreach ($apps as $app): ?><option value="<?= h($app) ?>"></option><?php endforeach; ?>
              </datalist>
            </div>

            <div class="field">
              <label for="composer">Ruta de composer</label>
              <input id="composer" class="input" name="composer" value="<?= h($formComposer) ?>" required
                     placeholder="/home/<?= h($formUser) ?>/composer.phar">
              <div class="hint">Detectado: <?= h($detect['composer'] !== '' ? $detect['composer'] : 'no encontrado') ?></div>
            </div>

            <div class="form-actions">
              <button class="btn" type="submit">Guardar ajustes</button>
              <button class="btn" data-variant="outline" type="submit" form="redetect-form">Volver a detectar</button>
            </div>
          </form>

          <form method="post" id="redetect-form">
            <input type="hidden" name="form" value="redetect">
            <?= csrf_field() ?>
          </form>
        </details>
      </section>
    </div>

    <!-- .env -->
    <?php if (!$settingsErrors): ?>
      <div class="card" id="env">
        <header>
          <h2>Archivo .env</h2>
          <?php if ($envExists): ?>
            <p>Ya existe <code>.env</code>. El panel no lo modifica.</p>
          <?php elseif ($envExample): ?>
            <p>Se crea en <code><?= h($settings['app_path']) ?>/.env</code> con <code>APP_KEY</code> automática, tomando <code>.env.example</code> como base.</p>
          <?php else: ?>
            <p>Se crea en <code><?= h($settings['app_path']) ?>/.env</code> con <code>APP_KEY</code> automática y una plantilla mínima.</p>
          <?php endif; ?>
        </header>

        <?php if ($envExists): ?>
          <section>
            <div class="alert"><section><p>El proyecto ya tiene <code>.env</code>. Si querés regenerarlo, borralo a mano.</p></section></div>
          </section>
        <?php else: ?>
          <section>
            <form method="post" action="env.php">
              <?= csrf_field() ?>
              <div class="grid2">
                <div class="field">
                  <label for="db_host">DB_HOST</label>
                  <input id="db_host" class="input" name="db_host" value="localhost">
                </div>
                <div class="field">
                  <label for="db_port">DB_PORT</label>
                  <input id="db_port" class="input" name="db_port" value="3306" inputmode="numeric">
                </div>
                <div class="field">
                  <label for="db_database">DB_DATABASE</label>
                  <input id="db_database" class="input" name="db_database" placeholder="usuario_db">
                </div>
                <div class="field">
                  <label for="db_username">DB_USERNAME</label>
                  <input id="db_username" class="input" name="db_username" placeholder="usuario_dbuser">
                </div>
                <div class="field">
                  <label for="db_password">DB_PASSWORD</label>
                  <input id="db_password" class="input" type="password" name="db_password" autocomplete="new-password">
                </div>
                <div class="field">
                  <label for="app_url">APP_URL</label>
                  <input id="app_url" class="input" name="app_url" value="<?= h($defaultUrl) ?>">
                </div>
                <div class="field">
                  <label for="app_env">APP_ENV</label>
                  <select id="app_env" class="select" name="app_env">
                    <option value="production">production</option>
                    <option value="local">local</option>
                  </select>
                </div>
                <div class="field">
                  <label>APP_DEBUG</label>
                  <label class="check"><input type="checkbox" name="app_debug" value="1"> Mostrar errores detallados</label>
                </div>
              </div>

              <div class="form-actions">
                <button class="btn" type="submit" name="mode" value="custom">Crear .env con estos datos</button>
                <?php if ($envExample): ?>
                  <button class="btn" data-variant="outline" type="submit" name="mode" value="copy">Solo copiar .env.example</button>
                <?php endif; ?>
              </div>
              <div class="hint">Los campos vacíos conservan el valor de <code>.env.example</code>. Nunca sobrescribe un <code>.env</code> existente.</div>
            </form>
          </section>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Acciones -->
    <div class="card" id="acciones">
      <header>
        <h2>Acciones</h2>
        <p>Todo se ejecuta al instante y queda en el historial.</p>
      </header>
      <section>
        <?php foreach ($groups as $group => $items): ?>
          <div class="actions">
            <div class="group-label"><?= h($group) ?></div>
            <?php foreach ($items as $id => $meta): ?>
              <button
                type="button"
                class="btn"
                data-variant="<?= $meta['danger'] ? 'destructive' : ($group === 'Diagnóstico' ? 'primary' : 'outline') ?>"
                data-size="sm"
                data-action="<?= h($id) ?>"
                data-danger="<?= $meta['danger'] ? '1' : '0' ?>"
                data-shell="<?= $meta['shell'] ? '1' : '0' ?>"
                title="<?= h($meta['hint']) ?>"
                <?= ($running || $settingsErrors) ? 'disabled' : '' ?>
              ><?= h($meta['label']) ?></button>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </section>
    </div>

    <!-- Consola -->
    <div class="console" id="consola">
      <div class="console-bar">
        <span class="cstatus" id="console-status">esperando</span>
        <span class="grow"></span>
        <span id="elapsed"></span>
        <button type="button" class="btn" data-variant="outline" data-size="xs" id="cancel" hidden>Cancelar</button>
        <button type="button" class="btn" data-variant="outline" data-size="xs" id="clear-console">Limpiar</button>
      </div>
      <div id="console-box"><span id="console" class="console-empty">Elegí una acción para ver la salida acá.</span></div>
    </div>

      </div>
    </main>
  <?php endif; ?>

<script src="assets/panel.js" defer></script>
</body>
</html>

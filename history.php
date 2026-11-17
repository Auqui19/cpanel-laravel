<?php
/**
 * Historial de ejecuciones: listado de trabajos + detalle del log.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (auth_user() === null) {
    header('Location: ./');
    exit;
}

$settings = deploy_settings_autoresolve();
$actions  = deploy_actions();
$jobs     = deploy_jobs(40);
$selected = isset($_GET['job']) && is_string($_GET['job']) ? $_GET['job'] : '';

if ($selected !== '' && !preg_match('/^[a-f0-9]{12}$/', $selected)) {
    $selected = '';
}

if ($selected === '' && $jobs) {
    $selected = (string) ($jobs[0]['id'] ?? '');
}

$current = null;
$logText = '';

if ($selected !== '') {
    foreach ($jobs as $job) {
        if (($job['id'] ?? '') === $selected) {
            $current = $job;
            break;
        }
    }

    if ($current) {
        $logFile = DEPLOY_LOGS . '/' . $selected . '.log';
        if (is_file($logFile)) {
            $logText = (string) file_get_contents($logFile);
            if (strlen($logText) > 300000) {
                $logText = substr($logText, -300000);
            }
        }
    }
}

function history_badge(array $job): string
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
    [$class, $label] = $map[$job['status'] ?? ''] ?? ['st-warn', strtoupper((string) ($job['status'] ?? ''))];
    return '<span class="badge ' . $class . '" data-variant="outline">' . h($label) . '</span>';
}

function history_duration(array $job): string
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
  <title>Historial · <?= h(deploy_cfg()['name']) ?></title>
  <link rel="icon" type="image/webp" href="assets/astrostudio.webp">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/basecoat-css@1.1.0/dist/basecoat-rhea.cdn.min.css">
  <script src="https://cdn.jsdelivr.net/npm/basecoat-css@1.1.0/dist/js/all.min.js" defer></script>
  <link rel="stylesheet" href="assets/panel.css">
  <style>
    .history-page main { min-height: calc(100vh - var(--navbar-h)); }
    .history-title { margin: 0; font-size: 18px; font-weight: 600; }
    .history-toolbar { display:flex; align-items:center; gap:10px; margin-bottom:16px; }
    .history-toolbar .grow { flex:1; }
    .history-layout {
      display:grid;
      grid-template-columns:minmax(230px, 30%) minmax(0, 70%);
      gap:16px;
      align-items:start;
    }
    .history-list, .history-detail { min-width:0; }
    .history-list .card, .history-detail .card { height:100%; }
    .history-items { display:flex; flex-direction:column; gap:6px; }
    .history-item {
      display:flex;
      flex-direction:column;
      gap:5px;
      width:100%;
      padding:10px 12px;
      text-align:left;
      color:var(--color-foreground);
      background:transparent;
      border:1px solid transparent;
      border-radius:var(--radius-lg);
      cursor:pointer;
      text-decoration:none;
    }
    .history-item:hover { background:var(--color-muted); }
    .history-item.active {
      background:var(--color-accent);
      border-color:var(--color-border);
    }
    .history-item-top { display:flex; align-items:center; gap:8px; }
    .history-item-top strong { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .history-meta { color:var(--color-muted-foreground); font:12px var(--font-mono); }
    .history-log {
      min-height:560px;
      max-height:calc(100vh - 190px);
      margin:0;
      padding:16px;
      overflow:auto;
      color:#cfdceb;
      background:#080d17;
      border:1px solid var(--color-border);
      border-radius:var(--radius-lg);
      font:12px/1.55 var(--font-mono);
      white-space:pre-wrap;
      word-break:break-word;
    }
    .history-empty {
      display:grid;
      min-height:260px;
      place-items:center;
      color:var(--color-muted-foreground);
      text-align:center;
    }
    @media (max-width:760px) {
      .history-layout { grid-template-columns:1fr; }
      .history-log { min-height:420px; max-height:none; }
    }
  </style>
</head>
<body class="history-page">
  <aside id="sidebar" class="sidebar" data-side="left">
    <nav aria-label="Navegación del panel">
      <header class="sidebar-brand">
        <img src="assets/astrostudio.webp" alt="AstroStudio">
        <span class="sidebar-plus" aria-hidden="true">+</span>
        <svg viewBox="0 0 256 264" aria-hidden="true"><path d="m255.9 59.6.1 1.1v56.6c0 1.4-.8 2.8-2 3.5l-47.6 27.4v54.2c0 1.4-.7 2.8-2 3.5l-99.1 57-.7.4-.3.1c-.7.2-1.4.2-2.1 0l-.4-.1-.6-.3L2 206c-1.3-.8-2.1-2.2-2.1-3.6V32.7l.1-1.1.2-.4.3-.6.2-.4.4-.5.4-.3c.2 0 .3-.2.5-.3L51.6.6c1.3-.8 2.9-.8 4.1 0L105.3 29c.2 0 .3.2.4.3l.5.3c0 .2.2.4.3.5l.3.4.3.6.1.4.2 1v106l41.2-23.7V60.7c0-.4 0-.7.2-1l.1-.4.3-.7.3-.3.3-.5.5-.3.4-.4 49.6-28.5c1.2-.7 2.8-.7 4 0L254 57l.5.4.4.3.4.5.2.3c.2.2.2.5.3.7l.2.3Zm-8.2 55.3v-47l-17.3 10-24 13.7v47l41.3-23.7Zm-49.5 85v-47l-23.6 13.5-67.2 38.4v47.5l90.8-52.3ZM8.2 39.9V200l90.9 52.3v-47.5l-47.5-26.9-.4-.4c-.2 0-.3-.1-.4-.3l-.4-.4-.3-.4-.2-.5-.2-.5v-.6l-.2-.5V63.6L25.6 49.8l-17.3-10Zm45.5-31L12.4 32.8l41.3 23.7 41.2-23.7L53.7 8.9ZM75 157.3l24-13.8V39.8l-17.3 10-24 13.8v103.6l17.3-10ZM202.3 36.9 161 60.7l41.3 23.8 41.3-23.8-41.3-23.8Zm-4.1 54.7-24-13.8-17.3-10v47l24 13.9 17.3 10v-47Zm-95 106 60.6-34.5 30.2-17.3-41.2-23.8-47.5 27.4L62 174.3l41.2 23.3Z" fill="#FF2D20"/></svg>
      </header>
      <section class="scrollbar-sm">
        <div role="group" aria-labelledby="history-nav">
          <h3 id="history-nav">Panel</h3>
          <ul>
            <li><a href="./">Proyecto</a></li>
            <li><a href="history.php" aria-current="page">Historial</a></li>
          </ul>
        </div>
      </section>
      <footer class="sidebar-footer-actions">
        <form method="post" action="./">
          <input type="hidden" name="form" value="logout">
          <?= csrf_field() ?>
          <button class="btn" data-variant="ghost" data-size="sm"
                  type="submit" aria-label="Cerrar sesión" title="Cerrar sesión">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-logout"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M9 12h12l-3 -3" /><path d="M18 15l3 -3" /></svg>
          
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
          <span class="chip">Cuenta: <b><?= h($settings['cpanel_user'] ?? '—') ?></b></span>
          <span class="chip">Proyecto: <b><?= h($settings['app_path'] !== '' ? basename($settings['app_path']) : 'sin proyecto') ?></b></span>
          <span class="chip">Historial: <b><?= count($jobs) ?> trabajos</b></span>
        </div>
      </div>
    </header>

    <div class="wrap">
      <div class="history-toolbar">
        <h1 class="history-title">Historial</h1>
        <span class="grow"></span>
        <a class="btn" data-variant="outline" data-size="sm" href="./">Volver al panel</a>
      </div>

      <div class="history-layout">
        <section class="history-list">
          <div class="card">
            <header>
              <h2>Trabajos</h2>
              <p>Seleccioná una ejecución para ver su log.</p>
            </header>
            <section>
              <?php if (!$jobs): ?>
                <div class="history-empty">Sin ejecuciones todavía.</div>
              <?php else: ?>
                <div class="history-items">
                  <?php foreach ($jobs as $job): ?>
                    <?php $jobId = (string) ($job['id'] ?? ''); ?>
                    <a class="history-item <?= $jobId === $selected ? 'active' : '' ?>"
                       href="history.php?job=<?= h($jobId) ?>">
                      <div class="history-item-top">
                        <strong><?= h($actions[$job['action']]['label'] ?? $job['action']) ?></strong>
                        <?= history_badge($job) ?>
                      </div>
                      <span class="history-meta"><?= h(date('d/m/Y H:i:s', (int) ($job['started'] ?? 0))) ?> · <?= h(history_duration($job)) ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>
          </div>
        </section>

        <section class="history-detail">
          <div class="card">
            <header>
              <h2><?= $current ? h($actions[$current['action']]['label'] ?? $current['action']) : 'Detalle del log' ?></h2>
              <p>
                <?php if ($current): ?>
                  <?= history_badge($current) ?> · exit <?= (int) ($current['exit'] ?? 0) ?> · <?= h(history_duration($current)) ?>
                <?php else: ?>
                  Elegí un trabajo del listado.
                <?php endif; ?>
              </p>
            </header>
            <section>
              <?php if ($current): ?>
                <pre class="history-log"><?= h($logText !== '' ? $logText : 'El trabajo todavía no tiene salida.') ?></pre>
                <div class="form-actions">
                  <a class="btn" data-variant="outline" data-size="sm" href="ajax.php?download=<?= h($selected) ?>">Descargar log</a>
                </div>
              <?php else: ?>
                <div class="history-empty">No hay un log seleccionado.</div>
              <?php endif; ?>
            </section>
          </div>
        </section>
      </div>
    </div>
  </main>

  <script src="assets/panel.js" defer></script>
</body>
</html>

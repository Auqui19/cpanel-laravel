(function () {
  const CSRF = document.body.dataset.csrf || '';
  const SHELL = document.body.dataset.shell === '1';
  const box = document.getElementById('console-box');
  if (!box) return;
  const out     = document.getElementById('console');
  const stateEl = document.getElementById('console-status');
  const elapsed = document.getElementById('elapsed');
  const cancel  = document.getElementById('cancel');

  let jobId = null, offset = 0, timer = null, t0 = 0, busy = false;

  function emit(text, cls) {
    if (!text) return;
    if (out.classList.contains('console-empty')) {
      out.classList.remove('console-empty');
      out.textContent = '';
    }
    const span = document.createElement('span');
    if (cls) span.className = cls;
    span.textContent = text;
    out.appendChild(span);
    box.scrollTop = box.scrollHeight;
  }

  function setButtons(disabled) {
    document.querySelectorAll('button[data-action]').forEach(function (b) {
      b.disabled = disabled || (b.dataset.shell === '1' && !SHELL);
    });
  }

  function tick() {
    if (!t0) return;
    elapsed.textContent = ((Date.now() - t0) / 1000).toFixed(1) + 's';
  }

  function reset() {
    out.classList.add('console-empty');
    out.textContent = 'Ejecutando…';
    elapsed.textContent = '';
    stateEl.className = 'cstatus';
    stateEl.textContent = 'lanzando…';
  }

  async function start(action, danger) {
    if (busy) return;
    if (danger && !confirm('¿Ejecutar "' + action + '"? Esta acción modifica el proyecto.')) return;

    busy = true;
    jobId = null;
    offset = 0;
    reset();
    setButtons(true);
    box.classList.add('busy');

    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', CSRF);

    try {
      const res  = await fetch('run.php', { method: 'POST', body: fd });
      const data = await res.json();

      if (!data.ok) {
        emit('\u2716 ' + data.error + '\n', 'errline');
        stateEl.className = 'cstatus bad';
        stateEl.textContent = 'no se pudo ejecutar';
        busy = false;
        box.classList.remove('busy');
        setButtons(false);
        return;
      }

      jobId = data.id;
      t0 = Date.now();
      cancel.hidden = false;
      stateEl.textContent = data.mode === 'inline' ? 'ejecutando (modo PHP)…' : 'en curso…';
      timer = setInterval(function () { tick(); poll(); }, 1200);
      poll();
    } catch (err) {
      emit('\u2716 Error de red: ' + err + '\n', 'errline');
      stateEl.className = 'cstatus bad';
      stateEl.textContent = 'error';
      busy = false;
      box.classList.remove('busy');
      setButtons(false);
    }
  }

  async function poll() {
    if (!jobId) return;
    try {
      const res  = await fetch('ajax.php?job=' + encodeURIComponent(jobId) + '&since=' + offset);
      const data = await res.json();

      if (!data.ok) {
        finish({ status: 'error', exit: -1, ms: 0 });
        return;
      }

      if (data.text) {
        offset = data.offset;
        emit(data.text);
      }

      if (data.status !== 'running' && data.status !== 'unknown') {
        finish(data);
      }
    } catch (err) {
      emit('\n(no se pudo leer el log: ' + err + ')\n', 'warnline');
    }
  }

  function finish(data) {
    clearInterval(timer);
    timer = null;
    busy = false;
    box.classList.remove('busy');
    cancel.hidden = true;

    const ok   = data.status === 'done';
    const warn = data.status === 'warn';

    stateEl.className = 'cstatus ' + (ok ? 'ok' : (warn ? 'warn' : 'bad'));
    stateEl.textContent = ok
      ? 'completado'
      : (warn ? 'completado con avisos' : (data.status === 'timeout' ? 'timeout' : 'error'));

    if (data.ms) elapsed.textContent = (data.ms / 1000).toFixed(1) + 's';

    const line = ok
      ? '\u2714 listo'
      : (warn
          ? '\u26a0 listo, con pendientes (no es un error)'
          : '\u2716 terminó con error (exit ' + data.exit + ')');

    emit('\n' + line + '\n', ok ? 'okline' : (warn ? 'warnline' : 'errline'));

    setButtons(false);
  }

  document.querySelectorAll('button[data-action]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      start(btn.dataset.action, btn.dataset.danger === '1');
    });
  });

  cancel.addEventListener('click', async function () {
    if (!jobId) return;
    const fd = new FormData();
    fd.append('cancel', jobId);
    fd.append('csrf', CSRF);
    try {
      await fetch('ajax.php', { method: 'POST', body: fd });
      emit('\n\u21af cancelación enviada\n', 'warnline');
    } catch (e) {
      emit('\n(no se pudo cancelar)\n', 'warnline');
    }
  });

  document.getElementById('clear-console').addEventListener('click', function () {
    out.textContent = '';
    out.classList.add('console-empty');
    out.textContent = 'Consola limpia.';
  });

  (async function init() {
    setButtons(false);
    try {
      const res  = await fetch('ajax.php?state=1');
      const data = await res.json();
      if (data.ok && data.running) {
        jobId = data.running.id;
        offset = 0;
        t0 = Date.now() - (data.running.ms || 0);
        busy = true;
        box.classList.add('busy');
        cancel.hidden = false;
        setButtons(true);
        out.classList.remove('console-empty');
        stateEl.textContent = 'en curso: ' + data.running.action;
        timer = setInterval(function () { tick(); poll(); }, 1200);
        poll();
      }
    } catch (e) { /* sin conexión: se ignora */ }
  })();
})();

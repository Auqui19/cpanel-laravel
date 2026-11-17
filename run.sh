#!/bin/bash
# ---------------------------------------------------------------------------
# Ejecutor del panel. Lo lanza run.php de forma desacoplada (nohup + setsid).
# Uso: run.sh <id> <accion>
#
# No hay cron: este script corre una sola acción y termina.
# ---------------------------------------------------------------------------

BASE="$(cd "$(dirname "$0")" && pwd)"
DATA="$BASE/data"
JOBS="$DATA/jobs"
LOGS="$DATA/logs"
ENVF="$DATA/settings.env"
LOCK="$DATA/run.lock"

ID="${1:-}"
ACTION="${2:-}"

mkdir -p "$JOBS" "$LOGS"

if ! [[ "$ID" =~ ^[a-f0-9]{12}$ ]]; then
    echo "ID invalido" >&2
    exit 2
fi

case "$ACTION" in
    doctor|vendor|dump|key|status|migrate|seed|storage|cache|clear|view|down|up|update) ;;
    *) echo "Accion no permitida" >&2; exit 2 ;;
esac

LOG="$LOGS/$ID.log"
STATUS="$JOBS/$ID.json"

now_ms() {
    local v
    v="$(date +%s%3N 2>/dev/null)"
    if [[ "$v" =~ ^[0-9]+$ ]]; then
        echo "$v"
    else
        echo $(( $(date +%s) * 1000 ))
    fi
}

write_status() {
    local status="$1" code="$2" ms="$3" started
    started=$(( $(date +%s) - (ms / 1000) ))
    printf '{"id":"%s","action":"%s","status":"%s","exit":%s,"ms":%s,"pid":%s,"started":%s,"ended":%s}\n' \
        "$ID" "$ACTION" "$status" "$code" "$ms" "$$" "$started" "$(date +%s)" > "$STATUS"
}

# Estado inicial: permite al panel saber el PID y que sigue vivo.
write_status running 0 0

# Un solo trabajo a la vez.
exec 9>"$LOCK"
if command -v flock >/dev/null 2>&1; then
    if ! flock -n 9; then
        echo "Ya hay otro proceso en curso." >> "$LOG"
        write_status busy 0 0
        exit 5
    fi
fi

if [ ! -f "$ENVF" ]; then
    echo "ERROR: falta data/settings.env. Configura el panel primero." >> "$LOG"
    write_status error 3 0
    exit 3
fi

# settings.env lo genera el panel con escapeshellarg() tras validar cada valor.
# shellcheck disable=SC1090
source "$ENVF"

export HOME="/home/$CP_USER"
export COMPOSER_HOME="$HOME/.composer"
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:$PATH"
export LC_ALL=C

# Rotación: conservar los últimos 40 trabajos.
ls -1t "$LOGS"/*.log 2>/dev/null | tail -n +41 | xargs -r rm -f
ls -1t "$JOBS"/*.json 2>/dev/null | tail -n +41 | xargs -r rm -f

START_MS="$(now_ms)"

{
    echo "=== $(date -u '+%F %T') UTC :: $ACTION :: $ID ==="
    echo "+ usuario : $CP_USER"
    echo "+ php     : $PHP"
    echo "+ proyecto: $APP"
} >> "$LOG"

if ! cd "$APP" 2>/dev/null; then
    echo "ERROR: no existe la ruta $APP" >> "$LOG"
    write_status error 4 0
    exit 4
fi

# Todo lo que sigue va al log del trabajo.
exec >> "$LOG" 2>&1

T=120
case "$ACTION" in
    vendor)          T=900 ;;
    migrate|seed|update) T=600 ;;
esac

TO=()
if command -v timeout >/dev/null 2>&1; then
    TO=(timeout "$T")
fi

if [[ "$COMPOSER" == *.phar ]]; then
    COMP=( "$PHP" -d memory_limit=-1 "$COMPOSER" )
else
    COMP=( "$COMPOSER" )
fi

run() {
    echo "+ $*"
    "$@"
    local rc=$?
    echo "→ exit $rc"
    return $rc
}

art() {
    local t=()
    if command -v timeout >/dev/null 2>&1; then
        t=(timeout "$T")
    fi

    if [ ${#t[@]} -gt 0 ]; then
        echo "+ timeout $T $PHP artisan $*"
    else
        echo "+ $PHP artisan $*"
    fi

    "${t[@]}" "$PHP" -d display_errors=1 -d error_reporting=-1 artisan "$@"
    local rc=$?
    echo "→ exit $rc"
    return $rc
}

# Igual que art() pero sin imprimir la línea del comando (para capturar salida).
art_quiet() {
    local t=()
    if command -v timeout >/dev/null 2>&1; then
        t=(timeout "$T")
    fi
    "${t[@]}" "$PHP" -d display_errors=1 -d error_reporting=-1 artisan "$@"
}

tail_laravel() {
    if [ -f storage/logs/laravel.log ]; then
        echo "--- ultimas lineas de storage/logs/laravel.log ---"
        tail -n 20 storage/logs/laravel.log
        echo "--- fin de laravel.log ---"
    fi
}

OK_N=0
WARN_N=0
ERR_N=0
STATE_OVERRIDE=""

mark_ok()   { OK_N=$((OK_N + 1));     echo "[  OK  ]      $*"; }
mark_warn() { WARN_N=$((WARN_N + 1)); echo "[ PENDIENTE ] $*"; }
mark_err()  { ERR_N=$((ERR_N + 1));   echo "[  ERROR ]    $*"; }

RC=0

case "$ACTION" in
    doctor)
        echo "=== diagnostico ==="

        php_v="$("$PHP" -r 'echo PHP_VERSION;' 2>/dev/null)"
        if [ -n "$php_v" ]; then
            mark_ok "PHP $php_v ($PHP)"
        else
            mark_err "No se pudo ejecutar el PHP configurado: $PHP"
        fi

        composer_v="$("${COMP[@]}" --version --no-ansi 2>/dev/null | head -n 1)"
        if [ -n "$composer_v" ]; then
            mark_ok "$composer_v"
        else
            mark_err "Composer no responde: $COMPOSER"
        fi

        if [ -f composer.json ]; then mark_ok "composer.json presente"; else mark_err "Falta composer.json"; fi
        if [ -f artisan ]; then mark_ok "artisan presente"; else mark_err "Falta artisan"; fi

        if [ -f vendor/autoload.php ]; then
            vendor_ok=1
            mark_ok "vendor/ instalado"
        else
            vendor_ok=0
            mark_warn "vendor/ no instalado (usa «Instalar vendor»)"
        fi

        if [ -f .env ]; then
            env_ok=1
            mark_ok ".env presente"
        else
            env_ok=0
            mark_warn ".env no existe (subilo por Administrador de archivos)"
        fi

        if [ "$env_ok" = 1 ] && grep -q '^APP_KEY=base64' .env; then
            mark_ok "APP_KEY definida"
        else
            mark_warn "APP_KEY sin definir (usa «Generar APP_KEY»)"
        fi

        if [ "$env_ok" = 1 ] && grep -q '^DB_DATABASE=.' .env; then
            mark_ok "DB_DATABASE definida"
        else
            mark_warn "DB_DATABASE sin definir en .env"
        fi

        for d in storage storage/app storage/framework storage/logs bootstrap/cache; do
            if [ ! -d "$d" ]; then
                mark_err "Falta la carpeta $d"
            elif [ ! -w "$d" ]; then
                mark_err "Sin permiso de escritura: $d"
            else
                mark_ok "Escritura en $d"
            fi
        done

        if [ -e public/storage ]; then
            mark_ok "public/storage enlazado"
        else
            mark_warn "public/storage sin enlazar (usa «storage:link»)"
        fi

        echo "--- laravel ---"
        if [ "$vendor_ok" = 1 ]; then
            av="$(art_quiet --version 2>&1)"
            if [ $? -eq 0 ]; then
                mark_ok "artisan responde: $av"
            else
                mark_err "artisan falla: ${av:-sin salida}"
                tail_laravel
            fi

            if [ "$env_ok" = 1 ]; then
                if art migrate:status; then
                    mark_ok "migrate:status responde"
                else
                    mark_err "migrate:status fallo"
                    tail_laravel
                fi
            else
                mark_warn "migrate:status omitido: primero subi .env"
            fi
        else
            mark_warn "Comandos artisan omitidos: primero instala vendor/"
        fi

        echo
        echo "=== resumen: $OK_N OK, $WARN_N pendientes, $ERR_N errores ==="

        if [ "$ERR_N" -gt 0 ]; then
            RC=1
        elif [ "$WARN_N" -gt 0 ]; then
            RC=0
            STATE_OVERRIDE="warn"
        else
            RC=0
        fi
        ;;

    vendor)
        run "${TO[@]}" "${COMP[@]}" install --no-dev --optimize-autoloader --no-interaction --no-ansi
        RC=$?
        ;;

    dump)
        run "${TO[@]}" "${COMP[@]}" dump-autoload --optimize --no-interaction --no-ansi
        RC=$?
        ;;

    key)
        if [ ! -f .env ]; then
            echo "ERROR: no existe .env. Subilo antes de generar la clave."
            RC=1
        elif grep -q '^APP_KEY=base64' .env 2>/dev/null; then
            echo "APP_KEY ya existe: no se modifica."
        else
            art key:generate --force --no-ansi
            RC=$?
        fi
        ;;

    status)
        art migrate:status --no-ansi
        RC=$?
        ;;

    migrate)
        art migrate --force --no-ansi
        RC=$?
        ;;

    seed)
        art db:seed --force --no-ansi
        RC=$?
        ;;

    storage)
        art storage:link --no-ansi
        RC=$?
        ;;

    cache)
        RC=0
        art config:cache --no-ansi; r=$?; [ "$r" -ne 0 ] && RC=$r
        art route:cache --no-ansi;  r=$?; [ "$r" -ne 0 ] && RC=$r
        art view:cache --no-ansi;   r=$?; [ "$r" -ne 0 ] && RC=$r
        ;;

    clear)
        art optimize:clear --no-ansi
        RC=$?
        ;;

    view)
        art view:clear --no-ansi
        RC=$?
        ;;

    down)
        art down --retry=60 --no-ansi
        RC=$?
        ;;

    up)
        art up --no-ansi
        RC=$?
        ;;

    update)
        RC=0
        run "${TO[@]}" "${COMP[@]}" install --no-dev --optimize-autoloader --no-interaction --no-ansi; r=$?; [ "$r" -ne 0 ] && RC=$r
        art migrate --force --no-ansi; r=$?; [ "$r" -ne 0 ] && RC=$r
        art optimize:clear --no-ansi; r=$?; [ "$r" -ne 0 ] && RC=$r
        ;;
esac

END_MS="$(now_ms)"
MS=$(( END_MS - START_MS ))
[ "$MS" -lt 0 ] && MS=0

# Si falló algo, mostrar el error real de Laravel (el doctor ya lo hace).
if [ "$RC" -ne 0 ] && [ "$ACTION" != "doctor" ]; then
    tail_laravel
fi

case "$RC" in
    0)   STATE="done" ;;
    124) STATE="timeout" ;;
    *)   STATE="error" ;;
esac

if [ -n "$STATE_OVERRIDE" ]; then
    STATE="$STATE_OVERRIDE"
fi

echo "=== fin $ACTION :: exit=$RC :: ${MS}ms ==="

write_status "$STATE" "$RC" "$MS"
exit "$RC"

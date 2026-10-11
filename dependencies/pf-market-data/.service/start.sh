#!/bin/sh
# Entrypoint de pf-market-data. Los ENV del Dockerfile no viajan al runtime: se fijan aquí.
# Como root: escribe el resolver DNS (bajo nodo no hay) y baja a uid 10001 con
# --no-new-privs. Sin root (docker run --user): arranca directamente.
set -eu

export PATH=/usr/local/bin:/usr/bin:/bin
APP=/opt/pf-market-data
PY=/usr/local/bin/python3
SERVICE_UID=10001
SERVICE_GID=10001

log() {
    printf '[pf-market-data] %s\n' "$1" >&2
}

# -E -s: ninguna variable PYTHON* ni site de usuario cambia lo que se ejecuta.
# -u: logs sin buffer. -B: sin escribir bytecode.
if [ "$(id -u)" -eq 0 ]; then
    if ! command -v setpriv >/dev/null 2>&1; then
        log "FATAL: no hay setpriv en la imagen; no se pueden bajar privilegios"
        exit 2
    fi
    if ! "$PY" -E -s -B "$APP/resolver.py"; then
        log "FATAL: no se pudo configurar la resolución DNS"
        exit 2
    fi
    # Solo /data: nunca se hace chown de una ruta que venga del entorno.
    mkdir -p /data
    chown "$SERVICE_UID:$SERVICE_GID" /data
    log "bajando a uid $SERVICE_UID con --no-new-privs"
    exec setpriv --reuid "$SERVICE_UID" --regid "$SERVICE_GID" --clear-groups --no-new-privs \
        "$PY" -E -s -u -B "$APP/server.py"
fi

log "ya sin privilegios (uid $(id -u)), arrancando"
exec "$PY" -E -s -u -B "$APP/server.py"

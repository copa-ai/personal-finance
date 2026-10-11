# pf-market-data — pasarela de datos de mercado públicos para personal-finance.
# Solo biblioteca estándar de Python: no hay pip install. Solo se exporta el
# filesystem; CMD/ENTRYPOINT/ENV se ignoran (ver service.json → init y envs).
FROM python:3.12.11-slim-bookworm

# setpriv (util-linux) baja privilegios en start.sh: se comprueba aquí para que
# falle el build y no el arranque. /data guarda la caché SQLite.
RUN command -v setpriv >/dev/null \
    && groupadd --system --gid 10001 pfmd \
    && useradd --system --uid 10001 --gid 10001 --no-create-home --shell /usr/sbin/nologin pfmd \
    && mkdir -p /data \
    && chown 10001:10001 /data

# `./service` lo reescribe el packer a `service/service` (código del proyecto);
# start.sh se toma del propio .service/, como en pf-postgres y pf-ollama.
COPY ./service /opt/pf-market-data
COPY start.sh /usr/local/bin/pf-market-data-start

RUN chmod +x /usr/local/bin/pf-market-data-start \
    && find /opt/pf-market-data -name '__pycache__' -prune -exec rm -rf {} + \
    && python3 -m compileall -q /opt/pf-market-data

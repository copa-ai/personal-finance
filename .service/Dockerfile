# Servicio Celaut de Personal Finance: UN solo microVM con todo lo que docker-compose.yml reparte en contenedores
# (web + colas + agente + traductor del chat + reenvio SSH al Coder). Aqui el Dockerfile solo construye el sistema de ficheros: CMD/ENTRYPOINT/EXPOSE se
# ignoran; el arranque es service.json -> init.entry_path (/opt/finance/start.sh) y los puertos, service.json -> api.
# Los COPY de origen del proyecto llevan «./»: el empaquetador los reescribe a service/...

# --- Dependencias de PHP (sin dev: ni pail ni phpunit ni sail) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY ./composer.json ./composer.lock /app/
COPY ./app ./app
COPY ./database ./database
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader --ignore-platform-reqs

# --- Stubs gRPC del chat: se generan aqui con grpcio-tools; el runtime solo lleva grpcio y protobuf ---
# Versiones fijadas y compatibles entre si (grpcio-tools 1.76 exige protobuf >= 6.31.1, < 7). Los stubs generados con
# ellas piden protobuf >= 6.31.1 en tiempo de ejecucion: es la misma version que se instala en la imagen final.
FROM python:3.11-slim-bookworm AS stubs
RUN pip install --no-cache-dir grpcio-tools==1.76.0 protobuf==6.31.1
COPY ./docker/chat /src/chat
RUN sed -i 's/\r$//' /src/chat/generar.sh && mkdir /stubs && bash /src/chat/generar.sh /stubs

# --- Binarios que se copian tal cual ---
FROM node:22-bookworm-slim AS node

# --- Imagen final ---
# FrankenPHP = Caddy con PHP (ZTS) embebido: un solo proceso web, sin php-fpm ni artisan serve.
FROM dunglas/frankenphp:1-php8.4-bookworm

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions intl zip bcmath gd pcntl opcache pdo_pgsql pgsql

RUN apt-get update && apt-get install -y --no-install-recommends \
        bash ca-certificates curl python3 python3-venv python-is-python3 util-linux \
    && rm -rf /var/lib/apt/lists/*

# Node solo para Claude Code (npm install -g @anthropic-ai/claude-code).
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && npm install -g @anthropic-ai/claude-code \
    && npm cache clean --force

# `celaut`: la service-library de Celaut (celaut-project/service-library) como binario estatico. Laravel habla con el nodo con el
# (App\Services\Celaut\Nodo): lee las redes resueltas, lanza pf-postgres si no hay base, lanza el servicio OCR subido como .bee y los
# para al terminar.
ARG CELAUT_VERSION=v0.6.3
ARG CELAUT_SHA256=4ba4affc7abf4c4ec3d73cb466ea8a68e7ecb26606350e21f40c0a6383ef7368
ADD https://github.com/celaut-project/service-library/releases/download/${CELAUT_VERSION}/celaut-linux-arm64 /usr/local/bin/celaut
RUN echo "${CELAUT_SHA256}  /usr/local/bin/celaut" | sha256sum -c - && chmod 755 /usr/local/bin/celaut

# Dependencia Celaut empaquetada por el packer (pf-postgres) y su hash (.dependencies).
COPY ./__services__ /opt/finance/celaut/__services__
COPY ./__metadata__ /opt/finance/celaut/__metadata__
COPY ./__block__ /opt/finance/celaut/__block__
COPY ./.dependencies /opt/finance/celaut/.dependencies

# Un usuario por pieza: lo que el agente (que ejecuta Bash) puede leer no incluye ni la base ni APP_KEY.
RUN groupadd -g 1000 app     && useradd -m -u 1000 -g 1000 -s /bin/bash app \
    && groupadd -g 1001 agente && useradd -m -u 1001 -g 1001 -s /bin/bash agente \
    && groupadd -g 1002 chat   && useradd -m -u 1002 -g 1002 -s /bin/bash chat \
    && groupadd -g 1003 sshproxy && useradd -m -u 1003 -g 1003 -s /usr/sbin/nologin sshproxy

# Traductor gRPC del chat (docker/chat; usuario chat, entorno vacio, sin datos propios): venv con grpcio y protobuf (mismas versiones
# que los stubs de la etapa «stubs»; grpcio-tools no hace falta en tiempo de ejecucion) y stubs generados junto al servidor.
RUN python3 -m venv /opt/chat-venv \
    && /opt/chat-venv/bin/pip install --no-cache-dir grpcio==1.76.0 protobuf==6.31.1 \
    && chmod -R a+rX /opt/chat-venv
COPY ./docker/chat /opt/chat
COPY --from=stubs /stubs/ /opt/chat/
RUN sed -i 's/\r$//' /opt/chat/*.py /opt/chat/*.sh 2>/dev/null; chmod -R a+rX /opt/chat

# Proyecto completo en /opt/finance/project: es el directorio que el servicio comparte (service.json -> shared_filesystems, tag «project»).
# Un servicio Coder hijo lo monta en lectura y escritura y cambia el codigo de Laravel sin reempaquetar. Laravel esta en la raiz del proyecto:
# la web y la cola corren desde /opt/finance/project. Lo que escribe la app (storage, caches) vive fuera, en /var/lib/finance/admin.
# El arranque (start.sh, Caddyfile, bucle.sh, estado) y el kit del agente NO se leen del directorio compartido: corren como root o como
# otro usuario y el Coder no debe poder cambiarlos. Por eso se copian tambien mas abajo, fuera de /opt/finance/project.
# Los COPY llevan «./»: el empaquetador los reescribe a service/... Cada ruta debe estar en .service/pack_config.json -> include.
# Sin .git ni node_modules (pack_config ignore y .service/.dockerignore); los tests se quedan.
COPY ./app /opt/finance/project/app
COPY ./bootstrap /opt/finance/project/bootstrap
COPY ./config /opt/finance/project/config
COPY ./database /opt/finance/project/database
COPY ./public /opt/finance/project/public
COPY ./resources /opt/finance/project/resources
COPY ./routes /opt/finance/project/routes
COPY ./tests /opt/finance/project/tests
COPY ./artisan /opt/finance/project/artisan
COPY ./composer.json ./composer.lock ./phpunit.xml ./package.json ./package-lock.json ./vite.config.js ./AGENT.md ./README.md /opt/finance/project/
COPY --from=vendor /app/vendor /opt/finance/project/vendor
COPY ./agente /opt/finance/project/agente
COPY ./docker /opt/finance/project/docker
COPY ./.service /opt/finance/project/.service
COPY ./dependencies /opt/finance/project/dependencies
COPY ./docs /opt/finance/project/docs
COPY ./services /opt/finance/project/services
RUN cd /opt/finance/project \
    && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
       LARAVEL_STORAGE_PATH=/tmp/storage-build \
       sh -c 'mkdir -p /tmp/storage-build/framework/views /tmp/storage-build/framework/cache /tmp/storage-build/logs \
              && php artisan package:discover --ansi && php artisan filament:assets --ansi' \
    && rm -rf /tmp/storage-build /opt/finance/project/bootstrap/cache/*.php.tmp

# PHP: las subidas de tickets son fotos, pero los paquetes .celaut.bee del servicio OCR y del Coder pueden pesar cientos de MB (el Coder:
# config/coder.php, 512 MB; ambos >= 512M aqui). max_execution_time alto: lanzar un servicio hijo desde el panel tarda minutos.
RUN printf 'file_uploads=On\nupload_max_filesize=1024M\npost_max_size=1030M\nmax_file_uploads=20\nmemory_limit=512M\nmax_execution_time=1800\nopcache.enable=1\nopcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/zz-finance.ini

# Kit del agente + runner: las mismas rutas que en personal-nutrition, porque se reutiliza su entrypoint.
COPY ./agente /opt/agente-kit
RUN mkdir -p /opt/agente-kit/.claude \
    && echo '{"fecha": "imagen-celaut", "sistema": "Linux (microVM Celaut)", "python": "python", "persona": "Personal Finance"}' \
        > /opt/agente-kit/.claude/setup-completado.json
COPY ./docker/agente/runner.py /opt/runner/runner.py
COPY ./docker/agente/entrypoint.sh /opt/runner/entrypoint.sh
COPY ./docker/servicio/start.sh /opt/finance/start.sh
COPY ./docker/servicio/Caddyfile /opt/finance/Caddyfile
COPY ./docker/servicio/estado /opt/finance/estado
COPY ./docker/servicio/bucle.sh /opt/finance/bucle.sh
COPY ./docker/servicio/recarga.sh /opt/finance/recarga.sh
# SSH forwarder (user sshproxy). It is outside the shared project, so a Coder child cannot change it.
COPY ./docker/servicio/ssh-proxy.py /opt/finance/ssh-proxy.py
RUN sed -i 's/\r$//' /opt/finance/ssh-proxy.py /opt/runner/entrypoint.sh /opt/runner/runner.py /opt/finance/start.sh /opt/finance/bucle.sh /opt/finance/recarga.sh /opt/finance/estado/servidor.py \
    && chmod 755 /opt/runner/entrypoint.sh /opt/finance/start.sh /opt/finance/bucle.sh /opt/finance/recarga.sh \
    && chmod 755 /opt/finance/ssh-proxy.py \
    && mkdir -p /var/lib/finance

# Copia original del proyecto, FUERA del directorio compartido (el montaje oculta el contenido de la imagen en esa ruta). Se hace al final,
# con el arbol ya completo y legible para todos. «Restaurar original» la extrae sobre el directorio compartido. Debe ser el ULTIMO paso.
RUN chmod -R a+rX /opt/finance/project \
    && tar -C /opt/finance/project -czf /opt/finance/project.orig.tar.gz . \
    && chmod 644 /opt/finance/project.orig.tar.gz

# Los datos locales (sesion de Claude, estado del runner y del pool de celaut) viven en /var/lib/finance, creado y repartido por start.sh.
# Todo lo demas (datos, cache, sesiones, cola y archivos del agente) vive en la PostgreSQL compartida.

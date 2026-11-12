#!/usr/bin/env bash
# Genera los stubs de Python de TODOS los .proto de esta carpeta (chat_pb2*, y los que se anadan, como cards_pb2*) en un directorio.
# Uso: ./generar.sh [directorio_destino]   (por defecto: ./generado)
# Los stubs no se versionan.
set -euo pipefail

aqui="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
destino="${1:-$aqui/generado}"
mkdir -p "$destino"

protos=("$aqui"/*.proto)
[ -e "${protos[0]}" ] || { echo "No hay ficheros .proto en $aqui" >&2; exit 1; }

python3 -m grpc_tools.protoc \
    -I "$aqui" \
    --python_out="$destino" \
    --grpc_python_out="$destino" \
    "${protos[@]}"

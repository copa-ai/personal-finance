#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Uso: $0 <ticket_hash>" >&2
  exit 1
fi

TICKET_HASH="$1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [[ -f "$TICKET_HASH" ]]; then
  TICKET_PATH="$TICKET_HASH"
else
  TICKET_PATH="${PROJECT_ROOT}/storage/app/private/${TICKET_HASH}"
fi

if [[ ! -f "$TICKET_PATH" ]]; then
  echo "No se encontró el ticket: ${TICKET_HASH}" >&2
  exit 1
fi

OCR_URL="${OCR_OLLAMA_URL:-http://172.17.0.1:11434/api/generate}"
OCR_MODEL="${OCR_OLLAMA_MODEL:-glm-ocr}"
OCR_PROMPT='Analiza la imagen del ticket y extrae todas las líneas de productos o servicios. Devuelve únicamente JSON con items.'

IMG="$(base64 -w 0 "$TICKET_PATH")"

TMP_PAYLOAD=$(mktemp)

printf '{"model":"%s","prompt":"%s","images":["%s"],"stream":false}' \
  "$OCR_MODEL" "$OCR_PROMPT" "$IMG" > "$TMP_PAYLOAD"

# 4. Enviamos el archivo temporal con curl usando -d @
curl -s "$OCR_URL" \
  -H "Content-Type: application/json" \
  --data-binary @"$TMP_PAYLOAD"

# 5. Limpieza
rm "$TMP_PAYLOAD"
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
OCR_PROMPT='''
Analyze the receipt image and extract all product or service line items.

Return ONLY valid JSON (no explanations, no extra text, no markdown) with this exact structure:

{
  "items": [
    {
      "concept": string,
      "quantity": number,
      "unit_price": number
    }
  ]
}

Mandatory rules:

- "concept": clean, human-readable product name.

- "quantity":
  - MUST always be present.
  - If not visible on the receipt, use 1.
  - Must be a number greater than 0.

- "unit_price":
  - Price per unit.
  - If only total price is visible, use that value.
  - Must be a number greater than or equal to 0.
  - Use dot as decimal separator (e.g., 15.95).

- Ignore:
  - Totals, subtotals, taxes, global discounts.
  - Any lines that are not actual products or services.

- Do NOT include extra fields (no "currency", "unit", etc).

- Do NOT return null values.

- Ensure all numbers are properly formatted.

- Each item must be independent (do not group products).

- Correct common OCR errors in product names when possible.

- If no products are found, return:
  {
    "items": []
  }

IMPORTANT:
The response must be raw JSON only. Do NOT wrap it in ```json or any code block.
'''

# 1. Creamos un archivo temporal para la imagen con extensión .png
TMP_IMG=$(mktemp --suffix=.png)

# 2. Redimensionamos a 1024x1024 y rellenamos el sobrante con blanco
# Dependiendo de tu versión de ImageMagick, el comando es 'convert' (v6) o 'magick' (v7)
convert "$TICKET_PATH" \
  -resize 1024x1024 \
  -background white \
  -gravity center \
  -extent 1024x1024 \
  "$TMP_IMG"

# 3. Convertimos la imagen TEMPORAL a Base64
IMG="$(base64 -w 0 "$TMP_IMG")"
# ------------------------------------------------------

# Creamos un archivo temporal para el payload JSON
TMP_PAYLOAD=$(mktemp)

printf '{"model":"%s","prompt":"%s","images":["%s"],"stream":false}' \
  "$OCR_MODEL" "$OCR_PROMPT" "$IMG" > "$TMP_PAYLOAD"

# Enviamos el archivo temporal con curl usando -d @
curl -s "$OCR_URL" \
  -H "Content-Type: application/json" \
  --data-binary @"$TMP_PAYLOAD"

# Limpieza: Borramos el payload JSON y la imagen temporal
rm -f "$TMP_PAYLOAD" "$TMP_IMG"
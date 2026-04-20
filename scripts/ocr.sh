#!/usr/bin/env bash

set -uo pipefail

fail() {
  printf '%s\n' "$1" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "Falta el comando requerido: $1"
}

if [ "$#" -lt 1 ]; then
  fail "Uso: $0 /ruta/absoluta/al/ticket.jpg"
fi

IMAGE_PATH="$1"

[ -f "$IMAGE_PATH" ] || fail "El fichero de imagen no existe: $IMAGE_PATH"
[ -r "$IMAGE_PATH" ] || fail "El fichero de imagen no es legible: $IMAGE_PATH"

require_command base64
require_command curl
require_command python3

OLLAMA_URL="${OCR_OLLAMA_URL:-http://172.17.0.1:11434/api/generate}"
OLLAMA_MODEL="${OCR_OLLAMA_MODEL:-glm-ocr}"
PROMPT='Analiza la imagen del ticket y extrae todas las líneas de productos o servicios. Devuelve únicamente JSON con items.'

IMAGE_BASE64="$(base64 -w 0 "$IMAGE_PATH")" || fail "No se pudo generar el base64 de la imagen."
[ -n "$IMAGE_BASE64" ] || fail "El base64 de la imagen está vacío."

PAYLOAD="$(python3 - "$OLLAMA_MODEL" "$PROMPT" "$IMAGE_BASE64" <<'PY'
import json
import sys

model, prompt, image_base64 = sys.argv[1:4]
print(json.dumps({
    "model": model,
    "prompt": prompt,
    "images": [image_base64],
    "stream": False,
}, ensure_ascii=False))
PY
)" || fail "No se pudo construir el payload JSON para Ollama."

STDERR_FILE="$(mktemp)"
trap 'rm -f "$STDERR_FILE"' EXIT

RESPONSE="$(
  curl "$OLLAMA_URL" \
    -sS \
    -H "Content-Type: application/json" \
    -H "Expect:" \
    --data "$PAYLOAD" \
    2>"$STDERR_FILE"
)"
CURL_EXIT_CODE=$?

if [ "$CURL_EXIT_CODE" -ne 0 ]; then
  fail "$(cat "$STDERR_FILE")"
fi

[ -n "$RESPONSE" ] || fail "Ollama no devolvió contenido."

python3 - "$RESPONSE" <<'PY'
import json
import re
import sys
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP


def fail(message: str) -> None:
    print(message, file=sys.stderr)
    raise SystemExit(1)


def strip_fenced_json(content: str) -> str:
    content = content.strip()
    fenced = re.search(r"```(?:json)?\s*(\{.*\})\s*```", content, flags=re.S)
    if fenced:
        return fenced.group(1).strip()
    return content


def extract_first_json_object(content):
    start = content.find("{")
    if start == -1:
        return None

    depth = 0
    in_string = False
    escaped = False

    for index in range(start, len(content)):
        char = content[index]

        if in_string:
            if escaped:
                escaped = False
                continue
            if char == "\\":
                escaped = True
                continue
            if char == '"':
                in_string = False
            continue

        if char == '"':
            in_string = True
            continue

        if char == "{":
            depth += 1
            continue

        if char == "}":
            depth -= 1
            if depth == 0:
                return content[start:index + 1]

    return None


def decode_ollama_response(raw_response):
    try:
        payload = json.loads(raw_response)
    except json.JSONDecodeError as exc:
        fail(f"La respuesta de Ollama no es JSON válido: {exc}")

    if not isinstance(payload, dict):
        fail("La respuesta de Ollama no tiene el formato esperado.")

    content = payload.get("response")
    if not isinstance(content, str) or not content.strip():
        fail("La respuesta de Ollama no contiene el campo response con contenido.")

    content = strip_fenced_json(content)

    try:
        decoded = json.loads(content)
    except json.JSONDecodeError:
        extracted = extract_first_json_object(content)
        if extracted is None:
            fail("No se pudo extraer un JSON válido del contenido OCR.")
        try:
            decoded = json.loads(extracted)
        except json.JSONDecodeError as exc:
            fail(f"El contenido OCR no es JSON válido: {exc}")

    if not isinstance(decoded, dict):
        fail("El contenido OCR no devolvió un objeto JSON.")

    return decoded


def coerce_decimal(raw_value, *, places, allow_zero):
    if raw_value is None:
        return None

    if isinstance(raw_value, (int, float)):
        text = str(raw_value)
    else:
        text = str(raw_value).strip()

    if not text:
        return None

    text = text.replace("\xa0", " ").strip()
    matches = re.findall(r"-?\d[\d.,]*", text)
    if not matches:
        return None

    numeric = matches[-1]

    if "," in numeric and "." in numeric:
        if numeric.rfind(",") > numeric.rfind("."):
            numeric = numeric.replace(".", "").replace(",", ".")
        else:
            numeric = numeric.replace(",", "")
    elif "," in numeric:
        numeric = numeric.replace(".", "").replace(",", ".")
    elif numeric.count(".") > 1:
        head, tail = numeric.rsplit(".", 1)
        numeric = head.replace(".", "") + "." + tail

    try:
        decimal_value = Decimal(numeric)
    except InvalidOperation:
        return None

    if decimal_value < 0:
        return None

    if not allow_zero and decimal_value <= 0:
        return None

    return format(decimal_value.quantize(Decimal(places), rounding=ROUND_HALF_UP), "f")


def normalize_items(decoded):
    raw_items = decoded.get("items")
    if not isinstance(raw_items, list):
        fail('El OCR no devolvió la clave "items" con una lista válida.')

    normalized = []

    for item in raw_items:
        if not isinstance(item, dict):
            continue

        concept = str(item.get("concept") or item.get("name") or "").strip()
        if not concept:
            continue

        quantity = coerce_decimal(item.get("quantity"), places="0.001", allow_zero=False)

        raw_unit_price = item.get("unit_price")
        if raw_unit_price in (None, ""):
            raw_unit_price = item.get("price")

        unit_price = coerce_decimal(raw_unit_price, places="0.01", allow_zero=True)

        if quantity is None or unit_price is None:
            continue

        normalized.append({
            "concept": concept[:255],
            "quantity": quantity,
            "unit_price": unit_price,
        })

    if not normalized:
        fail("El OCR no devolvió ninguna línea válida tras la normalización.")

    return normalized


decoded = decode_ollama_response(sys.argv[1])
normalized_items = normalize_items(decoded)
print(json.dumps({"items": normalized_items}, ensure_ascii=False))
PY

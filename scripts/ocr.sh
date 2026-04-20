#!/usr/bin/env bash

IMG=$(base64 -w 0 storage/app/private/tickets/01KKV2B25VQ0KB40TVT5TE4N5X.jpg)

curl http://172.17.0.1:11434/api/generate \
  -H "Content-Type: application/json" \
  -H "Expect:" \
  -d "{
    \"model\": \"glm-ocr\",
    \"prompt\": \"Analiza la imagen del ticket y extrae todas las líneas de productos o servicios. Devuelve únicamente JSON con items.\",
    \"images\": [\"$IMG\"],
    \"stream\": false
  }"

#!/bin/sh
# Entrypoint de pf-ollama. Los ENV del Dockerfile no viajan al runtime: se fijan aquí.
set -eu

export HOME=/root
export OLLAMA_MODELS=/opt/ollama/models
export OLLAMA_HOST=0.0.0.0:11434
export OLLAMA_KEEP_ALIVE="${OLLAMA_KEEP_ALIVE:-30m}"

exec ollama serve

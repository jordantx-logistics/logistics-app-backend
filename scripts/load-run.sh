#!/usr/bin/env sh
# Lanza k6 dentro de la red del stack de escala. Ejemplo: FACTOR=3 DURATION=5m scripts/load-run.sh
set -eu

MSYS_NO_PATHCONV=1 docker run --rm \
  --network logistics-scale_logistics-network \
  -v "$(pwd -W 2>/dev/null || pwd)/load:/load" -w /load \
  -e FACTOR="${FACTOR:-1}" -e DURATION="${DURATION:-3m}" -e ONLY="${ONLY:-}" \
  grafana/k6:2.3.0 run --quiet --no-usage-report phase9.js

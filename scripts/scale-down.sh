#!/usr/bin/env sh
# Apaga el stack de escala y borra sus volúmenes.
set -eu

docker compose -f docker-compose.yml -f docker-compose.scale.yml down -v --remove-orphans

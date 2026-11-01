#!/usr/bin/env sh
# Borra el stack aislado de los E2E, con sus volúmenes.
set -eu

docker compose -f docker-compose.yml -f docker-compose.e2e.yml --profile horizon down -v

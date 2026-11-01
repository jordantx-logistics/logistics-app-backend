#!/usr/bin/env sh
# Levanta el stack aislado de los E2E en http://localhost:8100, con datos limpios.
set -eu

compose="docker compose -f docker-compose.yml -f docker-compose.e2e.yml --profile horizon"

# Siempre desde cero: los flujos asumen una base recién sembrada.
$compose down -v --remove-orphans
$compose up -d --wait mysql redis
$compose up -d --wait app nginx
# Sin `--wait`: el scheduler no tiene healthcheck y el de Horizon tarda en responder
# bajo Docker Desktop. Si un worker no arranca, lo detectan los propios flujos.
$compose up -d horizon scheduler mailpit
$compose exec -T app php artisan migrate:fresh --seed --force
$compose exec -T app php artisan db:seed --class='Database\Seeders\E2eSeeder' --force
$compose exec -T app php artisan octane:reload

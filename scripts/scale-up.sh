#!/usr/bin/env sh
# Levanta el stack de escala en http://localhost:8200: primary, réplica y ProxySQL, con la
# imagen de producción. Las comprobaciones y seeders corren en el servicio `tools`.
set -eu

compose="docker compose -f docker-compose.yml -f docker-compose.scale.yml"

docker build --target production -t "${SCALE_IMAGE:-logistics-app-backend:production}" .
docker compose build app

$compose down -v --remove-orphans
$compose up -d --wait mysql redis
$compose up -d --wait mysql-replica
$compose exec -T mysql-replica sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < docker/mysql/replica-start.sql
$compose up -d proxysql
$compose run --rm --no-deps tools php artisan migrate:fresh --seed --force
$compose up -d --wait app nginx
$compose up -d horizon scheduler outbox

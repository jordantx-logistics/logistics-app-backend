#!/bin/sh
set -e

# Si se pasan argumentos explícitos, se ejecutan tal cual.
# Ej.: `docker compose run --rm app php artisan test`
if [ "$#" -gt 0 ]; then
  exec "$@"
fi

case "${APP_ROLE:-web}" in
  web)
    exec php artisan octane:start \
      --server=frankenphp \
      --host=0.0.0.0 \
      --port=8000 \
      --workers="${OCTANE_WORKERS:-auto}" \
      --max-requests="${OCTANE_MAX_REQUESTS:-500}"
    ;;
  horizon)
    exec php artisan horizon
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  *)
    echo "APP_ROLE desconocido: ${APP_ROLE}" >&2
    exit 1
    ;;
esac

#!/bin/sh
set -e

# Si se pasan argumentos explícitos, se ejecutan tal cual.
# Ej.: `docker compose run --rm app php artisan test`
if [ "$#" -gt 0 ]; then
  exec "$@"
fi

case "${APP_ROLE:-web}" in
  web)
    # `--log-level` hace que Octane imprima en crudo el stderr del proceso
    # FrankenPHP. Sin esa opción Octane interpreta cada línea como log JSON de
    # Caddy y las líneas JSON de la app salen como "ERROR unknown error".
    # El nivel solo afecta a Caddy (los logs de la app van aparte, por LOG_LEVEL).
    exec php artisan octane:start \
      --server=frankenphp \
      --host=0.0.0.0 \
      --port=8000 \
      --workers="${OCTANE_WORKERS:-auto}" \
      --max-requests="${OCTANE_MAX_REQUESTS:-500}" \
      --log-level="${OCTANE_LOG_LEVEL:-warn}"
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

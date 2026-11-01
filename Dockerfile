# syntax=docker/dockerfile:1.7

############################################################
# Imagen del backend (Octane + FrankenPHP)
#
# Una sola imagen sirve a los roles: web (Octane), horizon, scheduler y outbox.
# El rol se elige con APP_ROLE (ver docker/entrypoint.sh).
#
# La última etapa es la de desarrollo, la que construye `docker compose build`.
# Producción: `docker build --target production`.
############################################################
FROM dunglas/frankenphp:1-php8.3-alpine AS base

RUN install-php-extensions \
      pdo_mysql \
      bcmath \
      exif \
      gd \
      intl \
      pcntl \
      redis \
      zip

# Composer (la imagen base no lo trae)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php/app.ini /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /var/www

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

############################################################
# Producción: código y dependencias dentro de la imagen, sin bind mount,
# sin dependencias de desarrollo y sin root.
############################################################
FROM base AS production

COPY docker/php/opcache.production.ini /usr/local/etc/php/conf.d/99-opcache.ini

COPY . /var/www

# Octane crea el script del worker al arrancar si no existe, y aquí no podría: `public/`
# no es del usuario de la imagen. La capability de la imagen base (abrir puertos bajos, que
# aquí no se usan) impide ejecutar el binario en un pod con `allowPrivilegeEscalation: false`.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts \
    && rm /usr/local/bin/composer \
    && setcap -r /usr/local/bin/frankenphp \
    && cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app bootstrap/cache /data/caddy /config/caddy \
    && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan package:discover --ansi \
    && chown -R 10001:10001 storage bootstrap/cache /data/caddy /config/caddy

ENV APP_ENV=production \
    APP_DEBUG=false \
    CACHE_APP_ON_BOOT=true \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

USER 10001:10001

# El de la imagen base consulta la administración de Caddy, que aquí no se usa. Cada
# orquestador define su propia sonda (`k8s/base/web.yaml`).
HEALTHCHECK NONE

############################################################
# Desarrollo: el código llega por bind mount.
# pdo_sqlite y pcov son para la suite de tests (phpunit.xml) y la cobertura.
############################################################
FROM base AS development

RUN install-php-extensions pdo_sqlite pcov

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini

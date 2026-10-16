# syntax=docker/dockerfile:1.7

############################################################
# Imagen de desarrollo / runtime del backend (Octane + FrankenPHP)
#
# Una sola imagen sirve a los roles: web (Octane), horizon y scheduler.
# El rol se elige con APP_ROLE (ver docker/entrypoint.sh).
############################################################
FROM dunglas/frankenphp:1-php8.3-alpine

# Extensiones de la aplicación.
# pdo_sqlite se incluye porque la suite de tests usa SQLite en memoria (phpunit.xml).
RUN install-php-extensions \
      pdo_mysql \
      pdo_sqlite \
      bcmath \
      exif \
      gd \
      intl \
      pcntl \
      redis \
      zip

# Composer (la imagen base no lo trae)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/app.ini     /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /var/www

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

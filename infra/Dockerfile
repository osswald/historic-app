# syntax=docker/dockerfile:1

FROM node:22-alpine AS frontend
WORKDIR /src
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM dunglas/frankenphp:1-php8.4
WORKDIR /app

RUN install-php-extensions pdo_pgsql intl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY backend/composer.json backend/composer.lock ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --no-scripts

COPY backend/ ./
COPY --from=frontend /src/dist /srv/frontend
COPY infra/Caddyfile /etc/caddy/Caddyfile
COPY infra/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && mkdir -p var/cache var/log var/share

EXPOSE 80 443
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

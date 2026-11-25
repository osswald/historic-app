#!/bin/sh
set -eu

cd /app
mkdir -p var/cache var/log var/share

attempt=0
until php bin/console doctrine:migrations:migrate --no-interaction; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 30 ]; then
    echo "Database migrations did not succeed." >&2
    exit 1
  fi
  sleep 2
done

php bin/console cache:warmup --no-interaction
exec docker-php-entrypoint frankenphp run --config /etc/caddy/Caddyfile

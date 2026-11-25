# Vereinsplattform Historic Winterthur

Entwicklungsstand der Kernplattform: Anmeldung, Veranstaltungen, Tagesantworten und die Startseite nach der Designstudie v2.

## Start

Die Datenbank läuft lokal auf Port **5433**, damit ein bereits belegter Postgres auf 5432 nicht gestört wird.

```bash
docker compose -f infra/compose.test.yaml up -d
cd backend
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed-demo
php -S 127.0.0.1:8000 -t public public/router.php
```

In einem zweiten Terminal:

```bash
cd frontend
npm run dev
```

Die Oberfläche liegt auf http://127.0.0.1:5173. «Als Demomitglied weiter» führt in die Plattform. Auth0 übernimmt die Anmeldung, sobald `AUTH0_DOMAIN` und `AUTH0_AUDIENCE` gesetzt sind. Auf https://app.historic-winterthur.ch bleibt die Demo-Anmeldung vorerst an (`APP_ENV=prod`, `AUTH_DEV_MODE=1`) und wird danach auf `0` gestellt.

## Tests

```bash
cd backend && composer test
cd frontend && npm test
```

Die Backend-Tests legen die Datenbank `app_test` an und lassen die Demo-Datenbank `app` unangetastet. Lokal ist Postgres auf Port 5433. CI nutzt einen Postgres-16-Service.

## Deploy

`.github/workflows/ci.yml` testet bei Push und Pull Request. `.github/workflows/deploy.yml` startet nur nach grünem CI auf `main` und spielt per SSH und Docker Compose auf https://app.historic-winterthur.ch aus. Die Testinstanz in der Infomaniak Public Cloud (OpenStack, Ubuntu LTS, Docker) läuft. Die Floating IP ist `195.15.196.156`. DNS A/AAAA von `app.historic-winterthur.ch` muss auf diese Floating IP zeigen.

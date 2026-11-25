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

Die Oberfläche liegt auf http://127.0.0.1:5173. In der Entwicklungsumgebung führt «Als Demomitglied weiter» in die Plattform. Auth0 übernimmt die Anmeldung, sobald `AUTH0_DOMAIN` und `AUTH0_AUDIENCE` im Backend gesetzt sind. `AUTH_DEV_MODE` bleibt in Produktion aus.

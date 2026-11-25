# Proposal

## Why

The bot runs only on the development machine through a bind-mounted Docker Compose stack. There is no continuous integration, no production image, and no way to get a tested build onto the QNAP NAS. A green push should become a running, verified deployment without exposing the NAS to inbound traffic.

## What Changes

- Add a GitHub Actions workflow that runs the PHP test suite on every pull request and push to `main`, then builds and publishes production images only after the tests pass.
- Publish three private GHCR images per green `main` commit: the PHP application image, a matching Nginx image, and the Go engine image. Tag all three with the full commit SHA and `latest`.
- Add a production Docker build stage that runs `composer install` and the frontend build inside the image, so a clean checkout has no dependency on host `vendor/` or `public/build/`.
- Add a production Compose stack with no source bind mounts, persistent named volumes, and only Nginx exposed.
- Gate migrations and `storage:link` to the `app` service so Horizon and the scheduler never migrate concurrently.
- Add an outbound-only NAS deploy script that polls GHCR, deploys the desired image, waits for all seven services to become healthy, holds a failed image, and rolls back the application images on a health failure.
- Add persistent deploy state on the NAS for the desired tag, the last known good tag, and a failed-image hold, so manual pins survive cron ticks and a bad build does not flap every five minutes.
- Require production `APP_KEY` and other secrets to be supplied at runtime; never bake them into images or commit them.
- Start production from an empty database. No development data is migrated.
- Document first-time setup, routine operation, rollback, and token rotation.
- Keep the existing development Compose workflow working unchanged.

## Capabilities

### New Capabilities

- `deployment`: continuous integration, production image publication, outbound QNAP deployment, health-gated activation, failed-image holds, rollback, and production secret and migration handling.

### Modified Capabilities

None.

## Impact

Affected code and configuration: `.github/workflows/ci.yml`, `docker/php/Dockerfile`, `docker/php/entrypoint.sh`, `docker/nginx/Dockerfile`, `docker-compose.prod.yml`, `deploy/qnap/deploy.sh`, `.dockerignore`, `composer.json` scripts if needed, `README.md`, `docs/deployment.md`, and `docs/runtime.md`. No exchange, grid strategy, risk, alert, analytics, or dashboard behavior changes. Live trading remains disabled unless the production `.env` explicitly enables it.

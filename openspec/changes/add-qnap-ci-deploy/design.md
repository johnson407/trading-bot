# Design

## Context

See `proposal.md` for motivation. The repository currently has a development-only Docker Compose stack with source bind mounts, no CI, and no production image. The target is a private GitHub repository and a QNAP TS-253A (`x86_64`) that accepts only outbound connections. Production starts with an empty database, and live trading must stay disabled until explicitly enabled.

The full implementation design, including command-level detail and the first-install runbook, lives in `docs/superpowers/specs/2026-11-22-qnap-ci-deploy-design.md`. This document records the architecture and the decisions the implementation must preserve.

## Goals / Non-Goals

**Goals:**

- Test every pull request and production-branch push before publishing images.
- Publish immutable application and web-server images to a private registry.
- Deploy from the NAS using outbound access only.
- Treat the deployment as successful only when all six services are healthy.
- Survive a bad image without redeploying it every polling interval.
- Roll back application images automatically; keep secrets and data off the image.

**Non-Goals:**

- Inbound webhooks, a self-hosted runner, or a persistent connection from GitHub to the NAS.
- Multi-architecture images; the NAS is `amd64`.
- Automatic database schema or data rollback.
- Automatic synchronization of the production compose file or `.env`.
- Automated database backups in v1; backups are a recommended follow-up.
- Changing trading, strategy, risk, exchange, or bot-runtime behavior.

## Decisions

### Delivery path: GitHub Actions to GHCR, NAS pulls

GitHub Actions runs tests and, after a green production-branch build, pushes two private images to GHCR. A scheduled task on the NAS pulls and activates the desired tag. Alternatives rejected: building on the NAS (slow, needs source and toolchain), a self-hosted runner or inbound webhook (the NAS is outbound-only), and Watchtower (no health-gated rollback, hold, or pinning).

### CI gate and image identity

`.github/workflows/ci.yml` runs on `push` to `main`, `pull_request`, and `workflow_dispatch`. The `tests` job uses PHP 8.4, PostgreSQL 16, Node 22, Composer and npm caches, copies `.env.example`, generates a key, and runs `composer test`. The `build-and-push` job needs `tests` and runs only for `main` pushes or a manual dispatch. Pull requests may run a build-only validation of the production Dockerfile without pushing.

Images are tagged with the full commit SHA (`sha-<commit>`) and `latest` on the default branch. The SHA tag is the immutable identity used by rollback and pinning. Both the application and web-server images must exist for a tag before the deployer activates it.

### Production application image

Add a `production` target to `docker/php/Dockerfile`; keep the existing `app` target for development. The production target installs production PHP dependencies with Composer inside the image, compiles the frontend with npm inside the image, copies code from the build context, and does not require host `vendor/`, `node_modules/`, or a source mount. `.dockerignore` excludes `vendor`, `public/build`, `.github`, `docs`, and `openspec` so host artifacts cannot leak into the image.

The production stage does not copy `.env` and does not run `config:cache` at build time, because configuration depends on runtime secrets.

### Two images: application and web server

A second image, built from `docker/nginx/Dockerfile`, copies `public/` from the application image identified by an `APP_IMAGE` build argument and uses the existing Nginx configuration. This avoids an init container, a writable shared public volume, and `docker cp` in the deploy script. The cost is one extra image push per build. The web-server image also gets a `/storage/` alias to the application storage volume, mounted read-only, so public uploads continue to work.

### Production composition

`docker-compose.prod.yml` runs the same six services without repository bind mounts:

- `app` uses the application image and runs migrations.
- `horizon` and `scheduler` reuse the application image and do not run migrations.
- `nginx` uses the web-server image and is the only service that publishes a host port.
- `postgres` and `redis` use named volumes and publish no ports.
- Application storage uses a named `app-storage` volume shared with Nginx read-only.

`env_file: .env` supplies runtime configuration. `RUN_MIGRATIONS=true` is set only on `app`, and startup order is PostgreSQL/Redis healthy, then `app` healthy, then Nginx, Horizon, and scheduler. All long-running services use `restart: unless-stopped` and retain their health checks.

The compose file is committed as the reference version. The copy on the NAS is authoritative at runtime and is updated by the operator when infrastructure changes, so the NAS does not need repository credentials.

### Migration ownership and entrypoint

`docker/php/entrypoint.sh` gains a production branch that fails fast when `APP_KEY` is missing instead of copying `.env.example` or generating a key. Development behavior stays unchanged. Migrations and `storage:link` run only when `RUN_MIGRATIONS=true`. The development compose file is updated with the same gate so Horizon and scheduler stop racing the application on migrations.

### Outbound deploy script

`deploy/qnap/deploy.sh` is POSIX `sh` for QNAP's BusyBox environment. It runs from its own directory, takes a single-instance lock (preferring `flock`, falling back to a `mkdir` lock with stale-lock cleanup), resolves the desired tag, records the currently running application and Nginx image IDs, pulls the desired tag, and starts the production composition.

State files live beside the script:

- `.desired-tag`: defaults to `latest`; a SHA value pins the deployment across polls and reboots.
- `.last-good`: immutable tag and image ID of the last fully healthy deployment.
- `.hold`: image ID of a deployment that failed application-tier health checks.

The deploy succeeds only when all six services report healthy within 180 seconds. On an application-tier failure (`app`, `nginx`, `horizon`, or `scheduler`), the script retags the previous application and Nginx image IDs as `:rollback`, starts them with `--pull never`, waits for recovery, writes `.hold` with the failed image ID, logs the result, and exits non-zero. On an infrastructure-tier failure (PostgreSQL or Redis), it logs the failure and exits non-zero without rolling back images or writing `.hold`, so the next poll retries.

On success, the script records the immutable SHA tag and image ID in `.last-good`, clears `.hold` and temporary rollback tags, prunes dangling images, and exits zero. It never runs `docker compose down`, `migrate:fresh`, or `db:seed`. `DRY_RUN=1` prints commands without executing them. Logs append to `deploy.log` and are capped at roughly 1 MB.

Operator commands: `./deploy.sh sha-<commit>` pins, `--use-latest` clears the pin and hold, `--clear-hold` retries a held image, and `--rollback` activates `.last-good`.

### Scheduling

A cron entry in `/etc/config/crontab` runs the script every five minutes as the account with Docker access. If the installed QTS does not persist that file, the QTS scheduled-task UI or `autorun.sh` is the fallback. Five minutes is a deliberate traffic/latency trade-off and can be shortened. The cron command passes no arguments; state files keep manual pins and rollbacks from being undone.

### Registry authentication

Both GHCR packages are private. The NAS uses a GitHub token with only `read:packages`, stored through `docker login ghcr.io` in the deploy account's Docker config. CI uses the built-in `GITHUB_TOKEN` to push. Token rotation is documented, and a denied pull produces a clear login instruction.

### Runtime configuration, secrets, and data

Production configuration lives only in `.env` on the NAS, mode `600`, and is never baked into an image. It supplies `APP_ENV=production`, `APP_DEBUG=false`, a stable `APP_KEY`, database and Redis credentials, `TRADING_LIVE_ENABLED=false`, and the trading defaults. The database starts empty; no development rows or exchange credentials are copied. Because exchange credentials are encrypted with `APP_KEY`, a later data migration must reuse the old key or re-enter credentials. The first account is created through registration plus verification or an explicit command; the demo seeder is optional and not part of bootstrap.

### Documentation

Add a production-deployment section to `README.md`, a `docs/deployment.md` runbook covering NAS layout, first install, routine operation, holds, rollback, token rotation, and recovery, and a note in `docs/runtime.md` that only `app` migrates in production.

## Risks / Trade-offs

- **QNAP Docker/Compose differences** → preflight `docker version`, `docker compose version`, share path, and cron persistence before the first deploy; keep the script POSIX and provide a `docker-compose` v1 fallback if needed.
- **Non-backward-compatible migration** → image rollback cannot undo schema changes; keep migrations backward-compatible and rely on QNAP snapshots or `pg_dump` for recovery. Schedule backups as a follow-up.
- **Failed image flapping** → `.hold` by image ID prevents repeated restarts; a new image or `--clear-hold` releases it.
- **Manual pin lost by cron** → `.desired-tag` persists the pin; cron never overrides it.
- **Rollback tag cannot be pulled** → rollback retags local previous image IDs and uses `--pull never`; if the previous image is gone, the script fails loudly.
- **Registry token expiry** → clear login error and documented rotation.
- **Polling latency** → deployment is delayed by up to one interval; the interval is adjustable.
- **Health-check false negatives** → 180-second window, all-six requirement, and logs; infrastructure failures are not misclassified as image failures.
- **Nginx assets drift from the application** → the web-server image is built from the same SHA-tagged application image.
- **Host build artifacts leak into the image** → `.dockerignore` excludes `vendor` and `public/build`.
- **Disk growth on the NAS** → dangling-image prune on success and capped deploy logs; tagged images and volumes are preserved.
- **Cron does not survive reboot** → verify persistence and use the QTS UI or `autorun.sh` fallback.
- **Docker access is root-equivalent** → use a dedicated NAS account for the deploy cron where practical; otherwise document the admin identity.
- **SSH setup is not yet proven** → the NAS username and key authorization are preflight blockers; the generated key `~/.ssh/id_ed25519_qnap` is not yet authorized.

## Migration Plan

1. **Preflight:** confirm SSH, `x86_64`, Docker/Compose versions, Container Station share, free space, and cron persistence. Adjust the script only if the installed Compose is v1 or the share path differs.
2. **Repository changes:** add the CI workflow, production Dockerfile target, Nginx image, production compose, entrypoint gate, deploy script, `.dockerignore` updates, and documentation.
3. **Local validation:** build both production images from a clean checkout, validate the compose configuration, start the stack with local tags, and verify `/up`, `/login`, migrations, and all six health checks.
4. **First NAS install:** copy the compose file and deploy script, create `.env` with a freshly generated `APP_KEY`, log in to GHCR with a read-only token, run a dry run, perform the first deploy, verify the UI and logs, create the first account, and install the cron entry.
5. **Steady state:** push a green commit to `main`, verify the Actions run and the NAS activation within one polling interval, then enable branch protection requiring the `tests` job.
6. **Rollback:** use automatic image rollback on health failure; manual recovery is `./deploy.sh --rollback` or a SHA pin. Database recovery is manual from a snapshot or dump, followed by an image rollback.

## Open Questions

- The NAS SSH username, exact Container Station share path, and installed Docker/Compose versions are unknown until preflight. They affect commands and paths but not the architecture. Record the answers in `docs/deployment.md`.
- Whether to schedule database backups in this change or as a follow-up. The design treats them as a follow-up unless the operator requests otherwise.

# Tasks

## 1. Preflight and recorded constraints

- [x] 1.1 Confirm the NAS SSH account and architecture by running `ssh qnap 'uname -m; id'` and record the account, host key, and `x86_64` result in `docs/deployment.md`. Verify: the command succeeds and the recorded values match.
- [x] 1.2 Record Docker Engine, Compose, share, disk, and cron facts on the NAS by running `docker version`, `docker compose version`, `ls -ld /share/Docker`, `df -h /share/Docker`, and `crontab -l`. Verify: every command's result is captured in the preflight section of `docs/deployment.md`, including the fallback if Compose v1 or a different share path is found.

## 2. CI test gate and registry publication

- [x] 2.1 Add `.github/workflows/ci.yml` with `push` to `main`, `pull_request`, and `workflow_dispatch` triggers; least-privilege permissions; and a concurrency group that cancels pull-request runs but not `main` builds. Verify: the YAML parses with `python3 -c 'import yaml,sys; yaml.safe_load(open(".github/workflows/ci.yml"))'`, and the trigger, permission, and concurrency blocks match this description.
- [x] 2.2 Implement the `tests` job with PHP 8.4, the required extensions, Node 22, PostgreSQL 16, Composer/npm caches, `.env` setup, key generation, and `composer test`. Verify: a local dry run of the job's commands passes, or the first pull request shows a green `tests` job.
- [x] 2.3 Implement the `build-and-push` job gated on `tests`, restricted to `main` pushes and manual dispatch, using Buildx, GHCR login with `GITHUB_TOKEN`, SHA plus `latest` metadata, and cached app and Nginx builds. Verify: the YAML parses, and the job's `needs`, `if`, image names, tags, targets, and `APP_IMAGE` build argument match the design.
- [x] 2.4 Add a pull-request build-only path that builds all three production images with `push: false`. Verify: a pull request run produces image build steps without a registry push.

## 3. Production application image

- [x] 3.1 Update `.dockerignore` to exclude `vendor`, `public/build`, `.github`, `docs`, and `openspec`. Verify: `docker build --target production` does not copy host `vendor` or `public/build`, and the resulting image contains `vendor/autoload.php` from the in-image Composer install.
- [x] 3.2 Add the `production` target to `docker/php/Dockerfile` with in-image `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, production autoload generation, storage directories, and ownership. Verify: `docker build --target production -t trading-bot:test .` succeeds from a clean checkout and `docker run --rm trading-bot:test php artisan --version` reports the application version.
- [x] 3.3 Keep the existing `app` target working for development. Verify: `docker compose build app` succeeds and the development stack still starts.

## 4. Production web-server image

- [x] 4.1 Add `docker/nginx/Dockerfile` with an `APP_IMAGE` build argument, a stage that reads the application image, and a final Nginx stage that copies `/var/www/html/public` and the existing Nginx configuration. Verify: `docker build -f docker/nginx/Dockerfile --build-arg APP_IMAGE=trading-bot:test -t trading-bot-nginx:test .` succeeds and `docker run --rm trading-bot-nginx:test test -f /var/www/html/public/index.php` exits zero.
- [x] 4.2 Add the `/storage/` location to the Nginx configuration and document that the production composition mounts the application storage volume read-only at the aliased path. Verify: `docker run --rm trading-bot-nginx:test grep -q '/storage/' /etc/nginx/conf.d/default.conf` exits zero, and the compose file mounts `app-storage` read-only for Nginx.

## 5. Production composition

- [x] 5.1 Add `docker-compose.prod.yml` with the seven services, GHCR image references, `env_file: .env`, no repository bind mounts, named `app-storage`, `postgres-data`, and `redis-data` volumes, only the Nginx host port, health checks, restart policies, and the PostgreSQL/Redis → app → Nginx/Horizon/scheduler dependency order. Verify: with a temporary `.env`, `docker compose -f docker-compose.prod.yml config` succeeds and shows no source bind mounts and only the Nginx published port.
- [x] 5.2 Set `RUN_MIGRATIONS=true` only on `app` and `false` on `horizon` and `scheduler` in the production composition. Verify: `docker compose -f docker-compose.prod.yml config` shows the expected value per service.
- [x] 5.3 Document the production compose file as the NAS reference and keep `docker-compose.yml` as the development stack. Verify: `docs/deployment.md` links the production file and both compose commands resolve.

## 6. Entrypoint and migration ownership

- [x] 6.1 Update `docker/php/entrypoint.sh` to fail fast in production when `APP_KEY` is empty, preserve development key generation and `.env.example` copying, and gate migrations and `storage:link` behind `RUN_MIGRATIONS=true`. Verify: `sh -n docker/php/entrypoint.sh` passes; a production container without `APP_KEY` exits non-zero with a clear message; a container with `APP_KEY` and `RUN_MIGRATIONS=false` does not run migrations.
- [x] 6.2 Update the development `docker-compose.yml` so `app` sets `RUN_MIGRATIONS=true` and `horizon` and `scheduler` set it to `false`. Verify: `docker compose config` shows the expected values and a development start produces one migration run, not three.
- [x] 6.3 Update `docs/runtime.md` to state that production migrations and public storage linking happen only in the `app` service. Verify: the documented behavior matches the entrypoint and both compose files.

## 7. Outbound deploy script and runbook

- [x] 7.1 Add `deploy/qnap/deploy.sh` as POSIX `sh` with directory-relative state, a single-instance lock, desired-tag resolution, image ID capture, pull, all-seven health wait, application-tier rollback with `:rollback` and `--pull never`, infrastructure-tier retry, `.desired-tag`/`.last-good`/`.hold` handling, log capping, and `DRY_RUN=1`. Verify: `sh -n deploy/qnap/deploy.sh` passes and `DRY_RUN=1 ./deploy/qnap/deploy.sh` prints the expected commands without changing state.
- [x] 7.2 Add a shell test harness with stubbed Docker and Compose commands covering first deploy, successful deploy, held image skip, new-image release, manual pin persistence, `--use-latest`, `--clear-hold`, `--rollback`, infrastructure failure, and application-tier rollback. Verify: the harness runs all cases and exits zero.
- [x] 7.3 Add `docs/deployment.md` covering the NAS layout, preflight results, first install, registry login and token rotation, routine updates, state files, hold and rollback commands, log inspection, secrets, fresh database bootstrap, and database recovery. Verify: every command and path in the runbook matches the script and compose files, and a reviewer can follow it end to end.
- [x] 7.4 Add a short production-deployment section to `README.md` that links `docs/deployment.md`. Verify: the link resolves and the section does not duplicate the runbook.

## 8. Local production smoke test

- [x] 8.1 Build all three production images from a clean checkout, create a temporary production `.env`, and start the stack with `IMAGE_TAG` pointing at local tags. Verify: all seven services become healthy, `/up` succeeds, `/login` returns the login page, and the application logs contain no key or migration errors.
- [x] 8.2 Verify persistence and isolation by restarting the stack without removing volumes. Verify: PostgreSQL data, Redis data, and application storage survive, no development seed data is present, and only the Nginx port is reachable.
- [x] 8.3 Verify live trading is disabled and secrets are absent from the images. Verify: the runtime reports live trading disabled, and inspecting the images shows no `.env`, application key, database password, exchange credential, or registry token.

## 9. NAS first installation and steady state

- [x] 9.1 Create `/share/Docker/trading-bot`, copy `docker-compose.prod.yml` and `deploy/qnap/deploy.sh`, mark the script executable, create a mode-600 `.env` with a fresh `APP_KEY`, and log in to GHCR with the read-only token. Verify: `docker compose -f docker-compose.prod.yml config` succeeds and `docker pull` of all three images succeeds.
- [x] 9.2 Run `DRY_RUN=1 ./deploy.sh`, then the first real deploy. Verify: all seven services report healthy, `/up` and `/login` respond, and the application logs are clean.
- [x] 9.3 Create the first application user through registration plus verification or an explicit tinker command. Verify: the user can log in and the production database contains no development rows.
- [x] 9.4 Install the five-minute cron entry, restart cron, and confirm `deploy.log` receives output. Verify: a green push to `main` produces a successful Actions run and the NAS activates the new SHA within one polling interval.
- [ ] 9.5 Configure branch protection on `main` to require the `tests` job. Blocked: the private repository is on the GitHub free plan, where branch protection and rulesets return HTTP 403. Verify: the repository setting shows the required check and a pull request cannot merge with a failing test.
- [x] 9.6 Record the actual NAS versions, share path, cron command, first-deploy results, and any deviations in `docs/deployment.md`. Verify: the runbook matches the live NAS and no placeholders remain.
- [x] 9.7 Add the Go engine to the release path: build/push `ghcr.io/johnson407/trading-bot-engine`, run `go test ./...` in CI, add the `engine` service with its healthcheck to production Compose, and include engine pull/health/rollback in the QNAP deploy script. Verify: deploy shell tests pass, the engine image is built on PRs and pushed with SHA/latest tags on `main`, and a failing engine healthcheck rolls back the engine image.

## Workflow follow-up

- Archive the change after implementation and review are complete.
- Verify the archived specs and task status after archiving.

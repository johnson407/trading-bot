# QNAP CI/CD Deployment — Design

Status: proposed, awaiting human approval
Date: 2026-11-22

## 1. Intent

Today the application runs only on the development machine through
`docker-compose.yml`, with the repository bind-mounted into the containers. There
is no continuous integration, no production image, and no deployment path.

The goal is a hands-off flow in which every green push to `main` is tested,
packaged into a reproducible image, published to a private registry, and picked
up by the QNAP NAS on its own outbound connection. The NAS is not reachable from
the internet, so it must pull; GitHub must never need to connect to it.

The same flow must keep local development working exactly as it does now.

## 2. Goals

- Run the full PHP test suite on every pull request and every push to `main`.
- Build a production image only after the tests pass.
- Publish immutable, SHA-tagged images (plus a moving `latest`) to GitHub
  Container Registry (GHCR).
- Deploy automatically on the NAS within a few minutes of a green `main` push.
- Keep application data (PostgreSQL, Redis, uploaded files) across deploys.
- Keep live trading disabled by default; deployment must not change the live
  gate.
- Make a failed deploy visible and recoverable without guessing which image was
  running before.
- Require no inbound ports, tunnels, self-hosted runners, or public endpoints.
- Keep secrets (`.env`, `APP_KEY`, database password, registry token) out of the
  repository and out of the image.

## 3. Non-goals (v1)

- Multi-architecture images. The NAS is x86_64; only `linux/amd64` is built.
- A staging or preview environment.
- Blue/green or zero-downtime deployment. A deploy briefly restarts containers.
- Automatic database backups. QNAP snapshots or a separate `pg_dump` schedule
  are recommended but are not part of this design.
- Automatic migration rollback. Migrations run forward only.
- Deploy notifications (email, Slack, push). The deploy log and `docker compose
  ps` are the source of truth.
- TLS termination or a public hostname. The app is reached on the LAN.
- Image signing, SBOM generation, or vulnerability scanning. Good follow-ups,
  not required for the first production deployment.
- A second registry or registry mirror.

## 4. Anti-goals

The following would make the deployment a failure even if it technically works:

- Watchtower-style polling that restarts containers on any new tag without
  waiting for health, or that deploys red builds.
- Building the image on the NAS. The Celeron N3150 is slow, memory-limited, and
  would need a full PHP/Node toolchain plus source access on the appliance.
- Exposing SSH, Docker, PostgreSQL, Redis, or the web port to the internet.
- Baking `.env`, `APP_KEY`, exchange credentials, or database passwords into the
  image or the repository.
- Running migrations from the `horizon` and `scheduler` containers at the same
  time as `app`.
- A deploy that leaves the stack down and gives no path back to the previous
  image.
- A `latest`-only deployment with no way to identify or return to the commit
  that was previously healthy.

## 5. Constraints

- QNAP TS-253A, Intel Celeron N3150, x86_64. QTS with Container Station.
- The NAS has outbound internet access only. No port forwarding, public IP,
  Tailscale, or tunnel.
- The GitHub repository is private. GHCR packages are private by default.
- The NAS must authenticate to GHCR with a read-only token.
- Docker Compose is already used for local development and must keep working.
- The current PHP image (`docker/php/Dockerfile`, target `app`) does **not** run
  `composer install`; it relies on the host `vendor/` directory being copied
  into the image. A production image cannot depend on host build artifacts.
- `docker/php/entrypoint.sh` is inherited by `app`, `horizon`, and `scheduler`,
  and currently runs `php artisan migrate --force` in all three. That is unsafe
  in production.
- The local compose file bind-mounts the repository into the web and worker
  containers, so Nginx can see `public/`. An image-based deployment has no such
  bind mount, so the production Nginx needs its own copy of the public assets.
- The test suite uses PostgreSQL (`phpunit.xml`: `DB_CONNECTION=pgsql`,
  `DB_DATABASE=trading_bot_test`), not SQLite. CI must provide a Postgres
  service.
- PHP 8.4 is the runtime in the existing image; tests should run on 8.4 too.
- Some feature tests render Inertia pages. At least three call `withoutVite()`,
  so the CI test job must build the frontend assets or otherwise guarantee a
  Vite manifest.
- QNAP specifics that still need to be verified over SSH: Docker Engine version,
  Compose v2 availability, the persistent share path, and whether
  `/etc/config/crontab` is the right scheduling hook. The design assumes
  `/share/Container/trading-bot` and Docker Compose v2; the setup runbook
  includes a preflight check and a fallback.

## 6. Current state

Local development:

```
docker compose up -d --build
  app        php-fpm, repo bind-mounted
  nginx      nginx:1.27-alpine, repo and config bind-mounted
  postgres   postgres:16-alpine, named volume postgres-data
  redis      redis:7-alpine, named volume redis-data
  horizon    same app image, repo bind-mounted
  scheduler  same app image, repo bind-mounted
```

`docker/php/entrypoint.sh` waits for PostgreSQL, runs migrations, creates the
storage symlink, and then starts the service. `app` is healthy only after that.
`horizon` and `scheduler` wait for `app` to be healthy, but each still runs the
entrypoint migration step.

There is no `.github/` directory, no production compose file, no image registry
configuration, and no deployment script.

## 7. Approaches considered

### A. GitHub Actions → GHCR → NAS pull (chosen)

GitHub Actions runs the tests, builds the production images, and pushes them to
GHCR. A scheduled task on the NAS runs a deploy script that pulls the newest
image and recreates the stack. The NAS only makes outbound connections.

Trade-offs:

- CI and the registry do the heavy work; the NAS only pulls and runs.
- The image is built once and is byte-identical everywhere.
- Deployment latency is the polling interval (chosen: 5 minutes).
- The NAS needs a read-only registry token and a small amount of on-box setup.
- Build minutes are spent in GitHub, not on the appliance.

This is the approach the human selected.

### B. NAS checks out the repository and builds locally

A cron job on the NAS runs `git pull` and `docker compose build`, then restarts
the stack.

Trade-offs:

- No registry and no image publishing.
- The NAS needs Git, a PHP/Node build toolchain, enough RAM for `composer
  install` and `vite build`, and credentials to read a private repository.
- Builds are slow and can fail for reasons unrelated to the code (memory,
  missing extensions, stale caches).
- The built artifact is not reproducible or inspectable from CI.

Rejected: fragile on this hardware and duplicates the build environment.

### C. Self-hosted runner or inbound webhook

Run a GitHub Actions runner on the NAS, or open a tunnel so GitHub can trigger a
deploy job directly.

Trade-offs:

- Immediate deploys and full job logs on the NAS.
- Requires inbound reachability or an outbound tunnel, which the human ruled
  out.
- A compromised runner has direct access to the production Docker socket.
- More moving parts to maintain and secure.

Rejected: violates the outbound-only constraint and adds unnecessary attack
surface.

### D. Watchtower

A container watches GHCR and restarts services when a new image appears.

Trade-offs:

- Very little setup.
- No test gate of its own.
- Restarts on any tag change, with no health gate and no rollback.
- Hard to audit which commit is running and why.

Rejected: the human explicitly did not want it, and it conflicts with the
anti-goals above.

## 8. Architecture

```
developer push to main
        |
        v
GitHub Actions (ci.yml)
  job: tests (PHP 8.4 + Postgres 16)
        |
        v (green only)
  job: build-and-push
    - build app image (production target)
    - push ghcr.io/johnson407/trading-bot:sha-<commit> + :latest
    - build nginx image from that app image
    - push ghcr.io/johnson407/trading-bot-nginx:sha-<commit> + :latest
        |
        v
GitHub Container Registry (private)
        ^
        | docker pull (outbound HTTPS)
        |
QNAP cron, every 5 minutes
  /share/Container/trading-bot/deploy.sh
    - single-instance lock
    - record currently running image IDs
    - docker compose -f docker-compose.prod.yml pull
    - docker compose -f docker-compose.prod.yml up -d --remove-orphans
    - wait for app and nginx health (up to 180s)
    - on failure: retag previous images and roll back
    - on success: prune dangling images
        |
        v
app (php-fpm) + nginx + postgres + redis + horizon + scheduler
```

The GitHub workflow never contacts the NAS. The NAS never exposes a port for
deployment. The only shared dependency is the private registry.

## 9. The how

### 9.1 GitHub Actions workflow

New file: `.github/workflows/ci.yml`.

Triggers:

- `push` to `main`
- `pull_request`
- `workflow_dispatch` (manual rebuild/re-push from `main`)

Permissions:

- `contents: read`
- `packages: write` (only needed by the build job)

Concurrency:

- Group by workflow and ref.
- Cancel in-progress runs for pull requests.
- Do not cancel an in-progress `main` build; let it finish so the registry ends
  on a known good image.

#### Job 1: `tests`

Runs on `ubuntu-latest`.

- Checkout.
- `shivammathur/setup-php@v2` with PHP 8.4 and extensions: `bcmath`, `gmp`,
  `intl`, `pcntl`, `pdo_pgsql`, `pgsql`, `zip`, `redis`. Coverage disabled.
- `actions/setup-node@v4` with Node 22 and npm caching.
- PostgreSQL 16 service:
  - `POSTGRES_DB: trading_bot_test`
  - `POSTGRES_USER: postgres`
  - `POSTGRES_PASSWORD: postgres`
  - port `5432:5432`
  - `pg_isready` health check
- `composer install --prefer-dist --no-interaction --no-progress` with Composer
  caching.
- `npm ci` and `npm run build` (needed because some feature tests render Inertia
  pages; the build also validates the frontend before the image build).
- Copy `.env.example` to `.env`; run `php artisan key:generate`.
- Export test database settings (`DB_HOST=127.0.0.1`, `DB_PORT=5432`,
  `DB_USERNAME=postgres`, `DB_PASSWORD=postgres`). `phpunit.xml` already sets
  `DB_DATABASE=trading_bot_test`.
- Run `composer test` (which clears config and runs `php artisan test`).

The job fails on any test failure. The build job does not run.

#### Job 2: `build-and-push`

Needs `tests`. Runs only for `push` to `main` or `workflow_dispatch` on `main`.
For pull requests, an optional build-only variant validates the production
Dockerfile without pushing (recommended; it catches Dockerfile regressions
before merge).

Steps:

- Checkout.
- `docker/setup-buildx-action@v3`.
- `docker/login-action@v3` to `ghcr.io` as `${{ github.actor }}` with
  `${{ secrets.GITHUB_TOKEN }}`.
- `docker/metadata-action@v5` for the app image:
  - image: `ghcr.io/johnson407/trading-bot`
  - tags: `type=sha,format=long` (produces `sha-<full commit>`) and
    `type=raw,value=latest,enable={{is_default_branch}}`
- `docker/build-push-action@v6`:
  - context `.`
  - file `docker/php/Dockerfile`
  - target `production`
  - `push: true`
  - tags from metadata
  - `cache-from: type=gha`, `cache-to: type=gha,mode=max`
- `docker/metadata-action` for the Nginx image:
  - image: `ghcr.io/johnson407/trading-bot-nginx`
  - the same SHA and `latest` tags.
- `docker/build-push-action@v6` for Nginx:
  - context `.`
  - file `docker/nginx/Dockerfile`
  - `push: true`
  - `build-args: APP_IMAGE=ghcr.io/johnson407/trading-bot:sha-<full commit>`
  - same cache settings.

The Nginx build pulls the just-pushed app image and copies `public/` out of it.
The SHA tag makes the two images provably from the same commit.

Branch protection on `main` should require the `tests` job before merge. This is
a repository setting, not a file in the repo; it belongs in the setup runbook.

### 9.2 Production PHP image

Modify `docker/php/Dockerfile` to add a new `production` target. Keep the
existing `app` target for local development so `docker compose up --build`
continues to work.

Shape:

```dockerfile
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
    --optimize-autoloader --no-scripts

FROM base AS assets
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
COPY . .
RUN npm run build

FROM base AS production
COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=assets /var/www/html/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
```

Notes:

- `composer install` runs inside the image; no host `vendor/` is required.
- The frontend is built inside the image from `package-lock.json`.
- `.dockerignore` must add `vendor` and `public/build` so host artifacts cannot
  leak into the context and overwrite the freshly built ones. Add `.github`,
  `docs`, and `openspec` as well to keep the build context small; none are
  needed at runtime.
- The production stage does not copy `.env`; configuration is injected at
  runtime.
- No `config:cache` at build time. Configuration depends on runtime env vars and
  secrets; caching must happen at start-up if it is added later.
- The image runs the existing entrypoint and `php-fpm` as today.

### 9.3 Production Nginx image

New file: `docker/nginx/Dockerfile`.

```dockerfile
ARG APP_IMAGE=ghcr.io/johnson407/trading-bot:latest
FROM ${APP_IMAGE} AS app
FROM nginx:1.27-alpine
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
```

The existing `docker/nginx/default.conf` works as-is for PHP requests because
`fastcgi_pass app:9000` resolves within the compose network and the app
container has the PHP files. The nginx image only needs `public/` so it can
serve static assets and check `try_files`.

Add a `/storage/` location that aliases `/var/www/html/storage/app/public/`, and
mount the app storage volume read-only into the nginx container, so uploaded
public files served through the `storage:link` URL keep working. If the product
does not use public uploads, this location is harmless.

### 9.4 Production compose file

New file: `docker-compose.prod.yml` at the repository root (copied to the NAS
alongside the deploy script).

Differences from the development file:

- No repository bind mounts. All application code comes from the image.
- `app` uses `image: ghcr.io/johnson407/trading-bot:${IMAGE_TAG:-latest}`.
- `nginx` uses `image: ghcr.io/johnson407/trading-bot-nginx:${IMAGE_TAG:-latest}`.
- `horizon` and `scheduler` reuse the app image and do not build.
- `env_file: .env` on the application services, instead of repeating env vars.
- `RUN_MIGRATIONS=true` only on `app`; `horizon` and `scheduler` set it to
  `false`.
- A named `app-storage` volume mounted at `/var/www/html/storage` for `app`,
  `horizon`, `scheduler`, and read-only at the same path for `nginx`.
- Existing `postgres-data` and `redis-data` named volumes.
- Only nginx publishes a port: `${APP_PORT:-8000}:80`. PostgreSQL and Redis
  publish nothing.
- `restart: unless-stopped` on all long-running services.
- Health checks as today: app via `php artisan about`, nginx via `/up`,
  Postgres via `pg_isready`, Redis via `redis-cli ping`, Horizon via
  `horizon:status`, scheduler via `pgrep`.

Service dependency order:

```
postgres, redis (healthy)
      -> app (runs migrations, then healthy)
            -> nginx, horizon, scheduler
```

`IMAGE_TAG` defaults to `latest` so a normal deploy needs no extra input. The
deploy script can override it for a manual pin or rollback.

The compose file is intentionally committed to the repository as the reference
version. The copy on the NAS is authoritative at runtime; infrastructure changes
are copied over by the operator, not auto-synced by the deploy script. This
avoids giving the NAS repository credentials and keeps the pull path simple.

### 9.5 Entrypoint changes

Modify `docker/php/entrypoint.sh`:

- In production (`APP_ENV=production`), fail fast if `APP_KEY` is empty. Do not
  auto-generate a key and do not copy `.env.example`. A missing key should stop
  the container, not silently create a new identity.
- Keep the existing local behavior (copy `.env.example`, generate a key when
  missing) for development.
- Gate migrations and `storage:link` behind `RUN_MIGRATIONS=true`:
  ```sh
  if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
      php artisan migrate --force --no-interaction
      php artisan storage:link --force 2>/dev/null || true
  fi
  ```
- Keep the PostgreSQL wait loop for services that need the database.
- Update `docker-compose.yml` to set `RUN_MIGRATIONS=true` on `app` and `false`
  on `horizon` and `scheduler`, so local behavior becomes consistent with
  production and the concurrent-migration race is removed everywhere.

### 9.6 NAS directory layout

```
/share/Container/trading-bot/
  docker-compose.prod.yml   # copied from the repository
  deploy.sh                 # copied from deploy/qnap/deploy.sh
  .env                      # production secrets, never committed
  deploy.log                # appended by cron; capped by the script
```

The exact share path is confirmed during preflight. If `/share/Container` does
not exist, use the Container Station share that does and keep the same layout.

### 9.7 Deploy script

New file: `deploy/qnap/deploy.sh` (copied to the NAS). POSIX `sh`, no bash-only
features, because QNAP's shell may be BusyBox ash.

State files live next to the script on the NAS:

- `.desired-tag` — the tag the NAS should run. Absent means `latest`; a value of
  `sha-<commit>` pins the deployment until explicitly cleared.
- `.last-good` — the immutable tag and image ID of the last deployment that
  passed every health check.
- `.hold` — the image ID of a deployment that failed health checks. While the
  pulled image matches it, the script skips the restart; a new image clears it
  automatically.

Behavior:

1. `set -eu`; `cd` to the script's directory.
2. Take a single-instance lock. Prefer `flock` when available; fall back to a
   `mkdir` lock with stale-lock cleanup. If another deploy is running, log and
   exit 0.
3. Resolve the desired tag:
   - An argument or `IMAGE_TAG` value wins and is written to `.desired-tag`.
   - Otherwise read `.desired-tag`; if absent, use `latest`.
   - `--use-latest` removes `.desired-tag` and `.hold`, then deploys `latest`.
   - `--clear-hold` removes `.hold` and continues with the desired tag.
   - `--rollback` sets the desired tag to the immutable tag in `.last-good`.
4. Record the image IDs currently used by `app` and `nginx`, if those
   containers exist.
5. Pull the desired tag. Pull, authentication, or network failures log and exit
   1 without writing `.hold`, so the next tick retries.
6. Resolve the pulled image ID. If `.hold` contains that ID, log that the same
   failed image is held and exit 0. If `.hold` contains another ID, remove it
   and continue.
7. `docker compose -f docker-compose.prod.yml up -d --remove-orphans`.
8. Wait up to 180 seconds for **all six** services to report `healthy` via
   `docker inspect`. A missing health check or an exited container counts as
   failure.
9. On success:
   - Derive the immutable `sha-<revision>` tag from the app image's
     `org.opencontainers.image.revision` label (set by
     `docker/metadata-action`) and ensure the local image carries that tag.
   - Write `.last-good` with that tag and the app image ID.
   - Remove `.hold` and any temporary `:rollback` tags.
   - `docker image prune -f` (dangling images only; tagged images stay).
   - Exit 0.
10. On failure:
    - If `app`, `nginx`, `horizon`, or `scheduler` is unhealthy and previous
      image IDs exist, retag the previous app and nginx image IDs as
      `ghcr.io/johnson407/trading-bot:rollback` and
      `ghcr.io/johnson407/trading-bot-nginx:rollback`, then run
      `IMAGE_TAG=rollback docker compose -f docker-compose.prod.yml up -d
      --pull never --remove-orphans`. Wait up to 120 seconds for recovery.
    - If PostgreSQL or Redis is unhealthy, log an infrastructure failure and do
      not roll back the images; the images are not the cause. Do not write
      `.hold` for this case, so the next tick retries.
    - For an application-stack health failure, write `.hold` with the failed
      image ID, so the same image is not retried every five minutes.
      `--clear-hold` or a new image releases it.
    - Log the failure and the rollback result, and exit 1 so cron and monitoring
      can see it.
    - Do not run `docker image prune` on failure.

Support `DRY_RUN=1` to print the compose commands without executing them. This
makes the script testable on a workstation and during first-time setup.

Logging: append timestamped lines to `deploy.log`. The script caps the file at
roughly 1 MB by truncating the oldest content (for example, keep the last 500
lines) so a chatty cron does not fill the share.

The script does not manage `.env`, the compose file, the database, or the
registry login. It assumes those are already correct and fails loudly if they
are not.

### 9.8 Scheduling on QNAP

Preferred: a cron entry in `/etc/config/crontab` (persistent across reboots on
QTS), run as the account that has Docker access:

```
*/5 * * * * /share/Container/trading-bot/deploy.sh >> /share/Container/trading-bot/deploy.log 2>&1
```

Then restart cron (for example `/etc/init.d/cron restart`) and verify with
`crontab -l` or by tailing `deploy.log` after a test push.

The cron command always calls the script with no arguments. The script reads
`.desired-tag`, `.last-good`, and `.hold` from its own directory, so a manual pin
or rollback is not undone by the next tick. To return to automatic `latest`,
run `./deploy.sh --use-latest` once; cron then continues with `latest`.

Fallback if the QTS build does not persist or expose `/etc/config/crontab`: use
the QTS scheduled-task UI to run the same command every 5 minutes, or add it to
`autorun.sh` if that is the supported persistence hook on the installed QTS
version.

Polling every 5 minutes is a deliberate choice: it keeps registry traffic low
and still gives a short feedback loop. Five minutes is not a correctness
requirement; the interval can be shortened to 1–2 minutes if faster deploys are
wanted.

### 9.9 Registry authentication

The GHCR packages are private because the repository is private.

- Create a GitHub token with package read access (`read:packages`). A classic
  PAT with only `read:packages` is sufficient; a fine-grained token scoped to
  the repository and packages is also acceptable.
- On the NAS, run as the cron/deploy account:
  `echo "$TOKEN" | docker login ghcr.io -u johnson407 --password-stdin`.
- Store the token in the account's Docker config (`~/.docker/config.json`) and
  keep it out of shell history and logs.
- Set a calendar reminder to rotate the token before expiry. The deploy script
  should log a clear "run docker login ghcr.io" message when a pull is denied.

The CI workflow uses the built-in `GITHUB_TOKEN`; no long-lived secret is stored
in GitHub for pushing images.

### 9.10 Secrets and production `.env`

The production `.env` lives only on the NAS, mode `600`, owned by the deploy
account.

Required values:

- `APP_NAME=Trading Bot`
- `APP_ENV=production`
- `APP_KEY=` a fresh key generated once (for example
  `php artisan key:generate --show`)
- `APP_DEBUG=false`
- `APP_URL=http://192.168.88.150:8000` (or the chosen LAN hostname/port)
- `APP_PORT=8000`
- `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_PORT=5432`,
  `DB_DATABASE=trading_bot`, a strong `DB_USERNAME` and `DB_PASSWORD`
- `REDIS_HOST=redis`, `REDIS_PORT=6379`, `REDIS_PASSWORD=` as configured
- `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=database`
- `TRADING_LIVE_ENABLED=false`
- The trading defaults from `.env.example` (tick interval, risk, exchange
  timeouts) unless deliberately changed
- Mail settings appropriate for production; if mail stays on `log`, account
  verification links land in `storage/logs/laravel.log` inside the app
  container, and users must be verified manually or by an admin with tinker

Do not copy the development `.env` wholesale. In particular, do not reuse its
`APP_KEY` or database password.

This is a fresh production deployment: the database starts empty and no
development data is carried over. Exchange credentials are encrypted with
`APP_KEY`, so copying development rows into a database protected by a new key
would make those credentials undecryptable. If data is ever migrated later,
either reuse the old key or re-enter the credentials. The first account is
created through `/register` (then verified) or with tinker; the demo seeder is
optional and is not part of the production bootstrap.

### 9.11 Health, rollback, and data safety

Health is defined by the compose health checks, not by "the container started".

- `app` is healthy when `php artisan about` succeeds after migrations.
- `nginx` is healthy when `/up` returns success through the app.
- `horizon` is healthy when `horizon:status` reports running.
- `scheduler` is healthy when `schedule:work` is running.

The deploy is considered successful only when **all six** services are healthy
within the 180-second window. The workers depend on `app` being healthy, so a
broken app never leaves workers running against an unmigrated schema. A broken
Horizon or scheduler is a failed deployment: for this application it means bots
stop ticking even though the UI still responds.

Rollback is image-level. If `app`, `nginx`, `horizon`, or `scheduler` fails
health, the previous `app` and `nginx` image IDs are retagged and brought back,
and the failed image ID is written to `.hold`. If PostgreSQL or Redis is
unhealthy, the script logs an infrastructure
failure, does not roll back the images, and retries on the next tick. The
database is **not** rolled back. If a migration is not backward-compatible,
restoring from a QNAP snapshot or a `pg_dump` backup is the only recovery; this
is why backups are called out as a recommended follow-up.

Data safety:

- `postgres-data` and `redis-data` are named volumes, preserved by `docker
  compose down` (without `-v`). The deploy script never passes `-v`.
- `app-storage` is a named volume for logs and uploaded files, also preserved.
- The deploy script never runs `docker compose down`, `migrate:fresh`, or
  `db:seed`.

### 9.12 First-time setup runbook

These steps happen once, after the human authorizes the SSH key and provides the
NAS account name.

1. Confirm SSH: `ssh qnap 'uname -m'` should print `x86_64`.
2. Preflight on the NAS:
   - `docker version` (Engine 20.10+ is expected)
   - `docker compose version` (Compose v2; if only `docker-compose` v1 exists,
     record the version and adjust the script's command)
   - `ls -ld /share/Container` (or the actual Container Station share)
   - `df -h /share/Container` (space for images, volumes, and logs)
   - `crontab -l` and `ls -l /etc/config/crontab` (scheduling hook)
3. Create `/share/Container/trading-bot`.
4. Copy `docker-compose.prod.yml` and `deploy/qnap/deploy.sh` to that directory;
   `chmod +x deploy.sh`.
5. Create `.env` with the values from section 9.10. Generate `APP_KEY` locally
   and paste it in. `chmod 600 .env`.
6. Create the read-only GHCR token and run `docker login ghcr.io` as the deploy
   account.
7. Run `DRY_RUN=1 ./deploy.sh` first, then `./deploy.sh` for the first real
   pull/start. Verify:
   - `docker compose -f docker-compose.prod.yml ps` shows all six services
     healthy.
   - `curl -fsS http://127.0.0.1:8000/up` succeeds.
   - `curl -fsS http://127.0.0.1:8000/login` returns the login page.
   - `docker compose -f docker-compose.prod.yml logs app` has no migration or
     key errors.
8. Create the first application user. Registration works, but the account needs
   email verification; either configure real mail or verify/create the user with
   tinker. The demo seeder is optional on a fresh production database.
9. Install the cron entry, restart cron, and confirm `deploy.log` receives a
   line.
10. Push a trivial green commit to `main`, watch the Actions run, then watch the
    NAS deploy within one polling interval.
11. Enable branch protection on `main` requiring the `tests` job.

### 9.13 Repository documentation

- Add a short "Production deployment" section to `README.md` pointing at the
  runbook.
- Add `docs/deployment.md` with the NAS layout, first-time setup, routine
  operation, rollback, and token rotation.
- Update `docs/runtime.md` to note that in production `horizon` and `scheduler`
  no longer run migrations; only `app` does.
- Keep `docker-compose.yml` documented as the development stack.

## 10. Testing strategy

CI itself:

- A pull request with a deliberately failing test must show a red `tests` job
  and must not push an image.
- A green `main` push must produce four tags (app SHA, app `latest`, nginx SHA,
  nginx `latest`).
- The image build must succeed from a clean checkout with no host `vendor/` or
  `public/build/`.

Local production-image smoke test (before the NAS):

- `docker build --target production -t trading-bot:test .`
- `docker build -f docker/nginx/Dockerfile --build-arg APP_IMAGE=trading-bot:test -t trading-bot-nginx:test .`
- `docker compose -f docker-compose.prod.yml config` with a temporary `.env`.
- Start the stack with `IMAGE_TAG` pointing at the local tags, run migrations,
  and verify `/up` and `/login`.

Deploy script:

- `DRY_RUN=1` prints the expected commands and changes nothing.
- On the NAS or a local Docker host, deploy a deliberately unhealthy image tag
  and confirm the script logs failure, retags the previous images, and restores
  health.
- Restart the stack and confirm PostgreSQL data and the app storage survive.

Trading safety:

- `TRADING_LIVE_ENABLED=false` in the production `.env`; confirm the runtime
  gate reports live trading disabled after deploy.
- No deploy step touches bot mode, bot state, or exchange credentials.

## 11. Security notes

- The NAS pulls over HTTPS from GHCR; nothing is exposed inbound.
- The registry token is read-only and scoped to packages.
- `.env` is mode `600` and never enters the image or the repository.
- Only nginx publishes a port. Postgres and Redis are reachable only on the
  compose network.
- The app remains on the LAN unless the operator deliberately adds a reverse
  proxy later.
- `APP_DEBUG=false` in production; stack traces and env values are not exposed.
- The SSH key used for setup is restricted to the NAS host via `~/.ssh/config`
  and should be removed from `authorized_keys` if the workstation is retired.
- QNAP's Docker access is root-equivalent. Use a dedicated NAS account for the
  deploy cron if practical; otherwise document that the admin account is the
  deploy identity.

## 12. Rollback and recovery

Normal rollback is automatic on a failed health check, as described in 9.7.
After a failure the script records the failed image ID in `.hold`, so the same
image is not redeployed on every tick while the problem is being investigated.

Manual rollback:

1. SSH to the NAS.
2. Run `./deploy.sh --rollback` to deploy the immutable tag recorded in
   `.last-good`. This writes that tag to `.desired-tag`, so subsequent cron
   ticks keep it. If the registry tag is unavailable, retag the recorded
   `.last-good` image ID as `:rollback` and start with `--pull never`.
3. Alternatively, run `./deploy.sh sha-<previous-commit>` to pin any SHA tag
   that still exists in GHCR.
4. Verify health and logs. `./deploy.sh --use-latest` returns to automatic
   latest; `./deploy.sh --clear-hold` retries a held image after an operator
   fix.

Database recovery:

- If a migration broke compatibility, stop the stack, restore the PostgreSQL
  volume from a QNAP snapshot or `pg_dump`, then roll the image back.
- This is why the design recommends scheduling database backups before relying
  on automatic migrations in production.

## 13. Builder calls (made here, open to challenge)

- **Two images instead of a shared public volume.** The Nginx image copies
  `public/` from the app image. This avoids an init container, a writable shared
  volume, and `docker cp` in the deploy script. Cost: one extra image push per
  build.
- **Poll every 5 minutes.** Short enough for a personal deployment, long enough
  to avoid needless registry traffic. Adjustable in cron.
- **`latest` is the default desired tag, backed by a persistent state file.**
  `.desired-tag` lets an operator pin a SHA tag and keeps cron from undoing it.
  A successful deploy records an immutable `.last-good` tag; a failed image is
  held by ID so the stack does not flap every five minutes. Rollback uses the
  previous image ID locally and `--pull never` when the registry tag is
  unavailable.
- **All six services define deploy success.** A healthy UI with a dead Horizon
  or scheduler is still a failure for a trading bot. Any application-stack
  service failure triggers an image rollback. PostgreSQL/Redis failures are
  treated as infrastructure failures and retried without an image rollback.
- **Automatic image rollback on health failure, no database rollback.** Code
  rollback is fast and safe; schema rollback is not, so it stays manual.
- **Fresh production database, no data migration.** The `APP_KEY` change would
  make copied credential rows undecryptable; a fresh bootstrap is simpler and
  matches the human's "fresh deployment" answer.
- **Keep the compose file and `.env` on the NAS, updated by the operator.**
  Auto-syncing them would require repository credentials on the appliance and a
  second delivery mechanism.
- **Gate migrations with `RUN_MIGRATIONS` rather than a dedicated migrate
  service.** It is a small entrypoint change and preserves the existing
  `app`-healthy-then-workers order.
- **Build the frontend in CI before tests.** It costs a minute but removes a
  class of false failures from missing Vite manifests.
- **Build a production stage in the existing Dockerfile.** One file to maintain;
  the development target is untouched.
- **Only nginx is exposed.** Postgres and Redis stay private to the compose
  network even on the LAN.
- **No automatic secrets sync.** The production `.env` is created once by hand
  and rotated deliberately.

## 14. What is left to the builder

- Verify the NAS preflight items in 9.12 (SSH account, architecture, Docker and
  Compose versions, share path, cron persistence). Adjust the script only if the
  installed Compose is v1 or the share path differs.
- Implement the workflow, Dockerfile stage, Nginx image, production compose,
  entrypoint gate, deploy script, and docs.
- Test the production images and compose stack locally before touching the NAS.
- Implement and test the `.desired-tag` / `.last-good` / `.hold` lifecycle,
  including first deploy with no previous image, pin/unpin, manual rollback, and
  holding a failed image across repeated cron ticks.
- Confirm the installed Compose supports `up --pull never`; if it does not, use
  the v1-compatible equivalent (a local `:rollback` tag with no pull).
- Run the first deploy on the NAS and record the actual paths, versions, and
  cron command in `docs/deployment.md`.
- Configure the GHCR token and Docker login on the NAS.
- Configure branch protection for `main`.
- Decide whether to add a database backup schedule now or as a follow-up; if
  added, document the restore procedure.

## 15. Acceptance criteria

- [ ] A pull request runs the full test suite on PHP 8.4 with PostgreSQL 16.
- [ ] A red test prevents an image push.
- [ ] A green push to `main` publishes `trading-bot` and `trading-bot-nginx`
  images tagged with the commit SHA and `latest`.
- [ ] The production images build from a clean checkout without host `vendor/`
  or `public/build/`.
- [ ] The NAS deploys automatically within one polling interval of a green
  `main` push.
- [ ] All six services become healthy after a deploy.
- [ ] A failed health check on `app`, `nginx`, `horizon`, or `scheduler`
  triggers an image rollback and a non-zero exit.
- [ ] A failed image is held by ID; repeated cron ticks do not redeploy it.
- [ ] A manual SHA pin survives cron ticks, and `--use-latest` clears it.
- [ ] `--rollback` restores the immutable `.last-good` tag and persists it.
- [ ] PostgreSQL, Redis, and app storage data survive a deploy and a stack
  restart.
- [ ] The production database starts empty; no development rows are migrated.
- [ ] Live trading remains disabled unless the operator explicitly changes the
  production `.env`.
- [ ] No secret is present in the repository or the image.
- [ ] The deployment works with no inbound connection to the NAS.

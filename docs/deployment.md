# Production Deployment on a QNAP NAS

This runbook describes the outbound-only production deployment for the trading
bot. GitHub Actions tests every pull request and every push to `main`. A green
`main` build publishes three private GHCR images. A scheduled task on the NAS
pulls the desired image and activates it, waiting for all seven services to become
healthy.

The architecture, trade-offs, and failure-handling decisions are recorded in
[the QNAP CI/CD design](superpowers/specs/2026-11-22-qnap-ci-deploy-design.md).
The OpenSpec change is `add-qnap-ci-deploy`.

## What runs where

- **GitHub Actions** runs the PHP test suite. `main` builds publish
  `ghcr.io/johnson407/trading-bot`,
  `ghcr.io/johnson407/trading-bot-nginx`, and
  `ghcr.io/johnson407/trading-bot-engine`, tagged with `sha-<commit>` and
  `latest`.
- **GHCR** stores all three private images. The NAS needs only `read:packages`.
- **QNAP NAS** runs the seven containers from `docker-compose.prod.yml`. It polls
  outbound on a five-minute cron and never accepts an inbound deployment
  connection.

The production images contain the application code, Composer production
dependencies, and compiled frontend assets. The NAS does not need the repository,
PHP, Composer, or Node.js.

## Preflight

Run these on the NAS over SSH before the first install. The account is
`johnson-admin`, and the generated key `~/.ssh/id_ed25519_qnap` is authorized.

```sh
export PATH="/share/CACHEDEV2_DATA/.qpkg/container-station/bin:$PATH"
uname -m
id
docker version
docker compose version
docker-compose version
ls -ld /share/Docker
df -h /share/Docker
ls -l /etc/config/crontab
crontab -l
```

Recorded on 2026-11-22:

| Item | Value |
| --- | --- |
| SSH host / account | `192.168.88.150` / `johnson-admin`; ED25519 host key `SHA256:L8xAi87zl8yZN3t3PLsFNM7/nl8ti/I6uy1LT0Poryo` |
| NAS / OS | QNAP `TS-X53II`, QTS `5.2.11` (build `20261114`) |
| Architecture | `x86_64`; CI images are `linux/amd64` |
| Docker Engine | `27.1.2-qnap8`; binary at `/share/CACHEDEV2_DATA/.qpkg/container-station/bin/docker`, not on the default non-interactive PATH |
| Docker Compose | `v2.29.1-qnap2` |
| Container share | `/share/Docker` → `/share/CACHEDEV2_DATA/Docker`; `/share/Container` does not exist |
| Free space | 1.3 TB available on `/share/CACHEDEV2_DATA` (79% used) |
| Cron state | Installed 2026-11-22 in `/etc/config/crontab`; first scheduled poll at 18:55 logged `desired image latest is already running and healthy`. QTS reads `/tmp/cron/crontabs/admin`, which must match the source file. |

Port `8000` is free; port `443` is already used by QTS. The Docker socket is
`/var/run/docker.sock`, owned by `admin:administrators`; `johnson-admin` is in
`administrators` and can run Docker when the Container Station directory is on
`PATH`. If `docker compose version` is unavailable, the deploy script falls back
to `docker-compose`. The architecture must be `amd64`; the CI images are built
for `linux/amd64`.

Budget about 3 GiB of memory for the Compose limits (roughly 2.7 GiB of
service limits plus host headroom) and at least 1 GiB of free disk beyond the
images, database, and Redis data. The service defaults are 512 MiB each for
`app`, `engine`, `horizon`, and `postgres`, 256 MiB each for `scheduler` and
`redis`, and 128 MiB for `nginx`; override them with the `*_MEM_LIMIT`
variables in `.env` when the host has more or less capacity. Docker log rotation
bounds each service near 30 MiB, so keep several GiB free for images and data.
Check `df -h /share/Docker` and `docker stats` after the first deployment.

### Upgrading an existing deployment

Production Compose requires `ENGINE_INTERNAL_TOKEN`. Before deploying a
`latest` image to an existing NAS whose `.env` predates the token, add it and
keep the file at mode `600`:

```sh
printf 'ENGINE_INTERNAL_TOKEN=%s\n' "$(openssl rand -hex 32)" >> .env
chmod 600 .env
```

The `app` and `engine` services read the same value. If the token is missing,
Compose interpolation fails before the containers start and the deployer
records an application-tier failure and holds the image. Set the token first;
then run the normal poll or deployment command. Rotate by replacing the value
and recreating both services together.

Releases that add running-paper reconcile require a two-phase rollout:

1. Run the forward migration from the current app container:
   `docker compose exec app php artisan migrate --force`. This adds
   `bots.reconcile_pending`; the old app and engine ignore it.
2. Deploy the engine image that understands `reconcile`.
3. Deploy the app image that publishes reconcile commands.

Do not deploy the new app before the engine: an old engine would reset strategy
state without cancelling open orders. The new engine also requires the column,
so the migration must run before the engine image starts.

Roll back in reverse: app first, then clear pending reconcile requests, then
engine. The old engine ignores `reconcile_pending`, but clearing prevents a
later upgrade from acting on a stale request. Do not clear
`cancel_retry_requested`; it is used by other cancellation retries.

```sql
UPDATE bots
   SET reconcile_pending = false
 WHERE reconcile_pending = true;
```

## NAS layout

Keep configuration, state, and logs together in a persistent directory. The
examples use `/share/Docker/trading-bot`:

```text
/share/Docker/trading-bot/
├── docker-compose.prod.yml   # reference copy of the repository file
├── deploy.sh                 # outbound deploy script
├── .env                      # secrets and runtime configuration, mode 600
├── .docker/                  # registry credential, mode 700/600
├── .desired-tag              # optional pin; absent means latest
├── .last-good                # last fully healthy tag and image IDs
├── .hold                     # failed image ID to skip
└── deploy.log                # deployment log, capped near 1 MB
```

The script resolves its state relative to its own directory and does not need the
repository. The compose file is the reference version from the repository; update
the NAS copy manually when infrastructure changes. Compose project volumes are
named `app-storage`, `postgres-data`, and `redis-data` inside the project
namespace (for example, `trading-bot_app-storage`).

The compose stack publishes only the Nginx port (`APP_PORT`, default `8000`).
PostgreSQL and Redis are internal. Application storage is shared read-only with
Nginx so public uploads work.

## First installation

1. Create the directory and copy the two runtime files. In each NAS shell, put
   Container Station's Docker binary on `PATH` first:

   ```sh
   export PATH="/share/CACHEDEV2_DATA/.qpkg/container-station/bin:$PATH"
   ssh johnson-admin@192.168.88.150 'mkdir -p /share/Docker/trading-bot'
   scp docker-compose.prod.yml deploy/qnap/deploy.sh \
       johnson-admin@192.168.88.150:/share/Docker/trading-bot/
   ssh johnson-admin@192.168.88.150 \
       'chmod 755 /share/Docker/trading-bot/deploy.sh'
   ```

2. Create `.env` on the NAS. Never commit it or bake it into an image. Start from
   `.env.example` and set at least:

   ```dotenv
   APP_NAME="Grid Trading Bot"
   APP_ENV=production
   APP_KEY=base64:<32 random bytes>
   APP_DEBUG=false
   APP_URL=http://192.168.88.150:8000
   APP_PORT=8000

   DB_CONNECTION=pgsql
   DB_HOST=postgres
   DB_PORT=5432
   DB_DATABASE=trading_bot
   DB_USERNAME=trading_bot
   DB_PASSWORD=<strong password>

   REDIS_HOST=redis
   REDIS_PORT=6379
   QUEUE_CONNECTION=redis
   CACHE_STORE=redis
   SESSION_DRIVER=database

   TRADING_LIVE_ENABLED=false

   ENGINE_INTERNAL_TOKEN=<openssl rand -hex 32>
   ```

   Use the same `ENGINE_INTERNAL_TOKEN` value for the `app` and `engine`
   services. In production the app and engine refuse to start when the listener
   is reachable beyond loopback and the token is empty. To rotate it, replace
   the value in `.env` and restart both services together; Laravel sends it as
   `Authorization: Bearer <token>` on plan requests.

   Generate a key locally with `php artisan key:generate --show`, or with:

   ```sh
   printf 'base64:%s\n' "$(openssl rand -base64 32)"
   ```

   Prototype the database with the same `DB_DATABASE`, `DB_USERNAME`, and
   `DB_PASSWORD` values; the `postgres` service creates them on first start.
   Keep `APP_KEY` stable. Exchange credentials are encrypted with it, so changing
   the key makes existing credentials unreadable.

   ```sh
   chmod 600 /share/Docker/trading-bot/.env
   ```

3. Log in to GHCR with a GitHub **personal access token (classic)** that has only
   `read:packages`. Fine-grained tokens do not support GitHub Packages/GHCR. Do
   not reuse a write-capable CI token. Store the credential beside the deployment
   files so the scheduled task works regardless of which account runs it:

   ```sh
   mkdir -p /share/Docker/trading-bot/.docker
   chmod 700 /share/Docker/trading-bot/.docker
   printf '%s' '<read-only-token>' | \
       DOCKER_CONFIG=/share/Docker/trading-bot/.docker \
       docker login ghcr.io -u johnson407 --password-stdin
   chmod 600 /share/Docker/trading-bot/.docker/config.json
   ```

   If the registry rejects `johnson407`, retry with `x-access-token` as the
   username. `deploy.sh` automatically uses `.docker/config.json` when it exists.
   Rotate the credential by logging in again with a new token and revoking the
   old one.

4. Validate the stack definition and the script:

   ```sh
   cd /share/Docker/trading-bot
   docker compose -f docker-compose.prod.yml config
   DRY_RUN=1 ./deploy.sh
   ```

   A dry run prints the pull, activation, and health-wait commands and changes no
   state. It does not pull images.

5. Perform the first real deployment:

   ```sh
   ./deploy.sh
   ```

   The first poll deploys `latest`. The script pulls all three images, starts the
   stack, and waits up to 180 seconds for `app`, `nginx`, `horizon`, `scheduler`,
   `postgres`, and `redis` to report healthy. On success it records `.last-good`
   and removes `.hold`.

6. Verify the stack:

   ```sh
   docker compose -f docker-compose.prod.yml ps
   curl -fsS http://127.0.0.1:8000/up
   curl -fsS http://127.0.0.1:8000/login > /dev/null
   docker compose -f docker-compose.prod.yml logs --tail=100 app horizon scheduler
   ```

   First deployment recorded on 2026-11-22: image
   `sha-b4239202475ad86b94b8580663fb750980088c27`, all seven services healthy,
   `/up` and `/login` returned HTTP 200, `app_env=production`, live trading
   disabled, and the database contained zero users before bootstrap. The UI is
   available at `http://192.168.88.150:8000`.

   End-to-end update verified on 2026-11-22: the green `main` commit
   `81a91d7da1be02e162013715502073d598c8cb84` was published, the `19:15` cron
   poll activated `sha-81a91d7da1be02e162013715502073d598c8cb84` at `19:16:44`
   (within one five-minute interval), all seven services were healthy, and `/up`
   returned `Application up`. The `19:05` and `19:10` polls correctly skipped
   because the running image had not changed.

7. Create the first account. The production database starts empty; no development
   rows or exchange credentials are copied. Open `/register`, create the account,
   and verify the email. Mail uses the `log` driver by default, so the verification
   link is in `storage/logs/laravel.log` inside the `app` container:

   ```sh
   docker compose -f docker-compose.prod.yml exec app \
       tail -n 50 storage/logs/laravel.log
   ```

   Alternatively, create a verified account explicitly:

   ```sh
   docker compose -f docker-compose.prod.yml exec app \
       php artisan tinker --execute="\App\Models\User::factory()->create(['name'=>'Owner','email'=>'owner@example.com','password'=>'change-me-now']);"
   ```

8. Install the five-minute poll. On QTS, add this line to `/etc/config/crontab`
   and restart cron. `johnson-admin` cannot write that file, so use a
   sudo-capable account or the QTS Task Scheduler running as `admin`:

   ```cron
   */5 * * * * cd /share/Docker/trading-bot && ./deploy.sh >/dev/null 2>&1
   ```

   ```sh
   sudo sh -c 'echo "*/5 * * * * cd /share/Docker/trading-bot && ./deploy.sh >/dev/null 2>&1" >> /etc/config/crontab'
   sudo /etc/init.d/crond.sh restart
   grep trading-bot /etc/config/crontab
   ```

   On the tested NAS, restarting cron did not refresh the active spool. Confirm
   `/tmp/cron/crontabs/admin` matches `/etc/config/crontab`; if it does not, sync
   it and restart again:

   ```sh
   sudo cp /etc/config/crontab /tmp/cron/crontabs/admin
   sudo chown 0:0 /tmp/cron/crontabs/admin
   sudo chmod 600 /tmp/cron/crontabs/admin
   sudo /etc/init.d/crond.sh restart
   ```

   The first scheduled poll on 2026-11-22 ran at 18:55 and logged
   `desired image latest is already running and healthy; nothing to do`. The
   command also works from an account with Docker access because the script
   discovers Container Station's Docker binary and reads
   `.docker/config.json` beside itself. The script writes `deploy.log` itself, so
   cron does not redirect into the same file. If the installed QTS does not
   persist `/etc/config/crontab`, use the QTS scheduled-task UI or an
   `autorun.sh` entry with the same command. Confirm the entry survives a reboot.

## Routine operation

Push a tested commit to `main`. The `tests` job must pass, then
`build-and-push` publishes all three images. Within one polling interval the NAS
pulls the new desired image, activates it, and records it as last-good.

Watch a deployment:

```sh
cd /share/Docker/trading-bot
tail -f deploy.log
docker compose -f docker-compose.prod.yml ps
```

The deploy script takes a single-instance lock. If a poll arrives while a deploy
is running, it exits without changing anything. When the desired registry image
already matches the running application and all seven services are healthy, the
poll exits without pulling or restarting. Successful deployments prune dangling
images; tagged images and named volumes are preserved.

## State files and operator commands

| State | Meaning |
| --- | --- |
| `.desired-tag` | Tag to deploy. Absent means `latest`. A SHA pin survives polls and reboots. |
| `.last-good` | First line: last fully healthy tag. Second line: application image ID. Third line: Nginx image ID. Fourth line: engine image ID. |
| `.hold` | Application image ID that failed health checks. The same image is skipped until a different image is desired or the hold is cleared. |

```sh
./deploy.sh sha-<commit>   # pin and deploy an immutable tag
./deploy.sh --use-latest   # clear the pin and hold, deploy latest
./deploy.sh --clear-hold   # retry the currently desired tag after a failure
./deploy.sh --rollback     # deploy the last fully healthy tag
DRY_RUN=1 ./deploy.sh      # print commands only
```

A deployment is successful only when all seven services are healthy within 180
seconds. An application-tier failure (`app`, `nginx`, `horizon`, or `scheduler`)
retags the previous application, Nginx, and engine image IDs as `:rollback`, restarts them
with `--pull never`, and writes the failed image ID to `.hold`. A PostgreSQL or
Redis failure is an infrastructure failure: the script logs it, does not roll
back images, does not write a hold, and exits non-zero so the next poll retries.

Image rollback does not roll back database schema or data. Migrations should be
backward-compatible. For data recovery, restore a QNAP snapshot or `pg_dump`
backup, then deploy the matching image tag.

Running-paper reconcile is engine-first on upgrade and app-first on rollback.
After rolling the app back, clear pending reconcile flags before retagging the
engine, using the SQL in [Upgrading an existing deployment](#upgrading-an-existing-deployment).
This prevents an old engine from resetting strategy state without cancelling the
orders the new app asked it to reconcile.

## Logs and troubleshooting

The script appends to `deploy.log` and caps it near 1 MB. Compose service logs
remain available through Docker:

```sh
docker compose -f docker-compose.prod.yml logs --tail=200 app
docker compose -f docker-compose.prod.yml logs --tail=200 nginx
docker compose -f docker-compose.prod.yml logs --tail=200 horizon
docker compose -f docker-compose.prod.yml logs --tail=200 scheduler
```

Every service uses the `json-file` driver with `max-size: 10m`, `max-file: 3`,
and compression, so each service's Docker log is bounded near 30 MiB. The
`mem_limit` defaults are 512 MiB for `app`, `engine`, `horizon`, and `postgres`,
256 MiB for `scheduler` and `redis`, and 128 MiB for `nginx`. Override them with
`APP_MEM_LIMIT`, `ENGINE_MEM_LIMIT`, `HORIZON_MEM_LIMIT`,
`POSTGRES_MEM_LIMIT`, `SCHEDULER_MEM_LIMIT`, `REDIS_MEM_LIMIT`, and
`NGINX_MEM_LIMIT` in `.env`, then recreate the affected service. Watch
`docker stats` and `df -h /share/Docker`; a service that repeatedly reaches its
limit needs a larger override, and a disk near capacity needs image or log
cleanup before the next deployment.

Log retention depends on tick rate. Five bots ticking every second emit about
432,000 completion lines per day; at roughly 250-400 bytes per line that is
100-170 MB per day before compression. The 30 MiB-per-service rotation
therefore keeps only a few hours at that rate. Five bots at the default
60-second interval emit about 7,200 lines per day, roughly 2-3 MB, which the
same rotation retains for weeks. Ten one-second bots double the high-rate
figures. Operators who need longer history should ship logs to external
storage or raise `max-size`/`max-file` together with the host disk budget.

| Symptom | Action |
| --- | --- |
| Pull denied or unauthorized | Run `docker login ghcr.io` with a token that has `read:packages`; check token expiry. |
| `image ... is held after a previous failure` | Inspect the failure, then deploy a new image or run `./deploy.sh --clear-hold`. |
| `infrastructure failure` | Check PostgreSQL/Redis health and disk space. Images were not changed; the next poll retries. |
| Application unhealthy after activation | Check `app` logs and `.hold`; use `./deploy.sh --rollback` if needed. |
| Rollback cannot find images | The previous image was pruned. Deploy a known-good SHA pin and rebuild if necessary. |
| Compose v1 only | The script automatically uses `docker-compose`; confirm the file syntax is compatible. |
| Cron does not run | Check `crontab -l`, the QTS scheduled-task UI, and `deploy.log`; ensure the account can access Docker. |

Docker access is effectively root-equivalent. Use a dedicated NAS account for
the cron where practical; otherwise document the admin identity. Registry
credentials are read-only. Never put the token, `APP_KEY`, database password, or
exchange credentials in the compose file or an image.

## Verification checklist

- [ ] `uname -m` reports `x86_64`.
- [ ] `docker compose -f docker-compose.prod.yml config` succeeds.
- [ ] All three GHCR images pull with the read-only token.
- [ ] `DRY_RUN=1 ./deploy.sh` changes no state.
- [ ] First deploy reports all seven services healthy.
- [ ] `/up` and `/login` respond.
- [ ] The production database contains no development rows.
- [ ] The first account can log in.
- [ ] `crontab -l` shows the five-minute poll and survives a reboot.
- [ ] A green `main` push deploys within one polling interval.
- [ ] `TRADING_LIVE_ENABLED=false` unless live trading has been explicitly approved.
- [ ] A failed deploy holds its image and does not flap on the next poll.

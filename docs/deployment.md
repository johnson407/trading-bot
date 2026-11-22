# Production Deployment on a QNAP NAS

This runbook describes the outbound-only production deployment for the trading
bot. GitHub Actions tests every pull request and every push to `main`. A green
`main` build publishes two private GHCR images. A scheduled task on the NAS
pulls the desired image and activates it, waiting for all six services to become
healthy.

The architecture, trade-offs, and failure-handling decisions are recorded in
[the QNAP CI/CD design](superpowers/specs/2026-11-22-qnap-ci-deploy-design.md).
The OpenSpec change is `add-qnap-ci-deploy`.

## What runs where

- **GitHub Actions** runs the PHP test suite. `main` builds publish
  `ghcr.io/johnson407/trading-bot` and
  `ghcr.io/johnson407/trading-bot-nginx`, tagged with `sha-<commit>` and
  `latest`.
- **GHCR** stores both private images. The NAS needs only `read:packages`.
- **QNAP NAS** runs the six containers from `docker-compose.prod.yml`. It polls
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
| Cron state | `/etc/config/crontab` exists; `johnson-admin` cannot write it, `crontab` reports “must be suid”, and `sudo` requires a password. Use QTS Task Scheduler or a sudo-capable account. |

Port `8000` is free; port `443` is already used by QTS. The Docker socket is
`/var/run/docker.sock`, owned by `admin:administrators`; `johnson-admin` is in
`administrators` and can run Docker when the Container Station directory is on
`PATH`. If `docker compose version` is unavailable, the deploy script falls back
to `docker-compose`. The architecture must be `amd64`; the CI images are built
for `linux/amd64`.

## NAS layout

Keep configuration, state, and logs together in a persistent directory. The
examples use `/share/Docker/trading-bot`:

```text
/share/Docker/trading-bot/
├── docker-compose.prod.yml   # reference copy of the repository file
├── deploy.sh                 # outbound deploy script
├── .env                      # secrets and runtime configuration, mode 600
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
   ```

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

3. Log in to GHCR with a GitHub fine-grained or classic token that has only
   `read:packages`. Do not reuse a write-capable CI token.

   ```sh
   printf '%s' '<read-only-token>' | \
       docker login ghcr.io -u johnson407 --password-stdin
   ```

   The credential is stored in the deploy account's Docker config. Rotate it by
   logging in again with a new token and revoking the old one.

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

   The first poll deploys `latest`. The script pulls both images, starts the
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
   and restart cron:

   ```cron
   */5 * * * * cd /share/Docker/trading-bot && ./deploy.sh >/dev/null 2>&1
   ```

   ```sh
   /etc/init.d/cron restart
   crontab -l
   ```

   The script writes `deploy.log` itself, so cron does not redirect into the same
   file. If the installed QTS does not persist `/etc/config/crontab`, use the QTS
   scheduled-task UI or an `autorun.sh` entry with the same command. Confirm the
   entry survives a reboot.

## Routine operation

Push a tested commit to `main`. The `tests` job must pass, then
`build-and-push` publishes both images. Within one polling interval the NAS
pulls the new desired image, activates it, and records it as last-good.

Watch a deployment:

```sh
cd /share/Docker/trading-bot
tail -f deploy.log
docker compose -f docker-compose.prod.yml ps
```

The deploy script takes a single-instance lock. If a poll arrives while a deploy
is running, it exits without changing anything. Successful deployments prune
dangling images; tagged images and named volumes are preserved.

## State files and operator commands

| State | Meaning |
| --- | --- |
| `.desired-tag` | Tag to deploy. Absent means `latest`. A SHA pin survives polls and reboots. |
| `.last-good` | First line: last fully healthy tag. Second line: application image ID. Third line: Nginx image ID. |
| `.hold` | Application image ID that failed health checks. The same image is skipped until a different image is desired or the hold is cleared. |

```sh
./deploy.sh sha-<commit>   # pin and deploy an immutable tag
./deploy.sh --use-latest   # clear the pin and hold, deploy latest
./deploy.sh --clear-hold   # retry the currently desired tag after a failure
./deploy.sh --rollback     # deploy the last fully healthy tag
DRY_RUN=1 ./deploy.sh      # print commands only
```

A deployment is successful only when all six services are healthy within 180
seconds. An application-tier failure (`app`, `nginx`, `horizon`, or `scheduler`)
retags the previous application and Nginx image IDs as `:rollback`, restarts them
with `--pull never`, and writes the failed image ID to `.hold`. A PostgreSQL or
Redis failure is an infrastructure failure: the script logs it, does not roll
back images, does not write a hold, and exits non-zero so the next poll retries.

Image rollback does not roll back database schema or data. Migrations should be
backward-compatible. For data recovery, restore a QNAP snapshot or `pg_dump`
backup, then deploy the matching image tag.

## Logs and troubleshooting

The script appends to `deploy.log` and caps it near 1 MB. Compose service logs
remain available through Docker:

```sh
docker compose -f docker-compose.prod.yml logs --tail=200 app
docker compose -f docker-compose.prod.yml logs --tail=200 nginx
docker compose -f docker-compose.prod.yml logs --tail=200 horizon
docker compose -f docker-compose.prod.yml logs --tail=200 scheduler
```

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
- [ ] Both GHCR images pull with the read-only token.
- [ ] `DRY_RUN=1 ./deploy.sh` changes no state.
- [ ] First deploy reports all six services healthy.
- [ ] `/up` and `/login` respond.
- [ ] The production database contains no development rows.
- [ ] The first account can log in.
- [ ] `crontab -l` shows the five-minute poll and survives a reboot.
- [ ] A green `main` push deploys within one polling interval.
- [ ] `TRADING_LIVE_ENABLED=false` unless live trading has been explicitly approved.
- [ ] A failed deploy holds its image and does not flap on the next poll.

# Security Checklist

This checklist is the evidence record for the MVP security review. Each control
names the code that implements it and the test or command used to verify it.

## Authentication

- [x] Breeze authentication is enabled for the dashboard and all bot resources.
  Routes: `routes/web.php` wraps bot, kill-switch, credential, and profile routes
  in `auth`; the bot group also requires `verified`.
- [x] Guests are redirected to `/login` and cannot reach protected pages.
  Evidence: `tests/Feature/AuthorizationTest.php`.
- [x] Login attempts are limited to five per email + IP and cleared on success.
  `app/Http/Requests/Auth/LoginRequest.php`; failed attempts raise a lockout.
- [x] Email verification and password reset routes are rate-limited
  (`throttle:6,1` in `routes/auth.php`).

## Authorization

- [x] Bot ownership is enforced for view, update, archive, restore, and control.
  `app/Policies/BotPolicy.php`.
- [x] Exchange credential ownership is enforced for view, update, and delete.
  `app/Policies/ExchangeCredentialPolicy.php`.
- [x] Controllers call `authorize()` before sensitive actions:
  `BotController::show/activity/start/pause/stop/destroy/restore`,
  `ExchangeCredentialController::edit/update/destroy`.
- [x] Cross-user access is denied with 403 and denied guests are redirected.
  Evidence: `tests/Feature/AuthorizationTest.php`,
  `tests/Feature/BotControlTest.php`,
  `tests/Feature/BotArchiveTest.php`,
  `tests/Feature/ExchangeCredentialUiTest.php`,
  `tests/Feature/ActivityLogTest.php`.

## Credential protection

- [x] API key, secret, and passphrase use Laravel's `encrypted` cast and are
  `$hidden` from serialization. `app/Models/ExchangeCredential.php`.
  The payload is the `Crypt::encryptString` format: AES-256-CBC with a base64
  JSON envelope (`iv`, `value`, `mac`, `tag`) keyed from `APP_KEY`, not
  PHP-serialized `Crypt::encrypt()` output.
- [x] Credentials are stored in `exchange_credentials` and never returned raw;
  the dashboard uses `toMaskedArray()` (`abcd****5678`).
- [x] Replacing a credential updates the existing `(user, exchange)` row rather
  than creating duplicates. `app/Actions/SaveExchangeCredential.php`.
- [x] `APP_KEY` is required in every deployment; losing it makes stored
  credentials unreadable by design.
- [x] Secret values are not written to application logs. Evidence:
  `tests/Feature/ExchangeCredentialTest.php`,
  `tests/Feature/ModelRelationshipsTest.php`.

## Log redaction

- [x] `App\Logging\RedactSecrets` scrubs known secret context keys and
  `key=value` patterns in messages, replacing them with `[REDACTED]`.
- [x] The processor is pushed onto the default logger in
  `AppServiceProvider::boot()`.
- [x] Evidence: `tests/Unit/RedactSecretsTest.php`.

## CSRF and request safety

- [x] Laravel's default web middleware stack, including CSRF, remains intact in
  `bootstrap/app.php`.
- [x] Every mutation uses POST/PATCH/DELETE with the CSRF token supplied by
  Inertia's axios setup.
- [x] Form requests and controller validation restrict bot and credential
  fields; models use explicit `$fillable` lists.
- [x] All money/quantity input is validated and normalized through `Decimal`;
  no raw float arithmetic is accepted.

## Rate limiting

| Surface | Limit | Implementation |
| --- | --- | --- |
| Login | 5 attempts per email + IP | `LoginRequest` manual limiter |
| Email verification / password reset | 6 per minute | `routes/auth.php` |
| Bot creation and start/pause/stop/archive/restore | 30 per minute per user/IP | `RateLimiter::for('bot-control')` |
| Kill switch | 5 per minute per user/IP | `RateLimiter::for('kill-switch')` |
| Exchange credential mutations | 10 per minute per user/IP | `RateLimiter::for('exchange-credentials')` |

Evidence: `tests/Feature/RateLimitTest.php`.

## Live-trading safeguards

- [x] Live mode is disabled by default. `TRADING_LIVE_ENABLED=false` in the NAS
  `.env` is the single gate: Laravel reads `config('trading.live_enabled')`, the
  engine reads the same value and reports it in the heartbeat as `live_trading`,
  and production Compose passes `${TRADING_LIVE_ENABLED:-false}`. There is no
  second switch and no per-service override.
- [x] The disable rule is always available: set `TRADING_LIVE_ENABLED=false` in
  `.env`, recreate `app`, `engine`, `horizon`, and `scheduler`, then verify the
  application config and the engine heartbeat both report false. A failing final
  default `live:preflight` requires this immediately.
- [x] Exchange API keys are trade-only: spot trading enabled, withdrawals
  disabled, and an exchange-side IP allowlist for the NAS egress address. Never
  use a key with withdrawal or transfer permission for the bot.
- [x] Live go-live, testnet evidence, canary limits, kill switch, manual flatten,
  restart reconciliation, rollback, and the named escalation owner are documented
  in the [live-trading runbook](live-trading.md).
- [x] Creating or starting a live bot requires `<bot name> on <exchange>` as
  confirmation, and starting also requires a free quote balance at least equal to
  the capital allocation.
  `app/Domain/Risk/LiveModeGuard.php`; the engine repeats the balance check and
  pauses live starts that fail it.
- [x] Live placement, cancellation, and reconciliation run in the Go engine only
  when the gate is open. The engine persists a client order ID before the
  network call, looks it up before retrying, and records a rejection without a
  phantom open order.
- [x] Live failures pause the bot and cancel open orders; there is no silent
  fallback to paper. A closed gate pauses the bot and records
  `risk.live_trading_disabled`.
- [x] Testnet is the first live rollout target. Binance testnet requests use
  `https://testnet.binance.vision` and refuse a custom relay or production host;
  production real-money live requires an explicit operator yes.
- [x] Archiving requires both status and desired status to be stopped, no pending,
  open, or partially-filled orders, and a fresh engine heartbeat for a live bot.
  Archived bots are inert and excluded from engine ticks, operational lists,
  selectors, and the kill switch. `BotController::destroy`.
- [x] The kill switch writes `desired_status=paused`, publishes `kill_switch`, and
  cancels open live orders locally when the engine heartbeat is expired or the
  command cannot be published. `app/Actions/ActivateKillSwitch.php`.

## Transport and deployment

- [ ] TLS and HSTS termination are required before live trading. Terminate TLS
  at the QNAP reverse proxy (or a dedicated proxy) in front of the published
  Nginx port, redirect HTTP to HTTPS, and send
  `Strict-Transport-Security: max-age=31536000; includeSubDomains`. Set
  `APP_URL` to the public `https://` URL. The live preflight blocks a non-HTTPS
  URL outside local/testing.
- [ ] Mail uses a non-log transport with a monitored from address before live
  trading: set `MAIL_MAILER` to `smtp` (or another real transport) and
  `MAIL_FROM_ADDRESS`. The live preflight blocks the `log` and `array` mailers.
- [x] `docker/nginx/default.conf` proxies only the application and exposes the
  health endpoints. `GET /health/engine` reports the Go engine heartbeat as
  informational JSON (503 when stale); it never pauses bots or cancels orders.
- [x] Secrets are supplied through environment variables, not committed files;
  `.env` is ignored and `.env.example` contains placeholders.
- [x] `.env` permissions follow a split policy. On the NAS keep the file at mode
  `600` (`chmod 600 /share/Docker/trading-bot/.env`) so only the deployment
  account can read database, mail, and exchange settings. Use mode `644` only
  for the local Docker Compose bind mount: PHP-FPM, Horizon, and the scheduler
  run as Alpine `www-data` there, and a host-owned `600` file is unreadable
  inside the container, so Laravel falls back for keys Compose does not inject.
  The `644` exception never applies to the NAS.

## Residual risks

- No two-factor authentication in the MVP.
- Exchange permissions are enforced at the exchange. The trade-only,
  withdrawals-disabled, and IP-allowlist rules are operator controls; a
  misconfigured key is not caught by the application.
- TLS/HSTS termination and the non-log mailer are deployment controls, not
  application code. Both are required before the live gate is enabled.
- Coverage measurement currently requires a PHP build with Xdebug/PCOV; the
  static PHP build used in this environment has neither. Static analysis and the
  full test suite remain the enforced gates.
- Rate limiting is per application instance and uses the configured cache store;
  use Redis in production so all web workers share counters.
- The Go engine is part of the production stack: `docker-compose.prod.yml`
  includes the `engine` service, GHCR publishes
  `ghcr.io/johnson407/trading-bot-engine`, QNAP health and rollback cover it, and
  CI runs `go test ./...` with a PostgreSQL service.

## Review commands

```bash
php artisan test
composer test
composer validate --no-check-publish
php -l app/Providers/AppServiceProvider.php
php artisan route:list --name=bots
```

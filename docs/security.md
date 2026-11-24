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

- [x] Bot ownership is enforced for view, update, delete, and control.
  `app/Policies/BotPolicy.php`.
- [x] Exchange credential ownership is enforced for view, update, and delete.
  `app/Policies/ExchangeCredentialPolicy.php`.
- [x] Controllers call `authorize()` before sensitive actions:
  `BotController::show/activity/start/pause/stop`,
  `ExchangeCredentialController::edit/update/destroy`.
- [x] Cross-user access is denied with 403 and denied guests are redirected.
  Evidence: `tests/Feature/AuthorizationTest.php`,
  `tests/Feature/BotControlTest.php`,
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
| Bot creation and start/pause/stop | 30 per minute per user/IP | `RateLimiter::for('bot-control')` |
| Kill switch | 5 per minute per user/IP | `RateLimiter::for('kill-switch')` |
| Exchange credential mutations | 10 per minute per user/IP | `RateLimiter::for('exchange-credentials')` |

Evidence: `tests/Feature/RateLimitTest.php`.

## Live-trading safeguards

- [x] Live mode is disabled by default: `TRADING_LIVE_ENABLED=false`.
- [x] Creating or starting a live bot requires `<bot name> on <exchange>` as
  confirmation, and starting also requires a free quote balance at least equal to
  the capital allocation.
  `app/Domain/Risk/LiveModeGuard.php`.
- [x] Live failures pause the bot and cancel open orders in the Go engine; there
  is no silent fallback to paper.
- [x] The kill switch writes `desired_status=paused`, publishes `kill_switch`, and
  cancels open live orders locally when the engine heartbeat is expired or the
  command cannot be published. `app/Actions/ActivateKillSwitch.php`.

## Transport and deployment

- [ ] HTTPS termination is a deployment responsibility. Nginx must redirect
  HTTP to HTTPS and set HSTS in production.
- [x] `docker/nginx/default.conf` proxies only the application and exposes the
  health endpoints. `GET /health/engine` reports the Go engine heartbeat as
  informational JSON (503 when stale); it never pauses bots or cancels orders.
- [x] Secrets are supplied through environment variables, not committed files;
  `.env` is ignored and `.env.example` contains placeholders.
- [ ] `.env` permissions follow a split policy. Non-Docker setups should keep
  mode `600` so other local users cannot read database and exchange settings.
  Under the Docker Compose bind-mount, keep mode `644`: PHP-FPM, Horizon, and the
  scheduler run as Alpine `www-data`, not the host user, and must be able to read
  the file. A `600` file owned by the host user is unreadable inside the
  container, so Laravel falls back for keys Compose does not inject.

## Residual risks

- No two-factor authentication in the MVP.
- API-key permissions are enforced at the exchange; operators must create
  read + spot-trade keys with withdrawals disabled and an IP allowlist.
- Coverage measurement currently requires a PHP build with Xdebug/PCOV; the
  static PHP build used in this environment has neither. Static analysis and the
  full test suite remain the enforced gates.
- Rate limiting is per application instance and uses the configured cache store;
  use Redis in production so all web workers share counters.
- Phase 8 is released on the `hybrid-engine` branch/tag until `add-qnap-ci-deploy`
  adds the engine to production Compose, GHCR, QNAP rollback, and CI `go test`.

## Review commands

```bash
php artisan test
composer test
composer validate --no-check-publish
php -l app/Providers/AppServiceProvider.php
php artisan route:list --name=bots
```

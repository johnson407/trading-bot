# Tasks

## 1. Tick completion telemetry

- [x] 1.1 Add a tick-scoped statistics value, correlation ID, and a single deferred completion logger in `engine/internal/runtime/tick.go`; verify `RUNTIME_TEST_DSN=... go test ./internal/runtime` observes exactly one `bot tick completed` record and that `make test` uses the same DSN.
- [x] 1.2 Count orders placed and canceled, paper and live fills, and grid created/matched/replaced plus levels in the tick path; verify a runtime test asserts non-zero counts for a tick that places and cancels orders.
- [x] 1.3 Emit the completion record for `completed`, `paused`, `failed`, and `panicked` outcomes; mark `failed` in `tickFailure` before its nil returns, mark `paused` on every tick pause path, and recover/re-panic for panic logging; verify one record per tick for each outcome and that no credential, token, secret, or signature appears.
- [x] 1.4 Thread the tick correlation ID through order placement, cancellation, paper-fill, live-fill, and reconciliation records; verify at least one record of each kind shares the completion record's correlation ID.
- [x] 1.5 Document the tick fields, outcome vocabulary, correlation ID, and an example query in `docs/runtime.md` and `docs/engine.md`; verify every documented field name exists in the completion logger.

## 2. Production log and memory bounds

- [x] 2.1 Add a shared logging anchor with `driver: json-file`, `max-size: 10m`, `max-file: 3`, and compression to every service in `docker-compose.prod.yml`; verify a new `tests/Feature/ProductionComposeTest.php` asserts rotation and the explicit driver for all seven services.
- [x] 2.2 Add overridable `mem_limit` defaults to every production service (app/Horizon/engine/PostgreSQL 512 MiB, scheduler/Redis 256 MiB, Nginx 128 MiB); verify the same test asserts a limit for each service.
- [x] 2.3 Document the limits, rotation, expected retention at one-second tick rates, how to raise a limit, and a host RAM/disk preflight in `docs/deployment.md`; verify the documented variables match the Compose file.

## 3. Internal token hardening

- [x] 3.1 Require `ENGINE_INTERNAL_TOKEN` with Compose's required-variable interpolation on the production app and engine services, and add a blank/commented placeholder to `.env.example`; verify `ProductionComposeTest` asserts the requirement and `docker compose -f docker-compose.prod.yml config` fails without it.
- [x] 3.2 Validate the production/non-loopback/token combination in `config.Load` (or at the top of `main` before any pool, consumer, scheduler, or feed is created), and keep the local warning; verify unit tests cover production/loopback, production/non-loopback, local/non-loopback, and that missing-token startup never starts a worker.
- [x] 3.3 Document token generation, rotation, and the fail-closed behavior in `docs/deployment.md`, `docs/engine.md`, and `engine/README.md`; verify the documented commands and variable names match the code and Compose file.

## 4. Integration verification

- [x] 4.1 Run `cd engine && make test` with `RUNTIME_TEST_DSN`, the focused PHP tests including `ProductionComposeTest` and engine docs tests, and `docker compose -f docker-compose.prod.yml config` with a test token; verify all pass.
- [x] 4.2 Run `openspec validate engine-observability-ops-hardening --strict`; verify it passes.

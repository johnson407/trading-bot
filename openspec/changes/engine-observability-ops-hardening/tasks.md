# Tasks

## 1. Tick completion telemetry

- [ ] 1.1 Add a tick-scoped statistics value, correlation ID, and deferred completion logger in `engine/internal/runtime/tick.go`; verify `cd engine && make test` passes and a focused runtime log test observes `bot tick completed`.
- [ ] 1.2 Count orders placed and canceled, paper and live fills, and grid created/matched/replaced plus levels in the tick path; verify a runtime test asserts non-zero counts for a tick that places and cancels orders.
- [ ] 1.3 Emit the completion record for success, pause, failure, and panic paths with duration and outcome, and remove the duplicate failure warning; verify tests cover each outcome and that no credential, token, secret, or signature appears.
- [ ] 1.4 Document the tick fields and an example query in `docs/runtime.md` and `docs/engine.md`; verify every documented field name exists in the completion logger.

## 2. Production log and memory bounds

- [ ] 2.1 Add a shared logging anchor with `max-size: 10m`, `max-file: 3`, and compression to every service in `docker-compose.prod.yml`; verify a new `tests/Feature/ProductionComposeTest.php` asserts rotation for all seven services.
- [ ] 2.2 Add overridable `mem_limit` defaults to every production service (app/Horizon/engine/PostgreSQL 512 MiB, scheduler/Redis 256 MiB, Nginx 128 MiB); verify the same test asserts a limit for each service.
- [ ] 2.3 Document the limits, rotation, and how to raise a limit in `docs/deployment.md`; verify the documented variables match the Compose file.

## 3. Internal token hardening

- [ ] 3.1 Require `ENGINE_INTERNAL_TOKEN` with Compose's required-variable interpolation on the production app and engine services, and add the token to `.env.example`; verify `ProductionComposeTest` asserts the requirement and `docker compose -f docker-compose.prod.yml config` fails without it.
- [ ] 3.2 Replace the plan-endpoint warning with a production-only fail-closed check when the listener is not loopback and the token is empty; verify unit tests cover production/loopback, production/non-loopback, and local/non-loopback combinations.
- [ ] 3.3 Document token generation, rotation, and the fail-closed behavior in `docs/deployment.md`, `docs/engine.md`, and `engine/README.md`; verify the documented commands and variable names match the code and Compose file.

## 4. Integration verification

- [ ] 4.1 Run `cd engine && make test`, the focused PHP tests including `ProductionComposeTest` and engine docs tests, and `docker compose -f docker-compose.prod.yml config` with a test token; verify all pass.

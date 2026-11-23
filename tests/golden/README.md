# PHP engine golden fixtures

These fixtures are a deterministic snapshot of the **current committed PHP
engine**, captured before the phase-4 Go ports. They are inputs for the Go
tests in later tasks (4.2–4.8) and are not used by the runtime.

The Go parity tests read these committed files directly from the repository
root (via `engine/internal/goldenfixture`). Do not copy them into `engine/`:
the files here remain authoritative. Run the Go suite with the repository root
mounted, for example:

```bash
docker run --rm -v "$PWD:/src" -w /src/engine \
  -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod \
  golang:1.24-alpine go test ./...
```

The capture harness calls the production classes directly. It does not
reimplement, wrap, or change engine behaviour:

- `app/Console/Commands/DispatchBotTicks.php`, `app/Jobs/RunBotTick.php`,
  `app/Domain/Bot/BotHealthMonitor.php` — tick due-check, overlap/uniqueness,
  and stale threshold.
- `app/Domain/Grid/GridGenerator.php`, `app/Domain/Grid/GridDerivation.php`,
  and `BotTickPipeline::syncGrid()` — grid generation and sync.
- `app/Services/Exchange/PaperExchangeClient::qualifyingFills()` and
  `BotTickPipeline` tick-time fill processing — paper fills.
- `app/Strategies/{Simple,Dynamic,Martingale,Trailing}GridStrategy.php` — the
  four strategies.

The harness lives in `tests/Support/GoldenEngineFixtures.php`; the verification
test is `tests/Feature/GoldenEngineFixturesTest.php`
(`it matches the committed PHP golden engine fixtures`).

The phase-5 risk harness lives in `tests/Support/GoldenRiskFixtures.php`; its
verification test is `tests/Feature/GoldenRiskFixturesTest.php`. It calls the
production `StartBot`/`LiveModeGuard`, `RiskGuard`, `DailyLossMonitor`,
`ExitRules`/`ExitGuardMonitor`, `ConsecutiveFailureTracker`, and the
`StoreBotRequest` allowlist validation directly. No PHP engine behaviour is
changed for these fixtures.

## Files

| File | Covers | Later task |
| --- | --- | --- |
| `tests/golden/engine/tick_loop.json` | `bots:dispatch` due-check (60s default, 1s minimum, paused/stopped skip), dispatcher cache guard, `RunBotTick` uniqueness/overlap, `BotHealthMonitor` stale threshold | 4.2 |
| `tests/golden/engine/grid_generation.json` | `GridGenerator` arithmetic/geometric/dynamic ladders and validation failures; `GridDerivation` percent/quantity helpers and failures | 4.3 |
| `tests/golden/engine/grid_sync.json` | `BotTickPipeline` grid creation, level matching, fill-driven sync, and trailing recenter (old grid stopped, orders canceled, new grid/orders) | 4.3 |
| `tests/golden/engine/paper_fills.json` | `qualifyingFills` buy/sell crossing, closest-first, tie-break, partial remaining quantity, fees; tick-time cross-and-recross for both sides | 4.4 |
| `tests/golden/engine/strategies.json` | Initialize, on-tick, on-fill, on-cancel, `getGridLevels`, `shouldStop`, and validation errors for all four strategies | 4.5–4.8 |
| `tests/golden/engine/risk.json` | `LiveModeGuard` gate/confirmation/balance and no live-to-paper fallback; `RiskGuard` position/open-order/inventory paths; `DailyLossMonitor` per-bot/account pause, cancel, reset, baseline isolation, and no auto-resume; protective exit rules/precedence/cancel failure; `ConsecutiveFailureTracker` threshold/reset/cancel failure; `TRADING_EXCHANGES` creation validation plus the current-PHP runtime gap | 5.1–5.6 |
| `tests/golden/engine/credentials_ciphertext.json` | `Crypt::encryptString` payload shape (AES-256-CBC, base64 JSON `iv/value/mac/tag`, not PHP-serialized) and previous-key rotation for in-memory decryption. Contains a hardcoded **test-only** key and fake plaintexts, never `.env` or `APP_KEY` | 5.7 |

## Determinism

- Clock: `Carbon::setTestNow('2026-11-23T00:00:00+00:00')`. Timestamps in
  strategy inputs are explicit `DateTimeImmutable` values, never the native
  wall clock.
- Fee rate: fixed `0.001` for paper-fill and pipeline scenarios.
- Decimal scale: `App\Domain\Support\Decimal::SCALE` = `16`; all monetary and
  quantity values are decimal strings, never JSON floats. The only deliberate
  float values are the `GridDerivation` rejection cases that prove floats are
  refused.
- No database IDs, random client order IDs, exchange order IDs, wall-clock
  timestamps, or tick durations are recorded. Grids are indexed by creation
  order; orders are recorded by grid index, level sequence, side, price,
  quantity, and status.
- The files are pretty-printed JSON with unescaped slashes and a trailing
  newline. Regenerating them must produce no diff.

`risk.json` uses a fresh user/bot per case, deterministic `client_order_id`
values, and a fixed clock. Database order IDs are stripped from event contexts,
bot IDs in exception messages are normalized to `{bot_id}`, and tick durations
are not recorded. The current-PHP allowlist gap is captured explicitly:
creation validation rejects a non-allowlisted exchange, while a directly
inserted row can still start and place in current PHP. Go task 5.6 adds the
runtime blocking check.

`credentials_ciphertext.json` is generated once with `Crypt::encryptString`
using the hardcoded test-only key in `GoldenRiskFixtures`; it is not derived
from `.env` or `APP_KEY`. IVs are random, so regenerating the file intentionally
produces different bytes. The test verifies decryption, payload shape, the
absence of the PHP serialization prefix, and previous-key rotation instead of
byte-for-byte equality. Do not regenerate it on every capture.

`grid_sync.json` seeds paper bots with `base_inventory = 2` so current
`RiskGuard` can place both buy and sell orders. The recorded inventory, fees,
and P&L values are whatever current PHP produces; no risk behaviour is changed
for these fixtures.

## Regenerate

Run inside the Compose `app` service with the README testing environment. The
harness writes a temporary file and renames it over the target, so only the
fixture directory (not the existing files) must be writable by `www-data`.
On a fresh checkout, make that directory writable once:

```bash
mkdir -p tests/golden/engine
chmod 0777 tests/golden/engine
```

Then capture:

```bash
docker compose exec -u www-data \
  -e APP_ENV=testing -e DB_DATABASE=trading_bot_test \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync \
  -e GOLDEN_CAPTURE=1 \
  app php artisan test --filter=GoldenEngineFixturesTest
```

Then confirm a second run is stable:

```bash
docker compose exec -u www-data \
  -e APP_ENV=testing -e DB_DATABASE=trading_bot_test \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync \
  -e GOLDEN_CAPTURE=1 \
  app php artisan test --filter=GoldenEngineFixturesTest

git diff --exit-code -- tests/golden/engine
```

The risk fixtures use the same command with the risk filter:

```bash
docker compose exec -u www-data \
  -e APP_ENV=testing -e DB_DATABASE=trading_bot_test \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array -e CONCURRENCY_DRIVER=sync \
  -e GOLDEN_CAPTURE=1 \
  app php artisan test --filter=GoldenRiskFixturesTest

git diff --exit-code -- tests/golden/engine/risk.json
```

`GOLDEN_CAPTURE=1` rewrites `risk.json` but only creates
`credentials_ciphertext.json` when it is missing, so the committed ciphertext
stays stable. To intentionally regenerate the credential stub, delete it and
rerun with `GOLDEN_CAPTURE=1` (or use `GOLDEN_CAPTURE_CIPHERTEXT=1`).
Without `GOLDEN_CAPTURE=1` the tests only verify the committed files.

## Safety

These fixtures are paper-only. The harness uses `FakeExchangeClient` and
`PaperExchangeClient`, never the live exchange client, credentials, signed
endpoints, or `TRADING_LIVE_ENABLED=true`. It does not read or change `.env`.

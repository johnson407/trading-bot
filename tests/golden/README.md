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

## Files

| File | Covers | Later task |
| --- | --- | --- |
| `tests/golden/engine/tick_loop.json` | `bots:dispatch` due-check (60s default, 1s minimum, paused/stopped skip), dispatcher cache guard, `RunBotTick` uniqueness/overlap, `BotHealthMonitor` stale threshold | 4.2 |
| `tests/golden/engine/grid_generation.json` | `GridGenerator` arithmetic/geometric/dynamic ladders and validation failures; `GridDerivation` percent/quantity helpers and failures | 4.3 |
| `tests/golden/engine/grid_sync.json` | `BotTickPipeline` grid creation, level matching, fill-driven sync, and trailing recenter (old grid stopped, orders canceled, new grid/orders) | 4.3 |
| `tests/golden/engine/paper_fills.json` | `qualifyingFills` buy/sell crossing, closest-first, tie-break, partial remaining quantity, fees; tick-time cross-and-recross for both sides | 4.4 |
| `tests/golden/engine/strategies.json` | Initialize, on-tick, on-fill, on-cancel, `getGridLevels`, `shouldStop`, and validation errors for all four strategies | 4.5–4.8 |

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

Without `GOLDEN_CAPTURE=1` the same Pest test only verifies that the committed
files match current PHP output. It never writes fixtures.

## Safety

These fixtures are paper-only. The harness uses `FakeExchangeClient` and
`PaperExchangeClient`, never the live exchange client, credentials, signed
endpoints, or `TRADING_LIVE_ENABLED=true`. It does not read or change `.env`.

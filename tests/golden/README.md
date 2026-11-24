# PHP engine golden fixtures

These files are a frozen deterministic snapshot of the PHP engine captured
before the phase 4–6 Go ports. The PHP tick pipeline, strategies runtime, and
backtest engine have since been removed, so the fixtures are **not regenerated**
and the capture harnesses no longer exist in `tests/Support`.

The Go parity tests read these committed files directly from the repository
root via `engine/internal/goldenfixture`. They are the compatibility authority
for the ported engine behaviour. Do not copy them into `engine/` and do not
edit them by hand. Run the Go suite with the repository root mounted:

```bash
docker run --rm -v "$PWD:/src" -w /src/engine \
  -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod \
  golang:1.24-alpine go test ./...
```

The snapshot was captured from the production classes before deletion:

- `DispatchBotTicks`, `RunBotTick`, and `BotHealthMonitor` — tick due-check,
  overlap/uniqueness, and stale threshold.
- `GridGenerator`, `GridDerivation`, and `BotTickPipeline::syncGrid` — grid
  generation and sync.
- `PaperExchangeClient::qualifyingFills` and the tick fill path — paper fills.
- The four PHP strategies.
- `BacktestEngine` with `SimulatedOrderBook`, `InMemoryPortfolio`,
  `BacktestRiskGuard`, `MetricsCalculator`, `ExitRules`, and
  `BacktestConfiguration` — deterministic replay.
- `CreateBacktestSweep` and `BacktestSnapshotBuilder` — sweep grouping.
- `StartBot` / `LiveModeGuard`, `RiskGuard`, `DailyLossMonitor`,
  `ExitGuardMonitor`, `ConsecutiveFailureTracker`, and request validation —
  risk and credentials.

Phase 7 moved runtime `status` and `last_error` ownership to the engine. The
risk fixture reflects engine-owned status in its assertions; the PHP capture
normalized successful start status to `desired_status`. Runtime-only activity
types are likewise mapped to their contractual engine names in that fixture.

## Files

| File | Covers |
| --- | --- |
| `tests/golden/engine/credentials_ciphertext.json` | `Crypt::encryptString` / Eloquent `encrypted` round-trip and previous-key handling |
| `tests/golden/engine/grid_generation.json` | Grid generation for each strategy and spacing type |
| `tests/golden/engine/grid_sync.json` | Level preservation, replacement, and cancel events |
| `tests/golden/engine/paper_fills.json` | Close-only paper fills, ordering, partial quantities, fees |
| `tests/golden/engine/risk.json` | Live gate, risk guard, daily loss, exit guard, consecutive failures, credentials allowlist |
| `tests/golden/engine/strategies.json` | Initialize, on-tick, on-fill, on-cancel, grid levels, validation errors |
| `tests/golden/engine/tick_loop.json` | Due-check, overlap, stale threshold |
| `tests/golden/engine/backtest_replay.json` | Replay lifecycle, fills, fees, risk/exit replay, metrics, equity, exact errors |
| `tests/golden/engine/backtest_sweep.json` | Cartesian grouping, overrides, preserved fields, 160-character labels |

## Determinism

The capture fixed the clock at `2026-11-23T00:00:00+00:00`, fee rate `0.001`,
decimal scale 16, and no wall-clock durations. Backtest `duration_ms` is
normalized to `0`; sweep bot IDs are normalized to `0`. Database IDs, random
IDs, and tick durations are not recorded. The credential stub uses a hardcoded
test-only key and is never generated from `.env` or `APP_KEY`.

## Safety

Fixtures contain no secrets. They must not be used to configure a live bot or
to infer exchange credentials. The live gate remains independent of fixture
contents.

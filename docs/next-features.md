# Next Feature Candidates

Exploration notes captured 2026-11-21. Nothing here is committed work; this is a
shortlist to revisit before opening an OpenSpec change.

> **Update:** the backtest/replay engine has since been implemented through the
> `backtest-engine` OpenSpec change. See [backtesting.md](backtesting.md). The
> remaining candidates below are still open.

## Where the project stands

The MVP is complete: 7 capability specs, 4 strategies, paper and live CCXT
execution, risk guards, kill switch, restart recovery, and a growing test suite. At
capture time there were no active OpenSpec changes.

Capabilities already in place: `bots`, `grids`, `grid_levels`, `orders`, `fills`,
`inventory_ledger_entries`, `risk_events`, `activity_logs`, strategy state, and the
Inertia dashboard.

## Gaps found in the codebase

| Missing | Evidence |
| --- | --- |
| Strategy validation | No backtest, replay, or OHLCV code. Market history is only the last 30 tick prices in `bots.settings.recent_prices`. |
| Time-series performance | No equity or P&L snapshot table. The dashboard sums current `realized_pnl` and live unrealized P&L only. |
| Proactive alerting | `RiskEventRecorder` and `ActivityRecorder` write rows, but nothing notifies the user. |
| Exit logic | No stop-loss, take-profit, or range-exit. Only the daily-loss pause and max-notional block exist. |
| Venue breadth | `trading.exchanges` is an allowlist, but credential UI and docs are Binance-only. |
| External control | No API tokens, webhooks, or data export. |

## Four directions

```
                    PROVE                        PROTECT
        +----------------------------+  +----------------------------+
        | Backtest / historical      |  | Per-bot stop-loss /        |
        | replay + parameter sweep   |  | take-profit / range guard  |
        |                            |  |                            |
        | Equity curve + drawdown    |  | Alerts: paused, breach,    |
        | analytics                  |  | failure, kill switch       |
        +----------------------------+  +----------------------------+
                    EXPAND                       OPERATE
        +----------------------------+  +----------------------------+
        | Multi-exchange support     |  | Public API + webhooks      |
        | (Kraken, Bybit, ...)       |  | CSV/tax export             |
        +----------------------------+  +----------------------------+
```

## Candidates ranked

| Candidate | Why it fits | Effort | Main risk |
| --- | --- | --- | --- |
| Backtest / replay engine | `StrategyInterface` is pure and already driven through `PaperExchangeClient`; a replay driver could run the same strategies over historical candles. | Large | Fill-model fidelity: last-price paper fills do not translate directly to candles, which need high/low plus fee/slippage rules. |
| Alerts and notifications | `RiskEventRecorder` and `ActivityRecorder` are natural event sources; Laravel `Notifiable` is already on `User`. | Small | None significant. |
| Equity curve and analytics | Fills and the inventory ledger already hold the raw data; needs a snapshot table, scheduled job, and chart view. | Medium | Backfilling history from fills. |
| Stop-loss / take-profit / range exit | Extends `RiskGuard` and `DailyLossMonitor`; fits the `risk-controls` spec. | Medium | Defining semantics when open grid orders exist. |
| Multi-exchange support | CCXT is abstracted behind `ExchangeClient`; `trading.exchanges` allowlist already exists. | Large | Symbol precision, min-notional, credential UI, per-exchange testnet coverage. |
| API / webhooks / export | No auth-token layer today. | Medium | New attack surface. |

## Recommendation recorded at capture time

1. **Primary: backtest/replay engine, scoped to one bot configuration at a
   time.** The project exists to host pluggable strategies, but there is no way to
   judge a center price, spacing, level count, or strategy variant before risking
   capital. A replay harness also makes every later strategy and venue easier to
   validate. The core design question is the candle fill model, not the plumbing.
2. **If live money is imminent, flip the order:** alerts plus a per-bot exit guard
   are the smallest changes that close real operational gaps. A bot can pause from
   a daily-loss breach or repeated failures today and nobody is told until someone
   opens the dashboard.

## Decision to resolve before starting

> Is the next milestone about **proving the strategies** (backtest), **operating
> safely with real funds** (alerts, guardrails, analytics), or **expanding reach**
> (multi-exchange, API)?

## How to pick this up

- Re-read the relevant spec before proposing: `openspec/specs/strategy-engine/`,
  `openspec/specs/risk-controls/`, `openspec/specs/trading-dashboard/`.
- Start a change with `openspec new change <name>` once the direction above is
  chosen, then validate with `openspec validate <name> --strict`.
- Hook points worth inspecting first: `app/Domain/Bot/BotTickPipeline.php`,
  `app/Domain/Risk/RiskGuard.php`, `app/Domain/Order/FillProcessor.php`, and
  `app/Http/Controllers/DashboardController.php`.

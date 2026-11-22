# Next Feature Candidates

Exploration notes captured 2026-11-21. Nothing here is committed work; this is a
shortlist to revisit before opening an OpenSpec change.

> **Update (2026-11-22):** the backtest/replay engine shipped through the
> `backtest-engine` change, and proactive alerting shipped through the
> `alerts-and-notifications` change. See [backtesting.md](backtesting.md) and
> [alerts.md](alerts.md). The remaining candidates below are still open.

## Where the project stands

The MVP is complete: 8 capability specs, 4 strategies, paper and live CCXT
execution, risk guards, kill switch, restart recovery, historical backtesting,
owner-scoped alerting, and a growing test suite. At capture time there were no
active OpenSpec changes; the alerts change is implemented and awaiting archive.

Capabilities already in place: `bots`, `grids`, `grid_levels`, `orders`, `fills`,
`inventory_ledger_entries`, `risk_events`, `activity_logs`, strategy state,
`backtesting`, `alerts`, and the Inertia dashboard.

## Gaps found in the codebase

| Missing | Evidence |
| --- | --- |
| Strategy validation | **Resolved** by the `backtest-engine` change. Strategies now run over cached OHLCV candles with a close-only fill model. |
| Proactive alerting | **Resolved** by the `alerts-and-notifications` change. Covered pauses and kill-switch events create inbox alerts; critical alerts also queue email. |
| Time-series performance | No equity or P&L snapshot table. The dashboard sums current `realized_pnl` and live unrealized P&L only. |
| Exit logic | No stop-loss, take-profit, or range-exit. Only the daily-loss pause and max-notional block exist. |
| Venue breadth | `trading.exchanges` is an allowlist, but credential UI and docs are Binance-only. |
| External control | No API tokens, webhooks, or data export. |

## Four directions

```
                    PROVE                        PROTECT
        +----------------------------+  +----------------------------+
        | Backtest / replay (shipped)|  | Per-bot stop-loss /        |
        | Equity curve + drawdown    |  | take-profit / range guard  |
        | analytics                  |  | Alerts (shipped)           |
        +----------------------------+  +----------------------------+
                    EXPAND                       OPERATE
        +----------------------------+  +----------------------------+
        | Multi-exchange support     |  | Public API + webhooks      |
        | (Kraken, Bybit, ...)       |  | CSV/tax export             |
        +----------------------------+  +----------------------------+
```

## Candidates ranked

| Candidate | Status | Why it fits | Effort | Main risk |
| --- | --- | --- | --- | --- |
| Backtest / replay engine | Shipped | `StrategyInterface` is pure and already driven through `PaperExchangeClient`; the replay driver runs the same strategies over historical candles. | Large | Fill-model fidelity: last-price paper fills do not translate directly to candles, which need high/low plus fee/slippage rules. |
| Alerts and notifications | Shipped | `RiskEventRecorder` and `ActivityRecorder` are natural event sources; the alert recorder maps a curated catalog into an inbox and queued email. | Small | None significant. |
| Equity curve and analytics | Open | Fills and the inventory ledger already hold the raw data; needs a snapshot table, scheduled job, and chart view. | Medium | Backfilling history from fills. |
| Stop-loss / take-profit / range exit | Open | Extends `RiskGuard` and `DailyLossMonitor`; fits the `risk-controls` spec, and alerts now report a resulting pause. | Medium | Defining semantics when open grid orders exist. |
| Multi-exchange support | Open | CCXT is abstracted behind `ExchangeClient`; `trading.exchanges` allowlist already exists. | Large | Symbol precision, min-notional, credential UI, per-exchange testnet coverage. |
| API / webhooks / export | Open | No auth-token layer today. | Medium | New attack surface. |

## Recommendation

**Next: per-bot stop-loss / take-profit / range exit.** Alerting closed the
"nobody is told" gap; the remaining operational gap is that the bot has no
protective exit. A stop-loss that pauses and cancels orders is now visible through
the alert channel, so the two changes reinforce each other. **After that: equity
curve and analytics**, which turns the live P&L already computed on each dashboard
request into a trend the user can judge.

The backtest direction is not exhausted: a parameter sweep over center, spacing,
and level count remains a natural follow-up to the shipped replay engine. It is
lower priority than a protective exit unless strategy research is the immediate
goal.

## Decision to resolve before starting

> Is the next milestone about **operating safely with real funds** (exit guard,
> analytics) or **expanding reach** (multi-exchange, API)?

## How to pick this up

- Re-read the relevant spec before proposing: `openspec/specs/risk-controls/`,
  `openspec/specs/trading-dashboard/`, `openspec/specs/backtesting/`, and
  `openspec/specs/alerts/` after the alerts change archives.
- Start a change with `openspec new change <name>` once the direction above is
  chosen, then validate with `openspec validate <name> --strict`.
- Hook points worth inspecting first: `app/Domain/Bot/BotTickPipeline.php`,
  `app/Domain/Risk/RiskGuard.php`, `app/Domain/Order/FillProcessor.php`, and
  `app/Http/Controllers/DashboardController.php`.

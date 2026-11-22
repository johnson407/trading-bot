# Next Feature Candidates

Exploration notes captured 2026-11-21. Nothing here is committed work; this is a
shortlist to revisit before opening an OpenSpec change.

> **Update (2026-11-22):** the backtest/replay engine shipped through the
> `backtest-engine` change, proactive alerting shipped through the
> `alerts-and-notifications` change, and per-bot protective exits shipped through
> the `bot-exit-guard` change. See [backtesting.md](backtesting.md),
> [alerts.md](alerts.md), and [risk.md](risk.md). The remaining candidates below
> are still open.

## Where the project stands

The MVP is complete: 8 capability specs, 4 strategies, paper and live CCXT
execution, risk guards, kill switch, restart recovery, historical backtesting,
owner-scoped alerting, per-bot protective exits, and a growing test suite. At
capture time there were no active OpenSpec changes; backtesting, alerting, and
protective exits have since shipped.

Capabilities already in place: `bots`, `grids`, `grid_levels`, `orders`, `fills`,
`inventory_ledger_entries`, `risk_events`, `activity_logs`, strategy state,
`backtesting`, `alerts`, per-bot protective exits, and the Inertia dashboard.

## Gaps found in the codebase

| Missing | Evidence |
| --- | --- |
| Strategy validation | **Resolved** by the `backtest-engine` change. Strategies now run over cached OHLCV candles with a close-only fill model. |
| Proactive alerting | **Resolved** by the `alerts-and-notifications` change. Covered pauses and kill-switch events create inbox alerts; critical alerts also queue email. |
| Time-series performance | No equity or P&L snapshot table. The dashboard sums current `realized_pnl` and live unrealized P&L only. |
| Exit logic | **Resolved** by the `bot-exit-guard` change. Per-bot stop-loss, take-profit, and range exits pause the bot and cancel open orders; they do not liquidate inventory. |
| Venue breadth | `trading.exchanges` is an allowlist, but credential UI and docs are Binance-only. |
| External control | No API tokens, webhooks, or data export. |

## Four directions

```
                    PROVE                        PROTECT
        +----------------------------+  +----------------------------+
        | Backtest / replay (shipped)|  | Per-bot stop-loss /        |
        | Equity curve + drawdown    |  | take-profit / range guard  |
        | analytics                  |  | (shipped), alerts (shipped)|
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
| Stop-loss / take-profit / range exit | Shipped | Extends the tick pipeline and risk controls, is configurable per bot in every state, and is reported through alerts. | Medium | Resolved: triggers pause and cancel orders, leaving inventory for manual exit. |
| Multi-exchange support | Open | CCXT is abstracted behind `ExchangeClient`; `trading.exchanges` allowlist already exists. | Large | Symbol precision, min-notional, credential UI, per-exchange testnet coverage. |
| API / webhooks / export | Open | No auth-token layer today. | Medium | New attack surface. |

## Recommendation

**Next: equity curve and analytics.** Protective exits closed the "no way out"
gap without adding a liquidation path; the remaining operational gap is that live
performance has no history. A snapshot table, scheduled capture, and a chart turn
the P&L already computed on each dashboard request into a trend the user can judge.
**After that: a parameter sweep** over center, spacing, and level count, or
multi-exchange support if reach matters more than research.

The backtest direction is not exhausted: a parameter sweep remains a natural
follow-up to the shipped replay engine. It is lower priority than live equity
analytics unless strategy research is the immediate goal.

## Decision to resolve before starting

> Is the next milestone about **understanding performance over time** (equity
> analytics) or **expanding reach** (multi-exchange, API)?

## How to pick this up

- Re-read the relevant spec before proposing: `openspec/specs/risk-controls/`,
  `openspec/specs/trading-dashboard/`, `openspec/specs/backtesting/`, and
  `openspec/specs/alerts/` after the alerts change archives.
- Start a change with `openspec new change <name>` once the direction above is
  chosen, then validate with `openspec validate <name> --strict`.
- Hook points worth inspecting first: `app/Http/Controllers/DashboardController.php`,
  `app/Models/Fill.php`, `app/Models/InventoryLedgerEntry.php`,
  `app/Domain/Portfolio/InventoryLedger.php`, and the dashboard views.

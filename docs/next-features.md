# Next Feature Candidates

Exploration notes captured 2026-11-21. Nothing here is committed work; this is a
shortlist to revisit before opening an OpenSpec change.

> **Update (2026-11-22):** the backtest/replay engine shipped through the
> `backtest-engine` change, proactive alerting shipped through the
> `alerts-and-notifications` change, per-bot protective exits shipped through
> the `bot-exit-guard` change, live account equity analytics shipped through
> the `live-equity-analytics` change, and parameter sweeps shipped through the
> `backtest-parameter-sweep` change. See [backtesting.md](backtesting.md),
> [alerts.md](alerts.md), [risk.md](risk.md), and
> [analytics.md](analytics.md). The remaining candidates below are still open.

## Where the project stands

The MVP is complete: 10 capability specs, 4 strategies, paper and live CCXT
execution, risk guards, kill switch, restart recovery, historical backtesting,
parameter sweeps, owner-scoped alerting, per-bot protective exits, account
equity history, and a growing test suite. At capture time there were no active
OpenSpec changes; backtesting, parameter sweeps, alerting, protective exits, and
equity analytics have since shipped.

Capabilities already in place: `bots`, `grids`, `grid_levels`, `orders`, `fills`,
`inventory_ledger_entries`, `risk_events`, `activity_logs`, strategy state,
`backtesting`, parameter sweeps, `alerts`, `analytics`, per-bot protective exits,
and the Inertia dashboard.

## Gaps found in the codebase

| Missing | Evidence |
| --- | --- |
| Strategy validation | **Resolved** by the `backtest-engine` change. Strategies now run over cached OHLCV candles with a close-only fill model. |
| Proactive alerting | **Resolved** by the `alerts-and-notifications` change. Covered pauses and kill-switch events create inbox alerts; critical alerts also queue email. |
| Time-series performance | **Resolved** by the `live-equity-analytics` change. Account-level snapshots are captured every five minutes, retained for 90 days, and charted with drawdown metrics. |
| Exit logic | **Resolved** by the `bot-exit-guard` change. Per-bot stop-loss, take-profit, and range exits pause the bot and cancel open orders; they do not liquidate inventory. |
| Venue breadth | `trading.exchanges` is an allowlist, but credential UI and docs are Binance-only. |
| External control | No API tokens, webhooks, or data export. |

## Four directions

```
                    PROVE                        PROTECT
        +----------------------------+  +----------------------------+
        | Backtest / replay (shipped)|  | Per-bot stop-loss /        |
        | Parameter sweep (shipped)  |  | take-profit / range guard  |
        | Equity curve + drawdown    |  | (shipped), alerts (shipped)|
        | analytics (shipped)        |  |                            |
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
| Parameter sweep | Shipped | Runs the bounded cartesian product of center prices, spacing values, and level counts against one bot and period, then reuses comparison. | Medium | Queue burst and partial failure; both are bounded by the run cap and stale-run handling. |
| Alerts and notifications | Shipped | `RiskEventRecorder` and `ActivityRecorder` are natural event sources; the alert recorder maps a curated catalog into an inbox and queued email. | Small | None significant. |
| Equity curve and analytics | Shipped | Account snapshots are captured every five minutes and charted with return, peak, and drawdown metrics; history is forward-only. | Medium | Resolved: no backfill from fills or ledger entries. |
| Stop-loss / take-profit / range exit | Shipped | Extends the tick pipeline and risk controls, is configurable per bot in every state, and is reported through alerts. | Medium | Resolved: triggers pause and cancel orders, leaving inventory for manual exit. |
| Multi-exchange support | Open | CCXT is abstracted behind `ExchangeClient`; `trading.exchanges` allowlist already exists. | Large | Symbol precision, min-notional, credential UI, per-exchange testnet coverage. |
| API / webhooks / export | Open | No auth-token layer today. | Medium | New attack surface. |

## Recommendation

**Next: multi-exchange support.** Parameter sweeps now cover the research loop:
center, spacing, and level count can be compared across a bounded set of runs,
with a live combination count, cap, derived progress, and a direct path to the
existing 2–4 run comparison. That closes the "compare settings" gap, so the next
alternative is breadth rather than more backtest machinery.

Multi-exchange support is the next candidate because CCXT is already abstracted
behind `ExchangeClient` and `trading.exchanges` is an allowlist. API/webhooks and
export remain lower priority because they add attack surface before venue breadth
is proven.

## Decision to resolve before starting

> Is the next milestone about **expanding reach** (multi-exchange) or
> **operating the account** (API, webhooks, export)?

## How to pick this up

- Re-read the relevant spec before proposing: `openspec/specs/backtesting/`,
  `openspec/specs/analytics/`, `openspec/specs/trading-dashboard/`, and
  `openspec/specs/exchange-connectivity/`.
- Start a change with `openspec new change <name>` once the direction above is
  chosen, then validate with `openspec validate <name> --strict`.
- Hook points worth inspecting first: `app/Domain/Backtest/`,
  `app/Domain/Analytics/EquityHistory.php`, `app/Models/EquitySnapshot.php`,
  `app/Console/Commands/CaptureEquitySnapshots.php`, and the backtest/dashboard
  views.

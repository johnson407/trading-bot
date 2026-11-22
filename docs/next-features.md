# Next Feature Candidates

Exploration notes captured 2026-11-21. Nothing here is committed work; this is a
shortlist to revisit before opening an OpenSpec change.

> **Update (2026-11-22):** the backtest/replay engine shipped through the
> `backtest-engine` change, proactive alerting shipped through the
> `alerts-and-notifications` change, per-bot protective exits shipped through
> the `bot-exit-guard` change, and live account equity analytics shipped through
> the `live-equity-analytics` change. See [backtesting.md](backtesting.md),
> [alerts.md](alerts.md), [risk.md](risk.md), and
> [analytics.md](analytics.md). The remaining candidates below are still open.

## Where the project stands

The MVP is complete: 10 capability specs, 4 strategies, paper and live CCXT
execution, risk guards, kill switch, restart recovery, historical backtesting,
owner-scoped alerting, per-bot protective exits, account equity history, and a
growing test suite. At capture time there were no active OpenSpec changes;
backtesting, alerting, protective exits, and equity analytics have since shipped.

Capabilities already in place: `bots`, `grids`, `grid_levels`, `orders`, `fills`,
`inventory_ledger_entries`, `risk_events`, `activity_logs`, strategy state,
`backtesting`, `alerts`, `analytics`, per-bot protective exits, and the Inertia
dashboard.

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
        | Equity curve + drawdown    |  | take-profit / range guard  |
        | analytics (shipped)        |  | (shipped), alerts (shipped)|
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
| Equity curve and analytics | Shipped | Account snapshots are captured every five minutes and charted with return, peak, and drawdown metrics; history is forward-only. | Medium | Resolved: no backfill from fills or ledger entries. |
| Stop-loss / take-profit / range exit | Shipped | Extends the tick pipeline and risk controls, is configurable per bot in every state, and is reported through alerts. | Medium | Resolved: triggers pause and cancel orders, leaving inventory for manual exit. |
| Multi-exchange support | Open | CCXT is abstracted behind `ExchangeClient`; `trading.exchanges` allowlist already exists. | Large | Symbol precision, min-notional, credential UI, per-exchange testnet coverage. |
| API / webhooks / export | Open | No auth-token layer today. | Medium | New attack surface. |

## Recommendation

**Next: a parameter sweep** over center, spacing, and level count. The live
dashboard now shows whether an account is growing and how deep its drawdowns run,
so the next research gap is comparing strategy settings instead of judging them
one run at a time. Multi-exchange support is the alternative if reach matters more
than research.

The backtest direction is not exhausted: the replay engine already supports manual
run comparison, and a parameter sweep is its natural follow-up. API/webhooks and
export remain lower priority because they add attack surface before the strategy
research loop is complete.

## Decision to resolve before starting

> Is the next milestone about **research throughput** (parameter sweep) or
> **expanding reach** (multi-exchange, API)?

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

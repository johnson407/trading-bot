# Proposal

## Why

The MVP engine is complete and the seven critical review findings are fixed, but four PRD-visible capabilities are still missing or incomplete. The PRD promises that a user can create a bot on **any supported CCXT exchange**, operate it from a navigable dashboard with success and failure feedback, confirm live mode by naming the bot and exchange, and manage bots through CRUD. Today the bot stores an exchange but always trades the global default, the dashboard is the Breeze placeholder with no navigation and no rendered flash messages, live confirmation matches only the bot name, and bots cannot be edited at all. These gaps make the product look unfinished in a demo even though the trading engine works.

## What Changes

- **Exchange selection becomes real.** Add a configured allowlist of CCXT exchanges, validate the selected exchange at creation, persist it (already stored), and make the exchange client use the bot's exchange for market data, balances, and live orders instead of the global default.
- **Dashboard becomes navigable and informative.** Add navigation to Bots, Exchange credentials, and Kill switch; replace the placeholder dashboard with a summary; render success flashes and per-field errors; add exchange and unrealized P&L columns to the bot list; link the activity log from the bot detail page.
- **Live confirmation names the exchange.** Require the exact phrase `<bot name> on <exchange>` when creating a live bot and when starting one, validate it server-side in both paths, and render the confirmation error.
- **Bot editing for idle bots.** Add edit and update routes, request validation, policy, and an edit page. Name, capital allocation, fee rate, and strategy parameters are editable; symbol, exchange, and mode are immutable after creation; editing is only allowed while the bot is idle or stopped.

## Capabilities

### Modified Capabilities

- `exchange-connectivity`: per-bot exchange selection and exchange-scoped market data.
- `trading-dashboard`: navigation, action feedback, complete bot list, bot editing.
- `risk-controls`: live-start confirmation naming the bot and exchange.

Main specs do not exist yet because the foundational change has not been archived. The delta requirements below are therefore all additive.

## Impact

- **Configuration:** new `trading.exchanges` allowlist (env `TRADING_EXCHANGES`, default `binance`), documented in `README.md` and `docs/exchanges.md`.
- **Exchange layer:** `ExchangeClientFactory` constructs a client bound to the bot's exchange; `CcxtExchangeClient` and `PaperExchangeClient` accept the exchange; no interface signature changes.
- **HTTP:** new `GET bots/{bot}/edit` and `PATCH bots/{bot}` routes, `UpdateBotRequest`, `BotPolicy@update`, and controller actions.
- **Frontend:** new `Bots/Edit.vue`, shared flash component, layout navigation, dashboard summary, updated bot list and detail pages, live confirmation field on create.
- **Tests:** feature tests for exchange threading, allowlist rejection, confirmation at create/start, edit authorization and immutability, dashboard props and flash sharing; existing fixtures updated where they assumed the global exchange.
- **Out of scope:** live fee accounting, risk-limit editing, funded-sell rules, capacity benchmarking, and dynamic recent-price input. Those belong to the later live-correctness and hardening sets.

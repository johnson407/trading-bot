# trading-bot

A cryptocurrency grid trading engine with pluggable strategies, paper-trading by default, and optional live execution through [CCXT](https://github.com/ccxt/ccxt).

The MVP targets experienced PHP/Laravel developers and quantitative traders who want a maintainable foundation they can extend — not a black-box SaaS product.

## Status

Repository initialized. The product requirements are captured in [`Grid_Trading_Bot_PRD_v1.2.docx`](./Grid_Trading_Bot_PRD_v1.2.docx); implementation has not started.

## Planned stack

| Layer | Choice |
|-------|--------|
| Framework | PHP / Laravel 12 |
| UI | Inertia.js + Vue 3 (Laravel Breeze auth) |
| Exchange connectivity | CCXT (`ccxt/ccxt`) — Binance as the default exchange |
| Queues | Redis with Laravel Horizon |
| Local environment | Docker Compose (PHP, Nginx, Redis, MySQL/PostgreSQL) |

## Planned layout

```
app/
  Domain/          Bot, Grid, GridLevel, Order, Fill, StrategyConfig, RiskLimit
  Strategies/      StrategyInterface + Simple, Trailing, Martingale, Dynamic
  Services/Exchange/  Thin CCXT adapter + paper-trading simulator
  Jobs/            RunBotTick, PlaceOrder, SyncOrders
openspec/
  specs/           Capability specs
  changes/         In-flight change proposals and tasks
docs/              Design documents (as they're written)
```

## Scope highlights

- **In scope:** strategy engine with persistent state, grid/order lifecycle, paper + live modes, authenticated dashboard, risk limits and kill-switch.
- **Out of scope:** custom exchange adapters, advanced charting/indicators, mobile apps, real-time WebSocket UI, billing/multi-tenant SaaS, DEX trading.

## Workflow

This project uses [OpenSpec](https://github.com/Fission-AI/OpenSpec) for spec-driven changes. Proposals live under `openspec/changes/`; accepted capabilities graduate to `openspec/specs/`.

## Getting started

No application code exists yet. Setup instructions will be added once the Laravel skeleton and Docker environment land.

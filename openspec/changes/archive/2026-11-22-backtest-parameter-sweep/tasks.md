## 1. Schema, models, and configuration

- [x] 1.1 Add an additive migration for `backtest_sweeps` (`user_id` cascade, nullable `bot_id` null-on-delete, name, symbol, timeframe, start_at, end_at, `parameters` JSON, `total_runs`, timestamps) and a nullable indexed `backtest_runs.sweep_id` null-on-delete; verify migrate, rollback, and re-migrate.
- [x] 1.2 Create `App\Models\BacktestSweep` with fillable fields, `parameters` array cast, date casts, `user`/`bot`/`runs` relations, and a factory; add `BacktestRun::sweep()` and `BacktestSweep` relation tests.
- [x] 1.3 Add `sweep_max_runs` (default 24, minimum 2) to `config/backtest.php` and document the cap; assert the default and minimum in a config test.

## 2. Sweep creation and validation

- [x] 2.1 Write `tests/Feature/BacktestSweepTest.php` first for the cartesian product (2 centers × 2 spacings × 2 levels = 8 runs), exact override values, one sweep row, `sweep_id` on every run, and transaction rollback when a run cannot be created.
- [x] 2.2 Add optional `array $strategyOverrides = []` to `BacktestSnapshotBuilder::build()`, applying only `center_price`, `spacing_value`, `level_count`, and `levels_per_side` after loading the bot configuration; verify the stored snapshot contains the override and no unrelated mutation.
- [x] 2.3 Implement `CreateBacktestSweep` to build the cartesian product, create the sweep and runs in one transaction, dispatch one `RunBacktest` per run after commit, and log dispatch failures without losing created runs; verify the dispatch count and stale-run-compatible failure path.
- [x] 2.4 Add `StoreBacktestSweepRequest` validation for one owned grid bot, timeframe, date range, non-empty arrays, positive center/spacing decimals, level counts 1–50, duplicate rejection, and total combinations 2..`backtest.sweep_max_runs`; verify oversized, duplicate, non-grid, foreign-bot, and invalid-date rejections.
- [x] 2.5 Add `BacktestSweepController@store` and the `backtest-sweeps.store` route; verify redirect to the sweep, owner scoping, and that a rejected request creates no sweep or runs.

## 3. Sweep reads and routes

- [x] 3.1 Add `BacktestSweepController@index` with the owner's latest sweeps, run counts via `withCount`, active/completed/failed counts, and a `BacktestSweepResource`; verify ownership, ordering, and empty state.
- [x] 3.2 Add `BacktestSweepController@create` with the owner's grid bots, timeframes, defaults, presets, maximum combinations, and validation errors; verify foreign bots are absent and the cap is exposed.
- [x] 3.3 Add `BacktestSweepController@show` with swept values, run outcomes, derived status, progress, and a compare URL for 2–4 completed runs; verify another owner gets 404 and the compare URL is absent with fewer than two completions.
- [x] 3.4 Register `backtest-sweeps.index/create/store/show` in the authenticated+verified group and add a link from `Backtests/Index.vue`; verify named routes and the rendered link.

## 4. Dashboard pages

- [x] 4.1 Build `Backtests/Sweeps/Index.vue` with sweep name, bot/symbol, period, combination count, progress, status, and links; verify Inertia props and rendered labels.
- [x] 4.2 Build `Backtests/Sweeps/Create.vue` with bot/timeframe/date selection, comma- or chip-based center/spacing/level inputs, live combination count, cap warning, validation errors, and submit; verify the page exposes the cap and posts to `backtest-sweeps.store`.
- [x] 4.3 Build `Backtests/Sweeps/Show.vue` with swept parameter summary, run table (values, status, return, drawdown, end reason), progress polling while active, run links, and a compare action for 2–4 completed runs; verify polling props and compare URL behavior.
- [x] 4.4 Run `npm run build` and verify the new pages compile and appear in the manifest.

## 5. Docs and integration

- [x] 5.1 Add a parameter-sweep section to `docs/backtesting.md` covering the cartesian product, the three overridable fields, cap, validation, grouping, derived status, compare flow, and failure behavior; add the test map entries.
- [x] 5.2 Add the sweep routes and pages to `docs/dashboard.md` and mark the parameter sweep shipped in `docs/next-features.md`, leaving multi-exchange as the next alternative.
- [x] 5.3 Add an end-to-end feature test from sweep creation through completed runs to the detail page, including one failed run and the 2–4 run compare link.
- [x] 5.4 Run the full suite with `-e APP_ENV=testing -e DB_DATABASE=trading_bot_test -e CACHE_STORE=array -e QUEUE_CONNECTION=sync`, PHPStan, scoped Pint, Vite build, and `openspec validate backtest-parameter-sweep --strict`.

## 6. Workflow follow-up

- [x] 6.1 Request a code review after all tasks are complete and resolve Critical/Important findings before archiving.
- [x] 6.2 Sync the delta specs, archive the change, commit, and push when the user asks.

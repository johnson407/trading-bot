# Design

## Context

See `proposal.md` for motivation and the `money-formatting` spec for required behavior. Monetary props currently arrive as fixed-scale decimal strings from Eloquent casts (`decimal:16` and `decimal:8`), while each Vue page owns its own formatter. Some pages use `toFixed(2)`, some use `toLocaleString` with variable digits, some render the raw prop. Editable forms seed `useForm` directly from those raw props.

The application has no JavaScript test runner. The money helper can be tested with Node's built-in test runner without adding a dependency.

## Goals / Non-Goals

**Goals:**

- One monetary presentation rule and one implementation used by every page.
- Readable two-decimal values in read-only views and editable monetary inputs.
- Preserve exact stored values; formatting is presentation only.
- Keep quantities, fee rates, percentages, timestamps, and the immutable backtest snapshot untouched.

**Non-Goals:**

- No database, migration, calculation, validation, or breaking API contract changes. A read-only `spacing_type` field is added to sweep payloads so the UI can distinguish arithmetic spacing from geometric spacing.
- No per-symbol or per-asset decimal precision; the requested rule is exactly two decimals.
- No changes to the visual theme, table layout, or chart data.
- No formatting of the raw `config_snapshot` JSON, which is a technical diagnostic record.

## Decisions

### Decision: Format at render time, not in the API

Add a shared Vue module, `resources/js/Support/money.js`, exporting `formatMoney(value)` and `formatSignedMoney(value)`.

- `formatMoney` rounds half up to exactly two decimals, accepts decimal strings, numbers, and null/empty values, never uses scientific notation, emits no grouping separators, and normalizes `-0.00` to `0.00`.
- It rounds using decimal-string arithmetic (not `Number#toFixed`) so values such as `1.005` and fixed-scale database strings round predictably.
- `formatSignedMoney` adds a leading `+` for positive values, for profit-and-loss output, and otherwise delegates to `formatMoney`.

Keeping raw values in Inertia props avoids recursive formatting of nested backtest metrics and equity curves, preserves numeric chart inputs, and leaves stored precision available to forms. Formatting server-side would require transforming selected keys in nested resources and would risk changing chart data.

Alternative rejected: keep extending per-page `money` helpers. That caused the current inconsistency and would not help the next page. Alternative rejected: use `Number#toFixed` everywhere. It rounds binary floats, can emit `-0.00`, and loses exactness for fixed-scale decimal strings.

### Decision: Seed monetary inputs with the formatted value and preserve the original until edited

Bot edit seeds `capital_allocation`; bot detail seeds the protective-exit `range_low` and `range_high`; the risk-limit form seeds `max_position_notional` and `daily_loss_limit`. Each is seeded with `formatMoney(rawValue)`.

The raw value is retained in component state. On submit, an unchanged field sends its original raw string, so opening and saving a form does not silently round a stored value. A field the user actually edits sends the entered value. After a successful save, the raw snapshots and form baseline are refreshed from the submitted values, so the field returns to two-decimal presentation and later unchanged saves keep the latest raw value. Percentage fields (`stop_loss_percent`, `take_profit_percent`) and `fee_rate` are not monetary and keep their current seeding.

Alternative rejected: format monetary values in the controller payload. It would make the original value unavailable to the form and could silently change it on save.

### Decision: Apply the shared formatter to the identified monetary surfaces

- Dashboard: aggregate realized and unrealized P&L, peak equity; equity chart tooltip and data table values.
- Bot list: realized and unrealized P&L.
- Bot detail: grid/level prices, reference price, quote inventory, average entry price, order and fill prices, fill fees, P&L, fees paid, and risk-limit money values. Base inventory and order/fill quantities remain quantities.
- Bot edit and protective-exit forms: monetary seeds as above.
- Risk-limit form: both monetary limits.
- Backtest detail: capital, final quote inventory, final equity, net/realized/unrealized P&L, fees, fill prices, and fill fees. Fill quantity, final base inventory, fee rate, percentages, and snapshot JSON remain unchanged.
- Backtest comparison: net P&L and fees; return and drawdown percentages remain percentages.
- Sweep detail: center prices, arithmetic spacing values, and their swept pills. Geometric spacing is a fraction rather than a quote-currency amount, so it keeps its stored precision (the sweep payload carries `spacing_type` to make that distinction).
- Backtest and sweep create pages: the selected bot's capital in the helper text; the fee rate remains a rate.

`EquityChart` receives a formatting mode: monetary charts use `formatMoney` in the tooltip and data table, while the normalized comparison chart keeps four-decimal values.

## Risks / Trade-offs

- **Low-priced assets round to `0.00`** → This follows the explicit two-decimal rule. If the product later supports assets priced below `0.01`, revisit with per-symbol precision rather than weakening the money rule.
- **An edited field can still reduce stored precision** → Only values the user actually changes are submitted at the displayed precision; untouched fields keep their original raw value.
- **A monetary spot may be missed** → Tasks enumerate every known raw usage, and the implementation sweep should grep the Vue pages for price, P&L, inventory, fee, and capital bindings.
- **Chart values remain raw numbers** → Chart data stays numeric for Chart.js; only the tooltip and data table use the display formatter.

## Migration Plan

No data migration. Deploy the frontend assets and, if added, the Node test script. Rollback is a frontend revert; stored values are untouched.

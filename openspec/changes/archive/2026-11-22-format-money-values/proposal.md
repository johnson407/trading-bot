# Proposal

## Why

Monetary values reach the interface as fixed-scale database strings (for example `500.00000000`, `1000.0000000000000000`, or `0.00100000`) because model casts preserve 8 or 16 decimal places. Some screens already round these values, while others render the raw string, so the same amount looks different depending on the page and editable forms are prefilled with unusable precision.

## What Changes

- Define one display rule for monetary values: exactly two decimal places, no scientific notation, no trailing zeros beyond the second decimal, and a leading minus sign for negative amounts.
- Apply the rule to every screen that shows or prefills a monetary amount: dashboard totals and equity metrics, bot list and detail, bot create/edit forms, risk-limit form, backtest list/detail/compare, and sweep detail.
- Normalize monetary values loaded into editable forms so a user sees `1000.00` instead of `1000.0000000000000000`, without changing the stored value.
- Leave quantities (base-asset amounts), fee rates, percentages, timestamps, and the immutable backtest configuration snapshot at their existing precision; these are not money amounts, and forcing two decimals would destroy meaning.

## Capabilities

### New Capabilities

- `money-formatting`: Defines how monetary amounts are presented across the application, including read-only displays and editable form values.

### Modified Capabilities

None.

## Impact

- Affected surfaces: Inertia page props in dashboard, bot, risk-limit, backtest, and sweep controllers/resources; Vue pages that render or prefill monetary values; shared presentation helpers.
- No database schema, API contract, trading calculation, or stored-value changes. Amounts remain exact decimal strings internally.
- A shared formatting helper will prevent each page from implementing its own rounding.

/**
 * Merge values derived by the preview endpoint into the form. User-entered
 * fields such as name and capital are left untouched, and the existing values
 * survive a switch from quick setup to advanced mode.
 */
export function mergeDerivedValues(form, values) {
    if (!values) {
        return { ...form };
    }

    return {
        ...form,
        strategy_key: values.strategy_key ?? form.strategy_key,
        level_count: Number(values.level_count ?? form.level_count),
        spacing_type: values.spacing_type ?? form.spacing_type,
        spacing_value: values.spacing_value ?? form.spacing_value,
        order_quantity: values.order_quantity ?? form.order_quantity,
        trailing_distance: values.trailing_distance ?? form.trailing_distance,
    };
}

export function previewPayload({ setupMode, form, presetKey }) {
    if (form.source_backtest_run_id) {
        return { source_backtest_run_id: form.source_backtest_run_id };
    }

    const base = {
        exchange: form.exchange,
        symbol: form.symbol,
        capital_allocation: String(form.capital_allocation ?? ''),
        center_price: String(form.center_price ?? ''),
    };

    if (setupMode === 'quick') {
        return {
            preset_key: presetKey,
            ...base,
        };
    }

    return {
        ...base,
        strategy_key: form.strategy_key,
        level_count: Number(form.level_count),
        spacing_type: form.spacing_type,
        spacing_value: String(form.spacing_value ?? ''),
        order_quantity: String(form.order_quantity ?? ''),
        trailing_distance: form.trailing_distance,
        size_multiplier: form.size_multiplier,
        max_order_quantity: form.max_order_quantity,
        max_increases: form.max_increases,
        min_spacing: form.min_spacing,
        max_spacing: form.max_spacing,
        initial_spacing: form.initial_spacing,
        volatility_multiplier: form.volatility_multiplier,
        recalculation_interval_seconds: form.recalculation_interval_seconds,
        spacing_hysteresis_percent: form.spacing_hysteresis_percent,
    };
}

export function hiddenFields(strategyKey) {
    return strategyKey === 'dynamic-grid' ? ['spacing_type', 'spacing_value'] : [];
}

export function marketNotice(available) {
    return available
        ? null
        : 'Current market price is unavailable. Enter a center price manually.';
}

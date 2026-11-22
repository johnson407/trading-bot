import { formatMoney } from './money.js';

/**
 * Turn plan levels into display rows. Prices and notionals are money, so they
 * go through formatMoney; quantities stay raw so precision is not lost.
 */
export function planLevels(plan) {
    return (plan?.levels ?? []).map((level) => ({
        key: `${level.side}-${level.sequence}`,
        sequence: level.sequence,
        side: level.side,
        price: formatMoney(level.price),
        quantity: level.quantity,
        notional: formatMoney(level.notional),
    }));
}

export function planSummary(plan) {
    return {
        orderCount: plan?.order_count ?? 0,
        levelsPerSide: plan?.levels_per_side ?? 0,
        buyNotional: formatMoney(plan?.buy_notional ?? '0'),
        sellNotional: formatMoney(plan?.sell_notional ?? '0'),
    };
}

export function limitUsage(plan, limits) {
    return {
        orders: `${plan?.order_count ?? 0} of ${limits?.max_open_orders ?? 0}`,
        position: `${formatMoney(plan?.buy_notional ?? '0')} of ${formatMoney(limits?.max_position_notional ?? '0')}`,
    };
}

export function warningItems(warnings) {
    return (warnings ?? []).map((warning) => ({
        code: warning.code,
        message: warning.message,
    }));
}

export function warningMessages(warnings) {
    return warningItems(warnings).map((warning) => warning.message);
}

export function errorMessages(errors) {
    return (errors ?? []).map((error) => error.message);
}

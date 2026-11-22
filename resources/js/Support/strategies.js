const CATALOG = {
    'simple-grid': {
        label: 'Simple grid',
        description: 'Fixed levels around a center price. Best for markets that move sideways.',
    },
    'trailing-grid': {
        label: 'Trailing grid',
        description: 'Rebuilds the ladder when price moves past a trailing distance, following a trend.',
    },
    'martingale-grid': {
        label: 'Martingale grid',
        description: 'Increases order size after each level. Higher risk and advanced only.',
    },
    'dynamic-grid': {
        label: 'Dynamic grid',
        description: 'Adjusts spacing to recent volatility. Advanced only.',
    },
};

export function strategyMeta(key) {
    return CATALOG[key] ?? { label: key, description: '' };
}

export function strategyOptions(keys) {
    return keys.map((key) => ({ key, ...strategyMeta(key) }));
}

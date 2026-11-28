const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const RANGE_LABELS = {
    '1h': '1 hour',
    '6h': '6 hours',
    '24h': '24 hours',
    '7d': '7 days',
};

export function rangeLabel(range) {
    return RANGE_LABELS[range] ?? RANGE_LABELS['24h'];
}

function pad(value) {
    return String(value).padStart(2, '0');
}

export function formatTime(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return `${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`;
}

export function formatDateTime(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return `${MONTHS[date.getUTCMonth()]} ${date.getUTCDate()} ${formatTime(value)}`;
}

export function formatPrice(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);

    if (!Number.isFinite(number)) {
        return '—';
    }

    const magnitude = Math.abs(number);
    const maximumFractionDigits = magnitude >= 1000 ? 2 : magnitude >= 1 ? 4 : 8;

    return new Intl.NumberFormat('en-US', { maximumFractionDigits }).format(number);
}

export function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || !Number.isFinite(Number(seconds))) {
        return '—';
    }

    const total = Math.max(0, Math.round(Number(seconds)));

    if (total < 60) {
        return `${total}s`;
    }

    const totalMinutes = Math.round(total / 60);

    if (totalMinutes < 60) {
        return `${totalMinutes}m`;
    }

    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    return minutes === 0 ? `${hours}h` : `${hours}h ${minutes}m`;
}

function timestamp(value) {
    return typeof value === 'number' ? value : Date.parse(value);
}

function markerPoints(items, timeKey) {
    return (items ?? [])
        .map((item) => ({
            x: Date.parse(item[timeKey]),
            y: Number(item.price),
            id: item.id,
            side: item.side,
        }))
        .filter((point) => Number.isFinite(point.x) && Number.isFinite(point.y));
}

export function buildChartData(activity) {
    const price = (activity?.price?.points ?? [])
        .map((point) => ({ x: Number(point.t), y: Number(point.price) }))
        .filter((point) => Number.isFinite(point.x) && Number.isFinite(point.y));

    const fills = markerPoints(activity?.fills, 'filled_at');
    const cancels = markerPoints(activity?.cancels, 'closed_at');
    const levels = (activity?.grids ?? [])
        .flatMap((grid) => grid.levels ?? [])
        .map((level) => Number(level.price))
        .filter(Number.isFinite);

    const values = [
        ...price.map((point) => point.y),
        ...fills.map((point) => point.y),
        ...cancels.map((point) => point.y),
        ...levels,
    ];

    const yMin = values.length === 0 ? null : Math.min(...values);
    const yMax = values.length === 0 ? null : Math.max(...values);
    const yPadding = yMin === null || yMin !== yMax
        ? 0
        : Math.max(Math.max(Math.abs(yMin), Math.abs(yMax)) * 0.01, 1e-9);

    return {
        price,
        fills,
        cancels,
        yMin,
        yMax,
        yPadding,
    };
}

export function buildTableRows(activity, { showFills = true, showCancels = true } = {}) {
    const rows = [
        ...(activity?.price?.points ?? []).map((point) => ({
            key: `price-${point.t}`,
            time: timestamp(point.t),
            kind: 'Price',
            side: '',
            price: point.price,
            detail: '',
        })),
        ...(showFills ? activity?.fills ?? [] : []).map((fill) => ({
            key: `fill-${fill.id}`,
            time: timestamp(fill.filled_at),
            kind: 'Fill',
            side: fill.side,
            price: fill.price,
            detail: fill.quantity,
        })),
        ...(showCancels ? activity?.cancels ?? [] : []).map((cancel) => ({
            key: `cancel-${cancel.id}`,
            time: timestamp(cancel.closed_at),
            kind: 'Cancel',
            side: cancel.side,
            price: cancel.price,
            detail: '',
        })),
    ];

    return rows
        .filter((row) => Number.isFinite(row.time))
        .sort((left, right) => left.time - right.time);
}

export function hasActivity(activity) {
    return ['grids', 'fills', 'cancels'].some((key) => (activity?.[key]?.length ?? 0) > 0);
}

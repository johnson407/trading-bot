const DECIMAL_PATTERN = /^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/;

/**
 * Convert a decimal-ish value into a plain decimal string without exponents.
 * Invalid or non-finite values become "0".
 */
function toPlainDecimal(value) {
    if (value === null || value === undefined) {
        return '0';
    }

    if (typeof value === 'number') {
        if (!Number.isFinite(value)) {
            return '0';
        }

        value = String(value);
    }

    if (typeof value !== 'string') {
        return '0';
    }

    const match = DECIMAL_PATTERN.exec(value.trim());

    if (match === null) {
        return '0';
    }

    let [, sign, integer, fraction = '', exponent] = match;

    integer = integer === '' ? '0' : integer;

    if (exponent !== undefined) {
        const power = Number(exponent);

        if (!Number.isFinite(power) || Math.abs(power) > 1000) {
            return '0';
        }

        const digits = integer + fraction;
        const point = integer.length + power;

        if (point <= 0) {
            integer = '0';
            fraction = '0'.repeat(-point) + digits;
        } else if (point >= digits.length) {
            integer = digits + '0'.repeat(point - digits.length);
            fraction = '';
        } else {
            integer = digits.slice(0, point);
            fraction = digits.slice(point);
        }
    }

    integer = integer.replace(/^0+(?=\d)/, '') || '0';

    return `${sign}${integer}${fraction === '' ? '' : `.${fraction}`}`;
}

/**
 * Format a monetary value with exactly two decimal places, rounded half up.
 * Grouping separators are intentionally omitted so the result can also seed
 * numeric form inputs.
 */
export function formatMoney(value) {
    const plain = toPlainDecimal(value);
    const negative = plain.startsWith('-');
    const unsigned = plain.replace(/^[+-]/, '');
    const [integer, fraction = ''] = unsigned.split('.');
    const kept = (fraction + '00').slice(0, 2);
    const roundingDigit = fraction.length > 2 ? fraction[2] : '0';

    let cents = BigInt(integer) * 100n + BigInt(kept);

    if (roundingDigit >= '5') {
        cents += 1n;
    }

    const whole = cents / 100n;
    const decimals = (cents % 100n).toString().padStart(2, '0');
    const prefix = negative && cents !== 0n ? '-' : '';

    return `${prefix}${whole}.${decimals}`;
}

/**
 * Format a monetary value and prefix positive amounts with "+", for P&L.
 */
export function formatSignedMoney(value) {
    const formatted = formatMoney(value);

    return formatted.startsWith('-') || formatted === '0.00' ? formatted : `+${formatted}`;
}

/**
 * Keep the original raw value when an editable field still shows the seeded
 * presentation, and otherwise submit exactly what the user entered.
 */
export function submittedMoney(formValue, displayedValue, rawValue) {
    return formValue === displayedValue ? rawValue : formValue;
}

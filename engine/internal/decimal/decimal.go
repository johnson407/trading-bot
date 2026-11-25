// Package decimal implements the engine's money and quantity arithmetic with
// the same scale and rounding rules as the PHP App\Domain\Support\Decimal
// class (bcmath, scale 16). Floats are rejected on purpose: binary floating
// point must never enter monetary calculations.
package decimal

import (
	"errors"
	"fmt"
	"math/big"
	"strings"
)

// Scale is the public decimal scale used by the PHP Decimal class.
const Scale = 16

// Rounding modes accepted by Round.
const (
	RoundHalfUp   = "half_up"
	RoundHalfDown = "half_down"
	RoundDown     = "down"
	RoundUp       = "up"
)

// ErrOnlyDecimalValues is the PHP error for floats, booleans, nil, arrays, and
// objects passed where a decimal string or integer is required.
var ErrOnlyDecimalValues = errors.New("Only decimal strings and integers are allowed.")

// Decimal is an immutable exact finite decimal stored as coefficient * 10^-scale.
// The coefficient may carry more than Scale digits, which preserves PHP's
// behaviour of keeping the input precision until an arithmetic operation
// truncates it at scale 16.
type Decimal struct {
	coeff *big.Int
	scale int
}

// Zero returns 0.
func Zero() Decimal {
	return Decimal{coeff: big.NewInt(0)}
}

// FromInt returns an exact integer.
func FromInt(value int) Decimal {
	return FromInt64(int64(value))
}

// FromInt64 returns an exact integer.
func FromInt64(value int64) Decimal {
	return Decimal{coeff: big.NewInt(value)}
}

// Of accepts the same inputs as Decimal::of: another Decimal, a decimal string,
// or an integer. Floats and booleans are rejected.
func Of(value any) (Decimal, error) {
	switch typed := value.(type) {
	case Decimal:
		return typed, nil
	case *Decimal:
		if typed == nil {
			return Decimal{}, ErrOnlyDecimalValues
		}

		return *typed, nil
	case string:
		return Parse(typed)
	case int:
		return FromInt(typed), nil
	case int8:
		return FromInt64(int64(typed)), nil
	case int16:
		return FromInt64(int64(typed)), nil
	case int32:
		return FromInt64(int64(typed)), nil
	case int64:
		return FromInt64(typed), nil
	case uint:
		return parseUnsigned(uint64(typed))
	case uint8:
		return FromInt64(int64(typed)), nil
	case uint16:
		return FromInt64(int64(typed)), nil
	case uint32:
		return FromInt64(int64(typed)), nil
	case uint64:
		return parseUnsigned(typed)
	default:
		return Decimal{}, ErrOnlyDecimalValues
	}
}

// MustParse is a test and fixture helper. It panics on an invalid value.
func MustParse(value string) Decimal {
	parsed, err := Parse(value)
	if err != nil {
		panic(err)
	}

	return parsed
}

// Parse validates and normalizes a decimal string, matching Decimal::normalize.
func Parse(value string) (Decimal, error) {
	trimmed := strings.TrimSpace(value)
	if !validDecimalString(trimmed) {
		return Decimal{}, fmt.Errorf("Invalid decimal value: %s", trimmed)
	}

	negative := strings.HasPrefix(trimmed, "-")
	unsigned := strings.TrimLeft(trimmed, "+-")

	integer, fraction, found := strings.Cut(unsigned, ".")
	if !found {
		fraction = ""
	}

	integer = strings.TrimLeft(integer, "0")
	if integer == "" {
		integer = "0"
	}

	digits := integer + fraction
	coefficient := new(big.Int)
	if _, ok := coefficient.SetString(digits, 10); !ok {
		return Decimal{}, fmt.Errorf("Invalid decimal value: %s", trimmed)
	}

	if negative && coefficient.Sign() != 0 {
		coefficient.Neg(coefficient)
	}

	return normalize(coefficient, len(fraction)), nil
}

func validDecimalString(value string) bool {
	if value == "" {
		return false
	}

	index := 0
	if value[0] == '+' || value[0] == '-' {
		index = 1
	}

	digits := 0
	for ; index < len(value); index++ {
		char := value[index]
		if char >= '0' && char <= '9' {
			digits++

			continue
		}

		break
	}

	if digits == 0 {
		return false
	}

	if index == len(value) {
		return true
	}

	if value[index] != '.' {
		return false
	}

	index++
	fractionDigits := 0

	for ; index < len(value); index++ {
		char := value[index]
		if char < '0' || char > '9' {
			return false
		}

		fractionDigits++
	}

	return fractionDigits > 0
}

// String returns the normalized decimal string, matching Decimal::toString.
func (d Decimal) String() string {
	if d.coeff == nil || d.coeff.Sign() == 0 {
		return "0"
	}

	negative := d.coeff.Sign() < 0
	digits := new(big.Int).Abs(d.coeff).String()

	var result string

	if d.scale == 0 {
		result = digits
	} else {
		if len(digits) <= d.scale {
			digits = strings.Repeat("0", d.scale-len(digits)+1) + digits
		}

		split := len(digits) - d.scale
		integer := digits[:split]
		fraction := strings.TrimRight(digits[split:], "0")

		result = integer
		if fraction != "" {
			result += "." + fraction
		}
	}

	if negative && result != "0" {
		result = "-" + result
	}

	return result
}

// ToFixed returns the value with exactly scale decimal places, padding with
// zeros. It never rounds, matching Decimal::toFixed.
func (d Decimal) ToFixed(scale int) (string, error) {
	if scale < 0 || scale > Scale {
		return "", fmt.Errorf("Scale must be between 0 and %d.", Scale)
	}

	if d.coeff == nil || d.coeff.Sign() == 0 {
		if scale == 0 {
			return "0", nil
		}

		return "0." + strings.Repeat("0", scale), nil
	}

	negative := d.coeff.Sign() < 0
	digits := new(big.Int).Abs(d.coeff).String()

	var result string

	if scale == 0 {
		if len(digits) <= d.scale {
			result = "0"
		} else {
			result = digits[:len(digits)-d.scale]
		}
	} else {
		if len(digits) <= d.scale {
			digits = strings.Repeat("0", d.scale-len(digits)+1) + digits
		}

		split := len(digits) - d.scale
		integer := digits[:split]
		fraction := digits[split:]

		if len(integer) == 0 {
			integer = "0"
		}

		if len(fraction) < scale {
			fraction += strings.Repeat("0", scale-len(fraction))
		}

		// PHP str_pad does not truncate a longer fraction, so a value with more
		// than Scale decimals keeps them here too.
		result = integer + "." + fraction
	}

	if negative && result != "0" {
		result = "-" + result
	}

	return result, nil
}

// Add matches bcadd(..., Scale) followed by normalize.
func (d Decimal) Add(other Decimal) Decimal {
	scale := max(d.scale, other.scale)
	sum := new(big.Int).Add(d.coeffAt(scale), other.coeffAt(scale))

	return truncate(normalize(sum, scale), Scale)
}

// Sub matches bcsub(..., Scale) followed by normalize.
func (d Decimal) Sub(other Decimal) Decimal {
	scale := max(d.scale, other.scale)
	difference := new(big.Int).Sub(d.coeffAt(scale), other.coeffAt(scale))

	return truncate(normalize(difference, scale), Scale)
}

// Mul matches bcmul(..., Scale) followed by normalize.
func (d Decimal) Mul(other Decimal) Decimal {
	product := new(big.Int).Mul(d.coeff, other.coeff)

	return truncate(normalize(product, d.scale+other.scale), Scale)
}

// Div matches bcdiv(..., Scale+1) followed by half-up round(Scale).
func (d Decimal) Div(other Decimal) (Decimal, error) {
	if other.coeff.Sign() == 0 {
		return Decimal{}, errors.New("Division by zero.")
	}

	// q = trunc(a / b * 10^(Scale+1)) with bcmath's truncation toward zero.
	numerator := new(big.Int).Set(d.coeff)
	denominator := new(big.Int).Set(other.coeff)
	exponent := Scale + 1 + other.scale - d.scale

	if exponent >= 0 {
		numerator.Mul(numerator, pow10(exponent))
	} else {
		denominator.Mul(denominator, pow10(-exponent))
	}

	quotient := new(big.Int).Quo(numerator, denominator)

	return normalize(quotient, Scale+1).Round(Scale, RoundHalfUp)
}

// TruncateToStep rounds the magnitude down to the nearest multiple of step.
// Venue quantity and price filters express their precision as a step size, and
// an order that is not an exact multiple is rejected by the venue.
func (d Decimal) TruncateToStep(step Decimal) (Decimal, error) {
	if !step.IsPositive() {
		return Decimal{}, errors.New("Step must be positive.")
	}
	if d.IsZero() {
		return Zero(), nil
	}

	numerator := new(big.Int).Set(d.coeff)
	denominator := new(big.Int).Set(step.coeff)
	exponent := step.scale - d.scale

	if exponent >= 0 {
		numerator.Mul(numerator, pow10(exponent))
	} else {
		denominator.Mul(denominator, pow10(-exponent))
	}

	quotient := new(big.Int).Quo(numerator, denominator)

	return normalize(quotient, 0).Mul(step), nil
}

// Pow matches bcpow(..., Scale) for a non-negative integer exponent.
func (d Decimal) Pow(exponent int) (Decimal, error) {
	if exponent < 0 {
		return Decimal{}, errors.New("Exponent must not be negative.")
	}

	powered := new(big.Int).Exp(d.coeff, big.NewInt(int64(exponent)), nil)

	return truncate(normalize(powered, d.scale*exponent), Scale), nil
}

// Compare matches bccomp(..., Scale): both values are truncated to 16 decimal
// places before comparison.
func (d Decimal) Compare(other Decimal) int {
	return d.coeffAt(Scale).Cmp(other.coeffAt(Scale))
}

// Equals reports decimal equality at the public scale.
func (d Decimal) Equals(other Decimal) bool {
	return d.Compare(other) == 0
}

// GreaterThan reports d > other.
func (d Decimal) GreaterThan(other Decimal) bool {
	return d.Compare(other) > 0
}

// GreaterThanOrEqual reports d >= other.
func (d Decimal) GreaterThanOrEqual(other Decimal) bool {
	return d.Compare(other) >= 0
}

// LessThan reports d < other.
func (d Decimal) LessThan(other Decimal) bool {
	return d.Compare(other) < 0
}

// LessThanOrEqual reports d <= other.
func (d Decimal) LessThanOrEqual(other Decimal) bool {
	return d.Compare(other) <= 0
}

// Abs returns the absolute value.
func (d Decimal) Abs() Decimal {
	if d.coeff.Sign() >= 0 {
		return d
	}

	return Decimal{coeff: new(big.Int).Abs(d.coeff), scale: d.scale}
}

// Negate returns the additive inverse.
func (d Decimal) Negate() Decimal {
	if d.coeff.Sign() == 0 {
		return d
	}

	return Decimal{coeff: new(big.Int).Neg(d.coeff), scale: d.scale}
}

// IsZero reports whether the exact value is zero.
func (d Decimal) IsZero() bool {
	return d.coeff == nil || d.coeff.Sign() == 0
}

// IsPositive reports whether the exact value is greater than zero.
func (d Decimal) IsPositive() bool {
	return d.coeff != nil && d.coeff.Sign() > 0
}

// IsNegative reports whether the exact value is less than zero.
func (d Decimal) IsNegative() bool {
	return d.coeff != nil && d.coeff.Sign() < 0
}

// Round rounds the magnitude to scale decimal places, matching Decimal::round.
func (d Decimal) Round(scale int, mode string) (Decimal, error) {
	if scale < 0 || scale > Scale {
		return Decimal{}, fmt.Errorf("Scale must be between 0 and %d.", Scale)
	}

	if d.coeff == nil || d.coeff.Sign() == 0 {
		return Zero(), nil
	}

	if d.scale <= scale {
		return d, nil
	}

	divisor := pow10(d.scale - scale)
	absolute := new(big.Int).Abs(d.coeff)
	kept, remainder := new(big.Int).QuoRem(absolute, divisor, new(big.Int))
	nextDivisor := pow10(d.scale - scale - 1)
	next := new(big.Int).Quo(remainder, nextDivisor).Int64()
	hasMore := new(big.Int).Rem(remainder, nextDivisor).Sign() != 0

	increment := false

	switch mode {
	case RoundDown:
		increment = false
	case RoundUp:
		increment = next > 0 || hasMore
	case RoundHalfDown:
		increment = next > 5 || (next == 5 && hasMore)
	case RoundHalfUp:
		increment = next >= 5
	default:
		return Decimal{}, fmt.Errorf("Unknown rounding mode: %s", mode)
	}

	if increment {
		kept.Add(kept, big.NewInt(1))
	}

	if d.coeff.Sign() < 0 {
		kept.Neg(kept)
	}

	return normalize(kept, scale), nil
}

func parseUnsigned(value uint64) (Decimal, error) {
	coefficient := new(big.Int).SetUint64(value)

	return normalize(coefficient, 0), nil
}

// coeffAt returns the coefficient at the requested scale, truncating toward
// zero when scale is smaller, matching bcmath.
func (d Decimal) coeffAt(scale int) *big.Int {
	if d.scale == scale {
		return new(big.Int).Set(d.coeff)
	}

	if d.scale < scale {
		return new(big.Int).Mul(d.coeff, pow10(scale-d.scale))
	}

	return new(big.Int).Quo(d.coeff, pow10(d.scale-scale))
}

// truncate truncates toward zero at Scale, matching bcmath's output, then strips
// trailing zeros like normalize.
func truncate(value Decimal, scale int) Decimal {
	if value.scale <= scale {
		return normalize(value.coeff, value.scale)
	}

	coefficient := new(big.Int).Quo(value.coeff, pow10(value.scale-scale))

	return normalize(coefficient, scale)
}

// normalize strips trailing fractional zeros and canonicalizes zero.
func normalize(coefficient *big.Int, scale int) Decimal {
	if coefficient.Sign() == 0 {
		return Zero()
	}

	trimmed := new(big.Int).Set(coefficient)
	ten := big.NewInt(10)
	remainder := new(big.Int)

	for scale > 0 {
		quotient, rem := new(big.Int).QuoRem(trimmed, ten, remainder)
		if rem.Sign() != 0 {
			break
		}

		trimmed = quotient
		scale--
	}

	return Decimal{coeff: trimmed, scale: scale}
}

func pow10(exponent int) *big.Int {
	if exponent <= 0 {
		return big.NewInt(1)
	}

	return new(big.Int).Exp(big.NewInt(10), big.NewInt(int64(exponent)), nil)
}

package decimal_test

import (
	"errors"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func TestParseNormalizesLikePHP(t *testing.T) {
	cases := map[string]string{
		"100":                   "100",
		"  +001.2300  ":         "1.23",
		"-000.5000":             "-0.5",
		"-0":                    "0",
		"0.00000000000000001":   "0.00000000000000001",
		"0.0000000000000000100": "0.00000000000000001",
	}

	for input, expected := range cases {
		parsed, err := decimal.Parse(input)
		if err != nil {
			t.Fatalf("Parse(%q) error: %v", input, err)
		}

		if parsed.String() != expected {
			t.Fatalf("Parse(%q) = %q, want %q", input, parsed.String(), expected)
		}
	}
}

func TestOfRejectsFloatWithPHPError(t *testing.T) {
	for _, value := range []any{0.4, 1000.0, true, nil, []string{"x"}} {
		_, err := decimal.Of(value)
		if !errors.Is(err, decimal.ErrOnlyDecimalValues) {
			t.Fatalf("Of(%#v) error = %v, want %q", value, err, decimal.ErrOnlyDecimalValues)
		}
	}
}

func TestArithmeticMatchesBcmathTruncation(t *testing.T) {
	powered, err := decimal.MustParse("0.123456789").Pow(2)
	if err != nil {
		t.Fatal(err)
	}

	if got := powered.String(); got != "0.0152415787501905" {
		t.Fatalf("pow = %s", got)
	}

	quotient, err := decimal.FromInt(1).Div(decimal.FromInt(3))
	if err != nil {
		t.Fatal(err)
	}

	if got := quotient.String(); got != "0.3333333333333333" {
		t.Fatalf("1/3 = %s", got)
	}

	if got := decimal.MustParse("0.33333333333333335").Mul(decimal.FromInt(3)).String(); got != "1" {
		t.Fatalf("mul truncation = %s", got)
	}

	if got := decimal.MustParse("0.00000000000000009").Mul(decimal.FromInt(1)).String(); got != "0" {
		t.Fatalf("sub-scale mul = %s", got)
	}
}

func TestCompareUsesScale16AndIsPositiveUsesExactValue(t *testing.T) {
	tiny := decimal.MustParse("0.00000000000000001")

	if tiny.Compare(decimal.Zero()) != 0 {
		t.Fatal("1e-17 must compare equal to zero at scale 16")
	}

	if !tiny.IsPositive() {
		t.Fatal("1e-17 must still be positive, matching PHP Decimal::isPositive")
	}
}

func TestRoundHalfUpUsesMagnitude(t *testing.T) {
	cases := []struct {
		value  string
		scale  int
		mode   string
		expect string
	}{
		{"1.25", 1, decimal.RoundHalfUp, "1.3"},
		{"-1.25", 1, decimal.RoundHalfUp, "-1.3"},
		{"1.25", 1, decimal.RoundHalfDown, "1.2"},
		{"-1.25", 1, decimal.RoundHalfDown, "-1.2"},
		{"1.21", 1, decimal.RoundDown, "1.2"},
		{"1.21", 1, decimal.RoundUp, "1.3"},
	}

	for _, testCase := range cases {
		rounded, err := decimal.MustParse(testCase.value).Round(testCase.scale, testCase.mode)
		if err != nil {
			t.Fatal(err)
		}

		if rounded.String() != testCase.expect {
			t.Fatalf("round(%s, %d, %s) = %s, want %s", testCase.value, testCase.scale, testCase.mode, rounded.String(), testCase.expect)
		}
	}
}

func TestToFixedPadsWithoutRounding(t *testing.T) {
	got, err := decimal.MustParse("1.21").ToFixed(decimal.Scale)
	if err != nil {
		t.Fatal(err)
	}

	if got != "1.2100000000000000" {
		t.Fatalf("toFixed = %s", got)
	}
}

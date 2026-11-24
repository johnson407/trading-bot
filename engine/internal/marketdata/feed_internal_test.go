package marketdata

import (
	"testing"
	"time"
)

func TestNextBackoffDoublesAndCaps(t *testing.T) {
	t.Parallel()

	cases := []struct {
		name    string
		current time.Duration
		maximum time.Duration
		want    time.Duration
	}{
		{name: "double", current: 10 * time.Millisecond, maximum: 80 * time.Millisecond, want: 20 * time.Millisecond},
		{name: "cap", current: 50 * time.Millisecond, maximum: 80 * time.Millisecond, want: 80 * time.Millisecond},
		{name: "already capped", current: 80 * time.Millisecond, maximum: 80 * time.Millisecond, want: 80 * time.Millisecond},
	}

	for _, testCase := range cases {
		if got := nextBackoff(testCase.current, testCase.maximum); got != testCase.want {
			t.Errorf("%s: nextBackoff(%s, %s) = %s, want %s", testCase.name, testCase.current, testCase.maximum, got, testCase.want)
		}
	}
}

func TestValidDecimalRejectsExponentsAndNonNumbers(t *testing.T) {
	t.Parallel()

	for _, value := range []string{"100.50", "0.00000001", "-1.25"} {
		if !validDecimal(value) {
			t.Errorf("validDecimal(%q) = false, want true", value)
		}
	}

	for _, value := range []string{"", ".", "-", "1e3", "NaN", "1,0", "1.2.3"} {
		if validDecimal(value) {
			t.Errorf("validDecimal(%q) = true, want false", value)
		}
	}
}

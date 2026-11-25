package runtime

import (
	"regexp"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

var clientIDPattern = regexp.MustCompile(`^[A-Za-z0-9._:/-]+$`)

func TestLiveClientIDIsStableShortAndVenueSafe(t *testing.T) {
	sequence := 4

	first := liveClientID(12, 7, &sequence, "simple-grid", "buy", 1)
	second := liveClientID(12, 7, &sequence, "simple-grid", "buy", 1)

	if first != second {
		t.Fatalf("client ID is not deterministic: %q != %q", first, second)
	}
	if len(first) > liveClientIDMax {
		t.Errorf("client ID %q is longer than %d", first, liveClientIDMax)
	}
	if !clientIDPattern.MatchString(first) {
		t.Errorf("client ID %q contains venue-unsafe characters", first)
	}

	if next := liveClientID(12, 7, &sequence, "simple-grid", "buy", 2); next == first {
		t.Error("a new generation reused the completed client ID")
	}

	long := liveClientID(999999999999, 999999999999, nil, "a-very-long-strategy-key-with-many-characters", "sell", 999)
	if len(long) > liveClientIDMax {
		t.Errorf("hashed client ID %q is longer than %d", long, liveClientIDMax)
	}
}

func TestRoundOrderValueTruncatesToVenueStep(t *testing.T) {
	cases := []struct {
		value     string
		step      string
		precision int
		want      string
		wantOK    bool
	}{
		{value: "0.0026666666666667", step: "0.00001", precision: 5, want: "0.00266", wantOK: true},
		{value: "101.23456789", step: "0.01", precision: 2, want: "101.23", wantOK: true},
		{value: "100", step: "0", precision: 0, want: "100", wantOK: true},
		{value: "100.5", step: "0", precision: 0, want: "100", wantOK: true},
		{value: "0.0001", step: "0", precision: 3, want: "0", wantOK: true},
		{value: "1", step: "0", precision: -1, want: "", wantOK: false},
	}

	for _, test := range cases {
		got, ok := roundOrderValue(
			decimal.MustParse(test.value),
			decimal.MustParse(test.step),
			test.precision,
		)
		if ok != test.wantOK {
			t.Errorf("roundOrderValue(%s, %s, %d) ok = %v, want %v", test.value, test.step, test.precision, ok, test.wantOK)
			continue
		}
		if ok && got.String() != test.want {
			t.Errorf("roundOrderValue(%s, %s, %d) = %s, want %s", test.value, test.step, test.precision, got, test.want)
		}
	}
}

func TestOrderRiskStateKeepsRemainingNotionalAndInventory(t *testing.T) {
	sequence := 3
	open := []openOrder{
		{Price: decimal.MustParse("100"), Quantity: decimal.MustParse("2"), FilledQuantity: decimal.MustParse("1"), Side: "buy"},
		{Price: decimal.MustParse("110"), Quantity: decimal.MustParse("0.5"), FilledQuantity: decimal.Zero(), Side: "sell", Sequence: &sequence},
	}

	atPrice, atSequence, buyNotional, reservedSell := orderRiskState(open)

	if !atPrice["100"] || !atPrice["110"] {
		t.Errorf("atPrice = %v", atPrice)
	}
	if !atSequence[3] || len(atSequence) != 1 {
		t.Errorf("atSequence = %v", atSequence)
	}
	if buyNotional.String() != "100" {
		t.Errorf("buy notional = %s, want the unfilled remainder at 100", buyNotional.String())
	}
	if reservedSell.String() != "0.5" {
		t.Errorf("reserved sell = %s, want 0.5", reservedSell.String())
	}
}

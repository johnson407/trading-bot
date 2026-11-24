package backtest

import (
	"math"
	"strconv"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func TestDownsampleMatchesPHPIndices(t *testing.T) {
	equity := make([]EquityPoint, 2001)
	for index := range equity {
		equity[index] = EquityPoint{Equity: decimal.FromInt(index)}
	}

	downsampled := Downsample(equity, 1000)
	if len(downsampled) != 1000 {
		t.Fatalf("len = %d, want 1000", len(downsampled))
	}

	for index, point := range downsampled {
		source := int(math.Floor(float64(index)*2000/999 + 0.5))
		if point.Equity.String() != strconv.Itoa(source) {
			t.Fatalf("point %d = %s, want source %d", index, point.Equity.String(), source)
		}
	}

	if downsampled[0].Equity.String() != "0" || downsampled[len(downsampled)-1].Equity.String() != "2000" {
		t.Fatal("downsample did not keep first and last points")
	}
}

func TestDownsampleKeepsSmallSeries(t *testing.T) {
	equity := []EquityPoint{{Equity: decimal.FromInt(1)}, {Equity: decimal.FromInt(2)}}

	if got := Downsample(equity, 1); len(got) != len(equity) {
		t.Fatalf("maxPoints < 2 truncated to %d points", len(got))
	}
	if got := Downsample(equity, 10); len(got) != len(equity) {
		t.Fatalf("small series truncated to %d points", len(got))
	}
}

package backtest

import (
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func TestCandleWindowMatchesCoverage(t *testing.T) {
	start := time.Date(2026, 11, 23, 0, 0, 0, 0, time.UTC)
	end := time.Date(2026, 11, 23, 6, 0, 0, 0, time.UTC)
	configuration := Configuration{Timeframe: Timeframe15m, StartAt: start, EndAt: end}

	first, last, count, err := candleWindow(configuration, end)
	if err != nil {
		t.Fatalf("candleWindow: %v", err)
	}
	if count != 24 {
		t.Fatalf("count = %d, want 24", count)
	}
	if !first.Equal(start) {
		t.Fatalf("first = %s, want %s", first, start)
	}
	if want := end.Add(-15 * time.Minute); !last.Equal(want) {
		t.Fatalf("last = %s, want %s", last, want)
	}

	configuration.StartAt = start.Add(7 * time.Minute)
	first, _, count, err = candleWindow(configuration, end)
	if err != nil {
		t.Fatalf("candleWindow: %v", err)
	}
	if want := start.Add(15 * time.Minute); !first.Equal(want) {
		t.Fatalf("first = %s, want %s", first, want)
	}
	if count != 23 {
		t.Fatalf("count = %d, want 23", count)
	}

	configuration.StartAt = start
	trimmed, last, count, err := candleWindow(configuration, start.Add(30*time.Minute))
	if err != nil {
		t.Fatalf("candleWindow: %v", err)
	}
	if !trimmed.Equal(start) || !last.Equal(start.Add(15*time.Minute)) || count != 2 {
		t.Fatalf("window = %s..%s (%d), want 00:00..00:15 (2)", trimmed, last, count)
	}

	configuration.StartAt = start
	configuration.EndAt = start.Add(-time.Hour)
	_, _, count, err = candleWindow(configuration, end)
	if err != nil {
		t.Fatalf("candleWindow: %v", err)
	}
	if count != 0 {
		t.Fatalf("count = %d, want 0", count)
	}
}

func TestCandleGap(t *testing.T) {
	start := time.Date(2026, 11, 23, 0, 0, 0, 0, time.UTC)
	candles := []Candle{
		candleAt(start),
		candleAt(start.Add(15 * time.Minute)),
		candleAt(start.Add(45 * time.Minute)),
	}

	gapAt, missing, ok := candleGap(candles, start, 4, 900)
	if !ok {
		t.Fatal("candleGap = no gap, want a gap")
	}
	if want := start.Add(30 * time.Minute); !gapAt.Equal(want) {
		t.Fatalf("gap at %s, want %s", gapAt, want)
	}
	if missing != 1 {
		t.Fatalf("missing = %d, want 1", missing)
	}

	complete := []Candle{
		candleAt(start),
		candleAt(start.Add(15 * time.Minute)),
		candleAt(start.Add(30 * time.Minute)),
		candleAt(start.Add(45 * time.Minute)),
	}
	if _, _, ok := candleGap(complete, start, 4, 900); ok {
		t.Fatal("candleGap = gap for complete range")
	}
}

func TestGapMessageMatchesPHP(t *testing.T) {
	at := time.Date(2026, 11, 23, 0, 30, 0, 0, time.UTC)

	cases := []struct {
		missing int
		want    string
	}{
		{1, "Historical data gap at 2026-11-23T00:30:00+00:00 (1 candle missing)."},
		{2, "Historical data gap at 2026-11-23T00:30:00+00:00 (2 candles missing)."},
	}

	for _, testCase := range cases {
		if got := gapMessage(at, testCase.missing); got != testCase.want {
			t.Fatalf("gapMessage(%d) = %q, want %q", testCase.missing, got, testCase.want)
		}
	}
}

func TestCandleGapCountsEveryMissingCandle(t *testing.T) {
	start := time.Date(2026, 11, 23, 0, 0, 0, 0, time.UTC)
	candles := []Candle{
		candleAt(start),
		candleAt(start.Add(45 * time.Minute)),
		candleAt(start.Add(60 * time.Minute)),
	}

	gapAt, missing, ok := candleGap(candles, start, 5, 900)
	if !ok {
		t.Fatal("candleGap = no gap, want a gap")
	}
	if want := start.Add(15 * time.Minute); !gapAt.Equal(want) {
		t.Fatalf("gap at %s, want %s", gapAt, want)
	}
	if missing != 2 {
		t.Fatalf("missing = %d, want 2", missing)
	}
}

func TestSafeMessageMatchesPHPCap(t *testing.T) {
	if got := SafeMessage(nil); got != FailedMessageFallback {
		t.Fatalf("SafeMessage(nil) = %q", got)
	}
	if got := SafeMessage(errors.New("   ")); got != FailedMessageFallback {
		t.Fatalf("SafeMessage(blank) = %q", got)
	}

	long := strings.Repeat("é", 600)
	got := SafeMessage(errors.New(long))
	if runes := []rune(got); len(runes) != MaxFailedMessageLength {
		t.Fatalf("length = %d, want %d", len(runes), MaxFailedMessageLength)
	}
	if !strings.HasSuffix(got, "...") {
		t.Fatalf("message %q does not end with ...", got)
	}
}

func candleAt(at time.Time) Candle {
	price := decimal.MustParse("100")

	return Candle{OpenTime: at, Open: price, High: price, Low: price, Close: price, Volume: decimal.FromInt(1)}
}

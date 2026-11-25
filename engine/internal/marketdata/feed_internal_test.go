package marketdata

import (
	"bytes"
	"log/slog"
	"strings"
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

func TestApplyMessageDecodesBinanceTradeAndBookTickerFrames(t *testing.T) {
	t.Parallel()

	testCases := []struct {
		name        string
		frame       string
		wantLast    string
		wantBid     string
		wantAsk     string
		wantChanged bool
	}{
		{
			name:        "trade has numeric buyer and seller order ids",
			frame:       `{"e":"trade","E":123456789,"s":"BTCUSDT","t":12345,"p":"100.50","q":"1.00000000","b":88,"a":50,"T":123456785,"m":true,"M":true}`,
			wantLast:    "100.50",
			wantChanged: true,
		},
		{
			name:        "combined trade has numeric buyer and seller order ids",
			frame:       `{"stream":"btcusdt@trade","data":{"e":"trade","E":123456789,"s":"BTCUSDT","t":12346,"p":"101.25","q":"1.0","b":99,"a":51,"T":123456786}}`,
			wantLast:    "101.25",
			wantChanged: true,
		},
		{
			name:        "book ticker has string best bid and ask",
			frame:       `{"u":400900217,"s":"BTCUSDT","b":"100.40000000","B":"31.21000000","a":"100.60000000","A":"40.66000000"}`,
			wantBid:     "100.40000000",
			wantAsk:     "100.60000000",
			wantChanged: true,
		},
		{
			name:        "combined book ticker has string best bid and ask",
			frame:       `{"stream":"btcusdt@bookTicker","data":{"u":400900218,"s":"BTCUSDT","b":"102.10","B":"1","a":"102.20","A":"2"}}`,
			wantBid:     "102.10",
			wantAsk:     "102.20",
			wantChanged: true,
		},
		{
			name:  "subscription acknowledgement is ignored",
			frame: `{"result":null,"id":1}`,
		},
		{
			name:  "unknown event is ignored",
			frame: `{"e":"aggTrade","s":"BTCUSDT","p":"100.50"}`,
		},
	}

	for _, testCase := range testCases {
		t.Run(testCase.name, func(t *testing.T) {
			t.Parallel()

			feed, logs := newInternalFeed()

			changed, gap := feed.applyMessage([]byte(testCase.frame))
			if changed != testCase.wantChanged {
				t.Errorf("applyMessage() changed = %t, want %t", changed, testCase.wantChanged)
			}
			if gap {
				t.Error("applyMessage() reported a trade gap, want none")
			}
			if strings.Contains(logs.String(), "could not be decoded") {
				t.Errorf("applyMessage() logged a decode failure: %s", logs.String())
			}

			ticker, ok := feed.Snapshot("BTCUSDT")
			if !testCase.wantChanged {
				if ok {
					t.Errorf("applyMessage() cached a ticker for an ignored frame: %+v", ticker)
				}

				return
			}

			if !ok {
				t.Fatal("applyMessage() did not cache a ticker")
			}
			if ticker.Last != testCase.wantLast {
				t.Errorf("ticker.Last = %q, want %q", ticker.Last, testCase.wantLast)
			}
			if ticker.Bid != testCase.wantBid {
				t.Errorf("ticker.Bid = %q, want %q", ticker.Bid, testCase.wantBid)
			}
			if ticker.Ask != testCase.wantAsk {
				t.Errorf("ticker.Ask = %q, want %q", ticker.Ask, testCase.wantAsk)
			}
		})
	}
}

func TestDecodeWarningsAreAggregated(t *testing.T) {
	t.Parallel()

	feed, logs := newInternalFeed()
	base := time.Date(2026, 11, 25, 12, 0, 0, 0, time.UTC)
	feed.now = func() time.Time { return base }

	for range 3 {
		feed.applyMessage([]byte(`{"e":`))
	}

	if got := strings.Count(logs.String(), "could not be decoded"); got != 1 {
		t.Fatalf("decode warnings = %d, want 1; logs: %s", got, logs.String())
	}

	feed.now = func() time.Time { return base.Add(time.Minute) }
	feed.applyMessage([]byte(`{"e":`))

	if got := strings.Count(logs.String(), "could not be decoded"); got != 2 {
		t.Fatalf("decode warnings after interval = %d, want 2; logs: %s", got, logs.String())
	}
	if !strings.Contains(logs.String(), "suppressed=2") {
		t.Errorf("logs do not include the suppressed count: %s", logs.String())
	}
}

func newInternalFeed() (*Feed, *bytes.Buffer) {
	var logs bytes.Buffer
	feed := New(Config{}, slog.New(slog.NewTextHandler(&logs, nil)), nil)
	feed.active = []string{"BTCUSDT"}
	feed.now = func() time.Time { return time.Date(2026, 11, 25, 12, 0, 0, 0, time.UTC) }

	return feed, &logs
}

package runtime

import (
	"testing"
	"time"
)

func TestRiskEventCooldownSuppressesRepeatsUntilExpiry(t *testing.T) {
	now := time.Date(2026, 11, 26, 12, 0, 0, 0, time.UTC)
	cooldown := 5 * time.Minute
	trading := New(nil, nil, nil, WithNow(func() time.Time { return now }))

	if !trading.allowRiskEvent(7, "risk.position_limit", cooldown) {
		t.Fatal("first event was suppressed")
	}

	now = now.Add(cooldown - time.Second)
	if trading.allowRiskEvent(7, "risk.position_limit", cooldown) {
		t.Fatal("repeat inside the cooldown was allowed")
	}

	now = now.Add(2 * time.Second)
	if !trading.allowRiskEvent(7, "risk.position_limit", cooldown) {
		t.Fatal("event after the cooldown was suppressed")
	}
}

func TestRiskEventCooldownIsPerBotAndType(t *testing.T) {
	now := time.Date(2026, 11, 26, 12, 0, 0, 0, time.UTC)
	trading := New(nil, nil, nil, WithNow(func() time.Time { return now }))

	if !trading.allowRiskEvent(7, "risk.position_limit", time.Minute) {
		t.Fatal("first bot event was suppressed")
	}
	if !trading.allowRiskEvent(8, "risk.position_limit", time.Minute) {
		t.Fatal("another bot's first event was suppressed")
	}
	if !trading.allowRiskEvent(7, "risk.stale_tick", time.Minute) {
		t.Fatal("another type's first event was suppressed")
	}
}

func TestRiskEventCooldownPrunedForStoppedBot(t *testing.T) {
	now := time.Date(2026, 11, 26, 12, 0, 0, 0, time.UTC)
	trading := New(nil, nil, nil, WithNow(func() time.Time { return now }))

	if !trading.allowRiskEvent(7, "risk.position_limit", time.Minute) {
		t.Fatal("first event was suppressed")
	}

	trading.pruneRiskEventCooldowns(map[int64]struct{}{})

	if !trading.allowRiskEvent(7, "risk.position_limit", time.Minute) {
		t.Fatal("cooldown for a stopped bot was not pruned")
	}
}

func TestStaleThreshold(t *testing.T) {
	tests := []struct {
		interval int
		want     time.Duration
	}{
		{interval: 0, want: 180 * time.Second},
		{interval: 1, want: 180 * time.Second},
		{interval: 150, want: 180 * time.Second},
		{interval: 151, want: 181 * time.Second},
		{interval: 600, want: 630 * time.Second},
	}
	for _, test := range tests {
		if got := staleThreshold(test.interval); got != test.want {
			t.Errorf("staleThreshold(%d) = %s, want %s", test.interval, got, test.want)
		}
	}
}

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

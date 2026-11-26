package runtime

import "fmt"

// pauseCause is the engine-owned reason recorded whenever a bot is paused.
// The database check constraint and the documentation table must stay in sync
// with this set.
type pauseCause string

const (
	pauseCauseStaleTick           pauseCause = "stale_tick"
	pauseCauseRiskLimit           pauseCause = "risk_limit"
	pauseCauseProtectiveExit      pauseCause = "protective_exit"
	pauseCauseDailyLoss           pauseCause = "daily_loss"
	pauseCauseKillSwitch          pauseCause = "kill_switch"
	pauseCauseReconciliation      pauseCause = "reconciliation_conflict"
	pauseCauseTickFailure         pauseCause = "tick_failure"
	pauseCauseLiveGate            pauseCause = "live_gate"
	pauseCauseStrategyStop        pauseCause = "strategy_stop"
	pauseCauseUnsupportedExchange pauseCause = "unsupported_exchange"
	pauseCauseUser                pauseCause = "user"
)

func (c pauseCause) valid() bool {
	switch c {
	case pauseCauseStaleTick,
		pauseCauseRiskLimit,
		pauseCauseProtectiveExit,
		pauseCauseDailyLoss,
		pauseCauseKillSwitch,
		pauseCauseReconciliation,
		pauseCauseTickFailure,
		pauseCauseLiveGate,
		pauseCauseStrategyStop,
		pauseCauseUnsupportedExchange,
		pauseCauseUser:
		return true
	default:
		return false
	}
}

func (c pauseCause) String() string { return string(c) }

func requirePauseCause(c pauseCause) pauseCause {
	if !c.valid() {
		panic(fmt.Sprintf("invalid pause cause %q", c))
	}

	return c
}

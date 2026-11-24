package backtest

import (
	"context"
	"time"
)

// StaleRunMessage mirrors the message used by the removed
// App\Console\Commands\FailStaleBacktests.
const StaleRunMessage = "The backtest worker stopped before the run finished."

// StaleGrace is the extra time beyond the job timeout before a run is stale.
const StaleGrace = 60 * time.Second

// StaleStore fails running runs that have not updated since the cutoff, and
// pending runs whose command never arrived within the orphan window.
type StaleStore interface {
	FailStaleRuns(ctx context.Context, cutoff time.Time, message string) (int64, error)
}

// FailStaleRuns applies the engine's stale cutoff. timeout is
// config('backtest.job_timeout'), defaulting to 600 seconds. Running runs fail
// at the cutoff; pending commands stay in the Redis stream until claimed, so
// the store only fails pending runs after its longer orphan window.
func FailStaleRuns(ctx context.Context, store StaleStore, timeout time.Duration) (int64, error) {
	if timeout <= 0 {
		timeout = 600 * time.Second
	}

	cutoff := time.Now().UTC().Add(-(timeout + StaleGrace))

	return store.FailStaleRuns(ctx, cutoff, StaleRunMessage)
}

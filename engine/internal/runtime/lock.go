package runtime

import (
	"context"
	"sync"
)

// botLocks serializes ticks and reconciliation for one bot inside this engine
// process. v1 runs a single engine instance; the scheduler already keeps one
// in-flight tick per bot, and this lock also keeps the reconciler from
// racing a tick. Control commands are intentionally not serialized: they only
// update status/order rows, and order placement re-checks the bot's runtime
// status inside the insert so a pause or kill switch takes effect immediately.
var botLocks sync.Map

// withBotLock runs fn under the bot's process-local mutex.
func withBotLock(ctx context.Context, botID int64, fn func(context.Context) error) error {
	value, _ := botLocks.LoadOrStore(botID, &sync.Mutex{})
	mutex := value.(*sync.Mutex)
	mutex.Lock()
	defer mutex.Unlock()

	return fn(ctx)
}

// runBotCommand executes a control command without waiting behind a tick. The
// command updates durable status/order rows; placement re-checks status inside
// its insert, so a pause or kill switch is effective immediately.
func runBotCommand(ctx context.Context, fn func(context.Context) error) error {
	return fn(ctx)
}

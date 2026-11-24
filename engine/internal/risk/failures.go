package risk

// ConsecutiveFailureTracker ports App\Domain\Exchange\ConsecutiveFailureTracker.
type ConsecutiveFailureTracker struct {
	Store     *Store
	Canceler  OrderCanceler
	threshold int
}

// NewConsecutiveFailureTracker builds a tracker. The PHP default threshold is
// config trading.failure.max_consecutive (3); callers pass it explicitly.
func NewConsecutiveFailureTracker(store *Store, canceler OrderCanceler, threshold int) *ConsecutiveFailureTracker {
	if threshold <= 0 {
		threshold = 3
	}

	return &ConsecutiveFailureTracker{Store: store, Canceler: canceler, threshold: threshold}
}

// Threshold returns the configured failure threshold.
func (t *ConsecutiveFailureTracker) Threshold() int {
	return t.threshold
}

// RecordFailure increments the counter, pauses on the threshold, and cancels
// open orders. A stopped bot keeps its status but still cancels and records.
func (t *ConsecutiveFailureTracker) RecordFailure(bot *Bot, message, operation string) int {
	bot.ConsecutiveFailures++
	bot.LastError = &message

	reached := bot.ConsecutiveFailures >= t.threshold
	if reached && bot.Status != StatusStopped {
		bot.Status = StatusPaused
	}

	if !reached {
		return bot.ConsecutiveFailures
	}

	failures := t.Store.CancelOpenOrders(bot.ID, t.Canceler)
	cancellationFailures := len(failures)

	activityContext := map[string]any{
		"operation":             operation,
		"consecutive_failures":  bot.ConsecutiveFailures,
		"cancellation_failures": cancellationFailures,
		"error":                 message,
	}

	t.Store.RecordActivity(bot, "exchange.failure_threshold", "error", "Bot paused after consecutive exchange failures.", activityContext)

	riskContext := map[string]any{
		"operation":             operation,
		"consecutive_failures":  bot.ConsecutiveFailures,
		"cancellation_failures": cancellationFailures,
	}

	t.Store.RecordRisk(bot, "exchange.failure_threshold", SeverityCritical, "Consecutive exchange failures reached the pause threshold.", riskContext)

	if cancellationFailures > 0 {
		t.Store.RecordRisk(bot, "risk.failure_cancel_failed", SeverityCritical, "Orders could not be canceled after consecutive exchange failures.", riskContext)
	}

	return bot.ConsecutiveFailures
}

// RecordSuccess resets the counter. It deliberately leaves last_error intact,
// matching PHP: a later success clears the failure streak but not the message.
func (t *ConsecutiveFailureTracker) RecordSuccess(bot *Bot) {
	if bot.ConsecutiveFailures == 0 {
		return
	}

	bot.ConsecutiveFailures = 0
}

// RecordError is a convenience wrapper around RecordFailure.
func (t *ConsecutiveFailureTracker) RecordError(bot *Bot, err error, operation string) int {
	return t.RecordFailure(bot, err.Error(), operation)
}

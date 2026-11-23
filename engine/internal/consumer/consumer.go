// Package consumer implements at-least-once consumption of the v1 command
// stream with a Redis consumer group.
//
// Delivery rules:
//   - XACK happens only after the processor's durable effects commit and the
//     engine:cmd:{id} idempotency key is written.
//   - On start, idle pending entries (PEL) older than IdleThreshold are
//     auto-claimed. A completed id is acknowledged without a second effect; an
//     incomplete id is retried.
//   - After MaxAttempts failed processing attempts the command is written to
//     engine.commands.dlq with a failed event that carries no secrets, and the
//     original entry is XACKed so the group is not blocked.
//   - A single consumer processes entries in stream order. Commands that share
//     an OrderingKey (the user for a kill_switch and that user's bot commands)
//     are therefore applied serially in stream order. v1 runs one engine
//     instance.
package consumer

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"sort"
	"strings"
	"time"

	goredis "github.com/redis/go-redis/v9"

	"github.com/johnson407/trading-bot/engine/internal/protocol"
)

// Processor applies one command's durable effects. It must return nil only
// after its Postgres transaction has committed (or after a definitive
// rejection expressed as Rejection). Returning any other error asks the
// consumer to retry and eventually dead-letter.
type Processor interface {
	Process(ctx context.Context, command protocol.Envelope) error
}

// Idempotency records completed command ids.
type Idempotency interface {
	Completed(ctx context.Context, id string) (bool, error)
	MarkCompleted(ctx context.Context, id string) error
}

// Rejection is a definitive failure: the command will not succeed on retry and
// must not be executed. The consumer emits a failed event and acknowledges it.
type Rejection struct {
	Reason string
}

// Error implements error.
func (r Rejection) Error() string {
	reason := r.Reason
	if reason == "" {
		reason = "rejected"
	}

	return "command rejected: " + reason
}

// Config controls stream names and retry/reclaim timing.
type Config struct {
	Stream        string
	Group         string
	Consumer      string
	DLQ           string
	EventsStream  string
	StartID       string
	IdleThreshold time.Duration
	MaxAttempts   int
	RetryDelay    time.Duration
	ReadBlock     time.Duration
	BatchSize     int64
}

// DefaultConfig is the v1 contract configuration from design.md section 4.
func DefaultConfig() Config {
	return Config{
		Stream:        protocol.StreamCommands,
		Group:         protocol.CommandsGroup,
		Consumer:      "engine-1",
		DLQ:           protocol.StreamCommandsDLQ,
		EventsStream:  protocol.StreamEvents,
		StartID:       "$",
		IdleThreshold: 30 * time.Second,
		MaxAttempts:   5,
		RetryDelay:    time.Second,
		ReadBlock:     time.Second,
		BatchSize:     10,
	}
}

// Consumer consumes one command stream.
type Consumer struct {
	client      *goredis.Client
	processor   Processor
	idempotency Idempotency
	logger      *slog.Logger
	config      Config
	now         func() time.Time
}

// New builds a consumer. Empty names fall back to the v1 defaults; a zero
// RetryDelay or IdleThreshold is preserved so tests can run without waiting.
func New(
	client *goredis.Client,
	processor Processor,
	idempotency Idempotency,
	logger *slog.Logger,
	config Config,
) *Consumer {
	defaults := DefaultConfig()

	if config.Stream == "" {
		config.Stream = defaults.Stream
	}
	if config.Group == "" {
		config.Group = defaults.Group
	}
	if config.Consumer == "" {
		config.Consumer = defaults.Consumer
	}
	if config.DLQ == "" {
		config.DLQ = defaults.DLQ
	}
	if config.EventsStream == "" {
		config.EventsStream = defaults.EventsStream
	}
	if config.StartID == "" {
		config.StartID = defaults.StartID
	}
	if config.MaxAttempts < 1 {
		config.MaxAttempts = defaults.MaxAttempts
	}
	if config.BatchSize < 1 {
		config.BatchSize = defaults.BatchSize
	}
	if config.ReadBlock <= 0 {
		config.ReadBlock = defaults.ReadBlock
	}
	if config.RetryDelay < 0 {
		config.RetryDelay = defaults.RetryDelay
	}
	if config.IdleThreshold < 0 {
		config.IdleThreshold = defaults.IdleThreshold
	}
	if logger == nil {
		logger = slog.Default()
	}

	return &Consumer{
		client:      client,
		processor:   processor,
		idempotency: idempotency,
		logger:      logger,
		config:      config,
		now:         time.Now,
	}
}

// Run ensures the consumer group, reclaims idle pending entries, and then
// consumes new entries until ctx is cancelled.
func (c *Consumer) Run(ctx context.Context) error {
	for {
		if err := c.EnsureGroup(ctx); err == nil {
			break
		} else if ctx.Err() != nil {
			return nil
		} else {
			c.logger.Warn("command group unavailable; retrying",
				"group", c.config.Group,
				"error_type", errorType(err),
			)

			if !c.wait(ctx, c.config.RetryDelay) {
				return nil
			}
		}
	}

	if _, err := c.claimPending(ctx); err != nil && ctx.Err() == nil {
		// A failed reclaim must not stop new command consumption; pending
		// entries remain in the PEL for the next start or sweep.
		c.logger.Warn("pending command reclaim failed", "error_type", errorType(err))
	}

	for {
		if ctx.Err() != nil {
			return nil
		}

		streams, err := c.client.XReadGroup(ctx, &goredis.XReadGroupArgs{
			Group:    c.config.Group,
			Consumer: c.config.Consumer,
			Streams:  []string{c.config.Stream, ">"},
			Count:    c.config.BatchSize,
			Block:    c.config.ReadBlock,
		}).Result()

		if errors.Is(err, goredis.Nil) {
			continue
		}

		if err != nil {
			if ctx.Err() != nil {
				return nil
			}

			c.logger.Warn("command read failed; retrying", "error_type", errorType(err))

			if !c.wait(ctx, c.config.RetryDelay) {
				return nil
			}

			continue
		}

		c.handleStreams(ctx, streams)
	}
}

// Drain reclaims idle pending entries and processes all currently available
// new entries without blocking. It is used by tests and one-shot tooling.
func (c *Consumer) Drain(ctx context.Context) (int, error) {
	if err := c.EnsureGroup(ctx); err != nil {
		return 0, err
	}

	total, err := c.claimPending(ctx)
	if err != nil {
		return total, err
	}

	for {
		streams, err := c.client.XReadGroup(ctx, &goredis.XReadGroupArgs{
			Group:    c.config.Group,
			Consumer: c.config.Consumer,
			Streams:  []string{c.config.Stream, ">"},
			Count:    c.config.BatchSize,
			Block:    time.Millisecond,
		}).Result()

		if errors.Is(err, goredis.Nil) {
			return total, nil
		}

		if err != nil {
			return total, err
		}

		count := c.handleStreams(ctx, streams)
		total += count

		if count == 0 {
			return total, nil
		}
	}
}

// EnsureGroup creates the stream/group when missing. StartID "$" means a fresh
// group ignores commands published before the first engine start; Postgres
// desired_status is the recovery source in that case.
func (c *Consumer) EnsureGroup(ctx context.Context) error {
	err := c.client.XGroupCreateMkStream(ctx, c.config.Stream, c.config.Group, c.config.StartID).Err()
	if err != nil && !strings.Contains(err.Error(), "BUSYGROUP") {
		return fmt.Errorf("create consumer group: %w", err)
	}

	return nil
}

func (c *Consumer) handleStreams(ctx context.Context, streams []goredis.XStream) int {
	count := 0

	for _, stream := range streams {
		for _, message := range stream.Messages {
			if ctx.Err() != nil {
				return count
			}

			c.processWithRetry(ctx, message)
			count++
		}
	}

	return count
}

// claimPending auto-claims entries idle for at least IdleThreshold. This runs
// on engine start (and on Drain).
func (c *Consumer) claimPending(ctx context.Context) (int, error) {
	start := "0-0"
	total := 0

	for {
		messages, next, err := c.client.XAutoClaim(ctx, &goredis.XAutoClaimArgs{
			Stream:   c.config.Stream,
			Group:    c.config.Group,
			Consumer: c.config.Consumer,
			MinIdle:  c.config.IdleThreshold,
			Start:    start,
			Count:    c.config.BatchSize,
		}).Result()
		if err != nil {
			return total, fmt.Errorf("auto-claim pending entries: %w", err)
		}

		for _, message := range messages {
			c.processWithRetry(ctx, message)
			total++
		}

		if next == "" || next == "0-0" || next == start {
			return total, nil
		}

		start = next
	}
}

func (c *Consumer) processWithRetry(ctx context.Context, message goredis.XMessage) {
	for {
		err := c.process(ctx, message)
		if err == nil {
			c.clearAttempts(ctx, message)

			return
		}

		if ctx.Err() != nil {
			return
		}

		attempts, counterErr := c.recordAttempt(ctx, message)
		if counterErr != nil {
			c.logger.Warn("command attempt counter failed; leaving entry pending",
				"message_id", message.ID,
				"error_type", errorType(counterErr),
			)

			return
		}

		if attempts >= int64(c.config.MaxAttempts) {
			c.deadLetter(ctx, message, int(attempts), err)

			return
		}

		c.logger.Warn("command processing failed; retrying",
			"message_id", message.ID,
			"attempt", attempts,
			"max_attempts", c.config.MaxAttempts,
			"error_type", errorType(err),
		)

		if !c.wait(ctx, c.config.RetryDelay) {
			return
		}
	}
}

func (c *Consumer) process(ctx context.Context, message goredis.XMessage) error {
	raw, ok := envelopeBytes(message.Values)
	if !ok {
		return fmt.Errorf("stream entry has no %s field", protocol.EnvelopeField)
	}

	envelope, err := protocol.Decode(raw)
	if err != nil {
		return err
	}

	completed, err := c.idempotency.Completed(ctx, envelope.ID)
	if err != nil {
		return err
	}

	if completed {
		c.logger.Info("command already completed; acknowledging duplicate",
			"command_id", envelope.ID,
			"type", envelope.Type,
		)

		return c.ack(ctx, message)
	}

	if envelope.Unsupported() {
		c.logger.Warn("command uses unsupported envelope version; not executing",
			"command_id", envelope.ID,
			"type", envelope.Type,
			"version", envelope.V,
		)

		return c.definitiveFailure(ctx, message, envelope, "unsupported_version")
	}

	if err := envelope.ValidateCommand(); err != nil {
		c.logger.Warn("command is invalid; not executing",
			"command_id", envelope.ID,
			"type", envelope.Type,
			"reason", err.Error(),
		)

		return c.definitiveFailure(ctx, message, envelope, "invalid_command")
	}

	if err := c.processor.Process(ctx, envelope); err != nil {
		var rejection Rejection
		if errors.As(err, &rejection) {
			c.logger.Warn("command was definitively rejected",
				"command_id", envelope.ID,
				"type", envelope.Type,
				"reason", rejection.Reason,
			)

			return c.definitiveFailure(ctx, message, envelope, rejection.Reason)
		}

		return err
	}

	if err := c.idempotency.MarkCompleted(ctx, envelope.ID); err != nil {
		// Do not XACK: the durable effect exists but the completion marker does
		// not, so a retry must be allowed to observe per-action idempotency.
		return err
	}

	return c.ack(ctx, message)
}

func (c *Consumer) definitiveFailure(
	ctx context.Context,
	message goredis.XMessage,
	envelope protocol.Envelope,
	reason string,
) error {
	if err := c.emitFailure(ctx, envelope.ID, envelope.Type, envelope.User(), envelope.Bot(), reason, 0); err != nil {
		return err
	}

	if err := c.idempotency.MarkCompleted(ctx, envelope.ID); err != nil {
		return err
	}

	return c.ack(ctx, message)
}

func (c *Consumer) deadLetter(
	ctx context.Context,
	message goredis.XMessage,
	attempts int,
	processingErr error,
) {
	id, commandType, userID, botID := bestEffortEnvelope(message.Values)

	values := map[string]any{
		"message_id": message.ID,
		"reason":     "dead_lettered",
		"attempts":   attempts,
		"failed_at":  c.now().UTC().Format(time.RFC3339),
	}

	if raw, ok := message.Values[protocol.EnvelopeField]; ok {
		values[protocol.EnvelopeField] = raw
	} else {
		// Never copy unknown values into the DLQ: they might contain a secret.
		// Field names are enough for an operator to identify the producer bug.
		values["raw_fields"] = fieldNames(message.Values)
	}

	if _, err := c.client.XAdd(ctx, &goredis.XAddArgs{
		Stream: c.config.DLQ,
		Values: values,
	}).Result(); err != nil {
		c.logger.Error("failed to dead-letter command; leaving entry pending",
			"message_id", message.ID,
			"error_type", errorType(err),
		)

		return
	}

	if err := c.emitFailure(ctx, id, commandType, userID, botID, "dead_lettered", attempts); err != nil {
		c.logger.Error("failed to emit dead-letter event",
			"message_id", message.ID,
			"error_type", errorType(err),
		)

		return
	}

	if id != "" {
		if err := c.idempotency.MarkCompleted(ctx, id); err != nil {
			c.logger.Error("failed to mark dead-lettered command complete",
				"command_id", id,
				"error_type", errorType(err),
			)

			return
		}
	}

	if err := c.ack(ctx, message); err != nil {
		c.logger.Error("failed to acknowledge dead-lettered command",
			"message_id", message.ID,
			"error_type", errorType(err),
		)

		return
	}

	c.clearAttempts(ctx, message)

	c.logger.Error("command dead-lettered after repeated failures",
		"message_id", message.ID,
		"command_id", id,
		"type", commandType,
		"attempts", attempts,
		"error_type", errorType(processingErr),
	)
}

func (c *Consumer) emitFailure(
	ctx context.Context,
	commandID string,
	commandType string,
	userID int64,
	botID int64,
	reason string,
	attempts int,
) error {
	event, err := protocol.NewEvent(protocol.EventCommandFailed, map[string]any{
		"correlation_id": commandID,
		"command_id":     commandID,
		"command_type":   commandType,
		"reason":         reason,
		"attempts":       attempts,
	}, userID, botID)
	if err != nil {
		return err
	}

	encoded, err := event.Encode()
	if err != nil {
		return err
	}

	if _, err := c.client.XAdd(ctx, &goredis.XAddArgs{
		Stream: c.config.EventsStream,
		Values: map[string]any{protocol.EnvelopeField: encoded},
	}).Result(); err != nil {
		return fmt.Errorf("emit command failure event: %w", err)
	}

	return nil
}

func (c *Consumer) ack(ctx context.Context, message goredis.XMessage) error {
	if err := c.client.XAck(ctx, c.config.Stream, c.config.Group, message.ID).Err(); err != nil {
		return fmt.Errorf("ack command: %w", err)
	}

	return nil
}

func (c *Consumer) recordAttempt(ctx context.Context, message goredis.XMessage) (int64, error) {
	key := attemptsKey(message)

	attempts, err := c.client.Incr(ctx, key).Result()
	if err != nil {
		return 0, err
	}

	if attempts == 1 {
		if err := c.client.Expire(ctx, key, protocol.IdempotencyTTL).Err(); err != nil {
			return attempts, err
		}
	}

	return attempts, nil
}

func (c *Consumer) clearAttempts(ctx context.Context, message goredis.XMessage) {
	if err := c.client.Del(ctx, attemptsKey(message)).Err(); err != nil && ctx.Err() == nil {
		c.logger.Warn("failed to clear command attempt counter",
			"message_id", message.ID,
			"error_type", errorType(err),
		)
	}
}

func (c *Consumer) wait(ctx context.Context, delay time.Duration) bool {
	if delay <= 0 {
		select {
		case <-ctx.Done():
			return false
		default:
			return true
		}
	}

	timer := time.NewTimer(delay)
	defer timer.Stop()

	select {
	case <-ctx.Done():
		return false
	case <-timer.C:
		return true
	}
}

func attemptsKey(message goredis.XMessage) string {
	id, _, _, _ := bestEffortEnvelope(message.Values)
	if id != "" {
		return "engine:cmd:attempts:" + id
	}

	return "engine:cmd:attempts:msg:" + message.ID
}

func envelopeBytes(values map[string]any) ([]byte, bool) {
	// The contract field is `envelope`; accept common aliases for producer
	// compatibility without changing the contract.
	for _, key := range []string{protocol.EnvelopeField, "data", "payload"} {
		value, ok := values[key]
		if !ok {
			continue
		}

		switch typed := value.(type) {
		case string:
			return []byte(typed), true
		case []byte:
			return typed, true
		}
	}

	return nil, false
}

func bestEffortEnvelope(values map[string]any) (id, commandType string, userID, botID int64) {
	raw, ok := envelopeBytes(values)
	if !ok {
		return "", "", 0, 0
	}

	var envelope protocol.Envelope
	if err := json.Unmarshal(raw, &envelope); err != nil {
		return "", "", 0, 0
	}

	return envelope.ID, envelope.Type, envelope.User(), envelope.Bot()
}

func fieldNames(values map[string]any) string {
	names := make([]string, 0, len(values))
	for name := range values {
		names = append(names, name)
	}

	sort.Strings(names)

	return strings.Join(names, ",")
}

// errorType reports the Go type of an error without echoing its message, which
// may contain connection strings or other secrets.
func errorType(err error) string {
	if err == nil {
		return ""
	}

	return fmt.Sprintf("%T", err)
}

package consumer

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	goredis "github.com/redis/go-redis/v9"

	"github.com/johnson407/trading-bot/engine/internal/idempotency"
	"github.com/johnson407/trading-bot/engine/internal/protocol"
)

type recordedCall struct {
	Type   string
	ID     string
	BotID  int64
	UserID int64
}

type recordingProcessor struct {
	mu        sync.Mutex
	calls     []recordedCall
	failures  int
	failEvery bool
	err       error
	block     chan struct{}
}

func (p *recordingProcessor) Process(ctx context.Context, command protocol.Envelope) error {
	if p.block != nil {
		select {
		case <-p.block:
		case <-ctx.Done():
			return ctx.Err()
		}
	}

	p.mu.Lock()
	p.calls = append(p.calls, recordedCall{
		Type:   command.Type,
		ID:     command.ID,
		BotID:  command.Bot(),
		UserID: command.User(),
	})
	failures := p.failures
	if p.failures > 0 {
		p.failures--
	}
	failEvery := p.failEvery
	err := p.err
	p.mu.Unlock()

	if failEvery || failures > 0 {
		if err == nil {
			err = errors.New("durable effect failed: password=supersecret")
		}

		return err
	}

	return nil
}

func (p *recordingProcessor) Calls() []recordedCall {
	p.mu.Lock()
	defer p.mu.Unlock()

	return append([]recordedCall(nil), p.calls...)
}

func (p *recordingProcessor) CallTypes() []string {
	p.mu.Lock()
	defer p.mu.Unlock()

	types := make([]string, len(p.calls))
	for index, call := range p.calls {
		types[index] = call.Type
	}

	return types
}

type testRig struct {
	consumer    *Consumer
	client      *goredis.Client
	mini        *miniredis.Miniredis
	processor   *recordingProcessor
	idempotency *idempotency.Store
	config      Config
}

func newRig(t *testing.T, processor *recordingProcessor) *testRig {
	t.Helper()

	mini := miniredis.RunT(t)
	client := goredis.NewClient(&goredis.Options{Addr: mini.Addr()})
	t.Cleanup(func() { _ = client.Close() })

	config := DefaultConfig()
	config.StartID = "0"
	config.IdleThreshold = 0
	config.RetryDelay = 0
	config.BatchSize = 20

	store := idempotency.New(client)
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))

	return &testRig{
		consumer:    New(client, processor, store, logger, config),
		client:      client,
		mini:        mini,
		processor:   processor,
		idempotency: store,
		config:      config,
	}
}

func (r *testRig) publish(t *testing.T, envelope protocol.Envelope) string {
	t.Helper()

	encoded, err := envelope.Encode()
	if err != nil {
		t.Fatalf("Encode() error = %v", err)
	}

	id, err := r.client.XAdd(context.Background(), &goredis.XAddArgs{
		Stream: r.config.Stream,
		Values: map[string]any{protocol.EnvelopeField: encoded},
	}).Result()
	if err != nil {
		t.Fatalf("XAdd() error = %v", err)
	}

	return id
}

func (r *testRig) publishRaw(t *testing.T, field, value string) string {
	t.Helper()

	id, err := r.client.XAdd(context.Background(), &goredis.XAddArgs{
		Stream: r.config.Stream,
		Values: map[string]any{field: value},
	}).Result()
	if err != nil {
		t.Fatalf("XAdd(raw) error = %v", err)
	}

	return id
}

func (r *testRig) drain(t *testing.T) int {
	t.Helper()

	count, err := r.consumer.Drain(context.Background())
	if err != nil {
		t.Fatalf("Drain() error = %v", err)
	}

	return count
}

func (r *testRig) pendingCount(t *testing.T) int64 {
	t.Helper()

	pending, err := r.client.XPending(context.Background(), r.config.Stream, r.config.Group).Result()
	if err != nil {
		t.Fatalf("XPending() error = %v", err)
	}

	return pending.Count
}

func (r *testRig) messageCount(t *testing.T, stream string) int64 {
	t.Helper()

	count, err := r.client.XLen(context.Background(), stream).Result()
	if err != nil {
		t.Fatalf("XLen(%s) error = %v", stream, err)
	}

	return count
}

func (r *testRig) events(t *testing.T, stream string) []protocol.Envelope {
	t.Helper()

	messages, err := r.client.XRange(context.Background(), stream, "-", "+").Result()
	if err != nil {
		t.Fatalf("XRange(%s) error = %v", stream, err)
	}

	envelopes := make([]protocol.Envelope, 0, len(messages))
	for _, message := range messages {
		raw, ok := message.Values[protocol.EnvelopeField]
		if !ok {
			continue
		}

		var encoded []byte
		switch typed := raw.(type) {
		case string:
			encoded = []byte(typed)
		case []byte:
			encoded = typed
		default:
			t.Fatalf("unexpected envelope field type %T", raw)
		}

		decoded, err := protocol.Decode(encoded)
		if err != nil {
			t.Fatalf("event envelope is not valid: %v", err)
		}

		envelopes = append(envelopes, decoded)
	}

	return envelopes
}

func command(id, commandType string, userID, botID int64) protocol.Envelope {
	return protocol.Envelope{
		V:       1,
		ID:      id,
		Type:    commandType,
		TS:      "2026-11-23T05:00:00Z",
		UserID:  userID,
		BotID:   botID,
		Payload: json.RawMessage(`{}`),
	}
}

func TestUnknownHigherVersionIsNotExecuted(t *testing.T) {
	t.Parallel()

	rig := newRig(t, &recordingProcessor{})

	envelope := command("future-1", protocol.CommandBotStart, 1, 208)
	envelope.V = 2
	rig.publish(t, envelope)

	rig.drain(t)

	if calls := rig.processor.Calls(); len(calls) != 0 {
		t.Fatalf("processor calls = %+v, want none", calls)
	}

	if pending := rig.pendingCount(t); pending != 0 {
		t.Errorf("pending = %d, want 0 after definitive rejection", pending)
	}

	events := rig.events(t, rig.config.EventsStream)
	if len(events) != 1 {
		t.Fatalf("events = %d, want 1 failed command event", len(events))
	}

	if events[0].Type != protocol.EventCommandFailed {
		t.Errorf("event type = %q, want %q", events[0].Type, protocol.EventCommandFailed)
	}

	raw, _ := json.Marshal(events[0])
	if strings.Contains(string(raw), "supersecret") {
		t.Fatalf("event leaked a secret: %s", raw)
	}

	var payload map[string]any
	_ = json.Unmarshal(events[0].Payload, &payload)
	if payload["reason"] != "unsupported_version" || payload["correlation_id"] != "future-1" {
		t.Errorf("payload = %+v, want unsupported_version correlated with the command", payload)
	}
}

func TestDuplicateIDHasNoSecondEffect(t *testing.T) {
	t.Parallel()

	rig := newRig(t, &recordingProcessor{})

	first := command("duplicate-1", protocol.CommandBotPause, 1, 208)
	second := command("duplicate-1", protocol.CommandBotStart, 1, 208)
	rig.publish(t, first)
	rig.publish(t, second)

	rig.drain(t)

	calls := rig.processor.Calls()
	if len(calls) != 1 || calls[0].Type != protocol.CommandBotPause {
		t.Fatalf("processor calls = %+v, want only the first bot.pause", calls)
	}

	if pending := rig.pendingCount(t); pending != 0 {
		t.Errorf("pending = %d, want 0; both entries must be acknowledged", pending)
	}
}

func TestProcessDoesNotAckBeforeDurableEffects(t *testing.T) {
	t.Parallel()

	rig := newRig(t, &recordingProcessor{failEvery: true, err: errors.New("postgres down")})
	rig.publish(t, command("fail-1", protocol.CommandBotStart, 1, 208))

	if err := rig.consumer.EnsureGroup(context.Background()); err != nil {
		t.Fatalf("EnsureGroup() error = %v", err)
	}

	streams, err := rig.client.XReadGroup(context.Background(), &goredis.XReadGroupArgs{
		Group:    rig.config.Group,
		Consumer: rig.config.Consumer,
		Streams:  []string{rig.config.Stream, ">"},
		Count:    1,
	}).Result()
	if err != nil {
		t.Fatalf("XReadGroup() error = %v", err)
	}

	if err := rig.consumer.process(context.Background(), streams[0].Messages[0]); err == nil {
		t.Fatal("process() error = nil, want the durable-effect failure")
	}

	if pending := rig.pendingCount(t); pending != 1 {
		t.Errorf("pending = %d, want 1; a failed effect must not be acknowledged", pending)
	}

	completed, err := rig.idempotency.Completed(context.Background(), "fail-1")
	if err != nil {
		t.Fatalf("Completed() error = %v", err)
	}
	if completed {
		t.Error("idempotency key written before durable effects completed")
	}
}

func TestProcessDoesNotAckWhenIdempotencyWriteFails(t *testing.T) {
	t.Parallel()

	rig := newRig(t, &recordingProcessor{})
	rig.consumer.idempotency = failingIdempotency{}

	rig.publish(t, command("idem-1", protocol.CommandBotStart, 1, 208))

	if err := rig.consumer.EnsureGroup(context.Background()); err != nil {
		t.Fatalf("EnsureGroup() error = %v", err)
	}

	streams, err := rig.client.XReadGroup(context.Background(), &goredis.XReadGroupArgs{
		Group:    rig.config.Group,
		Consumer: rig.config.Consumer,
		Streams:  []string{rig.config.Stream, ">"},
		Count:    1,
	}).Result()
	if err != nil {
		t.Fatalf("XReadGroup() error = %v", err)
	}

	if err := rig.consumer.process(context.Background(), streams[0].Messages[0]); err == nil {
		t.Fatal("process() error = nil, want the idempotency write failure")
	}

	if pending := rig.pendingCount(t); pending != 1 {
		t.Errorf("pending = %d, want 1; a missing idempotency key must not be acknowledged", pending)
	}
}

func TestPELReclaimAcksCompletedIDWithoutSecondEffect(t *testing.T) {
	t.Parallel()

	rig := newRig(t, &recordingProcessor{})
	rig.publish(t, command("completed-1", protocol.CommandBotStart, 1, 208))

	deliverAsCrashedConsumer(t, rig)

	if err := rig.idempotency.MarkCompleted(context.Background(), "completed-1"); err != nil {
		t.Fatalf("MarkCompleted() error = %v", err)
	}

	rig.drain(t)

	if calls := rig.processor.Calls(); len(calls) != 0 {
		t.Fatalf("processor calls = %+v, want none for a completed id", calls)
	}

	if pending := rig.pendingCount(t); pending != 0 {
		t.Errorf("pending = %d, want 0 after reclaiming a completed id", pending)
	}
}

func TestPELReclaimRetriesIncompleteID(t *testing.T) {
	t.Parallel()

	rig := newRig(t, &recordingProcessor{})
	rig.publish(t, command("incomplete-1", protocol.CommandBotStart, 1, 208))

	deliverAsCrashedConsumer(t, rig)
	rig.drain(t)

	calls := rig.processor.Calls()
	if len(calls) != 1 || calls[0].ID != "incomplete-1" {
		t.Fatalf("processor calls = %+v, want the incomplete id retried once", calls)
	}

	if pending := rig.pendingCount(t); pending != 0 {
		t.Errorf("pending = %d, want 0 after a successful retry", pending)
	}
}

func TestPoisonCommandIsDeadLetteredAfterFiveAttempts(t *testing.T) {
	t.Parallel()

	rig := newRig(t, &recordingProcessor{failEvery: true, err: errors.New("boom password=supersecret")})

	rig.publish(t, command("poison-1", protocol.CommandBotStart, 1, 208))
	rig.drain(t)

	if calls := rig.processor.Calls(); len(calls) != 5 {
		t.Fatalf("processor attempts = %d, want 5", len(calls))
	}

	if pending := rig.pendingCount(t); pending != 0 {
		t.Errorf("pending = %d, want 0; a dead-lettered original must be XACKed", pending)
	}

	dlq := rig.messageCount(t, rig.config.DLQ)
	if dlq != 1 {
		t.Fatalf("DLQ entries = %d, want 1", dlq)
	}

	// The failed event goes to engine.events, not the DLQ. A second consumer
	// would normally read it; assert it exists and carries no secret.
	events := rig.events(t, rig.config.EventsStream)
	if len(events) != 1 || events[0].Type != protocol.EventCommandFailed {
		t.Fatalf("events = %+v, want one engine.command_failed", events)
	}

	raw, _ := json.Marshal(events[0])
	if strings.Contains(string(raw), "supersecret") {
		t.Fatalf("failed event leaked a secret: %s", raw)
	}

	var payload map[string]any
	_ = json.Unmarshal(events[0].Payload, &payload)
	if payload["reason"] != "dead_lettered" {
		t.Errorf("failure reason = %v, want dead_lettered", payload["reason"])
	}
	if attempts, ok := payload["attempts"].(float64); !ok || int(attempts) != 5 {
		t.Errorf("failure attempts = %v, want 5", payload["attempts"])
	}
}

func TestCommandsForOneBotApplyInStreamOrder(t *testing.T) {
	t.Parallel()

	rig := newRig(t, &recordingProcessor{})

	rig.publish(t, command("order-1", protocol.CommandBotPause, 7, 10))
	rig.publish(t, command("order-2", protocol.CommandKillSwitch, 7, 0))
	rig.publish(t, command("order-3", protocol.CommandBotStart, 7, 10))

	rig.drain(t)

	want := []string{protocol.CommandBotPause, protocol.CommandKillSwitch, protocol.CommandBotStart}
	got := rig.processor.CallTypes()

	if len(got) != len(want) {
		t.Fatalf("call types = %v, want %v", got, want)
	}

	for index := range want {
		if got[index] != want[index] {
			t.Fatalf("call types = %v, want %v (later command ran first)", got, want)
		}
	}
}

func TestInvalidCommandIsRejectedWithoutExecution(t *testing.T) {
	t.Parallel()

	rig := newRig(t, &recordingProcessor{})

	// bot.start without user_id cannot be serialized against a kill_switch.
	rig.publish(t, command("invalid-1", protocol.CommandBotStart, 0, 208))
	rig.drain(t)

	if calls := rig.processor.Calls(); len(calls) != 0 {
		t.Fatalf("processor calls = %+v, want none", calls)
	}

	if pending := rig.pendingCount(t); pending != 0 {
		t.Errorf("pending = %d, want 0 after definitive rejection", pending)
	}

	events := rig.events(t, rig.config.EventsStream)
	if len(events) != 1 {
		t.Fatalf("events = %d, want 1", len(events))
	}

	var payload map[string]any
	_ = json.Unmarshal(events[0].Payload, &payload)
	if payload["reason"] != "invalid_command" {
		t.Errorf("reason = %v, want invalid_command", payload["reason"])
	}
}

func TestMalformedCommandIsDeadLetteredWithoutBlocking(t *testing.T) {
	t.Parallel()

	rig := newRig(t, &recordingProcessor{})
	rig.config.MaxAttempts = 5
	rig.consumer.config.MaxAttempts = 5
	rig.publishRaw(t, protocol.EnvelopeField, "not-json")

	rig.drain(t)

	if pending := rig.pendingCount(t); pending != 0 {
		t.Errorf("pending = %d, want 0 after dead-lettering malformed input", pending)
	}

	if dlq := rig.messageCount(t, rig.config.DLQ); dlq != 1 {
		t.Errorf("DLQ entries = %d, want 1", dlq)
	}

	events := rig.events(t, rig.config.EventsStream)
	if len(events) != 1 || events[0].Type != protocol.EventCommandFailed {
		t.Fatalf("events = %+v, want one engine.command_failed", events)
	}
}

type failingIdempotency struct{}

func (failingIdempotency) Completed(context.Context, string) (bool, error) {
	return false, nil
}

func (failingIdempotency) MarkCompleted(context.Context, string) error {
	return errors.New("redis unavailable")
}

func deliverAsCrashedConsumer(t *testing.T, rig *testRig) {
	t.Helper()

	if err := rig.consumer.EnsureGroup(context.Background()); err != nil {
		t.Fatalf("EnsureGroup() error = %v", err)
	}

	// A consumer that dies before XACK leaves the entry in the group PEL.
	streams, err := rig.client.XReadGroup(context.Background(), &goredis.XReadGroupArgs{
		Group:    rig.config.Group,
		Consumer: "crashed-engine",
		Streams:  []string{rig.config.Stream, ">"},
		Count:    1,
		// XReadGroup returns immediately when Count is satisfied.
		Block: time.Millisecond,
	}).Result()
	if err != nil {
		t.Fatalf("XReadGroup(crashed) error = %v", err)
	}

	if len(streams) == 0 || len(streams[0].Messages) == 0 {
		t.Fatal("crashed consumer did not receive the pending entry")
	}
}

package protocol_test

import (
	"encoding/json"
	"regexp"
	"strings"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/protocol"
)

func TestEncodeDecodeRoundTrip(t *testing.T) {
	t.Parallel()

	envelope := protocol.Envelope{
		V:       protocol.Version,
		ID:      "01jcexxxxxxxxxxxxxxxxxxxxx",
		Type:    protocol.CommandBotStart,
		TS:      "2026-11-23T05:00:00Z",
		UserID:  1,
		BotID:   208,
		Payload: json.RawMessage(`{"bot_id":208,"note":"unknown fields are ignored"}`),
	}

	encoded, err := envelope.Encode()
	if err != nil {
		t.Fatalf("Encode() error = %v", err)
	}

	decoded, err := protocol.Decode(encoded)
	if err != nil {
		t.Fatalf("Decode() error = %v", err)
	}

	if decoded.V != 1 || decoded.ID != envelope.ID || decoded.Type != envelope.Type {
		t.Errorf("decoded = %+v, want v1 id/type preserved", decoded)
	}

	if decoded.User() != 1 || decoded.Bot() != 208 {
		t.Errorf("routing ids = %d/%d, want 1/208", decoded.User(), decoded.Bot())
	}

	id, ok := decoded.PayloadInt("bot_id")
	if !ok || id != 208 {
		t.Errorf("PayloadInt(bot_id) = %d/%v, want 208/true", id, ok)
	}
}

func TestDecodeAcceptsHigherVersionWithoutExecuting(t *testing.T) {
	t.Parallel()

	decoded, err := protocol.Decode([]byte(`{
		"v": 2,
		"id": "future-command",
		"type": "bot.start",
		"ts": "2026-11-23T05:00:00Z",
		"user_id": 1,
		"bot_id": 208,
		"payload": {}
	}`))
	if err != nil {
		t.Fatalf("Decode() error = %v", err)
	}

	if !decoded.Unsupported() {
		t.Fatal("Unsupported() = false, want true for v2")
	}

	if err := decoded.ValidateCommand(); err == nil {
		t.Fatal("ValidateCommand() error = nil, want an unsupported-version rejection")
	}
}

func TestDecodeIgnoresUnknownFields(t *testing.T) {
	t.Parallel()

	decoded, err := protocol.Decode([]byte(`{
		"v": 1,
		"id": "command-1",
		"type": "bot.pause",
		"ts": "2026-11-23T05:00:00Z",
		"user_id": 1,
		"bot_id": 208,
		"unknown_envelope_field": true,
		"payload": {"bot_id": 208, "unknown_payload_field": "ignored"}
	}`))
	if err != nil {
		t.Fatalf("Decode() error = %v", err)
	}

	if err := decoded.ValidateCommand(); err != nil {
		t.Errorf("ValidateCommand() error = %v, want nil", err)
	}
}

func TestDecodeRejectsMalformedEnvelopes(t *testing.T) {
	t.Parallel()

	cases := map[string]string{
		"missing id":   `{"v":1,"type":"bot.start","ts":"2026-11-23T05:00:00Z"}`,
		"missing type": `{"v":1,"id":"x","ts":"2026-11-23T05:00:00Z"}`,
		"missing ts":   `{"v":1,"id":"x","type":"bot.start"}`,
		"zero v":       `{"v":0,"id":"x","type":"bot.start","ts":"2026-11-23T05:00:00Z"}`,
		"bad ts":       `{"v":1,"id":"x","type":"bot.start","ts":"23/11/2026"}`,
		"trailing":     `{"v":1,"id":"x","type":"bot.start","ts":"2026-11-23T05:00:00Z"}{}`,
	}

	for name, data := range cases {
		if _, err := protocol.Decode([]byte(data)); err == nil {
			t.Errorf("%s: Decode() error = nil, want error", name)
		}
	}
}

func TestValidateCommandRequiresRoutingIdentifiers(t *testing.T) {
	t.Parallel()

	cases := map[string]protocol.Envelope{
		"kill switch user": {
			V: 1, ID: "x", Type: protocol.CommandKillSwitch, TS: "2026-11-23T05:00:00Z",
		},
		"bot command bot": {
			V: 1, ID: "x", Type: protocol.CommandBotStart, TS: "2026-11-23T05:00:00Z", UserID: 1,
		},
		"bot command user": {
			V: 1, ID: "x", Type: protocol.CommandBotPause, TS: "2026-11-23T05:00:00Z", BotID: 1,
		},
		"order id": {
			V: 1, ID: "x", Type: protocol.CommandOrderCancel, TS: "2026-11-23T05:00:00Z", UserID: 1, BotID: 1,
		},
		"backtest run id": {
			V: 1, ID: "x", Type: protocol.CommandBacktestRun, TS: "2026-11-23T05:00:00Z",
		},
		"backtest sweep id": {
			V: 1, ID: "x", Type: protocol.CommandBacktestSweep, TS: "2026-11-23T05:00:00Z",
		},
		"unknown type": {
			V: 1, ID: "x", Type: "bot.explode", TS: "2026-11-23T05:00:00Z", UserID: 1, BotID: 1,
		},
	}

	for name, envelope := range cases {
		if err := envelope.ValidateCommand(); err == nil {
			t.Errorf("%s: ValidateCommand() error = nil, want error", name)
		}
	}
}

func TestValidateCommandAcceptsEachV1Type(t *testing.T) {
	t.Parallel()

	base := protocol.Envelope{
		V: 1, ID: "x", Type: protocol.CommandBotStart, TS: "2026-11-23T05:00:00Z", UserID: 1, BotID: 208,
	}

	for _, commandType := range []string{
		protocol.CommandBotStart,
		protocol.CommandBotPause,
		protocol.CommandBotStop,
		protocol.CommandOrderCancel,
		protocol.CommandKillSwitch,
		protocol.CommandBacktestRun,
		protocol.CommandBacktestSweep,
	} {
		envelope := base
		envelope.Type = commandType

		switch commandType {
		case protocol.CommandKillSwitch:
			envelope.BotID = 0
		case protocol.CommandOrderCancel:
			envelope.Payload = json.RawMessage(`{"order_id":77}`)
		case protocol.CommandBacktestRun:
			envelope.Payload = json.RawMessage(`{"backtest_run_id":42}`)
		case protocol.CommandBacktestSweep:
			envelope.Payload = json.RawMessage(`{"backtest_sweep_id":43}`)
		}

		if err := envelope.ValidateCommand(); err != nil {
			t.Errorf("%s: ValidateCommand() error = %v, want nil", commandType, err)
		}
	}
}

func TestIdempotencyKeyTTLAndStreams(t *testing.T) {
	t.Parallel()

	if got := protocol.IdempotencyKey("abc"); got != "engine:cmd:abc" {
		t.Errorf("IdempotencyKey() = %q, want engine:cmd:abc", got)
	}

	if protocol.IdempotencyTTL < 7*24*time.Hour {
		t.Errorf("IdempotencyTTL = %s, want at least 7 days", protocol.IdempotencyTTL)
	}

	wantStreams := map[string]string{
		"commands":     protocol.StreamCommands,
		"events":       protocol.StreamEvents,
		"commands dlq": protocol.StreamCommandsDLQ,
		"events dlq":   protocol.StreamEventsDLQ,
	}

	for name, got := range wantStreams {
		if got == "" {
			t.Errorf("%s stream name is empty", name)
		}
	}
}

func TestOrderingKeySerializesKillSwitchWithUsersBots(t *testing.T) {
	t.Parallel()

	botCommand := protocol.Envelope{
		V: 1, ID: "bot", Type: protocol.CommandBotPause, TS: "2026-11-23T05:00:00Z", UserID: 7, BotID: 10,
	}
	killSwitch := protocol.Envelope{
		V: 1, ID: "kill", Type: protocol.CommandKillSwitch, TS: "2026-11-23T05:00:00Z", UserID: 7,
	}

	if botCommand.OrderingKey() != killSwitch.OrderingKey() {
		t.Errorf("ordering keys = %q and %q, want the same user queue",
			botCommand.OrderingKey(), killSwitch.OrderingKey())
	}

	otherUser := protocol.Envelope{
		V: 1, ID: "other", Type: protocol.CommandBotPause, TS: "2026-11-23T05:00:00Z", UserID: 8, BotID: 11,
	}
	if otherUser.OrderingKey() == botCommand.OrderingKey() {
		t.Error("different users share an ordering key, want independent queues")
	}
}

func TestNewIDIsCanonicalUUIDv4(t *testing.T) {
	t.Parallel()

	pattern := regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)
	seen := map[string]bool{}

	for range 100 {
		id := protocol.NewID()
		if !pattern.MatchString(id) {
			t.Fatalf("NewID() = %q, want canonical UUID v4", id)
		}

		if seen[id] {
			t.Fatalf("NewID() produced duplicate id %q", id)
		}

		seen[id] = true
	}
}

func TestNewEventCarriesCorrelationPayloadAndUTC(t *testing.T) {
	t.Parallel()

	event, err := protocol.NewEvent(protocol.EventCommandFailed, map[string]any{
		"correlation_id": "command-1",
		"reason":         "unsupported_version",
	}, 1, 208)
	if err != nil {
		t.Fatalf("NewEvent() error = %v", err)
	}

	if event.V != 1 || event.Type != protocol.EventCommandFailed {
		t.Errorf("event = %+v, want v1 engine.command_failed", event)
	}

	if !strings.HasSuffix(event.TS, "Z") {
		t.Errorf("event ts = %q, want UTC Z suffix", event.TS)
	}

	payload := event.Payload
	if len(payload) == 0 {
		t.Fatalf("payload is empty, want JSON")
	}

	var decoded map[string]any
	if err := json.Unmarshal(payload, &decoded); err != nil {
		t.Fatalf("payload is not JSON: %v", err)
	}

	if decoded["correlation_id"] != "command-1" {
		t.Errorf("correlation_id = %v, want command-1", decoded["correlation_id"])
	}
}

func TestDecodeRejectsMissingEnvelopeFields(t *testing.T) {
	t.Parallel()

	if _, err := protocol.Decode([]byte(`not json`)); err == nil {
		t.Error("Decode() error = nil, want error")
	}

	if _, err := protocol.Decode([]byte(`[]`)); err == nil {
		t.Error("Decode(array) error = nil, want error")
	}
}

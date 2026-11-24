// Package protocol defines the v1 Redis Stream command/event contract shared
// with Laravel: stream names, the JSON envelope, command/event types, and the
// idempotency key used by the engine consumer.
//
// Redis Stream entries carry the encoded envelope in the field named by
// EnvelopeField. Payloads must never contain credentials, APP_KEY, or raw
// authorization headers.
package protocol

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strconv"
	"time"
)

// Version is the envelope schema version this build produces and consumes.
const Version = 1

// Stream and consumer-group names from design.md section 4.
const (
	StreamCommands    = "engine.commands"
	StreamEvents      = "engine.events"
	StreamCommandsDLQ = "engine.commands.dlq"
	StreamEventsDLQ   = "engine.events.dlq"

	// Backtests use a dedicated command stream so long replays cannot block
	// control commands (stop, kill switch) on the serial command consumer.
	StreamBacktestCommands    = "engine.backtests"
	StreamBacktestCommandsDLQ = "engine.backtests.dlq"

	CommandsGroup         = "engine"
	BacktestCommandsGroup = "engine-backtests"
	EventsGroup           = "laravel"
)

// EnvelopeField is the Redis Stream field that holds the JSON envelope.
// Consumers should treat any other fields as transport metadata.
const EnvelopeField = "envelope"

// IdempotencyPrefix is the Redis key prefix for completed command ids.
const IdempotencyPrefix = "engine:cmd:"

// IdempotencyTTL is how long a completed id is remembered. The design requires
// at least seven days.
const IdempotencyTTL = 7 * 24 * time.Hour

// Command types (v1).
const (
	CommandBotStart      = "bot.start"
	CommandBotPause      = "bot.pause"
	CommandBotStop       = "bot.stop"
	CommandBotConfig     = "bot.config"
	CommandOrderCancel   = "order.cancel"
	CommandKillSwitch    = "kill_switch"
	CommandBacktestRun   = "backtest.run"
	CommandBacktestSweep = "backtest.sweep"
)

// Event types (v1). The design list is non-exhaustive; EventCommandFailed is
// added for contract-level rejections so operators can see a definitive
// failure without leaking a payload or secret.
const (
	EventHeartbeat           = "engine.heartbeat"
	EventBotStarted          = "bot.started"
	EventBotPaused           = "bot.paused"
	EventBotStopped          = "bot.stopped"
	EventBotTickCompleted    = "bot.tick_completed"
	EventBotError            = "bot.error"
	EventKillSwitchCompleted = "kill_switch.completed"
	EventBacktestProgress    = "backtest.progress"
	EventBacktestCompleted   = "backtest.completed"
	EventBacktestFailed      = "backtest.failed"
	EventCommandFailed       = "engine.command_failed"
)

var commandTypes = map[string]struct{}{
	CommandBotStart:      {},
	CommandBotPause:      {},
	CommandBotStop:       {},
	CommandBotConfig:     {},
	CommandOrderCancel:   {},
	CommandKillSwitch:    {},
	CommandBacktestRun:   {},
	CommandBacktestSweep: {},
}

// Envelope is the v1 JSON envelope. Payload is intentionally raw so unknown
// fields in a v1 payload are ignored by consumers and preserved in tests.
type Envelope struct {
	V       int             `json:"v"`
	ID      string          `json:"id"`
	Type    string          `json:"type"`
	TS      string          `json:"ts"`
	UserID  int64           `json:"user_id,omitempty"`
	BotID   int64           `json:"bot_id,omitempty"`
	Payload json.RawMessage `json:"payload,omitempty"`
}

// IdempotencyKey returns the Redis key stored after a command completes or is
// definitively rejected.
func IdempotencyKey(id string) string {
	return IdempotencyPrefix + id
}

// IsCommandType reports whether type is a v1 command type.
func IsCommandType(eventType string) bool {
	_, ok := commandTypes[eventType]

	return ok
}

// Unsupported reports whether the envelope uses a newer schema version.
func (e Envelope) Unsupported() bool {
	return e.V > Version
}

// ValidateBasic checks the fields every envelope must carry. It does not
// require the type to be known, because event types are non-exhaustive.
func (e Envelope) ValidateBasic() error {
	if e.V < 1 {
		return fmt.Errorf("envelope v must be a positive integer, got %d", e.V)
	}

	if e.ID == "" {
		return fmt.Errorf("envelope id is required")
	}

	if e.Type == "" {
		return fmt.Errorf("envelope type is required")
	}

	if e.TS == "" {
		return fmt.Errorf("envelope ts is required")
	}

	if _, err := time.Parse(time.RFC3339, e.TS); err != nil {
		return fmt.Errorf("envelope ts %q is not RFC 3339", e.TS)
	}

	return nil
}

// ValidateCommand checks a v1 command's required routing identifiers. It is a
// definitive validation: a malformed command should be rejected, not retried.
func (e Envelope) ValidateCommand() error {
	if err := e.ValidateBasic(); err != nil {
		return err
	}

	if e.Unsupported() {
		return fmt.Errorf("unsupported envelope version %d", e.V)
	}

	if !IsCommandType(e.Type) {
		return fmt.Errorf("unknown command type %q", e.Type)
	}

	switch e.Type {
	case CommandKillSwitch:
		if e.User() <= 0 {
			return fmt.Errorf("%s requires user_id", e.Type)
		}
	case CommandBacktestRun:
		if id, ok := e.PayloadInt("backtest_run_id"); !ok || id <= 0 {
			return fmt.Errorf("%s requires payload.backtest_run_id", e.Type)
		}
	case CommandBacktestSweep:
		if id, ok := e.PayloadInt("backtest_sweep_id"); !ok || id <= 0 {
			return fmt.Errorf("%s requires payload.backtest_sweep_id", e.Type)
		}
	default:
		if e.Bot() <= 0 {
			return fmt.Errorf("%s requires bot_id", e.Type)
		}

		// Producer contract: bot commands carry user_id so a kill_switch for a
		// user can be serialized against that user's bot commands.
		if e.User() <= 0 {
			return fmt.Errorf("%s requires user_id", e.Type)
		}

		if e.Type == CommandOrderCancel {
			if id, ok := e.PayloadInt("order_id"); !ok || id <= 0 {
				return fmt.Errorf("%s requires payload.order_id", e.Type)
			}
		}
	}

	return nil
}

// User returns the user id from the envelope, falling back to payload.user_id.
func (e Envelope) User() int64 {
	if e.UserID > 0 {
		return e.UserID
	}

	id, _ := e.PayloadInt("user_id")

	return id
}

// Bot returns the bot id from the envelope, falling back to payload.bot_id.
func (e Envelope) Bot() int64 {
	if e.BotID > 0 {
		return e.BotID
	}

	id, _ := e.PayloadInt("bot_id")

	return id
}

// PayloadMap decodes the payload object, rejecting non-object payloads.
func (e Envelope) PayloadMap() (map[string]json.RawMessage, error) {
	if len(e.Payload) == 0 || bytes.Equal(bytes.TrimSpace(e.Payload), []byte("null")) {
		return map[string]json.RawMessage{}, nil
	}

	var payload map[string]json.RawMessage
	if err := json.Unmarshal(e.Payload, &payload); err != nil {
		return nil, fmt.Errorf("payload must be a JSON object: %w", err)
	}

	if payload == nil {
		payload = map[string]json.RawMessage{}
	}

	return payload, nil
}

// PayloadInt reads an integer field from the payload. Integers only; floats are
// rejected so an id cannot silently lose precision.
func (e Envelope) PayloadInt(name string) (int64, bool) {
	payload, err := e.PayloadMap()
	if err != nil {
		return 0, false
	}

	raw, ok := payload[name]
	if !ok {
		return 0, false
	}

	var number json.Number
	if err := json.Unmarshal(raw, &number); err != nil {
		return 0, false
	}

	id, err := strconv.ParseInt(number.String(), 10, 64)
	if err != nil {
		return 0, false
	}

	return id, true
}

// OrderingKey returns the key whose in-stream order must be preserved. Bot
// commands use the user id when present so a kill_switch for that user shares
// the same queue; the bot id remains the fallback.
func (e Envelope) OrderingKey() string {
	if e.Type == CommandKillSwitch {
		if user := e.User(); user > 0 {
			return "user:" + strconv.FormatInt(user, 10)
		}
	}

	if user := e.User(); user > 0 {
		return "user:" + strconv.FormatInt(user, 10)
	}

	if bot := e.Bot(); bot > 0 {
		return "bot:" + strconv.FormatInt(bot, 10)
	}

	if id, ok := e.PayloadInt("backtest_run_id"); ok && id > 0 {
		return "backtest:" + strconv.FormatInt(id, 10)
	}

	if id, ok := e.PayloadInt("backtest_sweep_id"); ok && id > 0 {
		return "sweep:" + strconv.FormatInt(id, 10)
	}

	return "id:" + e.ID
}

// Encode validates the basic envelope shape and marshals it as JSON.
func (e Envelope) Encode() ([]byte, error) {
	if err := e.ValidateBasic(); err != nil {
		return nil, err
	}

	return json.Marshal(e)
}

// Decode parses one JSON envelope. A higher v decodes successfully and is left
// for the consumer to reject without executing.
func Decode(data []byte) (Envelope, error) {
	var envelope Envelope
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.UseNumber()

	if err := decoder.Decode(&envelope); err != nil {
		return Envelope{}, fmt.Errorf("decode envelope: %w", err)
	}

	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return Envelope{}, fmt.Errorf("decode envelope: trailing JSON data")
	}

	if err := envelope.ValidateBasic(); err != nil {
		return Envelope{}, err
	}

	return envelope, nil
}

// NewEvent builds a v1 event envelope with a fresh id and UTC timestamp.
func NewEvent(eventType string, payload map[string]any, userID, botID int64) (Envelope, error) {
	encoded := json.RawMessage("{}")

	if payload != nil {
		data, err := json.Marshal(payload)
		if err != nil {
			return Envelope{}, fmt.Errorf("encode event payload: %w", err)
		}

		encoded = data
	}

	return Envelope{
		V:       Version,
		ID:      NewID(),
		Type:    eventType,
		TS:      time.Now().UTC().Format(time.RFC3339),
		UserID:  userID,
		BotID:   botID,
		Payload: encoded,
	}, nil
}

// NewID returns a random RFC 4122 version 4 UUID in canonical form.
func NewID() string {
	var raw [16]byte
	if _, err := rand.Read(raw[:]); err != nil {
		// crypto/rand failure is unrecoverable for id generation. Panic rather
		// than silently returning a duplicate id.
		panic(fmt.Sprintf("generate id: %v", err))
	}

	raw[6] = (raw[6] & 0x0f) | 0x40
	raw[8] = (raw[8] & 0x3f) | 0x80

	encoded := make([]byte, 32)
	hex.Encode(encoded, raw[:])

	return fmt.Sprintf("%s-%s-%s-%s-%s",
		encoded[0:8], encoded[8:12], encoded[12:16], encoded[16:20], encoded[20:32],
	)
}

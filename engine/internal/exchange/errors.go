package exchange

import (
	"errors"
	"fmt"
	"time"
)

// ErrorType mirrors App\Domain\Exchange\Enums\ExchangeErrorType.
type ErrorType string

const (
	ErrorConnection        ErrorType = "connection"
	ErrorAuthentication    ErrorType = "authentication"
	ErrorRateLimit         ErrorType = "rate_limit"
	ErrorRejected          ErrorType = "rejected"
	ErrorInsufficientFunds ErrorType = "insufficient_balance"
	ErrorNotFound          ErrorType = "not_found"
	ErrorUnsupported       ErrorType = "unsupported"
	ErrorUnknown           ErrorType = "unknown"
)

// Error is a normalized, secret-free exchange failure.
type Error struct {
	Type       ErrorType
	Exchange   string
	Operation  string
	Message    string
	RetryAfter time.Duration
}

func (e *Error) Error() string {
	return fmt.Sprintf("%s %s failed (%s): %s", e.Exchange, e.Operation, e.Type, e.Message)
}

// Retryable reports whether an operation may be retried. Place must still be
// preceded by a client-order-id lookup even when this is true.
func (e *Error) Retryable() bool {
	return e.Type == ErrorConnection || e.Type == ErrorRateLimit
}

// IsRetryable reports whether err is a retryable exchange error.
func IsRetryable(err error) bool {
	var exchangeError *Error

	return errors.As(err, &exchangeError) && exchangeError.Retryable()
}

// LiveDisabledError is returned by a guarded client without any network call.
var ErrLiveDisabled = errors.New("live trading is disabled by configuration")

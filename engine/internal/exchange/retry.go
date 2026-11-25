package exchange

import (
	"context"
	"errors"
	"time"
)

// Backoff retries only normalized connection and rate-limit failures. It
// honors Retry-After and always waits before a retry, so a rate-limited caller
// never hammers the venue. PlaceLimit is not retried by the adapters: the live
// runtime looks up the client order ID before deciding to retry.
type Backoff struct {
	Base     time.Duration
	Max      time.Duration
	Attempts int
	Sleep    func(context.Context, time.Duration) error
}

// DefaultBackoff is three attempts starting at 250ms and capped at 5s.
func DefaultBackoff() Backoff {
	return Backoff{
		Base:     250 * time.Millisecond,
		Max:      5 * time.Second,
		Attempts: 3,
		Sleep:    sleepContext,
	}
}

// Do runs operation until it succeeds, the context ends, or a non-retryable
// error occurs. It returns the last error.
func (b Backoff) Do(ctx context.Context, operation func() error) error {
	attempts := b.Attempts
	if attempts < 1 {
		attempts = 1
	}

	base := b.Base
	if base <= 0 {
		base = 250 * time.Millisecond
	}

	sleep := b.Sleep
	if sleep == nil {
		sleep = sleepContext
	}

	delay := base
	var last error

	for attempt := 0; attempt < attempts; attempt++ {
		if err := ctx.Err(); err != nil {
			return err
		}

		last = operation()
		if last == nil {
			return nil
		}

		var exchangeError *Error
		if !errors.As(last, &exchangeError) || !exchangeError.Retryable() {
			return last
		}

		if attempt == attempts-1 {
			break
		}

		wait := delay
		if exchangeError.RetryAfter > wait {
			wait = exchangeError.RetryAfter
		}
		if b.Max > 0 && wait > b.Max {
			wait = b.Max
		}

		if err := sleep(ctx, wait); err != nil {
			return err
		}

		delay *= 2
		if b.Max > 0 && delay > b.Max {
			delay = b.Max
		}
	}

	return last
}

func sleepContext(ctx context.Context, duration time.Duration) error {
	timer := time.NewTimer(duration)
	defer timer.Stop()

	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-timer.C:
		return nil
	}
}

// GuardedClient refuses order placement when the live gate is closed. Reading
// orders and canceling stay available so a closed gate can reconcile fills and
// remove resting orders; it must never be the reason a real order is left
// working. Nothing here can fall through to paper.
type GuardedClient struct {
	Enabled bool
	Client  Client
}

func (g GuardedClient) Exchange() string {
	if g.Client == nil {
		return ""
	}

	return g.Client.Exchange()
}

func (g GuardedClient) PlaceLimit(ctx context.Context, request PlaceRequest) (Order, error) {
	if !g.Enabled {
		return Order{}, ErrLiveDisabled
	}

	return g.Client.PlaceLimit(ctx, request)
}

func (g GuardedClient) Cancel(ctx context.Context, symbol, exchangeOrderID, clientOrderID string) (Order, error) {
	// Cancellation stays available when the gate closes: pausing a live bot
	// must be able to remove its resting orders. It never places anything.
	if g.Client == nil {
		return Order{}, ErrLiveDisabled
	}

	return g.Client.Cancel(ctx, symbol, exchangeOrderID, clientOrderID)
}

func (g GuardedClient) Order(ctx context.Context, symbol, exchangeOrderID string) (Order, error) {
	if g.Client == nil {
		return Order{}, ErrLiveDisabled
	}

	return g.Client.Order(ctx, symbol, exchangeOrderID)
}

func (g GuardedClient) OrderByClientID(ctx context.Context, symbol, clientOrderID string) (Order, error) {
	if g.Client == nil {
		return Order{}, ErrLiveDisabled
	}

	return g.Client.OrderByClientID(ctx, symbol, clientOrderID)
}

func (g GuardedClient) OpenOrders(ctx context.Context, symbol string) ([]Order, error) {
	if g.Client == nil {
		return nil, ErrLiveDisabled
	}

	return g.Client.OpenOrders(ctx, symbol)
}

func (g GuardedClient) Rules(ctx context.Context, symbol string) (Rules, error) {
	if g.Client == nil {
		return Rules{}, ErrLiveDisabled
	}

	return g.Client.Rules(ctx, symbol)
}

func (g GuardedClient) Balances(ctx context.Context) ([]Balance, error) {
	if g.Client == nil {
		return nil, ErrLiveDisabled
	}

	return g.Client.Balances(ctx)
}

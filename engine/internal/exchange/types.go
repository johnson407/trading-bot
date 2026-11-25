// Package exchange implements the engine's private exchange client. It
// normalizes order, balance, rule, and error shapes so the runtime does not
// depend on a venue SDK. Credentials are held in memory only and are never
// logged or serialized.
package exchange

import (
	"context"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// Side is a normalized order side.
type Side string

const (
	SideBuy  Side = "buy"
	SideSell Side = "sell"
)

// Status is a normalized order status.
type Status string

const (
	StatusOpen            Status = "open"
	StatusPartiallyFilled Status = "partially_filled"
	StatusFilled          Status = "filled"
	StatusCanceled        Status = "canceled"
	StatusRejected        Status = "rejected"
)

// Credential is a decrypted exchange credential held only in memory.
type Credential struct {
	APIKey     string
	APISecret  string
	Passphrase string
	Testnet    bool
}

// Order is a normalized exchange order.
type Order struct {
	ExchangeOrderID string
	ClientOrderID   string
	Status          Status
	Side            Side
	Price           decimal.Decimal
	Quantity        decimal.Decimal
	FilledQuantity  decimal.Decimal
	AveragePrice    decimal.Decimal
	Fee             *decimal.Decimal
	FeeCurrency     string
	UpdatedAt       time.Time
}

// Balance is a normalized asset balance.
type Balance struct {
	Asset string
	Free  decimal.Decimal
	Used  decimal.Decimal
	Total decimal.Decimal
}

// Rules are the venue constraints a live order must satisfy.
type Rules struct {
	Symbol          string
	MinAmount       decimal.Decimal
	MinNotional     decimal.Decimal
	PriceStep       decimal.Decimal
	AmountStep      decimal.Decimal
	PricePrecision  int
	AmountPrecision int
}

// PlaceRequest is one live limit order. ClientOrderID must already be durable
// before a caller invokes PlaceLimit.
type PlaceRequest struct {
	Symbol        string
	Side          Side
	Price         decimal.Decimal
	Quantity      decimal.Decimal
	ClientOrderID string
}

// Client is the private exchange surface used by the live runtime. It is the
// Go equivalent of App\Domain\Exchange\Contracts\ExchangeClient, plus a
// client-order-id lookup for uncertain retries.
type Client interface {
	Exchange() string
	PlaceLimit(ctx context.Context, request PlaceRequest) (Order, error)
	Cancel(ctx context.Context, symbol, exchangeOrderID, clientOrderID string) (Order, error)
	Order(ctx context.Context, symbol, exchangeOrderID string) (Order, error)
	OrderByClientID(ctx context.Context, symbol, clientOrderID string) (Order, error)
	OpenOrders(ctx context.Context, symbol string) ([]Order, error)
	Rules(ctx context.Context, symbol string) (Rules, error)
	Balances(ctx context.Context) ([]Balance, error)
}

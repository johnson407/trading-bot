package exchange

import (
	"fmt"
	"net/http"
	"strings"
	"time"
)

// Factory builds a private client for a venue. It never reads credentials
// itself; the caller supplies an already-decrypted Credential.
type Factory struct {
	// BaseURL is the existing proxy/custom/NAS relay transport for production
	// requests. It is honored as transport only.
	BaseURL string
	// Sandbox forces testnet mode for venues that support it.
	Sandbox bool
	// AllowUnverifiedMAX must be set explicitly to build a MAX live client.
	// MAX is fake-proven only in this change; leaving this false prevents an
	// accidental MAX real-money connection.
	AllowUnverifiedMAX bool
	HTTPClient         *http.Client
	Now                func() time.Time
}

// Client builds a client for the venue. MAX has no testnet proof in this
// change, so sandbox mode rejects it instead of guessing an endpoint.
func (f Factory) Client(venue string, credential Credential) (Client, error) {
	switch strings.ToLower(strings.TrimSpace(venue)) {
	case "binance":
		return NewBinance(credential, BinanceOptions{
			Testnet:    f.Sandbox,
			BaseURL:    f.BaseURL,
			HTTPClient: f.HTTPClient,
			Now:        f.Now,
		})
	case "max":
		if f.Sandbox || credential.Testnet {
			return nil, fmt.Errorf("MAX has no testnet endpoint in this build; refusing sandbox or testnet credentials")
		}
		if !f.AllowUnverifiedMAX {
			return nil, fmt.Errorf("MAX live is not enabled in this build; the adapter is fake-tested only")
		}

		return NewMAX(credential, MAXOptions{
			BaseURL:    f.BaseURL,
			HTTPClient: f.HTTPClient,
			Now:        f.Now,
		}), nil
	default:
		return nil, fmt.Errorf("exchange %s is not supported by the live client", venue)
	}
}

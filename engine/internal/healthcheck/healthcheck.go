// Package healthcheck implements the in-container probe used by the Compose
// healthcheck. It speaks plain HTTP to the engine's own /healthz endpoint; it
// never calls an exchange.
package healthcheck

import (
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
)

// Path is the readiness endpoint probed by the container healthcheck.
const Path = "/healthz"

// URL maps a listen address such as ":8080" or "0.0.0.0:8080" to a loopback
// URL that works inside the container.
func URL(addr string) (string, error) {
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return "", fmt.Errorf("invalid engine listen address %q: %w", addr, err)
	}

	switch host {
	case "", "0.0.0.0", "::":
		host = "127.0.0.1"
	}

	return "http://" + net.JoinHostPort(host, port) + Path, nil
}

// Run GETs url and accepts only a 200 response.
func Run(ctx context.Context, url string, client *http.Client) error {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return fmt.Errorf("build health request: %w", err)
	}

	response, err := client.Do(request)
	if err != nil {
		return fmt.Errorf("health request failed: %w", err)
	}
	defer func() { _ = response.Body.Close() }()

	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 4096))

	if response.StatusCode != http.StatusOK {
		return fmt.Errorf("health endpoint returned %s", response.Status)
	}

	return nil
}

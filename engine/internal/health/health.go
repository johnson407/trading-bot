// Package health serves the engine's liveness and readiness endpoints. Checks
// only ping local infrastructure (PostgreSQL and Redis); nothing here calls an
// exchange or reads credentials.
package health

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"sync"
	"time"
)

// DefaultCheckTimeout bounds each readiness dependency check.
const DefaultCheckTimeout = 3 * time.Second

// Check is one readiness dependency. Ping must return quickly and must not
// perform trading work.
type Check struct {
	Name string
	Ping func(context.Context) error
}

// Handler serves GET /healthz (readiness) and GET /livez (liveness).
type Handler struct {
	checks  []Check
	timeout time.Duration
	logger  *slog.Logger
}

// Option customises a Handler, primarily for tests.
type Option func(*Handler)

// WithCheckTimeout overrides the per-check timeout.
func WithCheckTimeout(timeout time.Duration) Option {
	return func(h *Handler) {
		h.timeout = timeout
	}
}

// NewHandler builds a readiness/liveness handler.
func NewHandler(checks []Check, logger *slog.Logger, options ...Option) *Handler {
	if logger == nil {
		logger = slog.Default()
	}

	handler := &Handler{
		checks:  checks,
		timeout: DefaultCheckTimeout,
		logger:  logger,
	}

	for _, option := range options {
		option(handler)
	}

	return handler
}

// NewRouter returns the HTTP routes exposed by the engine. Go's method-aware
// patterns answer non-GET requests with 405 automatically.
func NewRouter(checks []Check, logger *slog.Logger, options ...Option) http.Handler {
	handler := NewHandler(checks, logger, options...)
	mux := http.NewServeMux()
	mux.Handle("GET /healthz", handler)
	mux.HandleFunc("GET /livez", Live)

	return mux
}

type checkResult struct {
	Name   string `json:"name"`
	Status string `json:"status"`
}

type readinessResponse struct {
	Status string        `json:"status"`
	Checks []checkResult `json:"checks"`
}

// ServeHTTP reports readiness. A failing dependency returns 503; only the
// dependency name and a coarse status are exposed, never the underlying error.
func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	results := make([]checkResult, len(h.checks))
	var waitGroup sync.WaitGroup

	for index, check := range h.checks {
		waitGroup.Add(1)

		go func(index int, check Check) {
			defer waitGroup.Done()

			ctx, cancel := context.WithTimeout(r.Context(), h.timeout)
			defer cancel()

			status := "ok"
			if err := check.Ping(ctx); err != nil {
				status = "unavailable"
				// Log only the check name and error type so a malformed DSN or
				// driver message cannot leak a password into stdout.
				h.logger.Warn("readiness check failed",
					"check", check.Name,
					"error_type", errorType(err),
				)
			}

			results[index] = checkResult{Name: check.Name, Status: status}
		}(index, check)
	}

	waitGroup.Wait()

	statusCode := http.StatusOK
	overall := "ok"

	for _, result := range results {
		if result.Status != "ok" {
			statusCode = http.StatusServiceUnavailable
			overall = "unavailable"

			break
		}
	}

	writeJSON(w, statusCode, readinessResponse{Status: overall, Checks: results})
}

// Live reports process liveness. It never touches a dependency so an unhealthy
// database cannot restart a running engine.
func Live(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, struct {
		Status string `json:"status"`
	}{Status: "ok"})
}

func writeJSON(w http.ResponseWriter, statusCode int, payload any) {
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(statusCode)

	if err := json.NewEncoder(w).Encode(payload); err != nil {
		slog.Default().Warn("failed to write health response", "error_type", errorType(err))
	}
}

// errorType reports the Go type of a dependency error without echoing its
// message, which could contain a connection string.
func errorType(err error) string {
	if err == nil {
		return ""
	}

	return fmt.Sprintf("%T", err)
}

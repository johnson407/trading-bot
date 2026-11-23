package health_test

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/health"
)

type readinessBody struct {
	Status string `json:"status"`
	Checks []struct {
		Name   string `json:"name"`
		Status string `json:"status"`
	} `json:"checks"`
}

func discardLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

func check(name string, err error) health.Check {
	return health.Check{
		Name: name,
		Ping: func(context.Context) error {
			return err
		},
	}
}

func router(checks ...health.Check) http.Handler {
	return health.NewRouter(checks, discardLogger())
}

func get(t *testing.T, handler http.Handler, path string) *httptest.ResponseRecorder {
	t.Helper()

	request := httptest.NewRequest(http.MethodGet, path, nil)
	recorder := httptest.NewRecorder()
	handler.ServeHTTP(recorder, request)

	return recorder
}

func TestReadinessOKWithHealthyDependencies(t *testing.T) {
	t.Parallel()

	recorder := get(t, router(check("postgres", nil), check("redis", nil)), "/healthz")
	if recorder.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", recorder.Code)
	}

	var body readinessBody
	if err := json.Unmarshal(recorder.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode body: %v", err)
	}

	if body.Status != "ok" {
		t.Errorf("status = %q, want ok", body.Status)
	}

	if len(body.Checks) != 2 || body.Checks[0].Name != "postgres" || body.Checks[1].Name != "redis" {
		t.Errorf("checks = %+v, want postgres then redis", body.Checks)
	}

	for _, result := range body.Checks {
		if result.Status != "ok" {
			t.Errorf("check %q status = %q, want ok", result.Name, result.Status)
		}
	}
}

func TestReadinessUnavailableWhenPostgresIsDown(t *testing.T) {
	t.Parallel()

	recorder := get(t,
		router(check("postgres", errors.New("dial tcp: connection refused")), check("redis", nil)),
		"/healthz",
	)

	if recorder.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want 503", recorder.Code)
	}

	var body readinessBody
	if err := json.Unmarshal(recorder.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode body: %v", err)
	}

	if body.Status != "unavailable" {
		t.Errorf("status = %q, want unavailable", body.Status)
	}

	if body.Checks[0].Name != "postgres" || body.Checks[0].Status != "unavailable" {
		t.Errorf("postgres result = %+v, want unavailable", body.Checks[0])
	}
}

func TestReadinessUnavailableWhenRedisIsDown(t *testing.T) {
	t.Parallel()

	recorder := get(t,
		router(check("postgres", nil), check("redis", errors.New("i/o timeout"))),
		"/healthz",
	)

	if recorder.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want 503", recorder.Code)
	}
}

func TestReadinessDoesNotEchoDependencyErrors(t *testing.T) {
	t.Parallel()

	const secret = "postgres://trading_bot:supersecret@postgres:5432/trading_bot"
	recorder := get(t, router(check("postgres", errors.New(secret))), "/healthz")

	if strings.Contains(recorder.Body.String(), "supersecret") {
		t.Fatalf("response body leaked dependency error: %s", recorder.Body.String())
	}
}

func TestReadinessTimesOutSlowDependency(t *testing.T) {
	t.Parallel()

	slow := health.Check{
		Name: "postgres",
		Ping: func(ctx context.Context) error {
			select {
			case <-time.After(2 * time.Second):
				return nil
			case <-ctx.Done():
				return ctx.Err()
			}
		},
	}

	handler := health.NewRouter(
		[]health.Check{slow},
		discardLogger(),
		health.WithCheckTimeout(20*time.Millisecond),
	)

	recorder := get(t, handler, "/healthz")
	if recorder.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want 503", recorder.Code)
	}
}

func TestLivenessDoesNotCheckDependencies(t *testing.T) {
	t.Parallel()

	recorder := get(t,
		router(check("postgres", errors.New("down")), check("redis", errors.New("down"))),
		"/livez",
	)

	if recorder.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", recorder.Code)
	}

	if !strings.Contains(recorder.Body.String(), `"ok"`) {
		t.Errorf("body = %s, want ok status", recorder.Body.String())
	}
}

func TestReadinessRejectsNonGet(t *testing.T) {
	t.Parallel()

	request := httptest.NewRequest(http.MethodPost, "/healthz", nil)
	recorder := httptest.NewRecorder()
	router().ServeHTTP(recorder, request)

	if recorder.Code != http.StatusMethodNotAllowed {
		t.Fatalf("status = %d, want 405", recorder.Code)
	}
}

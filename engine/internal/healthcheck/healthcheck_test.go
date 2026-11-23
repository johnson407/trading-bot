package healthcheck_test

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/healthcheck"
)

func TestURLUsesLoopbackForWildcardAddresses(t *testing.T) {
	t.Parallel()

	cases := map[string]string{
		":8080":          "http://127.0.0.1:8080/healthz",
		"0.0.0.0:8080":   "http://127.0.0.1:8080/healthz",
		"127.0.0.1:9000": "http://127.0.0.1:9000/healthz",
		"localhost:8000": "http://localhost:8000/healthz",
		"[::1]:8081":     "http://[::1]:8081/healthz",
	}

	for addr, want := range cases {
		got, err := healthcheck.URL(addr)
		if err != nil {
			t.Fatalf("URL(%q) error = %v", addr, err)
		}

		if got != want {
			t.Errorf("URL(%q) = %q, want %q", addr, got, want)
		}
	}
}

func TestURLRejectsAddressWithoutPort(t *testing.T) {
	t.Parallel()

	if _, err := healthcheck.URL("8080"); err == nil {
		t.Error("URL(\"8080\") error = nil, want error")
	}
}

func TestRunAcceptsHealthyEndpoint(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	if err := healthcheck.Run(ctx, server.URL+"/healthz", server.Client()); err != nil {
		t.Fatalf("Run() error = %v", err)
	}
}

func TestRunRejectsUnreadyEndpoint(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer server.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	err := healthcheck.Run(ctx, server.URL+"/healthz", server.Client())
	if err == nil {
		t.Fatal("Run() error = nil, want error")
	}

	if !strings.Contains(err.Error(), "503") {
		t.Errorf("error = %q, want it to mention 503", err)
	}
}

func TestRunRejectsUnreachableEndpoint(t *testing.T) {
	t.Parallel()

	ctx, cancel := context.WithTimeout(context.Background(), 500*time.Millisecond)
	defer cancel()

	err := healthcheck.Run(ctx, "http://127.0.0.1:1/healthz", &http.Client{})
	if err == nil {
		t.Fatal("Run() error = nil, want error")
	}
}

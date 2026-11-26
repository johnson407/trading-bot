package config_test

import (
	"strings"
	"testing"
)

func TestLoadRequiresInternalTokenForProductionListenerBeyondLoopback(t *testing.T) {
	cases := []struct {
		name    string
		env     map[string]string
		wantErr bool
	}{
		{
			name:    "production wildcard listener without token",
			env:     map[string]string{"APP_ENV": "production", "ENGINE_LISTEN_ADDR": ":8080"},
			wantErr: true,
		},
		{
			name:    "production all interfaces without token",
			env:     map[string]string{"APP_ENV": "production", "ENGINE_LISTEN_ADDR": "0.0.0.0:8080"},
			wantErr: true,
		},
		{
			name:    "production ipv6 all interfaces without token",
			env:     map[string]string{"APP_ENV": "production", "ENGINE_LISTEN_ADDR": "[::]:8080"},
			wantErr: true,
		},
		{
			name: "production loopback without token",
			env:  map[string]string{"APP_ENV": "production", "ENGINE_LISTEN_ADDR": "127.0.0.1:8080"},
		},
		{
			name: "production ipv6 loopback without token",
			env:  map[string]string{"APP_ENV": "production", "ENGINE_LISTEN_ADDR": "[::1]:8080"},
		},
		{
			name: "production listener with token",
			env: map[string]string{
				"APP_ENV":               "production",
				"ENGINE_LISTEN_ADDR":    ":8080",
				"ENGINE_INTERNAL_TOKEN": "secret-token",
			},
		},
		{
			name: "local wildcard listener without token",
			env:  map[string]string{"ENGINE_LISTEN_ADDR": ":8080"},
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := load(tc.env)
			if tc.wantErr {
				if err == nil {
					t.Fatal("expected an error")
				}
				if !strings.Contains(err.Error(), "ENGINE_INTERNAL_TOKEN") {
					t.Fatalf("error %q does not name ENGINE_INTERNAL_TOKEN", err)
				}

				return
			}
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
		})
	}
}

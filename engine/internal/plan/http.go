package plan

import (
	"crypto/subtle"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"strings"
)

const maxBodyBytes = 1 << 20

// Handler serves POST /v1/plan/evaluate for Laravel previews and validation.
// When token is non-empty the Authorization header must be
// "Bearer <token>". The engine Compose service publishes no port; the token is
// for deployments that expose the HTTP port beyond the internal network.
type Handler struct {
	token  string
	logger *slog.Logger
}

// NewHandler builds the plan HTTP handler.
func NewHandler(token string, logger *slog.Logger) *Handler {
	if logger == nil {
		logger = slog.Default()
	}

	return &Handler{token: strings.TrimSpace(token), logger: logger}
}

func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	defer func() {
		if recovered := recover(); recovered != nil {
			h.logger.Error("plan evaluation panicked", "panic_type", fmt.Sprintf("%T", recovered))
			h.json(w, http.StatusInternalServerError, map[string]string{"error": "evaluation_failed"})
		}
	}()

	if r.Method != http.MethodPost {
		w.Header().Set("Allow", http.MethodPost)
		h.json(w, http.StatusMethodNotAllowed, map[string]string{"error": "method_not_allowed"})

		return
	}

	if h.token != "" && !h.authorized(r) {
		h.json(w, http.StatusUnauthorized, map[string]string{"error": "unauthorized"})

		return
	}

	body := http.MaxBytesReader(w, r.Body, maxBodyBytes)
	defer func() { _ = body.Close() }()

	decoder := json.NewDecoder(body)
	decoder.UseNumber()

	var request Request
	if err := decoder.Decode(&request); err != nil {
		h.logger.Warn("plan request is not valid JSON", "error_type", fmt.Sprintf("%T", err))
		h.json(w, http.StatusBadRequest, map[string]string{"error": "invalid_request"})

		return
	}

	if strings.TrimSpace(request.StrategyKey) == "" {
		h.json(w, http.StatusBadRequest, map[string]string{"error": "missing_strategy_key"})

		return
	}

	if request.Configuration == nil {
		request.Configuration = map[string]any{}
	}

	h.json(w, http.StatusOK, Evaluate(request))
}

func (h *Handler) authorized(r *http.Request) bool {
	provided := strings.TrimSpace(strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer "))

	return subtle.ConstantTimeCompare([]byte(provided), []byte(h.token)) == 1
}

func (h *Handler) json(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(payload)
}

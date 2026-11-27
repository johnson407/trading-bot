package runtime

import (
	"context"
	"fmt"
	"log/slog"
	"time"

	"github.com/jackc/pgx/v5"
)

const pauseProvenanceProbeInterval = 500 * time.Millisecond

type schemaQuerier interface {
	QueryRow(ctx context.Context, sql string, args ...any) pgx.Row
}

// WaitForPauseProvenance blocks until both pause-provenance columns exist.
// Callers bound the wait; the engine uses this before starting any worker so a
// missing migration fails closed instead of surfacing later as a pause write
// error.
func WaitForPauseProvenance(ctx context.Context, db schemaQuerier, logger *slog.Logger) error {
	if logger == nil {
		logger = slog.Default()
	}

	waiting := false
	for {
		ready, err := pauseProvenanceReady(ctx, db)
		if err == nil && ready {
			if waiting {
				logger.Info("pause provenance schema is ready")
			}

			return nil
		}

		if !waiting {
			logger.Info("waiting for pause provenance schema", "columns", "pause_reason,pause_reason_recorded_at")
			waiting = true
		}

		select {
		case <-ctx.Done():
			if err != nil {
				return fmt.Errorf("wait for pause provenance schema: %w", err)
			}

			return fmt.Errorf("pause provenance columns are not ready; apply the pending database migration: %w", ctx.Err())
		case <-time.After(pauseProvenanceProbeInterval):
		}
	}
}

func pauseProvenanceReady(ctx context.Context, db schemaQuerier) (bool, error) {
	var reason, recorded bool
	err := db.QueryRow(ctx,
		`SELECT
		    EXISTS (
		        SELECT 1 FROM information_schema.columns
		         WHERE table_schema = current_schema()
		           AND table_name = 'bots'
		           AND column_name = 'pause_reason'
		    ),
		    EXISTS (
		        SELECT 1 FROM information_schema.columns
		         WHERE table_schema = current_schema()
		           AND table_name = 'bots'
		           AND column_name = 'pause_reason_recorded_at'
		    )`,
	).Scan(&reason, &recorded)

	return reason && recorded, err
}

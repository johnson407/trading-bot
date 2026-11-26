package runtime

import (
	"bytes"
	"errors"
	"log/slog"
	"strconv"
	"strings"
	"testing"
	"time"
)

func tickStatsTestRuntime(logs *bytes.Buffer, now time.Time) *Runtime {
	logger := slog.New(slog.NewTextHandler(logs, nil))

	return New(nil, nil, logger, WithNow(func() time.Time { return now }))
}

func tickCompletionLines(logs string) []string {
	var lines []string
	for _, line := range strings.Split(logs, "\n") {
		if strings.Contains(line, "bot tick completed") {
			lines = append(lines, line)
		}
	}

	return lines
}

func TestTickStatsFinishLogsExactlyOneCompletionRecord(t *testing.T) {
	start := time.Date(2026, 11, 26, 12, 0, 0, 0, time.UTC)
	cases := []struct {
		name      string
		prepare   func(*tickStats)
		finish    func(*tickStats, *Runtime)
		want      string
		wantPanic bool
	}{
		{
			name: "completed",
			finish: func(stats *tickStats, rt *Runtime) {
				stats.finish(rt, nil, nil)
			},
			want: "outcome=completed",
		},
		{
			name: "failed",
			finish: func(stats *tickStats, rt *Runtime) {
				stats.finish(rt, nil, errors.New("boom"))
			},
			want: "outcome=failed",
		},
		{
			name: "paused",
			prepare: func(stats *tickStats) {
				stats.markPaused()
			},
			finish: func(stats *tickStats, rt *Runtime) {
				stats.finish(rt, nil, nil)
			},
			want: "outcome=paused",
		},
		{
			name: "panicked",
			finish: func(stats *tickStats, rt *Runtime) {
				defer func() {
					if recovered := recover(); recovered == nil {
						t.Fatal("finish did not re-panic")
					}
				}()
				stats.finish(rt, "boom", nil)
			},
			want:      "outcome=panicked",
			wantPanic: true,
		},
	}

	for _, test := range cases {
		t.Run(test.name, func(t *testing.T) {
			var logs bytes.Buffer
			rt := tickStatsTestRuntime(&logs, start.Add(1500*time.Millisecond))
			stats := newTickStats(42, start)
			if test.prepare != nil {
				test.prepare(stats)
			}

			test.finish(stats, rt)

			lines := tickCompletionLines(logs.String())
			if len(lines) != 1 {
				t.Fatalf("want exactly one completion record, got %d:\n%s", len(lines), logs.String())
			}
			line := lines[0]
			for _, want := range []string{
				"bot_id=42",
				"correlation_id=tick-42-" + strconv.FormatInt(start.UnixNano(), 10),
				"duration_ms=1500",
				test.want,
			} {
				if !strings.Contains(line, want) {
					t.Errorf("completion record missing %q:\n%s", want, line)
				}
			}
			if test.want == "outcome=failed" && !strings.Contains(line, "failure_type=") {
				t.Errorf("failed completion record missing failure_type:\n%s", line)
			}
			for _, secret := range []string{"APP_KEY", "api_key", "api_secret", "signature"} {
				if strings.Contains(line, secret) {
					t.Errorf("completion record contains secret marker %q:\n%s", secret, line)
				}
			}
		})
	}
}

func TestTickStatsLogsCounters(t *testing.T) {
	start := time.Date(2026, 11, 26, 12, 0, 0, 0, time.UTC)
	var logs bytes.Buffer
	rt := tickStatsTestRuntime(&logs, start.Add(time.Second))
	stats := newTickStats(7, start)
	stats.ordersPlaced = 3
	stats.ordersCanceled = 2
	stats.paperFills = 4
	stats.liveFills = 5
	stats.addGridCreated(6)
	stats.addGridMatched(7)
	stats.addGridReplaced(8)

	stats.finish(rt, nil, nil)

	lines := tickCompletionLines(logs.String())
	if len(lines) != 1 {
		t.Fatalf("want exactly one completion record, got %d:\n%s", len(lines), logs.String())
	}
	line := lines[0]
	for _, want := range []string{
		"orders_placed=3",
		"orders_canceled=2",
		"paper_fills=4",
		"live_fills=5",
		"grids_created=1",
		"grids_matched=1",
		"grids_replaced=1",
		"levels_created=6",
		"levels_matched=7",
		"levels_replaced=8",
	} {
		if !strings.Contains(line, want) {
			t.Errorf("completion record missing %q:\n%s", want, line)
		}
	}
}

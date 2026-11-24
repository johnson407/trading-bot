package backtest_test

import (
	"bytes"
	"encoding/json"
	"reflect"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/backtest"
	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/goldenfixture"
)

type replayFixture struct {
	Metadata replayMetadata    `json:"metadata"`
	Cases    []replayCase      `json:"cases"`
	Errors   map[string]string `json:"errors"`
}

type replayMetadata struct {
	Format       string `json:"format"`
	Clock        string `json:"clock"`
	DecimalScale int    `json:"decimal_scale"`
	FeeRate      string `json:"fee_rate"`
}

type replayCase struct {
	Label    string            `json:"label"`
	Snapshot json.RawMessage   `json:"snapshot"`
	Candles  []backtest.Candle `json:"candles"`
	Expected json.RawMessage   `json:"expected"`
}

type sweepFixture struct {
	Metadata struct {
		Format string `json:"format"`
	} `json:"metadata"`
	Cases []sweepCase `json:"cases"`
}

type sweepCase struct {
	Label      string                   `json:"label"`
	Parameters backtest.SweepParameters `json:"parameters"`
	Name       string                   `json:"name"`
	TotalRuns  int                      `json:"total_runs"`
	Runs       []sweepRun               `json:"runs"`
}

type sweepRun struct {
	Label                 string            `json:"label"`
	StrategyConfiguration map[string]any    `json:"strategy_configuration"`
	Snapshot              backtest.Snapshot `json:"snapshot"`
}

func TestGoldenReplay(t *testing.T) {
	var fixture replayFixture
	if err := goldenfixture.Load("backtest_replay", &fixture); err != nil {
		t.Fatalf("load fixture: %v", err)
	}

	if fixture.Metadata.Format != "php-golden-backtest/1" {
		t.Fatalf("unexpected fixture format %q", fixture.Metadata.Format)
	}

	engine := backtest.NewEngine()
	for _, testCase := range fixture.Cases {
		t.Run(testCase.Label, func(t *testing.T) {
			configuration, _, err := backtest.DecodeSnapshot(testCase.Snapshot)
			if err != nil {
				t.Fatalf("decode snapshot: %v", err)
			}

			result, err := engine.Run(configuration, testCase.Candles, nil)
			if err != nil {
				t.Fatalf("run replay: %v", err)
			}

			result.Metrics.DurationMs = 0
			actual := normalize(t, result.ToArray())
			expected := decodeRaw(t, testCase.Expected)

			if !reflect.DeepEqual(actual, expected) {
				actualJSON, _ := json.MarshalIndent(actual, "", "  ")
				expectedJSON, _ := json.MarshalIndent(expected, "", "  ")
				t.Fatalf("replay mismatch\nactual:\n%s\nexpected:\n%s", actualJSON, expectedJSON)
			}
		})
	}

	t.Run("no_candles", func(t *testing.T) {
		configuration, _, err := backtest.DecodeSnapshot(fixture.Cases[0].Snapshot)
		if err != nil {
			t.Fatalf("decode snapshot: %v", err)
		}

		_, err = engine.Run(configuration, nil, nil)
		if err == nil || err.Error() != fixture.Errors["no_candles"] {
			t.Fatalf("expected %q, got %v", fixture.Errors["no_candles"], err)
		}
	})

	t.Run("max_fills", func(t *testing.T) {
		caseIndex := caseIndex(fixture.Cases, "same_candle_sequential_fills")
		configuration, _, err := backtest.DecodeSnapshot(fixture.Cases[caseIndex].Snapshot)
		if err != nil {
			t.Fatalf("decode snapshot: %v", err)
		}

		_, err = backtest.NewEngine(backtest.WithMaxFills(1)).Run(
			configuration,
			fixture.Cases[caseIndex].Candles,
			nil,
		)
		if err == nil || err.Error() != fixture.Errors["max_fills"] {
			t.Fatalf("expected %q, got %v", fixture.Errors["max_fills"], err)
		}
	})
}

func TestGoldenSweep(t *testing.T) {
	var fixture sweepFixture
	if err := goldenfixture.Load("backtest_sweep", &fixture); err != nil {
		t.Fatalf("load fixture: %v", err)
	}

	if fixture.Metadata.Format != "php-golden-backtest-sweep/1" {
		t.Fatalf("unexpected fixture format %q", fixture.Metadata.Format)
	}

	for _, testCase := range fixture.Cases {
		t.Run(testCase.Label, func(t *testing.T) {
			base := testCase.Runs[0].Snapshot
			runs, err := backtest.PlanSweep(testCase.Name, testCase.Parameters, base)
			if err != nil {
				t.Fatalf("plan sweep: %v", err)
			}
			if len(runs) != testCase.TotalRuns {
				t.Fatalf("expected %d runs, got %d", testCase.TotalRuns, len(runs))
			}

			actual := make([]any, 0, len(runs))
			for _, run := range runs {
				actual = append(actual, map[string]any{
					"label":                  run.Label,
					"strategy_configuration": run.StrategyConfiguration,
					"snapshot":               run.Snapshot,
				})
			}

			if !reflect.DeepEqual(normalize(t, actual), normalize(t, testCase.Runs)) {
				actualJSON, _ := json.MarshalIndent(normalize(t, actual), "", "  ")
				expectedJSON, _ := json.MarshalIndent(normalize(t, testCase.Runs), "", "  ")
				t.Fatalf("sweep mismatch\nactual:\n%s\nexpected:\n%s", actualJSON, expectedJSON)
			}
		})
	}
}

func TestDecimalScaleFixture(t *testing.T) {
	var fixture replayFixture
	if err := goldenfixture.Load("backtest_replay", &fixture); err != nil {
		t.Fatalf("load fixture: %v", err)
	}

	if fixture.Metadata.DecimalScale != decimal.Scale {
		t.Fatalf("fixture scale %d does not match engine scale %d", fixture.Metadata.DecimalScale, decimal.Scale)
	}
	if _, err := time.Parse(time.RFC3339, fixture.Metadata.Clock); err != nil {
		t.Fatalf("invalid fixture clock: %v", err)
	}
}

func caseIndex(cases []replayCase, label string) int {
	for index, testCase := range cases {
		if testCase.Label == label {
			return index
		}
	}
	panic("missing replay case " + label)
}

func normalize(t *testing.T, value any) any {
	t.Helper()

	raw, err := json.Marshal(value)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	return decodeRaw(t, raw)
}

func decodeRaw(t *testing.T, raw []byte) any {
	t.Helper()

	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()

	var value any
	if err := decoder.Decode(&value); err != nil {
		t.Fatalf("decode: %v", err)
	}

	return value
}

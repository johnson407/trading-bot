package grid_test

import (
	"errors"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/goldenfixture"
	"github.com/johnson407/trading-bot/engine/internal/grid"
)

type generationFixture struct {
	Generator  []generationCase `json:"generator"`
	Derivation []derivationCase `json:"derivation"`
}

type fixtureError struct {
	Exception string `json:"exception"`
	Message   string `json:"message"`
}

type generationCase struct {
	Label         string `json:"label"`
	CenterPrice   string `json:"center_price"`
	SpacingType   string `json:"spacing_type"`
	SpacingValue  string `json:"spacing_value"`
	LevelsPerSide int    `json:"levels_per_side"`
	Levels        []struct {
		Sequence int    `json:"sequence"`
		Price    string `json:"price"`
		Side     string `json:"side"`
	} `json:"levels"`
	Error *fixtureError `json:"error"`
}

type derivationCase struct {
	Label     string         `json:"label"`
	Arguments map[string]any `json:"arguments"`
	Result    *string        `json:"result"`
	Error     *fixtureError  `json:"error"`
}

func TestGoldenGridGenerationArithmeticGeometricDynamicAndValidation(t *testing.T) {
	var fixture generationFixture
	if err := goldenfixture.Load("grid_generation", &fixture); err != nil {
		t.Fatal(err)
	}

	for _, testCase := range fixture.Generator {
		t.Run(testCase.Label, func(t *testing.T) {
			levels, err := grid.Generate(
				testCase.CenterPrice,
				testCase.SpacingValue,
				grid.SpacingType(testCase.SpacingType),
				testCase.LevelsPerSide,
			)

			if testCase.Error != nil {
				if err == nil {
					t.Fatal("expected an error")
				}

				if err.Error() != testCase.Error.Message {
					t.Fatalf("error = %q, want %q", err.Error(), testCase.Error.Message)
				}

				if testCase.Error.Exception == "InvalidGridException" {
					var invalid *grid.InvalidGridError
					if !errors.As(err, &invalid) {
						t.Fatalf("error type = %T, want *grid.InvalidGridError", err)
					}
				}

				return
			}

			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}

			if len(levels) != len(testCase.Levels) {
				t.Fatalf("level count = %d, want %d", len(levels), len(testCase.Levels))
			}

			for index, expected := range testCase.Levels {
				actual := levels[index]
				if actual.Sequence != expected.Sequence || actual.Price.String() != expected.Price || string(actual.Side) != expected.Side {
					t.Fatalf("level[%d] = {%d %s %s}, want {%d %s %s}",
						index,
						actual.Sequence, actual.Price.String(), actual.Side,
						expected.Sequence, expected.Price, expected.Side,
					)
				}
			}
		})
	}
}

func TestGoldenGridDerivationPercentQuantityAndValidation(t *testing.T) {
	var fixture generationFixture
	if err := goldenfixture.Load("grid_generation", &fixture); err != nil {
		t.Fatal(err)
	}

	for _, testCase := range fixture.Derivation {
		t.Run(testCase.Label, func(t *testing.T) {
			var (
				result string
				err    error
			)

			if percent, ok := testCase.Arguments["percent"]; ok {
				value, percentErr := grid.PercentToFraction(percent)
				err = percentErr
				result = value.String()
			} else {
				value, quantityErr := grid.OrderQuantity(
					testCase.Arguments["capital"],
					testCase.Arguments["utilization"],
					intArgument(t, testCase.Arguments, "levels_per_side"),
					testCase.Arguments["center_price"],
				)
				err = quantityErr
				result = value.String()
			}

			if testCase.Error != nil {
				if err == nil {
					t.Fatal("expected an error")
				}

				if err.Error() != testCase.Error.Message {
					t.Fatalf("error = %q, want %q", err.Error(), testCase.Error.Message)
				}

				return
			}

			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}

			if testCase.Result == nil {
				t.Fatal("fixture has neither result nor error")
			}

			if result != *testCase.Result {
				t.Fatalf("result = %q, want %q", result, *testCase.Result)
			}
		})
	}
}

func intArgument(t *testing.T, arguments map[string]any, name string) int {
	t.Helper()

	value, ok := arguments[name]
	if !ok {
		t.Fatalf("missing argument %q", name)
	}

	number, ok := value.(float64)
	if !ok {
		t.Fatalf("argument %q = %#v, want a JSON number", name, value)
	}

	return int(number)
}

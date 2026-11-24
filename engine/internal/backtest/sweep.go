package backtest

import (
	"fmt"
	"strconv"
)

// SweepParameters mirrors the persisted backtest_sweeps.parameters JSON.
type SweepParameters struct {
	CenterPrices  []string `json:"center_prices"`
	SpacingValues []string `json:"spacing_values"`
	LevelCounts   []int    `json:"level_counts"`
}

// SweepRunPlan is one expanded run inside a sweep.
type SweepRunPlan struct {
	Label                 string
	StrategyConfiguration map[string]any
	Snapshot              Snapshot
}

// PlanSweep mirrors App\Actions\CreateBacktestSweep's expansion order and
// label rules. Parameters are assumed to have been normalized by Laravel.
func PlanSweep(name string, parameters SweepParameters, base Snapshot) ([]SweepRunPlan, error) {
	if len(parameters.CenterPrices) == 0 || len(parameters.SpacingValues) == 0 || len(parameters.LevelCounts) == 0 {
		return nil, fmt.Errorf("A sweep requires center prices, spacing values, and level counts.")
	}

	total := len(parameters.CenterPrices) * len(parameters.SpacingValues) * len(parameters.LevelCounts)
	if total < 2 {
		return nil, fmt.Errorf("A sweep requires at least 2 combinations.")
	}

	runs := make([]SweepRunPlan, 0, total)
	for _, center := range parameters.CenterPrices {
		for _, spacing := range parameters.SpacingValues {
			for _, levels := range parameters.LevelCounts {
				snapshot := cloneSnapshot(base)
				snapshot.Strategy.Configuration["center_price"] = center
				snapshot.Strategy.Configuration["spacing_value"] = spacing
				snapshot.Strategy.Configuration["level_count"] = levels
				snapshot.Strategy.Configuration["levels_per_side"] = levels

				runs = append(runs, SweepRunPlan{
					Label:                 sweepLabel(name, center, spacing, levels),
					StrategyConfiguration: cloneValues(snapshot.Strategy.Configuration),
					Snapshot:              snapshot,
				})
			}
		}
	}

	return runs, nil
}

func sweepLabel(name, center, spacing string, levels int) string {
	label := name + " · center " + center + " · spacing " + spacing + " · levels " + strconv.Itoa(levels)

	return trimWidth(label, 160)
}

// trimWidth mirrors mb_strimwidth(value, 0, width, '...').
func trimWidth(value string, width int) string {
	runes := []rune(value)
	if len(runes) <= width {
		return value
	}

	keep := width - 3
	if keep < 0 {
		keep = 0
	}

	return string(runes[:keep]) + "..."
}

func cloneSnapshot(snapshot Snapshot) Snapshot {
	snapshot.Strategy.Configuration = cloneValues(snapshot.Strategy.Configuration)

	return snapshot
}

func cloneValues(values map[string]any) map[string]any {
	cloned := make(map[string]any, len(values))
	for key, value := range values {
		cloned[key] = value
	}

	return cloned
}

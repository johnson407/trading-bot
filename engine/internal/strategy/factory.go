package strategy

import "fmt"

// NewStrategy builds a runtime strategy by its persisted key.
func NewStrategy(key string) (Strategy, error) {
	switch key {
	case "simple-grid":
		return NewSimpleGrid(), nil
	case "dynamic-grid":
		return NewDynamicGrid(), nil
	case "martingale-grid":
		return NewMartingaleGrid(), nil
	case "trailing-grid":
		return NewTrailingGrid(), nil
	default:
		return nil, fmt.Errorf(
			"Unknown strategy [%s]. Known strategies: simple-grid, trailing-grid, martingale-grid, dynamic-grid.",
			key,
		)
	}
}

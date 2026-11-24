// Package goldenfixture locates the committed PHP golden engine fixtures.
//
// The JSON files under tests/golden/engine at the repository root are
// authoritative. The Go module does not vendor a second copy; tests read the
// committed originals through this helper.
package goldenfixture

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

// Dir walks up from the current working directory to the repository root and
// returns its tests/golden/engine directory.
func Dir() (string, error) {
	dir, err := os.Getwd()
	if err != nil {
		return "", fmt.Errorf("locate golden fixtures: %w", err)
	}

	for {
		candidate := filepath.Join(dir, "tests", "golden", "engine")
		if info, statErr := os.Stat(candidate); statErr == nil && info.IsDir() {
			return candidate, nil
		}

		parent := filepath.Dir(dir)
		if parent == dir {
			return "", fmt.Errorf("locate golden fixtures: tests/golden/engine not found above %s", dir)
		}

		dir = parent
	}
}

// Path returns the committed fixture path for a fixture name such as
// "tick_loop".
func Path(name string) (string, error) {
	dir, err := Dir()
	if err != nil {
		return "", err
	}

	return filepath.Join(dir, name+".json"), nil
}

// Load decodes a committed fixture into target.
func Load(name string, target any) error {
	path, err := Path(name)
	if err != nil {
		return err
	}

	data, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("read golden fixture %s: %w", path, err)
	}

	if err := json.Unmarshal(data, target); err != nil {
		return fmt.Errorf("decode golden fixture %s: %w", path, err)
	}

	return nil
}

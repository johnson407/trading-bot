package main

import (
	"context"
	"sync"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/marketdata"
)

func TestDrainWaitCompletes(t *testing.T) {
	var workers sync.WaitGroup
	workers.Add(1)
	go func() {
		defer workers.Done()
		time.Sleep(10 * time.Millisecond)
	}()

	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()

	if !drainWait(ctx, &workers) {
		t.Fatal("drainWait reported a timeout for workers that finished")
	}
}

func TestDrainWaitTimesOut(t *testing.T) {
	var workers sync.WaitGroup
	release := make(chan struct{})
	workers.Add(1)
	go func() {
		defer workers.Done()
		<-release
	}()
	defer close(release)

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Millisecond)
	defer cancel()

	if drainWait(ctx, &workers) {
		t.Fatal("drainWait reported completion while a worker was blocked")
	}
}

func TestBuildHeartbeatSnapshotConfiguredButIdle(t *testing.T) {
	startedAt := time.Date(2026, 11, 26, 11, 0, 0, 0, time.UTC)
	lastMessage := time.Date(2026, 11, 26, 11, 59, 58, 0, time.UTC)

	got := buildHeartbeatSnapshot(startedAt, true, true, 2, marketdata.Status{
		Mode:          marketdata.ModeIdle,
		Reason:        "",
		Symbols:       nil,
		LastMessageAt: lastMessage,
	})

	if !got.MarketData.Configured {
		t.Error("configured = false, want true for a configured feed")
	}
	if got.MarketData.Mode != string(marketdata.ModeIdle) {
		t.Errorf("mode = %q, want idle", got.MarketData.Mode)
	}
	if got.MarketData.Degraded {
		t.Error("degraded = true, want false")
	}
	if got.RunningBots != 2 || !got.LiveTrading {
		t.Errorf("snapshot = %+v, want two running bots and live trading", got)
	}
	if got.MarketData.LastMessageAt == nil || !got.MarketData.LastMessageAt.Equal(lastMessage) {
		t.Errorf("last_message_at = %v, want %s", got.MarketData.LastMessageAt, lastMessage)
	}
}

func TestBuildHeartbeatSnapshotNotConfigured(t *testing.T) {
	got := buildHeartbeatSnapshot(time.Now(), false, false, 0, marketdata.Status{Mode: marketdata.ModeIdle})

	if got.MarketData.Configured {
		t.Error("configured = true, want false for an unconfigured feed")
	}
	if got.MarketData.Mode != string(marketdata.ModeIdle) {
		t.Errorf("mode = %q, want idle", got.MarketData.Mode)
	}
	if got.MarketData.LastMessageAt != nil {
		t.Errorf("last_message_at = %v, want nil", got.MarketData.LastMessageAt)
	}
}

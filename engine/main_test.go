package main

import (
	"context"
	"sync"
	"testing"
	"time"
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

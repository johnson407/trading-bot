<?php

use App\Services\Engine\SchedulerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2026-11-28T12:00:00Z');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reports a freshly recorded heartbeat as fresh', function () {
    $heartbeat = app(SchedulerHeartbeat::class);

    $heartbeat->record();

    expect($heartbeat->lastSeenAt())->not->toBeNull()
        ->and($heartbeat->fresh())->toBeTrue();
});

it('reports a heartbeat older than three minutes as stale', function () {
    $heartbeat = app(SchedulerHeartbeat::class);

    $heartbeat->record();
    Carbon::setTestNow(now()->addSeconds(181));

    expect($heartbeat->fresh())->toBeFalse();
});

it('reports a missing heartbeat as stale', function () {
    expect(app(SchedulerHeartbeat::class)->fresh())->toBeFalse();
});

<?php

it('resolves the analytics defaults', function () {
    expect(config('analytics.capture_interval_minutes'))->toBe(5)
        ->and(config('analytics.retention_days'))->toBe(90)
        ->and(config('analytics.default_range_days'))->toBe(30)
        ->and(config('analytics.chart_points'))->toBe(500)
        ->and(config('analytics.ranges'))->toBe([7, 30, 90]);
});

it('keeps configured values positive', function () {
    expect(config('analytics.capture_interval_minutes'))->toBeGreaterThan(0)
        ->and(config('analytics.retention_days'))->toBeGreaterThan(0)
        ->and(config('analytics.default_range_days'))->toBeGreaterThan(0)
        ->and(config('analytics.chart_points'))->toBeGreaterThan(1);
});

it('keeps the capture interval aligned to the cron grid', function () {
    expect(60 % config('analytics.capture_interval_minutes'))->toBe(0);
});

it('keeps the default range selectable', function () {
    expect(config('analytics.ranges'))->toContain(config('analytics.default_range_days'));
});

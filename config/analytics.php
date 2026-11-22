<?php

$ranges = [7, 30, 90];
$validIntervals = [1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60];

$captureInterval = (int) env('ANALYTICS_CAPTURE_INTERVAL_MINUTES', 5);
$retentionDays = (int) env('ANALYTICS_RETENTION_DAYS', 90);
$defaultRange = (int) env('ANALYTICS_DEFAULT_RANGE_DAYS', 30);

return [
    /*
    |--------------------------------------------------------------------------
    | Capture cadence
    |--------------------------------------------------------------------------
    |
    | Snapshots are written once per interval. The captured timestamp is
    | floored to this bucket, so retries within the same interval replace
    | the existing snapshot instead of creating another row. The interval
    | must divide an hour evenly so the cron schedule and bucket grid align.
    |
    */
    'capture_interval_minutes' => in_array($captureInterval, $validIntervals, true)
        ? $captureInterval
        : 5,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Snapshots older than this many days are removed by the prune command.
    | Fills, ledger entries, risk events, and activity logs are untouched.
    |
    */
    'retention_days' => $retentionDays > 0 ? $retentionDays : 90,

    /*
    |--------------------------------------------------------------------------
    | Dashboard history
    |--------------------------------------------------------------------------
    */
    'default_range_days' => in_array($defaultRange, $ranges, true) ? $defaultRange : 30,

    'ranges' => $ranges,

    'chart_points' => max(2, (int) env('ANALYTICS_CHART_POINTS', 500)),
];

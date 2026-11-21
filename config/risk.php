<?php

return [
    'defaults' => [
        // Quote-asset notional cap for a single bot.
        'max_position_notional' => env('RISK_MAX_POSITION_NOTIONAL', '1000'),

        'max_open_orders' => (int) env('RISK_MAX_OPEN_ORDERS', 20),

        // Quote-asset loss allowed within the current UTC day.
        'daily_loss_limit' => env('RISK_DAILY_LOSS_LIMIT', '100'),
    ],

    'daily_loss' => [
        // Loss windows reset at 00:00 UTC.
        'timezone' => 'UTC',
    ],
];

<?php

it('documents risk defaults that match configuration', function () {
    $docs = file_get_contents(base_path('docs/risk.md'));

    expect($docs)
        ->toContain((string) config('risk.defaults.max_position_notional'))
        ->toContain((string) config('risk.defaults.max_open_orders'))
        ->toContain((string) config('risk.defaults.daily_loss_limit'))
        ->toContain('risk.defaults.max_position_notional')
        ->toContain('risk.defaults.max_open_orders')
        ->toContain('risk.defaults.daily_loss_limit')
        ->toContain('TRADING_LIVE_ENABLED')
        ->toContain('midnight UTC')
        ->toContain('kill_switch_cancel_failed');
});

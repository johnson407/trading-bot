<?php

it('documents dynamic-grid spacing hysteresis', function () {
    $docs = file_get_contents(base_path('docs/bot-parameters.md'));

    expect($docs)
        ->toContain('spacing_hysteresis_percent')
        ->toContain('0-90, default `20`')
        ->toContain('`20` means 20 percent, not `0.20`')
        ->toContain('`spacing_hysteresis_percent: 0`')
        ->toContain('replace the ladder')
        ->toContain('prices shared with the new ladder are eligible for fresh orders')
        ->toContain('initial_spacing')
        ->toContain('backtest observation is one candle close');
});

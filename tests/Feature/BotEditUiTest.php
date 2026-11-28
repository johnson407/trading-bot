<?php

it('offers edit for running paper bots', function () {
    $source = file_get_contents(resource_path('js/Pages/Bots/Show.vue'));

    expect($source)
        ->toContain("bot.status === 'running' && bot.mode === 'paper'")
        ->toContain("route('bots.edit'");
});

it('shows the running paper diff, preview and confirmation controls', function () {
    $source = file_get_contents(resource_path('js/Pages/Bots/Edit.vue'));

    expect($source)
        ->toContain('This bot is running.')
        ->toContain('Saving changes can cancel open orders and rebuild the ladder on the next tick.')
        ->toContain('Changes to save')
        ->toContain('form.confirm_running_edit')
        ->toContain('I understand saving structural or capital changes will cancel open orders and rebuild the ladder.')
        ->toContain('requiresConfirmation')
        ->toContain('form.name')
        ->toContain('form.fee_rate')
        ->toContain('form.tick_interval_seconds')
        ->toContain('form.size_multiplier')
        ->toContain('form.max_increases')
        ->toContain('form.volatility_multiplier')
        ->toContain('form.recalculation_interval_seconds');
});

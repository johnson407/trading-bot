<?php

use App\Domain\Exit\ExitRule;
use App\Domain\Exit\ExitRules;

it('triggers a stop-loss at or below the average entry percentage', function () {
    $rules = new ExitRules(stopLossPercent: '10');

    $atThreshold = $rules->evaluate(markPrice: '90', averageEntryPrice: '100', baseInventory: '1');
    $below = $rules->evaluate(markPrice: '89.99', averageEntryPrice: '100', baseInventory: '1');
    $above = $rules->evaluate(markPrice: '90.01', averageEntryPrice: '100', baseInventory: '1');

    expect($atThreshold?->rule)->toBe(ExitRule::StopLoss)
        ->and($atThreshold?->threshold)->toBe('90')
        ->and($below?->rule)->toBe(ExitRule::StopLoss)
        ->and($above)->toBeNull();
});

it('triggers a take-profit at or above the average entry percentage', function () {
    $rules = new ExitRules(takeProfitPercent: '10');

    $atThreshold = $rules->evaluate(markPrice: '110', averageEntryPrice: '100', baseInventory: '1');
    $above = $rules->evaluate(markPrice: '110.01', averageEntryPrice: '100', baseInventory: '1');
    $below = $rules->evaluate(markPrice: '109.99', averageEntryPrice: '100', baseInventory: '1');

    expect($atThreshold?->rule)->toBe(ExitRule::TakeProfit)
        ->and($atThreshold?->threshold)->toBe('110')
        ->and($above?->rule)->toBe(ExitRule::TakeProfit)
        ->and($below)->toBeNull();
});

it('triggers a range exit on either bound', function () {
    $rules = new ExitRules(rangeLow: '90', rangeHigh: '110');

    $low = $rules->evaluate(markPrice: '90', averageEntryPrice: '100', baseInventory: '1');
    $high = $rules->evaluate(markPrice: '110', averageEntryPrice: '100', baseInventory: '1');
    $inside = $rules->evaluate(markPrice: '100', averageEntryPrice: '100', baseInventory: '1');

    expect($low?->rule)->toBe(ExitRule::RangeExit)
        ->and($low?->bound)->toBe('low')
        ->and($low?->threshold)->toBe('90')
        ->and($high?->rule)->toBe(ExitRule::RangeExit)
        ->and($high?->bound)->toBe('high')
        ->and($high?->threshold)->toBe('110')
        ->and($inside)->toBeNull();
});

it('ignores disabled rules', function () {
    $rules = new ExitRules(stopLossPercent: null, takeProfitPercent: '', rangeLow: null, rangeHigh: null);

    expect($rules->evaluate(markPrice: '1', averageEntryPrice: '100', baseInventory: '1'))->toBeNull()
        ->and($rules->isEmpty())->toBeTrue();
});

it('skips percentage rules without inventory or an average entry', function () {
    $rules = new ExitRules(stopLossPercent: '10', takeProfitPercent: '10');

    expect($rules->evaluate(markPrice: '1', averageEntryPrice: '100', baseInventory: '0'))->toBeNull()
        ->and($rules->evaluate(markPrice: '1', averageEntryPrice: '0', baseInventory: '1'))->toBeNull();
});

it('applies a range without inventory or an average entry', function () {
    $rules = new ExitRules(rangeLow: '90');

    $signal = $rules->evaluate(markPrice: '80', averageEntryPrice: '0', baseInventory: '0');

    expect($signal?->rule)->toBe(ExitRule::RangeExit)
        ->and($signal?->bound)->toBe('low');
});

it('supports a single range bound', function () {
    $lowOnly = new ExitRules(rangeLow: '90');
    $highOnly = new ExitRules(rangeHigh: '110');

    expect($lowOnly->evaluate(markPrice: '89', averageEntryPrice: '100', baseInventory: '1')?->bound)->toBe('low')
        ->and($lowOnly->evaluate(markPrice: '111', averageEntryPrice: '100', baseInventory: '1'))->toBeNull()
        ->and($highOnly->evaluate(markPrice: '111', averageEntryPrice: '100', baseInventory: '1')?->bound)->toBe('high')
        ->and($highOnly->evaluate(markPrice: '89', averageEntryPrice: '100', baseInventory: '1'))->toBeNull();
});

it('prefers stop-loss over a range exit and take-profit', function () {
    $rules = new ExitRules(stopLossPercent: '10', takeProfitPercent: '10', rangeLow: '95', rangeHigh: '105');

    $signal = $rules->evaluate(markPrice: '80', averageEntryPrice: '100', baseInventory: '1');

    expect($signal?->rule)->toBe(ExitRule::StopLoss)
        ->and($signal?->threshold)->toBe('90');
});

it('prefers take-profit over a range exit', function () {
    $rules = new ExitRules(stopLossPercent: '10', takeProfitPercent: '10', rangeLow: '90', rangeHigh: '100');

    $signal = $rules->evaluate(markPrice: '110', averageEntryPrice: '100', baseInventory: '1');

    expect($signal?->rule)->toBe(ExitRule::TakeProfit);
});

it('returns no signal inside every enabled threshold', function () {
    $rules = new ExitRules(stopLossPercent: '10', takeProfitPercent: '10', rangeLow: '85', rangeHigh: '115');

    expect($rules->evaluate(markPrice: '100', averageEntryPrice: '100', baseInventory: '1'))->toBeNull();
});

it('reports the observed price, average entry, and base inventory on the signal', function () {
    $rules = new ExitRules(stopLossPercent: '10');

    $signal = $rules->evaluate(markPrice: '85', averageEntryPrice: '100', baseInventory: '2.5');

    expect($signal?->markPrice)->toBe('85')
        ->and($signal?->averageEntryPrice)->toBe('100')
        ->and($signal?->baseInventory)->toBe('2.5')
        ->and($signal?->context())->toMatchArray([
            'rule' => 'stop_loss',
            'observed_price' => '85',
            'threshold' => '90',
            'average_entry_price' => '100',
            'base_inventory' => '2.5',
        ]);
});

it('ignores non-positive configured values', function () {
    $rules = new ExitRules(stopLossPercent: '0', takeProfitPercent: '-5', rangeLow: '0', rangeHigh: '-1');

    expect($rules->evaluate(markPrice: '1', averageEntryPrice: '100', baseInventory: '1'))->toBeNull();
});

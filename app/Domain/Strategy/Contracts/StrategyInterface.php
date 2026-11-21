<?php

namespace App\Domain\Strategy\Contracts;

use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyInput;

interface StrategyInterface
{
    public function key(): string;

    /**
     * Idempotently create the strategy state when none exists.
     */
    public function initialize(StrategyInput $input): StrategyDecision;

    public function onTick(StrategyInput $input): StrategyDecision;

    public function onFill(StrategyInput $input, FillEvent $fill): StrategyDecision;

    public function onCancel(StrategyInput $input, CancelEvent $cancel): StrategyDecision;

    public function shouldStop(StrategyInput $input): bool;

    /** @return list<GridLevelPlan> */
    public function getGridLevels(StrategyInput $input): array;
}

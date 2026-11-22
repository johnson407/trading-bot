<?php

namespace App\Domain\Exit;

final readonly class ExitSignal
{
    public function __construct(
        public ExitRule $rule,
        public string $markPrice,
        public string $threshold,
        public ?string $averageEntryPrice = null,
        public ?string $baseInventory = null,
        public ?string $bound = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        $context = [
            'rule' => $this->rule->value,
            'observed_price' => $this->markPrice,
            'threshold' => $this->threshold,
        ];

        if ($this->averageEntryPrice !== null) {
            $context['average_entry_price'] = $this->averageEntryPrice;
        }

        if ($this->baseInventory !== null) {
            $context['base_inventory'] = $this->baseInventory;
        }

        if ($this->bound !== null) {
            $context['bound'] = $this->bound;
        }

        return $context;
    }
}

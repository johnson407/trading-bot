<?php

namespace App\Domain\Risk;

use App\Domain\Exchange\Contracts\ExchangeClient;
use App\Domain\Exchange\ValueObjects\Balance;
use App\Domain\Risk\Exceptions\InsufficientLiveBalanceException;
use App\Domain\Risk\Exceptions\LiveConfirmationRequiredException;
use App\Domain\Risk\Exceptions\LiveTradingDisabledException;
use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Services\Exchange\ExchangeClientFactory;

class LiveModeGuard
{
    public function __construct(private readonly ExchangeClientFactory $clients) {}

    public function assertCanStart(
        Bot $bot,
        ?LiveStartConfirmation $confirmation = null,
        ?ExchangeClient $exchange = null,
    ): void {
        if (! $bot->isLive()) {
            return;
        }

        $this->assertTradingAllowed($bot);

        if ($confirmation === null || ! $confirmation->matches($bot)) {
            throw LiveConfirmationRequiredException::forBot($bot->id, $bot->name);
        }

        $exchange ??= $this->clients->forBot($bot);
        $balances = $exchange->balances($bot);
        $asset = $this->quoteAsset($bot->symbol);
        $available = $this->available($balances, $asset);
        $required = Decimal::of($bot->capital_allocation);

        if ($available->lessThan($required)) {
            throw new InsufficientLiveBalanceException($asset, $required, $available, $bot->id);
        }
    }

    /**
     * Reject ongoing live trading when the configuration gate has been closed.
     *
     * Unlike assertCanStart(), this does not require a start confirmation or
     * balance check because the bot has already been authorized to run.
     */
    public function assertTradingAllowed(Bot $bot): void
    {
        if (! $bot->isLive()) {
            return;
        }

        if (! config('trading.live_enabled', false)) {
            throw LiveTradingDisabledException::forBot($bot->id);
        }
    }

    /** @param list<Balance> $balances */
    private function available(array $balances, string $asset): Decimal
    {
        foreach ($balances as $balance) {
            if ($balance->asset === $asset) {
                return $balance->free;
            }
        }

        return Decimal::zero();
    }

    private function quoteAsset(string $symbol): string
    {
        $parts = explode('/', $symbol);

        return $parts[1] ?? 'USDT';
    }
}

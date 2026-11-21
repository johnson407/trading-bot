<?php

namespace App\Domain\Bot;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Risk\Exceptions\LiveTradingDisabledException;
use App\Domain\Risk\LiveModeGuard;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Services\Exchange\ExchangeClientFactory;

class BalanceSynchronizer
{
    public function __construct(
        private readonly ExchangeClientFactory $clients,
        private readonly ActivityRecorder $activity,
        private readonly LiveModeGuard $liveMode,
    ) {}

    public static function isSharedAccount(Bot $bot): bool
    {
        return self::liveBotsOnAccount($bot) > 1;
    }

    private static function liveBotsOnAccount(Bot $bot): int
    {
        return Bot::query()
            ->where('mode', 'live')
            ->when(
                $bot->exchange_credential_id === null,
                fn ($query) => $query->where('user_id', $bot->user_id)->whereNull('exchange_credential_id'),
                fn ($query) => $query->where('exchange_credential_id', $bot->exchange_credential_id),
            )
            ->count();
    }

    /**
     * Refresh local base/quote inventory from the exchange for a live bot.
     *
     * @return array{base: string, quote: string}|null
     */
    public function sync(Bot $bot): ?array
    {
        if (! $bot->isLive()) {
            return null;
        }

        try {
            $this->liveMode->assertTradingAllowed($bot);
        } catch (LiveTradingDisabledException) {
            return null;
        }

        $liveBotsOnAccount = self::liveBotsOnAccount($bot);

        if ($liveBotsOnAccount > 1) {
            $this->activity->record(
                'bot.balance_sync_skipped',
                'Balance sync skipped because several live bots share this exchange account.',
                $bot,
                context: ['reason' => 'shared_account', 'live_bots' => $liveBotsOnAccount],
                severity: 'warning',
            );

            return null;
        }

        $client = $this->clients->forBot($bot);
        $balances = $client->balances($bot);
        [$baseAsset, $quoteAsset] = array_pad(explode('/', $bot->symbol), 2, 'USDT');

        $base = '0';
        $quote = '0';

        foreach ($balances as $balance) {
            if ($balance->asset === $baseAsset) {
                $base = $balance->total->toString();
            }

            if ($balance->asset === $quoteAsset) {
                $quote = $balance->total->toString();
            }
        }

        $oldBase = Decimal::of($bot->base_inventory);
        $oldQuote = Decimal::of($bot->quote_inventory);

        $bot->forceFill([
            'base_inventory' => $base,
            'quote_inventory' => $quote,
        ])->save();

        $this->activity->record('bot.balance_synced', 'Live balances refreshed from the exchange', $bot, context: [
            'old_base' => $oldBase->toString(),
            'old_quote' => $oldQuote->toString(),
            'base' => $base,
            'quote' => $quote,
        ]);

        return ['base' => $base, 'quote' => $quote];
    }
}

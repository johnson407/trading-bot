<?php

namespace Database\Seeders;

use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Grid\Enums\GridLevelState;
use App\Domain\Grid\Enums\GridSpacingType;
use App\Domain\Grid\Enums\GridStatus;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderType;
use App\Domain\Risk\Enums\RiskScope;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\Grid;
use App\Models\GridLevel;
use App\Models\Order;
use App\Models\RiskLimit;
use App\Models\StrategyConfig;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Demo Trader',
            'email' => 'demo@example.com',
            'password' => Hash::make('password'),
        ]);

        $credential = ExchangeCredential::create([
            'user_id' => $user->id,
            'exchange' => 'binance',
            'api_key' => 'demo-public-key',
            'api_secret' => 'demo-secret-value',
            'passphrase' => null,
            'testnet' => true,
        ]);

        $bot = Bot::create([
            'user_id' => $user->id,
            'exchange_credential_id' => $credential->id,
            'name' => 'BTC/USDT paper grid',
            'exchange' => 'binance',
            'symbol' => 'BTC/USDT',
            'strategy_key' => 'simple-grid',
            'mode' => 'paper',
            'status' => BotStatus::Idle,
            'capital_allocation' => '1000',
            'quote_inventory' => '1000',
            'fee_rate' => '0',
            'tick_interval_seconds' => max(1, (int) config('trading.tick.interval_seconds')),
            'settings' => [],
        ]);

        StrategyConfig::create([
            'bot_id' => $bot->id,
            'strategy_key' => 'simple-grid',
            'configuration' => [
                'center_price' => '50000',
                'level_count' => 6,
                'spacing_type' => 'arithmetic',
                'spacing_value' => '100',
                'order_quantity' => '0.001',
            ],
            'state' => null,
            'state_version' => 1,
        ]);

        RiskLimit::create([
            'scope' => RiskScope::User,
            'user_id' => $user->id,
            'max_position_notional' => '5000',
            'max_open_orders' => 50,
            'daily_loss_limit' => '250',
            'day_start' => now('UTC')->startOfDay(),
        ]);

        RiskLimit::create([
            'scope' => RiskScope::Bot,
            'user_id' => $user->id,
            'bot_id' => $bot->id,
            'max_position_notional' => '1000',
            'max_open_orders' => 20,
            'daily_loss_limit' => '100',
            'day_start' => now('UTC')->startOfDay(),
        ]);

        $grid = Grid::create([
            'bot_id' => $bot->id,
            'center_price' => '50000',
            'spacing_type' => GridSpacingType::Arithmetic,
            'level_count' => 6,
            'spacing_value' => '100',
            'status' => GridStatus::Active,
        ]);

        $prices = [49700, 49800, 49900, 50100, 50200, 50300];

        foreach ($prices as $index => $price) {
            $side = $price < 50000 ? OrderSide::Buy : OrderSide::Sell;

            $level = GridLevel::create([
                'grid_id' => $grid->id,
                'sequence' => $index + 1,
                'price' => (string) $price,
                'side' => $side,
                'state' => GridLevelState::Open,
            ]);

            Order::create([
                'bot_id' => $bot->id,
                'grid_id' => $grid->id,
                'grid_level_id' => $level->id,
                'client_order_id' => "demo-{$bot->id}-{$grid->id}-{$level->sequence}-1",
                'mode' => 'paper',
                'side' => $side,
                'type' => OrderType::Limit,
                'price' => (string) $price,
                'quantity' => '0.001',
                'filled_quantity' => '0',
                'status' => OrderStatus::Open,
                'requested_at' => now(),
                'opened_at' => now(),
            ]);
        }
    }
}

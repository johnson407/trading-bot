<?php

namespace App\Services\Engine;

use App\Domain\Grid\Exceptions\GridPlanUnavailableException;
use App\Domain\Grid\ValueObjects\GridPlan;
use App\Domain\Grid\ValueObjects\GridPlanRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Calls the Go engine's internal plan endpoint. Preview and create/update
 * validation both use this client so the ladder a user sees is exactly the
 * ladder the engine will run on the first tick.
 */
final class GridPlanClient
{
    public function evaluate(GridPlanRequest $request): GridPlan
    {
        $url = rtrim((string) config('engine.plan_url', ''), '/');

        if ($url === '') {
            throw GridPlanUnavailableException::because('ENGINE_PLAN_URL is not configured.');
        }

        $http = Http::acceptJson()
            ->asJson()
            ->timeout((float) config('engine.plan_timeout', 5));

        $token = (string) config('engine.internal_token', '');

        if ($token !== '') {
            $http = $http->withToken($token);
        }

        try {
            $response = $http->post($url.'/v1/plan/evaluate', $this->payload($request));
        } catch (ConnectionException $exception) {
            throw GridPlanUnavailableException::because('the engine could not be reached.');
        }

        if ($response->failed()) {
            throw GridPlanUnavailableException::because("the engine returned HTTP {$response->status()}.");
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['strategy_key'])) {
            throw GridPlanUnavailableException::because('the engine returned an unexpected response.');
        }

        return GridPlan::fromArray($data);
    }

    /** @return array<string, mixed> */
    private function payload(GridPlanRequest $request): array
    {
        $limits = $request->limits;

        return [
            'strategy_key' => $request->strategyKey,
            'symbol' => $request->symbol,
            'configuration' => $request->configuration,
            'capital_allocation' => (string) $request->capitalAllocation,
            'base_inventory' => (string) $request->baseInventory,
            'market_price' => $request->marketPrice === null ? null : (string) $request->marketPrice,
            'limits' => $limits === null ? null : [
                'max_open_orders' => (int) ($limits['max_open_orders'] ?? 20),
                'max_position_notional' => (string) ($limits['max_position_notional'] ?? '1000'),
            ],
            'market' => $request->market === null ? null : [
                'symbol' => $request->market->symbol,
                'last' => $request->market->last->toString(),
                'min_amount' => $request->market->minAmount->toString(),
                'min_notional' => $request->market->minNotional->toString(),
                'price_precision' => $request->market->pricePrecision,
                'amount_precision' => $request->market->amountPrecision,
            ],
        ];
    }
}

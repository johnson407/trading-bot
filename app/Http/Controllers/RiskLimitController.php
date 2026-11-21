<?php

namespace App\Http\Controllers;

use App\Domain\Risk\RiskLimitService;
use App\Http\Requests\UpdateRiskLimitRequest;
use App\Models\RiskLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RiskLimitController extends Controller
{
    public function edit(Request $request, RiskLimitService $limits): Response
    {
        return Inertia::render('RiskLimits/Edit', [
            'limits' => $this->payload($limits->forUser($request->user())),
        ]);
    }

    public function update(UpdateRiskLimitRequest $request, RiskLimitService $limits): RedirectResponse
    {
        $limits->updateForUser($request->user(), $request->validated());

        return redirect()
            ->route('risk-limits.edit')
            ->with('status', 'Risk limits updated.');
    }

    /** @return array{max_position_notional: string, max_open_orders: int, daily_loss_limit: string} */
    private function payload(RiskLimit $limit): array
    {
        return [
            'max_position_notional' => (string) $limit->max_position_notional,
            'max_open_orders' => (int) $limit->max_open_orders,
            'daily_loss_limit' => (string) $limit->daily_loss_limit,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\DeleteExchangeCredential;
use App\Actions\SaveExchangeCredential;
use App\Models\ExchangeCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExchangeCredentialController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $credentials = $request->user()->exchangeCredentials()
            ->latest()
            ->get()
            ->map(fn (ExchangeCredential $credential) => $credential->toMaskedArray())
            ->values();

        return Inertia::render('ExchangeCredentials/Index', [
            'credentials' => $credentials,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ExchangeCredentials/Create', [
            'exchanges' => ['binance'],
        ]);
    }

    public function store(Request $request, SaveExchangeCredential $save): RedirectResponse
    {
        $validated = $this->validated($request);

        $save->handle(
            $request->user(),
            $validated['exchange'],
            $validated['api_key'],
            $validated['api_secret'],
            $validated['passphrase'] ?? null,
            (bool) ($validated['testnet'] ?? true),
        );

        return redirect()->route('exchange-credentials.index')->with('status', 'Exchange credentials saved.');
    }

    public function edit(ExchangeCredential $exchangeCredential): Response
    {
        $this->authorize('update', $exchangeCredential);

        return Inertia::render('ExchangeCredentials/Edit', [
            'credential' => $exchangeCredential->toMaskedArray(),
            'exchanges' => ['binance'],
        ]);
    }

    public function update(Request $request, ExchangeCredential $exchangeCredential, SaveExchangeCredential $save): RedirectResponse
    {
        $this->authorize('update', $exchangeCredential);
        $validated = $this->validated($request);

        $save->handle(
            $request->user(),
            $validated['exchange'],
            $validated['api_key'],
            $validated['api_secret'],
            $validated['passphrase'] ?? null,
            (bool) ($validated['testnet'] ?? true),
        );

        return redirect()->route('exchange-credentials.index')->with('status', 'Exchange credentials replaced.');
    }

    public function destroy(ExchangeCredential $exchangeCredential, DeleteExchangeCredential $delete): RedirectResponse
    {
        $this->authorize('delete', $exchangeCredential);
        $delete->handle($exchangeCredential);

        return redirect()->route('exchange-credentials.index')->with('status', 'Exchange credentials deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'exchange' => ['required', 'string', Rule::in(['binance'])],
            'api_key' => ['required', 'string', 'max:512'],
            'api_secret' => ['required', 'string', 'max:1024'],
            'passphrase' => ['nullable', 'string', 'max:512'],
            'testnet' => ['sometimes', 'boolean'],
        ]);
    }
}

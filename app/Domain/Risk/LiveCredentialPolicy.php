<?php

namespace App\Domain\Risk;

use App\Domain\Risk\Exceptions\LiveCredentialConflictException;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\User;

class LiveCredentialPolicy
{
    public function assertCanCreate(User $user, ?ExchangeCredential $credential, string $mode): void
    {
        if ($mode !== 'live') {
            return;
        }

        if (! $credential instanceof ExchangeCredential) {
            throw LiveCredentialConflictException::missingCredential();
        }

        $this->assertNoOtherActiveLiveBot($user->id, $credential->id);
    }

    public function assertCanStart(Bot $bot): void
    {
        if (! $bot->isLive()) {
            return;
        }

        if ($bot->exchange_credential_id === null) {
            throw LiveCredentialConflictException::missingCredential();
        }

        $this->assertNoOtherActiveLiveBot(
            $bot->user_id,
            (int) $bot->exchange_credential_id,
            (int) $bot->getKey(),
        );
    }

    /**
     * @return list<int>
     */
    public function conflictingCredentialIds(): array
    {
        return Bot::query()
            ->whereNotNull('exchange_credential_id')
            ->where('mode', 'live')
            ->where(function ($query): void {
                $query->whereIn('status', ['running', 'paused'])
                    ->orWhere('desired_status', 'running');
            })
            ->select('exchange_credential_id')
            ->groupBy('exchange_credential_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('exchange_credential_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function assertNoOtherActiveLiveBot(int $userId, int $credentialId, ?int $excludeBotId = null): void
    {
        $active = Bot::query()
            ->where('user_id', $userId)
            ->where('exchange_credential_id', $credentialId)
            ->where('mode', 'live')
            ->when($excludeBotId !== null, fn ($query) => $query->whereKeyNot($excludeBotId))
            ->where(function ($query): void {
                $query->whereIn('status', ['running', 'paused'])
                    ->orWhere('desired_status', 'running');
            })
            ->first();

        if ($active instanceof Bot) {
            throw LiveCredentialConflictException::conflicting($active);
        }
    }
}

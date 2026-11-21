<?php

namespace App\Policies;

use App\Models\ExchangeCredential;
use App\Models\User;

class ExchangeCredentialPolicy
{
    public function view(User $user, ExchangeCredential $credential): bool
    {
        return $this->owns($user, $credential);
    }

    public function update(User $user, ExchangeCredential $credential): bool
    {
        return $this->owns($user, $credential);
    }

    public function delete(User $user, ExchangeCredential $credential): bool
    {
        return $this->owns($user, $credential);
    }

    private function owns(User $user, ExchangeCredential $credential): bool
    {
        return $user->id === $credential->user_id;
    }
}

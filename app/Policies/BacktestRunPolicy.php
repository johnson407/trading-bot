<?php

namespace App\Policies;

use App\Models\BacktestRun;
use App\Models\User;

class BacktestRunPolicy
{
    public function view(User $user, BacktestRun $run): bool
    {
        return $run->user_id === $user->id;
    }

    public function delete(User $user, BacktestRun $run): bool
    {
        return $run->user_id === $user->id;
    }
}

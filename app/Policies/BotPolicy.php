<?php

namespace App\Policies;

use App\Domain\Bot\Enums\BotStatus;
use App\Models\Bot;
use App\Models\User;

class BotPolicy
{
    public function view(User $user, Bot $bot): bool
    {
        return $this->owns($user, $bot);
    }

    public function update(User $user, Bot $bot): bool
    {
        return $this->owns($user, $bot)
            && (
                in_array($bot->status, [BotStatus::Idle, BotStatus::Stopped], true)
                || ($bot->isRunning() && ! $bot->isLive())
            );
    }

    public function delete(User $user, Bot $bot): bool
    {
        return $this->owns($user, $bot);
    }

    public function restore(User $user, Bot $bot): bool
    {
        return $this->owns($user, $bot);
    }

    public function control(User $user, Bot $bot): bool
    {
        return $this->owns($user, $bot);
    }

    private function owns(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }
}

<?php

namespace App\Services\Engine;

use Illuminate\Support\Facades\Artisan;
use Throwable;

class HorizonStatus
{
    public function running(): bool
    {
        try {
            return Artisan::call('horizon:status') === 0;
        } catch (Throwable) {
            return false;
        }
    }
}

<?php

namespace App\Services\Engine;

use Illuminate\Support\Facades\Artisan;

class HorizonStatus
{
    public function running(): bool
    {
        return Artisan::call('horizon:status') === 0;
    }
}

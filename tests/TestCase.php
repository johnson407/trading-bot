<?php

namespace Tests;

use App\Services\Engine\CommandPublisher;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\EnginePlanServer;
use Tests\Support\FakeCommandPublisher;
use Tests\Support\FakeEngineHeartbeat;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(CommandPublisher::class, new FakeCommandPublisher);
        $this->app->instance(EngineHeartbeat::class, new FakeEngineHeartbeat);

        $planUrl = EnginePlanServer::ensure((string) config('engine.plan_url', ''));

        if ($planUrl !== null) {
            config(['engine.plan_url' => $planUrl]);
        }
    }
}

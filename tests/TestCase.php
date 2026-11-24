<?php

namespace Tests;

use App\Services\Engine\CommandPublisher;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeCommandPublisher;
use Tests\Support\FakeEngineHeartbeat;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(CommandPublisher::class, new FakeCommandPublisher);
        $this->app->instance(EngineHeartbeat::class, new FakeEngineHeartbeat);
    }
}

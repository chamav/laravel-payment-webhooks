<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

trait SignedWebhookRequests
{
    use InteractsWithWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00.123456', 'UTC'));
        config(['services.demo_pay.webhook_secret' => self::SECRET, 'app.debug' => false]);
    }
}

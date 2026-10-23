<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ServiceStatusTest extends TestCase
{
    public function test_root_identifies_the_service(): void
    {
        $this->get('/')->assertOk()->assertExactJson(['service' => 'laravel-payment-webhooks']);
    }

    public function test_liveness_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}

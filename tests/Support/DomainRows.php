<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;

final class DomainRows
{
    public static function order(array $overrides = []): array
    {
        return array_replace([
            'id' => (string) Str::uuid7(), 'amount_minor' => 12990, 'currency' => 'EUR',
            'status' => 'pending', 'paid_at' => null,
            'created_at' => '2026-10-23 08:00:00+00', 'updated_at' => '2026-10-23 08:00:00+00',
        ], $overrides);
    }

    public static function payment(string $orderId, array $overrides = []): array
    {
        return array_replace([
            'id' => (string) Str::uuid7(), 'order_id' => $orderId, 'provider' => 'demo-pay',
            'provider_payment_id' => 'pay_'.Str::random(16), 'amount_minor' => 12990,
            'currency' => 'EUR', 'created_at' => '2026-10-23 08:00:00+00',
        ], $overrides);
    }

    public static function receipt(array $overrides = []): array
    {
        return array_replace([
            'id' => (string) Str::uuid7(), 'provider' => 'demo-pay', 'event_id' => 'evt_'.Str::random(16),
            'event_type' => 'payment.succeeded', 'body_sha256' => str_repeat('a', 64),
            'outcome' => 'processed', 'reason_code' => null, 'http_status' => 200,
            'completed_at' => '2026-10-23 08:00:00+00', 'order_id' => null,
        ], $overrides);
    }
}

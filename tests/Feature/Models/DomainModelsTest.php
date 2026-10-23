<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\Currency;
use App\Enums\OrderStatus;
use App\Enums\WebhookOutcome;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\DomainRows;
use Tests\TestCase;

class DomainModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_uuid_and_restores_order_casts(): void
    {
        $order = Order::create(['amount_minor' => 12990, 'currency' => Currency::EUR, 'status' => OrderStatus::Pending]);
        $order->refresh();

        $this->assertTrue(Str::isUuid($order->id));
        $this->assertFalse($order->getIncrementing());
        $this->assertSame('string', $order->getKeyType());
        $this->assertSame(12990, $order->amount_minor);
        $this->assertSame(Currency::EUR, $order->currency);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertInstanceOf(CarbonImmutable::class, $order->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $order->updated_at);
        $this->assertNull($order->payment);
        $this->assertCount(0, $order->receipts);
    }

    public function test_restores_payment_and_receipt_casts_and_relations(): void
    {
        $order = Order::create(['amount_minor' => 12990, 'currency' => Currency::USD, 'status' => OrderStatus::Paid, 'paid_at' => now()]);
        $payment = Payment::create(collect(DomainRows::payment($order->id, ['currency' => 'USD']))->except(['id', 'created_at'])->all());
        $receipt = WebhookReceipt::create(collect(DomainRows::receipt(['order_id' => $order->id]))->except('id')->all());
        $payment->refresh();
        $receipt->refresh();

        $this->assertTrue(Str::isUuid($payment->id));
        $this->assertTrue(Str::isUuid($receipt->id));
        $this->assertSame(Currency::USD, $payment->currency);
        $this->assertSame(12990, $payment->amount_minor);
        $this->assertInstanceOf(CarbonImmutable::class, $payment->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $order->fresh()->paid_at);
        $this->assertSame(WebhookOutcome::Processed, $receipt->outcome);
        $this->assertSame(200, $receipt->http_status);
        $this->assertInstanceOf(CarbonImmutable::class, $receipt->completed_at);
        $this->assertSame($order->id, $payment->order->id);
        $this->assertSame($payment->id, $order->payment->id);
        $this->assertSame([$receipt->id], $order->receipts->modelKeys());
        $this->assertArrayNotHasKey('updated_at', $payment->getAttributes());
        $this->assertArrayNotHasKey('created_at', $receipt->getAttributes());
        $this->assertArrayNotHasKey('updated_at', $receipt->getAttributes());

        $payment->save();
        $receipt->save();
    }

    public function test_preserves_microseconds_when_saving_domain_models(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00:00.123456 UTC'));

        $order = Order::create(['amount_minor' => 12990, 'currency' => Currency::EUR, 'status' => OrderStatus::Paid, 'paid_at' => now()]);
        $payment = Payment::create(collect(DomainRows::payment($order->id))->except(['id', 'created_at'])->all());
        $receipt = WebhookReceipt::create(collect(DomainRows::receipt(['order_id' => $order->id, 'completed_at' => now()]))->except('id')->all());
        $order->refresh();
        $payment->refresh();
        $receipt->refresh();

        $expected = '2026-10-23T10:00:00.123456Z';
        $this->assertSame($expected, $order->created_at->toISOString());
        $this->assertSame($expected, $order->updated_at->toISOString());
        $this->assertSame($expected, $order->paid_at->toISOString());
        $this->assertSame($expected, $payment->created_at->toISOString());
        $this->assertSame($expected, $receipt->completed_at->toISOString());
    }

    public function test_receipt_order_is_nullable_and_may_not_exist(): void
    {
        $receipt = WebhookReceipt::create(DomainRows::receipt(['outcome' => 'ignored']));
        $this->assertNull($receipt->fresh()->order_id);
        $missing = (string) Str::uuid7();
        $rejected = WebhookReceipt::create(DomainRows::receipt(['order_id' => $missing, 'outcome' => 'rejected', 'reason_code' => 'order_not_found', 'http_status' => 409]));
        $this->assertSame($missing, $rejected->fresh()->order_id);
    }
}

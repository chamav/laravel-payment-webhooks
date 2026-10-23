<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DomainRows;
use Tests\Support\SignedWebhookRequests;
use Tests\TestCase;

class DemoPayReplayTest extends TestCase
{
    use SignedWebhookRequests;

    public function test_d02_replays_processed_event_without_mutating_rows(): void
    {
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $headers = $this->signedHeaders($raw);
        $this->deliverDecision($raw, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $before = $this->domainState();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:01:00.234567 UTC'));
        $this->sendRaw($raw, $headers)->assertOk()->assertExactJson([
            'event_id' => 'evt_first', 'outcome' => 'processed', 'duplicate' => true,
        ]);
        $this->assertSame($before, $this->domainState());
    }

    public function test_d03_replays_same_bytes_with_fresh_timestamp(): void
    {
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $oldTimestamp = now()->getTimestamp();
        $this->deliverDecision($raw, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $before = $this->domainState();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 13:00:00.345678 UTC'));
        $this->assertGreaterThan(300, now()->getTimestamp() - $oldTimestamp);
        $this->deliverDecision($raw, 'processed', duplicate: true);
        $this->assertSame($before, $this->domainState());
    }

    #[DataProvider('changedBodies')]
    public function test_d04_rejects_changed_body_for_existing_event(string $change): void
    {
        $order = $this->insertOrder();
        $other = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $this->deliverDecision($raw, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order), $other], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $before = $this->domainState();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 13:00:00.345678 UTC'));
        $changed = match ($change) {
            'whitespace' => $raw."\n",
            'different order' => $this->paymentBody($other, paymentId: 'pay_other'),
            'different amount' => $this->paymentBody($order, data: ['amount_minor' => 1]),
            'different type' => str_replace('payment.succeeded', 'payment.refunded', $raw),
        };
        $this->sendRaw($changed)->assertStatus(409)->assertExactJson(['error' => 'event_conflict']);
        $this->assertSame($before, $this->domainState());
    }

    public static function changedBodies(): array
    {
        return [
            'D04 whitespace only' => ['whitespace'], 'D04 different order' => ['different order'],
            'D04 amount before business rejection' => ['different amount'], 'D04 changed event type' => ['different type'],
        ];
    }

    #[DataProvider('rejectionReasons')]
    public function test_d10_replays_rejected_decision_after_order_changes(string $reason): void
    {
        $orders = $payments = $receipts = [];
        if (in_array($reason, ['order_already_paid', 'payment_conflict'], true)) {
            $owner = $this->insertOrder();
            $first = $this->paymentBody($owner);
            $this->deliverDecision($first, 'processed');
            $orders[] = $this->expectedPaidOrder($owner);
            $payments[] = $this->expectedPayment($first);
            $receipts[] = $this->expectedReceipt($first, 'processed');
            $this->assertTables($orders, $payments, $receipts);
        }

        if ($reason === 'order_not_found') {
            $order = DomainRows::order();
        } elseif ($reason === 'order_already_paid') {
            $order = $owner;
        } else {
            $order = $this->insertOrder();
            $orders[] = $order;
        }
        $raw = $this->paymentBody($order, 'evt_rejected', $reason === 'payment_conflict' ? 'pay_first' : 'pay_rejected', [
            'amount_minor' => $reason === 'amount_mismatch' ? 1 : 12990,
            'currency' => $reason === 'currency_mismatch' ? 'USD' : 'EUR',
        ]);
        $this->deliverDecision($raw, 'rejected', $reason);
        $receipts[] = $this->expectedReceipt($raw, 'rejected', $reason);
        $this->assertTables($orders, $payments, $receipts);

        $this->travelTo(CarbonImmutable::parse('2026-10-23 13:00:00.345678 UTC'));
        if (in_array($reason, ['amount_mismatch', 'currency_mismatch'], true)) {
            $changes = [
                'amount_minor' => $reason === 'amount_mismatch' ? 1 : 12990,
                'currency' => $reason === 'currency_mismatch' ? 'USD' : 'EUR',
                'updated_at' => '2026-10-23 13:00:00.345678+00',
            ];
            DB::table('orders')->where('id', $order['id'])->update($changes);
            $this->assertTables([array_replace($order, $changes)], $payments, $receipts);
        }
        $before = $this->domainState();
        $this->deliverDecision($raw, 'rejected', $reason, duplicate: true);
        $this->assertSame($before, $this->domainState());
    }

    public static function rejectionReasons(): array
    {
        return [
            'D10 corrected amount' => ['amount_mismatch'], 'D10 corrected currency' => ['currency_mismatch'],
            'D10 paid order' => ['order_already_paid'], 'D10 payment conflict' => ['payment_conflict'],
            'D10 missing order' => ['order_not_found'],
        ];
    }

    public function test_d11_preserves_missing_order_rejection_then_accepts_new_event(): void
    {
        $this->deliverDecision(self::BODY, 'rejected', 'order_not_found');
        $rejected = $this->expectedReceipt(self::BODY, 'rejected', 'order_not_found');
        $this->assertTables([], [], [$rejected]);

        $this->travelTo(CarbonImmutable::parse('2026-10-23 13:00:00.345678 UTC'));
        $order = $this->insertOrder(['id' => '0199a600-0000-7000-8000-000000000001']);
        $before = $this->domainState();
        $this->deliverDecision(self::BODY, 'rejected', 'order_not_found', duplicate: true);
        $this->assertSame($before, $this->domainState());
        $this->assertTables([$order], [], [$rejected]);

        $this->travelTo(CarbonImmutable::parse('2026-10-23 14:00:00.456789 UTC'));
        $fresh = str_replace('evt_demo_001', 'evt_new', self::BODY);
        $this->deliverDecision($fresh, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($fresh)], [
            $rejected, $this->expectedReceipt($fresh, 'processed'),
        ]);
    }

    public function test_d12_replays_ignored_event_then_rejects_changed_bytes(): void
    {
        $raw = '{"id":"evt_ignored","type":"payment.refunded","data":{}}';
        $this->deliverDecision($raw, 'ignored');
        $this->assertTables([], [], [$this->expectedReceipt($raw, 'ignored')]);
        $before = $this->domainState();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 13:00:00.345678 UTC'));
        $this->deliverDecision($raw, 'ignored', duplicate: true);
        $this->assertSame($before, $this->domainState());

        $this->travelTo(CarbonImmutable::parse('2026-10-23 14:00:00.456789 UTC'));
        $this->sendRaw($raw."\n")->assertStatus(409)->assertExactJson(['error' => 'event_conflict']);
        $this->assertSame($before, $this->domainState());
    }

    public function test_d05_replays_duplicate_payment_receipt_without_changes(): void
    {
        $order = $this->insertOrder();
        $first = $this->paymentBody($order);
        $this->deliverDecision($first, 'processed');
        $paid = $this->expectedPaidOrder($order);
        $payment = $this->expectedPayment($first);
        $receipt = $this->expectedReceipt($first, 'processed');
        $this->assertTables([$paid], [$payment], [$receipt]);

        $this->travelTo(CarbonImmutable::parse('2026-10-23 13:00:00.345678 UTC'));
        $duplicate = $this->paymentBody($order, 'evt_duplicate');
        $this->deliverDecision($duplicate, 'duplicate_payment');
        $this->assertTables([$paid], [$payment], [$receipt, $this->expectedReceipt($duplicate, 'duplicate_payment')]);
        $before = $this->domainState();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 14:00:00.456789 UTC'));
        $this->deliverDecision($duplicate, 'duplicate_payment', duplicate: true);
        $this->assertSame($before, $this->domainState());
    }
}

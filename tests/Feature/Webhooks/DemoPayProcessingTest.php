<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SignedWebhookRequests;
use Tests\TestCase;

class DemoPayProcessingTest extends TestCase
{
    use SignedWebhookRequests;

    #[DataProvider('acceptedPayments')]
    public function test_d01_processes_pending_order_atomically(int $amount, string $currency): void
    {
        $order = $this->insertOrder(['amount_minor' => $amount, 'currency' => $currency]);
        $raw = $this->paymentBody($order);
        $this->deliverDecision($raw, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $this->assertSame('read committed', DB::selectOne("select current_setting('transaction_isolation') as isolation")->isolation);
    }

    public static function acceptedPayments(): array
    {
        return ['D01 EUR minimum' => [1, 'EUR'], 'D01 USD maximum' => [1000000000, 'USD']];
    }

    public function test_d01_applies_configured_timeouts_before_claiming_receipt(): void
    {
        config(['webhooks.lock_timeout_ms' => '275', 'webhooks.statement_timeout_ms' => 1250]);
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $settings = [];
        DB::listen(function (QueryExecuted $query) use (&$settings): void {
            if (str_starts_with($query->sql, 'insert into "webhook_receipts"')) {
                $settings[] = (array) DB::selectOne("select current_setting('lock_timeout') as lock_timeout, current_setting('statement_timeout') as statement_timeout, current_setting('transaction_isolation') as isolation");
            }
        });

        $this->deliverDecision($raw, 'processed');
        $this->assertSame([['lock_timeout' => '275ms', 'statement_timeout' => '1250ms', 'isolation' => 'read committed']], $settings);
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
    }

    #[DataProvider('invalidTimeouts')]
    public function test_invalid_timeout_configuration_writes_no_domain_rows(mixed $lockTimeout, mixed $statementTimeout): void
    {
        config(['webhooks.lock_timeout_ms' => $lockTimeout, 'webhooks.statement_timeout_ms' => $statementTimeout]);
        $order = $this->insertOrder();
        $this->sendRaw($this->paymentBody($order))->assertStatus(500)->assertExactJson(['error' => 'internal_error']);
        $this->assertTables([$order], [], []);
    }

    public static function invalidTimeouts(): array
    {
        return [
            'zero disables bounding' => [0, 10000], 'negative' => [-1, 10000],
            'fractional' => [1.5, 10000], 'boolean' => [true, 10000],
            'noninteger text' => ['275ms', 10000], 'SQL text' => ["1; select 'unsafe'", 10000],
            'PostgreSQL overflow' => [3000, '2147483648'], 'equal limits' => [3000, 3000],
            'statement before lock limit' => [10000, 3000],
        ];
    }

    public function test_d05_records_duplicate_payment_without_rewriting_payment_or_order(): void
    {
        $order = $this->insertOrder();
        $first = $this->paymentBody($order);
        $this->deliverDecision($first, 'processed');
        $paid = $this->expectedPaidOrder($order);
        $payment = $this->expectedPayment($first);
        $receipt = $this->expectedReceipt($first, 'processed');
        $this->assertTables([$paid], [$payment], [$receipt]);

        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:05:01.234567 UTC'));
        $second = $this->paymentBody($order, 'evt_second', data: ['order_id' => strtoupper($order['id'])]);
        $this->deliverDecision($second, 'duplicate_payment');
        $this->assertTables([$paid], [$payment], [$receipt, $this->expectedReceipt($second, 'duplicate_payment')]);
    }

    public function test_d06_rejects_new_payment_for_paid_order(): void
    {
        $order = $this->insertOrder();
        $first = $this->paymentBody($order);
        $this->deliverDecision($first, 'processed');
        $paid = $this->expectedPaidOrder($order);
        $payment = $this->expectedPayment($first);
        $receipt = $this->expectedReceipt($first, 'processed');
        $this->assertTables([$paid], [$payment], [$receipt]);

        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:05:01.234567 UTC'));
        $second = $this->paymentBody($order, 'evt_second', 'pay_second');
        $this->deliverDecision($second, 'rejected', 'order_already_paid');
        $this->assertTables([$paid], [$payment], [$receipt, $this->expectedReceipt($second, 'rejected', 'order_already_paid')]);
    }

    public function test_d07_rejects_payment_id_bound_to_another_order(): void
    {
        $order = $this->insertOrder();
        $first = $this->paymentBody($order);
        $this->deliverDecision($first, 'processed');
        $paid = $this->expectedPaidOrder($order);
        $payment = $this->expectedPayment($first);
        $receipt = $this->expectedReceipt($first, 'processed');
        $this->assertTables([$paid], [$payment], [$receipt]);

        $pending = $this->insertOrder();
        $second = $this->paymentBody($pending, 'evt_second');
        $this->deliverDecision($second, 'rejected', 'payment_conflict');
        $this->assertTables([$paid, $pending], [$payment], [$receipt, $this->expectedReceipt($second, 'rejected', 'payment_conflict')]);
    }

    public function test_d08_rejects_missing_order(): void
    {
        $this->deliverDecision(self::BODY, 'rejected', 'order_not_found');
        $this->assertTables([], [], [$this->expectedReceipt(self::BODY, 'rejected', 'order_not_found')]);
    }

    #[DataProvider('businessViolations')]
    public function test_d09_selects_first_business_rejection(string $target, int $amount, string $currency, bool $usedPayment, string $reason): void
    {
        $owner = $this->insertOrder();
        $first = $this->paymentBody($owner);
        $this->deliverDecision($first, 'processed');
        $paid = $this->expectedPaidOrder($owner);
        $payment = $this->expectedPayment($first);
        $receipt = $this->expectedReceipt($first, 'processed');
        $this->assertTables([$paid], [$payment], [$receipt]);
        $orders = [$paid];
        if ($target === 'paid') {
            $order = $owner;
        } elseif ($target === 'missing') {
            $order = array_replace($owner, ['id' => (string) Str::uuid7()]);
        } else {
            $order = $this->insertOrder();
            $orders[] = $order;
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:05:01.234567 UTC'));
        $raw = $this->paymentBody($order, 'evt_rejected', $usedPayment ? 'pay_first' : 'pay_new', [
            'amount_minor' => $amount, 'currency' => $currency,
        ]);
        $this->deliverDecision($raw, 'rejected', $reason);
        $this->assertTables($orders, [$payment], [$receipt, $this->expectedReceipt($raw, 'rejected', $reason)]);
    }

    public static function businessViolations(): array
    {
        return [
            'D09 missing before amount currency payment' => ['missing', 1, 'USD', true, 'order_not_found'],
            'D09 amount mismatch only' => ['pending', 12991, 'EUR', false, 'amount_mismatch'],
            'D09 currency mismatch only' => ['pending', 12990, 'USD', false, 'currency_mismatch'],
            'D09 amount before currency' => ['pending', 1, 'USD', false, 'amount_mismatch'],
            'D09 amount before paid' => ['paid', 1, 'EUR', false, 'amount_mismatch'],
            'D09 currency before paid' => ['paid', 12990, 'USD', false, 'currency_mismatch'],
            'D09 amount before shared payment' => ['pending', 1, 'EUR', true, 'amount_mismatch'],
            'D09 currency before shared payment' => ['pending', 12990, 'USD', true, 'currency_mismatch'],
            'D09 amount before currency payment paid' => ['paid', 1, 'USD', true, 'amount_mismatch'],
        ];
    }

    public function test_h13_records_unknown_type_with_empty_data_as_ignored(): void
    {
        $raw = '{"id":"evt_ignored","type":"unknown.event","data":{}}';
        $this->deliverDecision($raw, 'ignored');
        $this->assertTables([], [], [$this->expectedReceipt($raw, 'ignored')]);
    }

    public function test_d13_ignores_refund_after_successful_payment(): void
    {
        $order = $this->insertOrder();
        $first = $this->paymentBody($order);
        $this->deliverDecision($first, 'processed');
        $paid = $this->expectedPaidOrder($order);
        $payment = $this->expectedPayment($first);
        $receipt = $this->expectedReceipt($first, 'processed');
        $this->assertTables([$paid], [$payment], [$receipt]);

        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:05:01.234567 UTC'));
        $refund = str_replace(['evt_first', 'payment.succeeded'], ['evt_refund', 'payment.refunded'], $first);
        $this->deliverDecision($refund, 'ignored');
        $this->assertTables([$paid], [$payment], [$receipt, $this->expectedReceipt($refund, 'ignored')]);
    }

    public function test_d14_processes_independent_orders_and_payments(): void
    {
        $firstOrder = $this->insertOrder();
        $first = $this->paymentBody($firstOrder);
        $this->deliverDecision($first, 'processed');
        $paid = $this->expectedPaidOrder($firstOrder);
        $payment = $this->expectedPayment($first);
        $receipt = $this->expectedReceipt($first, 'processed');
        $this->assertTables([$paid], [$payment], [$receipt]);

        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:05:01.234567 UTC'));
        $secondOrder = $this->insertOrder(['amount_minor' => 4500, 'currency' => 'USD']);
        $second = $this->paymentBody($secondOrder, 'evt_second', 'pay_second');
        $this->deliverDecision($second, 'processed');
        $this->assertTables([$paid, $this->expectedPaidOrder($secondOrder)], [$payment, $this->expectedPayment($second)], [
            $receipt, $this->expectedReceipt($second, 'processed'),
        ]);
    }
}

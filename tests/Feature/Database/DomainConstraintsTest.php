<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DomainRows;
use Tests\TestCase;

class DomainConstraintsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('invalidRows')]
    public function test_d15_rejects_invalid_rows(string $table, array $overrides, string $sqlstate = '23514'): void
    {
        $order = DomainRows::order();
        DB::table('orders')->insert($order);
        $row = match ($table) {
            'orders' => DomainRows::order(),
            'payments' => DomainRows::payment($order['id']),
            'webhook_receipts' => DomainRows::receipt(),
        };

        $this->assertRejected(fn () => DB::table($table)->insert(array_replace($row, $overrides)), $sqlstate);
    }

    public static function invalidRows(): iterable
    {
        foreach (['orders', 'payments'] as $table) {
            foreach ([0, -1, 1000000001] as $amount) {
                yield "D15_{$table}_amount_{$amount}" => [$table, ['amount_minor' => $amount]];
            }
            foreach (['eur', 'GBP', ''] as $currency) {
                yield "D15_{$table}_currency_{$currency}" => [$table, ['currency' => $currency]];
            }
        }

        yield 'D15_order_status' => ['orders', ['status' => 'cancelled']];
        yield 'D15_paid_without_paid_at' => ['orders', ['status' => 'paid']];
        yield 'D15_pending_with_paid_at' => ['orders', ['paid_at' => '2026-10-23 08:00:00+00']];
        yield 'D15_payment_provider' => ['payments', ['provider' => 'other']];
        yield 'D15_receipt_provider' => ['webhook_receipts', ['provider' => 'other']];

        foreach (['payments' => 'provider_payment_id', 'webhook_receipts' => 'event_id'] as $table => $column) {
            foreach (['empty' => '', 'space' => 'bad id', 'unicode' => 'é', 'newline' => "bad\n", 'dot' => 'bad.id', 'slash' => 'bad/id'] as $case => $value) {
                yield "D15_{$column}_{$case}" => [$table, [$column => $value]];
            }
            yield "D15_{$column}_too_long" => [$table, [$column => str_repeat('a', 101)], $table === 'payments' ? '22001' : '23514'];
        }

        foreach (['empty' => '', 'space' => 'bad type', 'slash' => 'bad/type', 'unicode' => 'é', 'newline' => "bad\n", 'too_long' => str_repeat('a', 101)] as $case => $value) {
            yield "D15_event_type_{$case}" => ['webhook_receipts', ['event_type' => $value]];
        }
        foreach (['short' => str_repeat('a', 63), 'long' => str_repeat('a', 65), 'uppercase' => str_repeat('A', 64), 'non_hex' => str_repeat('g', 64), 'newline' => str_repeat('a', 63)."\n"] as $case => $value) {
            yield "D15_hash_{$case}" => ['webhook_receipts', ['body_sha256' => $value]];
        }
        yield 'D15_outcome' => ['webhook_receipts', ['outcome' => 'processing']];
        foreach ([null, '', '   '] as $key => $reason) {
            yield "D15_rejected_reason_{$key}" => ['webhook_receipts', ['outcome' => 'rejected', 'reason_code' => $reason, 'http_status' => 409]];
        }
        foreach (['processed', 'duplicate_payment', 'ignored'] as $outcome) {
            yield "D15_{$outcome}_with_reason" => ['webhook_receipts', ['outcome' => $outcome, 'reason_code' => 'order_not_found']];
            yield "D15_{$outcome}_wrong_http" => ['webhook_receipts', ['outcome' => $outcome, 'http_status' => 409]];
        }
        yield 'D15_rejected_wrong_http' => ['webhook_receipts', ['outcome' => 'rejected', 'reason_code' => 'order_not_found', 'http_status' => 200]];

        $required = [
            'orders' => ['id', 'amount_minor', 'currency', 'status', 'created_at', 'updated_at'],
            'payments' => ['id', 'order_id', 'provider', 'provider_payment_id', 'amount_minor', 'currency', 'created_at'],
            'webhook_receipts' => ['id', 'provider', 'event_id', 'event_type', 'body_sha256', 'outcome', 'http_status', 'completed_at'],
        ];
        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                yield "D15_{$table}_{$column}_not_null" => [$table, [$column => null], '23502'];
            }
        }
    }

    #[DataProvider('duplicateKeys')]
    public function test_d15_rejects_duplicate_keys(string $table, array $columns): void
    {
        $first = DomainRows::order();
        $second = DomainRows::order();
        DB::table('orders')->insert([$first, $second]);
        $original = match ($table) {
            'orders' => $first,
            'payments' => DomainRows::payment($first['id']),
            'webhook_receipts' => DomainRows::receipt(),
        };
        if ($table !== 'orders') {
            DB::table($table)->insert($original);
        }
        $candidate = match ($table) {
            'orders' => DomainRows::order(),
            'payments' => DomainRows::payment($second['id']),
            'webhook_receipts' => DomainRows::receipt(),
        };
        foreach ($columns as $column) {
            $candidate[$column] = $original[$column];
        }
        $this->assertRejected(fn () => DB::table($table)->insert($candidate), '23505');
    }

    public static function duplicateKeys(): array
    {
        return [
            'D15_orders_pk' => ['orders', ['id']],
            'D15_payments_pk' => ['payments', ['id']],
            'D15_receipts_pk' => ['webhook_receipts', ['id']],
            'D15_one_payment_per_order' => ['payments', ['order_id']],
            'D15_provider_payment' => ['payments', ['provider', 'provider_payment_id']],
            'D15_provider_event' => ['webhook_receipts', ['provider', 'event_id']],
        ];
    }

    public function test_d15_rejects_missing_payment_order(): void
    {
        $this->assertRejected(fn () => DB::table('payments')->insert(DomainRows::payment((string) Str::uuid7())), '23503');
    }

    public function test_d15_restricts_deleting_order_with_payment(): void
    {
        $order = DomainRows::order();
        DB::table('orders')->insert($order);
        DB::table('payments')->insert(DomainRows::payment($order['id']));
        $this->assertRejected(fn () => DB::table('orders')->where('id', $order['id'])->delete(), '23001');
        $this->assertRejected(fn () => DB::table('orders')->where('id', $order['id'])->update(['id' => (string) Str::uuid7()]), '23001');
    }

    public function test_d15_rejects_inconsistent_status_updates(): void
    {
        $order = DomainRows::order();
        DB::table('orders')->insert($order);
        $this->assertRejected(fn () => DB::table('orders')->where('id', $order['id'])->update(['status' => 'paid']), '23514');
        $this->assertRejected(fn () => DB::table('orders')->where('id', $order['id'])->update(['paid_at' => now()]), '23514');
    }

    public function test_d15_accepts_boundary_values_and_missing_receipt_order(): void
    {
        foreach ([1 => 'EUR', 1000000000 => 'USD'] as $amount => $currency) {
            $order = DomainRows::order(['amount_minor' => $amount, 'currency' => $currency, 'status' => 'paid', 'paid_at' => '2026-10-23 08:00:00.123456+00']);
            DB::table('orders')->insert($order);
            DB::table('payments')->insert(DomainRows::payment($order['id'], ['amount_minor' => $amount, 'currency' => $currency, 'provider_payment_id' => str_repeat('a', 99).($amount === 1 ? '_' : '-')]));
        }
        foreach (['processed', 'duplicate_payment', 'ignored', 'rejected'] as $outcome) {
            DB::table('webhook_receipts')->insert(DomainRows::receipt([
                'event_id' => str_repeat('Z', 99).substr($outcome, 0, 1),
                'event_type' => str_repeat('a', 96).'._-9', 'outcome' => $outcome,
                'completed_at' => '2026-10-23 08:00:00.123456+00',
                'reason_code' => $outcome === 'rejected' ? 'order_not_found' : null,
                'http_status' => $outcome === 'rejected' ? 409 : 200,
                'order_id' => $outcome === 'rejected' ? (string) Str::uuid7() : null,
            ]));
        }
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('webhook_receipts', 4);
        $this->assertSame(['123456'], DB::table('orders')->selectRaw("to_char(paid_at, 'US') AS microseconds")->distinct()->pluck('microseconds')->all());
        $this->assertSame(['123456'], DB::table('webhook_receipts')->selectRaw("to_char(completed_at, 'US') AS microseconds")->distinct()->pluck('microseconds')->all());
    }

    private function assertRejected(Closure $write, string $sqlstate): void
    {
        $before = $this->snapshot();
        try {
            DB::transaction($write);
            $this->fail('Invalid SQL write was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlstate, $exception->errorInfo[0]);
        }
        $this->assertEquals($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        return array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->all(), ['orders', 'payments', 'webhook_receipts']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DomainRows;
use Tests\TestCase;

class DemoOrderCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_pending_order_and_prints_uuid(): void
    {
        $this->assertSame(0, Artisan::call('demo:order:create', ['amount_minor' => '12990', 'currency' => 'EUR']));
        $output = Artisan::output();
        $this->assertSame(trim($output).PHP_EOL, $output);
        $this->assertTrue(Str::isUuid(trim($output)));
        $this->assertDatabaseHas('orders', ['id' => trim($output), 'amount_minor' => 12990, 'currency' => 'EUR', 'status' => 'pending', 'paid_at' => null]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('webhook_receipts', 0);
    }

    #[DataProvider('invalidArguments')]
    public function test_rejects_invalid_order_arguments_without_writes(string $amount, string $currency): void
    {
        $this->artisan('demo:order:create', ['amount_minor' => $amount, 'currency' => $currency])->assertExitCode(2);
        foreach (['orders', 'payments', 'webhook_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public static function invalidArguments(): iterable
    {
        foreach (['0', '-1', '1000000001', str_repeat('9', 50), '1.0', '1e2', '01', ' 1', '1 ', "1\n", ''] as $amount) {
            yield 'amount_'.bin2hex($amount) => [$amount, 'EUR'];
        }
        yield 'lowercase_currency' => ['1', 'eur'];
        yield 'unknown_currency' => ['1', 'GBP'];
    }

    public function test_shows_order_payment_and_receipts(): void
    {
        $order = DomainRows::order(['status' => 'paid', 'paid_at' => '2026-10-23 08:00:00+00']);
        DB::table('orders')->insert($order);
        $payment = DomainRows::payment($order['id']);
        DB::table('payments')->insert($payment);
        $first = DomainRows::receipt(['order_id' => $order['id'], 'outcome' => 'ignored']);
        $second = DomainRows::receipt(['order_id' => $order['id'], 'outcome' => 'rejected', 'http_status' => 409, 'reason_code' => 'order_already_paid']);
        DB::table('webhook_receipts')->insert([$second, $first]);
        DB::table('webhook_receipts')->insert(DomainRows::receipt());
        $before = DB::table('orders')->first();
        DB::enableQueryLog();

        $this->assertSame(0, Artisan::call('demo:order:show', ['order_id' => $order['id']]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(3, $queries);
        $this->assertSame('paid', $data['status']);
        $this->assertSame('2026-10-23T08:00:00.000000Z', $data['paid_at']);
        $this->assertSame($payment['id'], $data['payment']['id']);
        $this->assertSame([$first['id'], $second['id']], array_column($data['receipts'], 'id'));
        $this->assertEquals($before, DB::table('orders')->first());
    }

    public function test_shows_pending_order_without_payment_or_receipts(): void
    {
        $order = DomainRows::order();
        DB::table('orders')->insert($order);
        $this->assertSame(0, Artisan::call('demo:order:show', ['order_id' => $order['id']]));
        $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('pending', $data['status']);
        $this->assertNull($data['paid_at']);
        $this->assertNull($data['payment']);
        $this->assertSame([], $data['receipts']);
    }

    public function test_reports_missing_order(): void
    {
        $this->artisan('demo:order:show', ['order_id' => (string) Str::uuid7()])->expectsOutput('Order not found.')->assertFailed();
        $this->artisan('demo:order:show', ['order_id' => 'invalid'])->expectsOutput('Invalid order_id: a UUID is required.')->assertExitCode(2);
    }
}

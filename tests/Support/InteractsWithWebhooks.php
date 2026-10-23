<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\ProcessWebhook;
use App\Data\WebhookEvent;
use App\Data\WebhookResult;
use App\Enums\WebhookOutcome;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;

trait InteractsWithWebhooks
{
    protected const string URI = '/api/webhooks/demo-pay';

    protected const string SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected const string BODY = '{"id":"evt_demo_001","type":"payment.succeeded","data":{"payment_id":"pay_demo_001","order_id":"0199a600-0000-7000-8000-000000000001","amount_minor":12990,"currency":"EUR"}}';

    protected function expectAction(?Closure $inspect = null): void
    {
        $this->mock(ProcessWebhook::class)->shouldReceive('handle')->once()
            ->with(Mockery::on(function (WebhookEvent $event) use ($inspect): bool {
                if ($inspect !== null) {
                    $inspect($event);
                }

                return true;
            }))
            ->andReturnUsing(fn (WebhookEvent $event) => new WebhookResult($event->eventId, WebhookOutcome::Processed, false));
    }

    protected function rejectAction(): void
    {
        $this->mock(ProcessWebhook::class)->shouldNotReceive('handle');
    }

    /** @return array<string, string> */
    protected function signedHeaders(string $raw, ?string $timestamp = null, string $secret = self::SECRET): array
    {
        $timestamp ??= (string) now()->getTimestamp();

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret),
        ];
    }

    /** @param array<string, mixed> $overrides */
    protected function sendRaw(string $raw = self::BODY, array $overrides = [], string $method = 'POST'): TestResponse
    {
        $server = array_filter(array_replace($this->signedHeaders($raw), $overrides), fn ($value) => $value !== null);

        return $this->call($method, self::URI, [], [], [], $server, $raw);
    }

    protected function assertRejected(TestResponse $response, int $status, string $error): void
    {
        $response->assertStatus($status)->assertExactJson(['error' => $error])->assertHeader('Content-Type', 'application/json');
        $this->assertDomainEmpty();
    }

    protected function assertDomainEmpty(): void
    {
        foreach (['orders', 'payments', 'webhook_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    protected function insertOrder(array $overrides = []): array
    {
        $row = DomainRows::order(array_replace([
            'created_at' => '2026-10-23 11:00:00.654321+00',
            'updated_at' => '2026-10-23 11:00:00.654321+00',
        ], $overrides));
        DB::table('orders')->insert($row);

        return (array) DB::table('orders')->where('id', $row['id'])->sole();
    }

    protected function paymentBody(array $order, string $eventId = 'evt_first', string $paymentId = 'pay_first', array $data = []): string
    {
        return json_encode([
            'id' => $eventId, 'type' => 'payment.succeeded',
            'data' => array_replace([
                'payment_id' => $paymentId, 'order_id' => $order['id'],
                'amount_minor' => $order['amount_minor'], 'currency' => $order['currency'],
            ], $data),
        ], JSON_THROW_ON_ERROR);
    }

    protected function deliverDecision(string $raw, string $outcome, ?string $reason = null, bool $duplicate = false): TestResponse
    {
        $body = [
            'event_id' => json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['id'],
            'outcome' => $outcome, 'duplicate' => $duplicate,
        ];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        return $this->sendRaw($raw)->assertStatus($outcome === 'rejected' ? 409 : 200)
            ->assertExactJson($body)->assertHeader('Content-Type', 'application/json');
    }

    protected function expectedReceipt(string $raw, string $outcome, ?string $reason = null): array
    {
        $event = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $id = DB::table('webhook_receipts')->where('event_id', $event['id'])->sole()->id;
        $this->assertTrue(Str::isUuid($id));

        return [
            'id' => $id, 'provider' => 'demo-pay', 'event_id' => $event['id'],
            'event_type' => $event['type'], 'body_sha256' => hash('sha256', $raw),
            'outcome' => $outcome, 'reason_code' => $reason,
            'http_status' => $outcome === 'rejected' ? 409 : 200,
            'completed_at' => now()->format('Y-m-d H:i:s.u').'+00',
            'order_id' => $event['type'] === 'payment.succeeded' ? strtolower($event['data']['order_id']) : null,
        ];
    }

    protected function expectedPayment(string $raw): array
    {
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['data'];
        $id = DB::table('payments')->where('provider_payment_id', $data['payment_id'])->sole()->id;
        $this->assertTrue(Str::isUuid($id));

        return [
            'id' => $id, 'order_id' => strtolower($data['order_id']), 'provider' => 'demo-pay',
            'provider_payment_id' => $data['payment_id'], 'amount_minor' => $data['amount_minor'],
            'currency' => $data['currency'], 'created_at' => now()->format('Y-m-d H:i:s.u').'+00',
        ];
    }

    protected function expectedPaidOrder(array $order): array
    {
        return array_replace($order, [
            'status' => 'paid', 'paid_at' => now()->format('Y-m-d H:i:s.u').'+00',
            'updated_at' => now()->format('Y-m-d H:i:s.u').'+00',
        ]);
    }

    protected function domainState(): array
    {
        $state = [];
        foreach (['orders', 'payments', 'webhook_receipts'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    protected function assertTables(array $orders, array $payments, array $receipts): void
    {
        $expected = ['orders' => $orders, 'payments' => $payments, 'webhook_receipts' => $receipts];
        $actual = $this->domainState();
        foreach ($expected as $table => &$rows) {
            usort($rows, fn ($a, $b) => strcmp($a['id'], $b['id']));
            foreach ($rows as &$row) {
                ksort($row);
            }
            unset($row);
            foreach ($actual[$table] as &$row) {
                ksort($row);
            }
            unset($row);
        }
        unset($rows);
        $this->assertSame($expected, $actual);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\ProcessWebhook;
use App\Data\WebhookEvent;
use App\Data\WebhookResult;
use App\Enums\WebhookOutcome;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;

trait SignedWebhookRequests
{
    use RefreshDatabase;

    protected const string URI = '/api/webhooks/demo-pay';

    protected const string SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected const string BODY = '{"id":"evt_demo_001","type":"payment.succeeded","data":{"payment_id":"pay_demo_001","order_id":"0199a600-0000-7000-8000-000000000001","amount_minor":12990,"currency":"EUR"}}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00', 'UTC'));
        config(['services.demo_pay.webhook_secret' => self::SECRET, 'app.debug' => false]);
    }

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
}

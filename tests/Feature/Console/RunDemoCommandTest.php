<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\ProcessWebhook;
use App\Console\Commands\RunDemoCommand;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookReceipt;
use App\Services\SendDemoWebhook;
use App\Validation\DecodeWebhook;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class RunDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'public-demo-test-secret';

    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.demo_pay.webhook_secret' => self::SECRET]);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', 'testing');
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_p04_checks_all_expected_responses_and_order_states(): void
    {
        $this->fakeReceiver();
        [$exit, $output] = $this->runDemo();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Demo checks passed.', $output);
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('webhook_receipts', 1);
        Http::assertSentCount(4);
        $this->assertSame($this->bodies[0], $this->bodies[1]);
        $this->assertSame($this->bodies[0].' ', $this->bodies[3]);
        $this->assertSame(self::SECRET, config('services.demo_pay.webhook_secret'));
        Sleep::assertNeverSlept();
    }

    #[DataProvider('mismatches')]
    public function test_p04_fails_on_response_or_state_mismatch(string $fault, string $check): void
    {
        $this->fakeReceiver($fault);
        [$exit, $output] = $this->runDemo();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Demo failed: '.$check.'.', $output);
        $this->assertStringNotContainsString('LEAK_MARKER', $output);
        $this->assertSame(self::SECRET, config('services.demo_pay.webhook_secret'));
    }

    public static function mismatches(): array
    {
        return [
            ['status', 'success_response'], ['outcome', 'success_response'], ['duplicate', 'success_response'],
            ['id', 'success_response'], ['html', 'success_response'], ['exception', 'success_response'],
            ['missing_state', 'success_state'], ['amount', 'success_state'], ['hash', 'success_state'],
            ['replay_status', 'replay_response'], ['replay_duplicate', 'replay_response'],
            ['timestamp', 'replay_state'], ['payment', 'replay_state'], ['receipt', 'replay_state'],
            ['signature_error', 'signature_response'], ['signature_exception', 'signature_response'],
            ['signature_state', 'signature_state'], ['orphan_receipt', 'signature_state'], ['conflict_error', 'conflict_response'],
            ['conflict_state', 'conflict_state'], ['network', 'success_response'],
        ];
    }

    public function test_p04_uses_fresh_ids_without_resetting_existing_data(): void
    {
        $this->fakeReceiver();
        $this->assertSame(0, $this->runDemo()[0]);
        $first = Order::orderBy('id')->get()->toArray();
        $paymentIds = Payment::pluck('provider_payment_id')->all();
        $eventIds = WebhookReceipt::pluck('event_id')->all();
        $this->assertSame(0, $this->runDemo()[0]);
        $this->assertSame($first, Order::whereIn('id', array_column($first, 'id'))->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('orders', 4);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('webhook_receipts', 2);
        $this->assertCount(1, array_diff(Payment::pluck('provider_payment_id')->all(), $paymentIds));
        $this->assertCount(1, array_diff(WebhookReceipt::pluck('event_id')->all(), $eventIds));
    }

    public function test_p04_rejects_unsafe_environment_and_test_database(): void
    {
        Http::fake();
        $this->assertSame(1, Artisan::call('demo:run'));
        $this->assertStringContainsString('environment', Artisan::output());
        $this->app->instance('env', 'local');
        $this->assertSame(1, Artisan::call('demo:run'));
        $this->assertStringContainsString('demo_database', Artisan::output());
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    private function runDemo(): array
    {
        $this->app->instance('env', 'local');
        // Only the connection guard is replaced; all writes stay on the guarded test connection.
        $command = new class extends RunDemoCommand
        {
            protected function isDemoDatabase(): bool
            {
                return DB::connection()->getDatabaseName() === 'webhooks_test';
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $exit = $command->handle(app(SendDemoWebhook::class));

        return [$exit, $output->fetch()];
    }

    private function fakeReceiver(string $fault = ''): void
    {
        $attempt = 0;
        Http::fake(function (Request $request) use ($fault, &$attempt) {
            $step = ++$attempt;
            $raw = $request->body();
            $this->bodies[] = $raw;
            if ($fault === 'network') {
                return Http::failedConnection('LEAK_MARKER')($request);
            }
            if (($step === 1 && $fault === 'exception') || ($step === 3 && $fault === 'signature_exception')) {
                throw new RuntimeException('LEAK_MARKER');
            }
            $timestamp = $request->header('X-Webhook-Timestamp')[0];
            $valid = $request->header('X-Webhook-Signature')[0] === 'v1='.hash_hmac('sha256', $timestamp.'.'.$raw, self::SECRET);
            if (! $valid) {
                $this->assertNotSame(self::SECRET, config('services.demo_pay.webhook_secret'));
                if ($fault === 'signature_state') {
                    Order::whereKey(json_decode($raw, true)['data']['order_id'])->update(['updated_at' => now()->addDay()]);
                }

                if ($fault === 'orphan_receipt') {
                    WebhookReceipt::create(['provider' => 'demo-pay', 'event_id' => json_decode($raw, true)['id'],
                        'event_type' => 'payment.succeeded', 'body_sha256' => hash('sha256', $raw),
                        'outcome' => 'ignored', 'http_status' => 200, 'completed_at' => now()]);
                }

                return Http::response(['error' => $fault === 'signature_error' ? 'internal_error' : 'invalid_signature'], 401);
            }
            if ($step === 1 && $fault === 'html') {
                return Http::response('<html>LEAK_MARKER</html>');
            }
            if ($step === 1 && $fault === 'missing_state') {
                return Http::response(['event_id' => json_decode($raw, true)['id'], 'outcome' => 'processed', 'duplicate' => false]);
            }
            try {
                $result = app(ProcessWebhook::class)->handle(app(DecodeWebhook::class)->handle($raw));
            } catch (ConflictHttpException) {
                if ($fault === 'conflict_state') {
                    WebhookReceipt::query()->update(['completed_at' => now()->addDay()]);
                }

                return Http::response(['error' => $fault === 'conflict_error' ? 'internal_error' : 'event_conflict'], 409);
            }
            $body = ['event_id' => $result->eventId, 'outcome' => $result->outcome->value, 'duplicate' => $result->duplicate];
            if ($step === 1) {
                match ($fault) {
                    'outcome' => $body['outcome'] = 'ignored',
                    'duplicate' => $body['duplicate'] = true,
                    'id' => $body['event_id'] = 'evt_wrong',
                    'amount' => Payment::query()->update(['amount_minor' => 1]),
                    'hash' => WebhookReceipt::query()->update(['body_sha256' => str_repeat('0', 64)]),
                    default => null,
                };
            }
            if ($step === 2) {
                match ($fault) {
                    'replay_duplicate' => $body['duplicate'] = false,
                    'timestamp' => Order::where('status', 'paid')->update(['paid_at' => now()->addDay()]),
                    'payment' => Payment::query()->update(['provider_payment_id' => 'pay_changed']),
                    'receipt' => WebhookReceipt::query()->update(['completed_at' => now()->addDay()]),
                    default => null,
                };
            }

            return Http::response($body, ($step === 1 && $fault === 'status') || ($step === 2 && $fault === 'replay_status') ? 201 : 200);
        });
    }
}

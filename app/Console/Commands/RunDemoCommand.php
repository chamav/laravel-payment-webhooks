<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Currency;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookReceipt;
use App\Services\SendDemoWebhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RunDemoCommand extends Command
{
    protected $signature = 'demo:run';

    protected $description = 'Check signed HTTP deliveries and database state using fresh demo orders';

    public function handle(SendDemoWebhook $sender): int
    {
        $check = 'environment';
        Log::debug('Demo checks started.');
        try {
            $this->check(app()->environment('local'), $check);
            $check = 'demo_database';
            $this->check($this->isDemoDatabase(), $check);

            $check = 'create_orders';
            $a = Order::create(['amount_minor' => 12990, 'currency' => Currency::EUR, 'status' => OrderStatus::Pending]);
            $b = Order::create(['amount_minor' => 12990, 'currency' => Currency::EUR, 'status' => OrderStatus::Pending]);
            $eventId = 'evt_'.Str::uuid7();
            $paymentId = 'pay_'.Str::uuid7();
            $badEventId = 'evt_'.Str::uuid7();
            $badPaymentId = 'pay_'.Str::uuid7();
            $raw = $this->body($a->id, $eventId, $paymentId);
            $initialA = $this->state($a->id, $eventId, $paymentId);
            $initialB = $this->state($b->id, $badEventId, $badPaymentId);
            $this->line('Orders: A='.$a->id.'; B='.$b->id.'.');
            $this->line('Event: '.$eventId.'; payment: '.$paymentId.'.');

            $check = 'success_response';
            $result = $sender->send($raw);
            $this->check($result->httpStatus === 200 && $result->successful() && $result->attempts === 1
                && $result->responseSummary === ['event_id' => $eventId, 'outcome' => 'processed', 'duplicate' => false], $check);
            $check = 'success_state';
            $paid = $this->state($a->id, $eventId, $paymentId);
            $this->checkPaid($paid, $initialA, $eventId, $paymentId, $raw, $check);
            $this->check($this->state($b->id, $badEventId, $badPaymentId) === $initialB, $check);
            $this->line('success: HTTP 200; '.$this->summary($result->responseSummary).'; state checked.');

            $check = 'replay_response';
            $result = $sender->send($raw);
            $this->check($result->httpStatus === 200 && $result->successful() && $result->attempts === 1
                && $result->responseSummary === ['event_id' => $eventId, 'outcome' => 'processed', 'duplicate' => true], $check);
            $check = 'replay_state';
            $this->check($this->state($a->id, $eventId, $paymentId) === $paid && $this->state($b->id, $badEventId, $badPaymentId) === $initialB, $check);
            $this->line('replay: HTTP 200; '.$this->summary($result->responseSummary).'; state unchanged.');

            $check = 'signature_response';
            $secret = config('services.demo_pay.webhook_secret');
            try {
                config(['services.demo_pay.webhook_secret' => bin2hex(random_bytes(32))]);
                $result = $sender->send($this->body($b->id, $badEventId, $badPaymentId));
            } finally {
                config(['services.demo_pay.webhook_secret' => $secret]);
            }
            $this->check($result->httpStatus === 401 && ! $result->successful() && $result->attempts === 1
                && $result->responseSummary === ['error' => 'invalid_signature'], $check);
            $check = 'signature_state';
            $this->check($this->state($b->id, $badEventId, $badPaymentId) === $initialB && $this->state($a->id, $eventId, $paymentId) === $paid, $check);
            $this->line('signature: HTTP 401; {"error":"invalid_signature"}; state unchanged.');

            $check = 'conflict_response';
            // An extra space keeps JSON valid but changes the byte hash under the same event ID.
            $result = $sender->send($raw.' ');
            $this->check($result->httpStatus === 409 && ! $result->successful() && $result->attempts === 1
                && $result->responseSummary === ['error' => 'event_conflict'], $check);
            $check = 'conflict_state';
            $this->check($this->state($a->id, $eventId, $paymentId) === $paid && $this->state($b->id, $badEventId, $badPaymentId) === $initialB, $check);
            $this->line('conflict: HTTP 409; {"error":"event_conflict"}; state unchanged.');
            $this->line('A: paid; payments=1; receipts=1. B: pending; payments=0; receipts=0.');
            $this->info('Demo checks passed.');
            Log::debug('Demo checks completed.');

            return self::SUCCESS;
        } catch (Throwable) {
            Log::error('Demo check failed.', ['check' => $check]);
            $this->output->getErrorStyle()->writeln('Demo failed: '.$check.'.');

            return self::FAILURE;
        }
    }

    protected function isDemoDatabase(): bool
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();

        return $connection->getConfig('driver') === 'pgsql' && $connection->getConfig('host') === 'db'
            && $database !== '' && $database !== 'webhooks_test'
            && ($connection->selectOne('SELECT current_database() AS name', [], false)->name ?? null) === $database;
    }

    private function body(string $orderId, string $eventId, string $paymentId): string
    {
        return json_encode(['id' => $eventId, 'type' => 'payment.succeeded', 'data' => [
            'payment_id' => $paymentId, 'order_id' => $orderId, 'amount_minor' => 12990, 'currency' => 'EUR',
        ]], JSON_THROW_ON_ERROR);
    }

    /** @return array{order: array<string, mixed>, payments: array<int, array<string, mixed>>, receipts: array<int, array<string, mixed>>} */
    private function state(string $orderId, string $eventId, string $paymentId): array
    {
        return [
            'order' => Order::query()->findOrFail($orderId)->getAttributes(),
            'payments' => Payment::query()->where(fn ($query) => $query->where('order_id', $orderId)->orWhere('provider_payment_id', $paymentId))->orderBy('id')->get()->map(fn (Payment $row): array => $row->getAttributes())->all(),
            'receipts' => WebhookReceipt::query()->where(fn ($query) => $query->where('order_id', $orderId)->orWhere('event_id', $eventId))->orderBy('id')->get()->map(fn (WebhookReceipt $row): array => $row->getAttributes())->all(),
        ];
    }

    /** @param array{order: array<string, mixed>, payments: array<int, array<string, mixed>>, receipts: array<int, array<string, mixed>>} $state
     * @param  array{order: array<string, mixed>, payments: array<int, array<string, mixed>>, receipts: array<int, array<string, mixed>>}  $initial
     */
    private function checkPaid(array $state, array $initial, string $eventId, string $paymentId, string $raw, string $check): void
    {
        $order = $state['order'];
        $payment = $state['payments'][0] ?? [];
        $receipt = $state['receipts'][0] ?? [];
        $this->check(count($state['payments']) === 1 && count($state['receipts']) === 1
            && $order['status'] === 'paid' && $order['paid_at'] !== null
            && array_diff_key($order, array_flip(['status', 'paid_at', 'updated_at'])) === array_diff_key($initial['order'], array_flip(['status', 'paid_at', 'updated_at']))
            && ($payment['order_id'] ?? null) === $order['id']
            && ($payment['provider'] ?? null) === 'demo-pay' && ($payment['provider_payment_id'] ?? null) === $paymentId
            && ($payment['amount_minor'] ?? null) === 12990 && ($payment['currency'] ?? null) === 'EUR'
            && ($payment['created_at'] ?? null) !== null
            && ($receipt['order_id'] ?? null) === $order['id']
            && ($receipt['event_id'] ?? null) === $eventId && ($receipt['event_type'] ?? null) === 'payment.succeeded'
            && ($receipt['provider'] ?? null) === 'demo-pay' && ($receipt['body_sha256'] ?? null) === hash('sha256', $raw)
            && ($receipt['outcome'] ?? null) === 'processed' && ($receipt['http_status'] ?? null) === 200
            && ($receipt['reason_code'] ?? null) === null && ($receipt['completed_at'] ?? null) !== null, $check);
    }

    private function check(bool $condition, string $name): void
    {
        if (! $condition) {
            throw new RuntimeException($name);
        }
    }

    /** @param array<string, bool|string>|null $summary */
    private function summary(?array $summary): string
    {
        return json_encode($summary, JSON_THROW_ON_ERROR);
    }
}

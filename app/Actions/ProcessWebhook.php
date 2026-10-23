<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\WebhookEvent;
use App\Data\WebhookResult;
use App\Enums\OrderStatus;
use App\Enums\WebhookOutcome;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ProcessWebhook
{
    private const string PROVIDER = 'demo-pay';

    public function handle(WebhookEvent $event): WebhookResult
    {
        $lockTimeout = $this->timeout('lock_timeout_ms');
        $statementTimeout = $this->timeout('statement_timeout_ms');
        if ($statementTimeout <= $lockTimeout) {
            throw new LogicException('Statement timeout must exceed lock timeout.');
        }

        return DB::transaction(function () use ($event, $lockTimeout, $statementTimeout): WebhookResult {
            DB::statement("select set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)", [
                $lockTimeout.'ms', $statementTimeout.'ms',
            ]);

            $receiptId = (string) Str::uuid7();
            // This schema-valid claim is private to this transaction; every owner path finalizes it.
            $claimed = DB::table('webhook_receipts')->insertOrIgnoreReturning([
                'id' => $receiptId,
                'provider' => self::PROVIDER,
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'body_sha256' => $event->bodySha256,
                'outcome' => WebhookOutcome::Ignored->value,
                'reason_code' => null,
                'http_status' => 200,
                'completed_at' => now()->format('Y-m-d H:i:s.uP'),
                'order_id' => null,
            ], ['id'], ['provider', 'event_id']);

            if ($claimed->isEmpty()) {
                // A new statement sees the committed winner under Read Committed.
                $receipt = WebhookReceipt::query()->where('provider', self::PROVIDER)
                    ->where('event_id', $event->eventId)->first();
                if ($receipt === null) {
                    throw new LogicException('Receipt conflict winner is missing.');
                }

                return $this->replay($event, $receipt);
            }

            $result = $event->eventType === 'payment.succeeded'
                ? $this->processPayment($event)
                : new WebhookResult($event->eventId, WebhookOutcome::Ignored, false);

            return $this->finalize($receiptId, $event, $result);
        }, 1);
    }

    private function timeout(string $key): int
    {
        $value = config('webhooks.'.$key);
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
            throw new LogicException('Webhook timeouts must be positive integer milliseconds.');
        }
        $timeout = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if ($timeout === false) {
            throw new LogicException('Webhook timeout exceeds the PostgreSQL range.');
        }

        return $timeout;
    }

    private function replay(WebhookEvent $event, WebhookReceipt $receipt): WebhookResult
    {
        if ($receipt->body_sha256 !== $event->bodySha256) {
            throw new ConflictHttpException;
        }

        return new WebhookResult($event->eventId, $receipt->outcome, true, $receipt->http_status, $receipt->reason_code);
    }

    private function processPayment(WebhookEvent $event): WebhookResult
    {
        $payment = $event->payment ?? throw new LogicException('Succeeded event has no payment.');
        // Global acquisition order: receipt slot -> target order -> payment unique key.
        $order = Order::query()->whereKey($payment->orderId)->lockForUpdate()->first();
        if ($order === null) {
            return $this->reject($event, 'order_not_found');
        }
        if ($order->amount_minor !== $payment->amountMinor) {
            return $this->reject($event, 'amount_mismatch');
        }
        if ($order->currency !== $payment->currency) {
            return $this->reject($event, 'currency_mismatch');
        }

        $existing = $this->findPayment($payment->paymentId);
        if ($existing !== null) {
            return $this->classifyPayment($event, $order, $existing);
        }
        if ($order->status === OrderStatus::Paid) {
            return $this->reject($event, 'order_already_paid');
        }

        $inserted = DB::table('payments')->insertOrIgnoreReturning([
            'id' => (string) Str::uuid7(),
            'order_id' => $order->id,
            'provider' => self::PROVIDER,
            'provider_payment_id' => $payment->paymentId,
            'amount_minor' => $payment->amountMinor,
            'currency' => $payment->currency->value,
            'created_at' => now()->format('Y-m-d H:i:s.uP'),
        ], ['id'], ['provider', 'provider_payment_id']);
        if ($inserted->isEmpty()) {
            // Another order may have won this payment ID since our preliminary read.
            $winner = $this->findPayment($payment->paymentId)
                ?? throw new LogicException('Payment conflict winner is missing.');

            return $this->classifyPayment($event, $order, $winner);
        }

        $order->fill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

        return new WebhookResult($event->eventId, WebhookOutcome::Processed, false);
    }

    private function findPayment(string $paymentId): ?Payment
    {
        return Payment::query()->where('provider', self::PROVIDER)
            ->where('provider_payment_id', $paymentId)->first();
    }

    private function classifyPayment(WebhookEvent $event, Order $order, Payment $existing): WebhookResult
    {
        if ($existing->order_id !== $order->id || $existing->amount_minor !== $order->amount_minor || $existing->currency !== $order->currency) {
            return $this->reject($event, 'payment_conflict');
        }

        return new WebhookResult($event->eventId, WebhookOutcome::DuplicatePayment, false);
    }

    private function reject(WebhookEvent $event, string $reason): WebhookResult
    {
        return new WebhookResult($event->eventId, WebhookOutcome::Rejected, false, 409, $reason);
    }

    private function finalize(string $receiptId, WebhookEvent $event, WebhookResult $result): WebhookResult
    {
        $updated = DB::table('webhook_receipts')->where('id', $receiptId)->update([
            'outcome' => $result->outcome->value,
            'reason_code' => $result->reason,
            'http_status' => $result->httpStatus,
            'completed_at' => now()->format('Y-m-d H:i:s.uP'),
            'order_id' => $event->eventType === 'payment.succeeded' ? $event->payment?->orderId : null,
        ]);
        if ($updated !== 1) {
            throw new LogicException('Owned receipt could not be finalized.');
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Validation;

use App\Data\SucceededPayment;
use App\Data\WebhookEvent;
use App\Enums\Currency;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class DecodeWebhook
{
    public function handle(string $rawBody): WebhookEvent
    {
        try {
            $decoded = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException;
        }

        if (! $decoded instanceof stdClass || ! ($decoded->data ?? null) instanceof stdClass) {
            throw ValidationException::withMessages(['envelope' => 'Expected JSON objects.']);
        }

        $envelope = Validator::make(get_object_vars($decoded), [
            'id' => ['bail', 'required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,100}\z/'],
            'type' => ['bail', 'required', 'string', 'regex:/\A[A-Za-z0-9._-]{1,100}\z/'],
        ])->validate();

        return new WebhookEvent(
            eventId: (string) $envelope['id'],
            eventType: (string) $envelope['type'],
            bodySha256: hash('sha256', $rawBody),
            payment: $envelope['type'] === 'payment.succeeded' ? $this->payment($decoded->data) : null,
        );
    }

    private function payment(stdClass $data): SucceededPayment
    {
        $validated = Validator::make(get_object_vars($data), [
            'payment_id' => ['bail', 'required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,100}\z/'],
            'order_id' => ['bail', 'required', 'string', 'uuid'],
            'amount_minor' => ['bail', 'required', 'integer:strict', 'min:1', 'max:1000000000'],
            'currency' => ['bail', 'required', 'string', Rule::enum(Currency::class)],
        ])->validate();

        return new SucceededPayment(
            paymentId: (string) $validated['payment_id'],
            orderId: (string) $validated['order_id'],
            amountMinor: (int) $validated['amount_minor'],
            currency: Currency::from($validated['currency']),
        );
    }
}

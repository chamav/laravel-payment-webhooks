<?php

declare(strict_types=1);

namespace App\Data;

final readonly class WebhookEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $bodySha256,
        public ?SucceededPayment $payment,
    ) {}
}

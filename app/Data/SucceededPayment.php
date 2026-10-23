<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Currency;

final readonly class SucceededPayment
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public int $amountMinor,
        public Currency $currency,
    ) {}
}

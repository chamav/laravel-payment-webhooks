<?php

declare(strict_types=1);

namespace App\Data;

final readonly class WebhookDeliveryResult
{
    public function __construct(
        public int $attempts,
        public ?int $httpStatus,
        public ?string $errorCode,
    ) {}

    public function successful(): bool
    {
        return $this->httpStatus !== null && $this->httpStatus >= 200 && $this->httpStatus < 300;
    }
}

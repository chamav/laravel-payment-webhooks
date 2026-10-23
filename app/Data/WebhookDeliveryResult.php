<?php

declare(strict_types=1);

namespace App\Data;

final readonly class WebhookDeliveryResult
{
    /** @param array<string, bool|string>|null $responseSummary */
    public function __construct(
        public int $attempts,
        public ?int $httpStatus,
        public ?string $errorCode,
        public ?array $responseSummary = null,
    ) {}

    public function successful(): bool
    {
        return $this->httpStatus !== null && $this->httpStatus >= 200 && $this->httpStatus < 300;
    }
}

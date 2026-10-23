<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\WebhookOutcome;

final readonly class WebhookResult
{
    public function __construct(
        public string $eventId,
        public WebhookOutcome $outcome,
        public bool $duplicate,
    ) {}
}

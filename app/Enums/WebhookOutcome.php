<?php

declare(strict_types=1);

namespace App\Enums;

enum WebhookOutcome: string
{
    case Processed = 'processed';
    case DuplicatePayment = 'duplicate_payment';
    case Ignored = 'ignored';
    case Rejected = 'rejected';
}

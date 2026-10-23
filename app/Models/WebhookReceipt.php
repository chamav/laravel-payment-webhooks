<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'provider', 'event_id', 'event_type', 'body_sha256', 'outcome', 'reason_code', 'http_status', 'completed_at', 'order_id'])]
class WebhookReceipt extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'outcome' => WebhookOutcome::class,
            'http_status' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }
}

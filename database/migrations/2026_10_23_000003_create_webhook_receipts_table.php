<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('provider');
            $table->text('event_id');
            $table->text('event_type');
            $table->text('body_sha256');
            $table->text('outcome');
            $table->text('reason_code')->nullable();
            $table->smallInteger('http_status');
            $table->timestampTz('completed_at')->precision(null);
            $table->uuid('order_id')->nullable()->index();
            $table->unique(['provider', 'event_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE webhook_receipts
                ADD CONSTRAINT webhook_receipts_provider_check CHECK (provider = 'demo-pay'),
                ADD CONSTRAINT webhook_receipts_event_id_check CHECK (event_id COLLATE "C" ~ '^[A-Za-z0-9_-]{1,100}$'),
                ADD CONSTRAINT webhook_receipts_event_type_check CHECK (event_type COLLATE "C" ~ '^[A-Za-z0-9._-]{1,100}$'),
                ADD CONSTRAINT webhook_receipts_body_sha256_check CHECK (body_sha256 COLLATE "C" ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT webhook_receipts_outcome_check CHECK (outcome IN ('processed', 'duplicate_payment', 'ignored', 'rejected')),
                ADD CONSTRAINT webhook_receipts_reason_code_check CHECK (
                    (outcome = 'rejected' AND reason_code IS NOT NULL AND length(btrim(reason_code)) > 0)
                    OR (outcome <> 'rejected' AND reason_code IS NULL)
                ),
                ADD CONSTRAINT webhook_receipts_http_status_check CHECK (
                    (outcome = 'rejected' AND http_status = 409)
                    OR (outcome <> 'rejected' AND http_status = 200)
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
    }
};

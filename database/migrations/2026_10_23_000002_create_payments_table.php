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
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained('orders')->restrictOnDelete()->restrictOnUpdate();
            $table->text('provider');
            $table->string('provider_payment_id', 100);
            $table->integer('amount_minor');
            $table->text('currency');
            $table->timestampTz('created_at')->precision(null);
            $table->unique(['provider', 'provider_payment_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_provider_check CHECK (provider = 'demo-pay'),
                ADD CONSTRAINT payments_provider_payment_id_check CHECK (provider_payment_id COLLATE "C" ~ '^[A-Za-z0-9_-]{1,100}$'),
                ADD CONSTRAINT payments_amount_minor_check CHECK (amount_minor BETWEEN 1 AND 1000000000),
                ADD CONSTRAINT payments_currency_check CHECK (currency IN ('EUR', 'USD'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

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
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->integer('amount_minor');
            $table->text('currency');
            $table->text('status');
            $table->timestampTz('paid_at')->precision(null)->nullable();
            $table->timestampTz('created_at')->precision(null);
            $table->timestampTz('updated_at')->precision(null);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_amount_minor_check CHECK (amount_minor BETWEEN 1 AND 1000000000),
                ADD CONSTRAINT orders_currency_check CHECK (currency IN ('EUR', 'USD')),
                ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'paid')),
                ADD CONSTRAINT orders_paid_at_check CHECK ((status = 'paid') = (paid_at IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

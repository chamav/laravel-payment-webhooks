<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DomainRows;
use Tests\TestCase;

class MigrationRoundTripTest extends TestCase
{
    public function test_d15_migrate_rollback_migrate_restores_domain_constraints(): void
    {
        $paths = [
            'database/migrations/2026_10_23_000001_create_orders_table.php',
            'database/migrations/2026_10_23_000002_create_payments_table.php',
            'database/migrations/2026_10_23_000003_create_webhook_receipts_table.php',
        ];
        $sentinel = 'migration_roundtrip_sentinel';

        try {
            $this->migrateSafely();
            DB::table('cache')->insert(['key' => $sentinel, 'value' => 'preserved', 'expiration' => 2000000000]);
            $skeleton = DB::table('migrations')->whereNotIn('migration', array_map(fn (string $path) => basename($path, '.php'), $paths))->orderBy('id')->get();

            $this->guardTestDatabase();
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $paths, '--step' => DB::table('migrations')->count(), '--force' => true]));
            foreach (['orders', 'payments', 'webhook_receipts'] as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $this->assertSame('preserved', DB::table('cache')->where('key', $sentinel)->value('value'));
            $this->assertEquals($skeleton, DB::table('migrations')->orderBy('id')->get());

            $this->migrateSafely();
            $counts = DB::select(<<<'SQL'
                SELECT conrelid::regclass::text AS name, count(*) AS checks
                FROM pg_constraint WHERE contype = 'c' AND conrelid IN ('orders'::regclass, 'payments'::regclass, 'webhook_receipts'::regclass)
                GROUP BY conrelid ORDER BY name
                SQL);
            $this->assertSame(['orders' => 4, 'payments' => 4, 'webhook_receipts' => 7], array_column($counts, 'checks', 'name'));
            $this->assertSame(0, DB::table('pg_constraint')->where('contype', 'f')->whereRaw("conrelid = 'webhook_receipts'::regclass")->count());
            $timestamps = DB::select(<<<'SQL'
                SELECT format_type(atttypid, atttypmod) AS type
                FROM pg_attribute
                WHERE attrelid IN ('orders'::regclass, 'payments'::regclass, 'webhook_receipts'::regclass)
                    AND attname IN ('paid_at', 'created_at', 'updated_at', 'completed_at') AND NOT attisdropped
                SQL);
            $this->assertCount(5, $timestamps);
            $this->assertSame(['timestamp with time zone'], array_values(array_unique(array_column($timestamps, 'type'))));

            try {
                DB::transaction(fn () => DB::table('orders')->insert(DomainRows::order(['amount_minor' => 0])));
                $this->fail('Restored CHECK was not enforced.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
        } finally {
            $this->migrateSafely();
            $this->guardTestDatabase();
            DB::table('cache')->where('key', $sentinel)->delete();
            RefreshDatabaseState::$migrated = false;
        }
    }

    private function migrateSafely(): void
    {
        $this->guardTestDatabase();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }
}

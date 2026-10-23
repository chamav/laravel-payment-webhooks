<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\ResetDemoData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DomainRows;
use Tests\TestCase;

class ResetDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_only_domain_rows_and_can_repeat(): void
    {
        $this->seedDomain();
        $userId = DB::table('users')->insertGetId(['name' => 'Reset sentinel', 'email' => 'reset@example.test', 'password' => 'unused']);
        $migrations = DB::table('migrations')->orderBy('id')->get();
        $this->guardTestDatabase();

        $counts = (new ResetDemoData)->handle(DB::connection());

        $this->assertSame(['webhook_receipts' => 1, 'payments' => 1, 'orders' => 1], $counts);
        foreach (array_keys($counts) as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('users', ['id' => $userId, 'email' => 'reset@example.test']);
        $this->assertEquals($migrations, DB::table('migrations')->orderBy('id')->get());
        $this->guardTestDatabase();
        $this->assertSame(['webhook_receipts' => 0, 'payments' => 0, 'orders' => 0], (new ResetDemoData)->handle(DB::connection()));
    }

    public function test_rolls_back_first_delete_when_later_delete_fails(): void
    {
        $this->seedDomain();
        $before = array_map(fn (string $table) => DB::table($table)->get()->all(), ['orders', 'payments', 'webhook_receipts']);
        $this->guardTestDatabase();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION pg_temp.fail_payment_delete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM webhook_receipts) THEN
                    RAISE EXCEPTION 'test_receipts_not_deleted' USING ERRCODE = 'P0002';
                END IF;
                RAISE EXCEPTION 'test_delete_failure' USING ERRCODE = 'P0001';
            END;
            $$;
            CREATE TRIGGER fail_payment_delete BEFORE DELETE ON payments
            FOR EACH STATEMENT EXECUTE FUNCTION pg_temp.fail_payment_delete();
            SQL);

        try {
            try {
                $this->guardTestDatabase();
                (new ResetDemoData)->handle(DB::connection());
                $this->fail('The injected delete failure did not occur.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->errorInfo[0]);
            }
            $this->assertEquals($before, array_map(fn (string $table) => DB::table($table)->get()->all(), ['orders', 'payments', 'webhook_receipts']));
        } finally {
            $this->guardTestDatabase();
            DB::unprepared('DROP TRIGGER IF EXISTS fail_payment_delete ON payments; DROP FUNCTION IF EXISTS pg_temp.fail_payment_delete();');
        }
    }

    private function seedDomain(): void
    {
        $order = DomainRows::order();
        DB::table('orders')->insert($order);
        DB::table('payments')->insert(DomainRows::payment($order['id']));
        DB::table('webhook_receipts')->insert(DomainRows::receipt(['order_id' => $order['id']]));
    }
}

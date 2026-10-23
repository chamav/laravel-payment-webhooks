<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use PDOException;
use Tests\Support\CommittedWebhookTestCase;

class DemoPayFailureHandlingTest extends CommittedWebhookTestCase
{
    public function test_r01_r02_rolls_back_all_writes_then_processes_same_event(): void
    {
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $fault = $this->fault('P0001');
        $observed = [];
        Log::getLogger()->pushProcessor(function (LogRecord $record) use (&$observed): LogRecord {
            $observed[] = [$this->domainState(), DB::transactionLevel()];

            return $record;
        });
        try {
            $this->sendRaw($raw)->assertStatus(500)->assertExactJson(['error' => 'internal_error']);
            $this->assertSame(1, $fault->attempts());
            $this->assertTables([$order], [], []);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame([[$this->domainState(), 0]], $observed);
            $this->assertFailureLog('P0001', 500);
        } finally {
            $fault->drop();
        }

        $this->logs->clear();
        $observed = [];
        $this->deliverDecision($raw, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $this->assertSame([[$this->domainState(), 0]], $observed);
        $this->assertCount(1, $this->logs->getRecords());
        $this->assertSame('processed', $this->logs->getRecords()[0]->context['outcome']);
    }

    public function test_r03_returns_503_for_real_refused_connection_and_recovers(): void
    {
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $original = config('database.connections.pgsql');
        try {
            // Only this PHPUnit process is redirected; both PostgreSQL servers remain running.
            DB::purge('pgsql');
            config(['database.connections.pgsql' => array_replace($original, [
                'url' => null, 'host' => '127.0.0.1', 'port' => '1', 'connect_timeout' => 1,
            ])]);
            $response = $this->sendRaw($raw)->assertStatus(503)->assertExactJson(['error' => 'temporarily_unavailable']);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFailureLog('08006');
            $this->assertSame(PDOException::class, $this->logs->getRecords()[0]->context['exception_class']);
            $visible = $response->getContent().json_encode($this->logs->getRecords()[0]->toArray(), JSON_THROW_ON_ERROR);
            foreach (['127.0.0.1', 'webhooks_test', 'Connection refused', self::SECRET, $raw] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $visible);
            }
        } finally {
            config(['database.connections.pgsql' => $original]);
            DB::purge('pgsql');
            $this->guardTestDatabase();
        }

        $this->assertTables([$order], [], []);
        $this->logs->clear();
        $this->deliverDecision($raw, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertCount(1, $this->logs->getRecords());
        $this->assertSame('processed', $this->logs->getRecords()[0]->context['outcome']);
    }

    public function test_r03_returns_503_when_backend_terminates_during_processing_and_recovers(): void
    {
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $name = 'webhook_fault_'.str_replace('-', '', (string) Str::uuid7());
        try {
            // Kill only this test session after payment/order writes, before the receipt commits.
            DB::unprepared(<<<SQL
                CREATE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM payments WHERE order_id = NEW.order_id)
                        OR NOT EXISTS (SELECT 1 FROM orders WHERE id = NEW.order_id AND status = 'paid') THEN
                        RAISE EXCEPTION 'Payment and paid order must precede receipt finalization';
                    END IF;
                    PERFORM pg_terminate_backend(pg_backend_pid());
                    RETURN NEW;
                END;
                \$\$
                SQL);
            DB::statement("CREATE TRIGGER $name BEFORE UPDATE ON webhook_receipts FOR EACH ROW EXECUTE FUNCTION $name()");

            $response = $this->sendRaw($raw)->assertStatus(503)->assertExactJson(['error' => 'temporarily_unavailable']);
            $this->assertCount(1, $this->logs->getRecords());
            $record = $this->logs->getRecords()[0];
            $this->assertSame('ERROR', $record['level_name']);
            $this->assertSame('temporarily_unavailable', $record->context['error_code']);
            $this->assertSame('connection_failure', $record->context['database_error_type']);
            $this->assertFalse($record->context['duplicate']);
            $visible = $response->getContent().json_encode($record->toArray(), JSON_THROW_ON_ERROR);
            foreach (['terminating connection', 'server closed', 'webhooks_test', 'pgsql:', 'SELECT', 'bindings', 'trace', self::SECRET, $raw] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $visible);
            }

            DB::purge('pgsql');
            $this->guardTestDatabase();
            $this->assertTables([$order], [], []);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::purge('pgsql');
            $this->guardTestDatabase();
            DB::statement("DROP TRIGGER IF EXISTS $name ON webhook_receipts");
            DB::statement("DROP FUNCTION IF EXISTS $name()");
            $this->assertFalse(DB::table('pg_trigger')->where('tgname', $name)->exists());
            $this->assertFalse(DB::table('pg_proc')->where('proname', $name)->exists());
        }

        $this->logs->clear();
        $this->deliverDecision($raw, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertCount(1, $this->logs->getRecords());
        $this->assertSame('processed', $this->logs->getRecords()[0]->context['outcome']);
    }

    public function test_r04_replays_committed_result_after_simulated_response_loss(): void
    {
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $observer = $this->auxiliaryConnection('webhook_observer');
        $this->assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $observer->selectOne('SELECT pg_backend_pid() AS pid')->pid);
        $response = $this->deliverDecision($raw, 'processed');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $before = $this->domainState();
        foreach ($before as $table => $rows) {
            $committed = $observer->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $this->assertSame($rows, $committed);
        }

        // Discard an actual committed response; this simulates loss without cutting a TCP connection.
        unset($response);
        $this->travel(10)->minutes();
        $this->deliverDecision($raw, 'processed', duplicate: true);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->domainState());
        $records = $this->logs->getRecords();
        $this->assertCount(2, $records);
        $this->assertNotSame($records[0]->context['request_id'], $records[1]->context['request_id']);
        foreach ($records as $index => $record) {
            $this->assertSame('INFO', $record['level_name']);
            $this->assertSame('processed', $record->context['outcome']);
            $this->assertSame($index === 1, $record->context['duplicate']);
        }
    }
}

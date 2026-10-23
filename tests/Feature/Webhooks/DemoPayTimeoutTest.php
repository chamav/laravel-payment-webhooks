<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedWebhookTestCase;

class DemoPayTimeoutTest extends CommittedWebhookTestCase
{
    public function test_r05_times_out_on_order_lock_then_processes_same_event(): void
    {
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $blocker = $this->auxiliaryConnection('webhook_blocker');
        $this->assertSame(0, DB::transactionLevel());
        $receiverPid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $blockerPid = $blocker->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $this->assertNotSame($receiverPid, $blockerPid);
        config(['webhooks.lock_timeout_ms' => 500, 'webhooks.statement_timeout_ms' => 3000, 'webhooks.transaction_attempts' => 3]);
        // A session-level statement deadline also bounds a regression that omits SET LOCAL.
        DB::statement("SET lock_timeout = '0'");
        DB::statement("SET statement_timeout = '3000ms'");
        $timeoutSetups = 0;
        DB::listen(function ($query) use (&$timeoutSetups): void {
            if (str_starts_with($query->sql, "select set_config('lock_timeout'")) {
                $timeoutSetups++;
            }
        });

        try {
            $blocker->beginTransaction();
            $this->assertSame($order['id'], $blocker->table('orders')->where('id', $order['id'])->lockForUpdate()->sole()->id);
            $this->assertTrue(DB::table('pg_locks')->where('pid', $blockerPid)
                ->whereRaw("relation = 'orders'::regclass")->where('mode', 'RowShareLock')->where('granted', true)->exists());

            $started = hrtime(true);
            $response = $this->sendRaw($raw);
            $elapsedMs = (hrtime(true) - $started) / 1000000;
            $response->assertStatus(503)->assertExactJson(['error' => 'temporarily_unavailable']);
            $this->assertGreaterThanOrEqual(400, $elapsedMs);
            $this->assertLessThanOrEqual(2500, $elapsedMs);
            $this->assertSame(1, $timeoutSetups);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFailureLog('55P03');
            $this->assertTables([$order], [], []);
        } finally {
            $blocker->rollBack(0);
        }

        $this->logs->clear();
        $this->travel(1)->seconds();
        $this->deliverDecision($raw, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $this->assertCount(1, $this->logs->getRecords());
        $this->assertSame('INFO', $this->logs->getRecords()[0]['level_name']);
    }

    public function test_statement_timeout_returns_503_without_partial_writes(): void
    {
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        config(['webhooks.lock_timeout_ms' => 100, 'webhooks.statement_timeout_ms' => 500]);
        // pg_sleep causes real server cancellation, and is not evidence of concurrent overlap.
        $fault = $this->fault('57014', sleep: true);
        try {
            $started = hrtime(true);
            $response = $this->sendRaw($raw);
            $elapsedMs = (hrtime(true) - $started) / 1000000;
            $response->assertStatus(503)->assertExactJson(['error' => 'temporarily_unavailable']);
            $this->assertGreaterThanOrEqual(400, $elapsedMs);
            $this->assertLessThanOrEqual(2500, $elapsedMs);
            $this->assertSame(1, $fault->attempts());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFailureLog('57014');
            $this->assertTables([$order], [], []);
        } finally {
            $fault->drop();
        }

        $this->logs->clear();
        $this->travel(1)->seconds();
        $this->deliverDecision($raw, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $this->assertCount(1, $this->logs->getRecords());
        $this->assertSame('INFO', $this->logs->getRecords()[0]['level_name']);
    }
}

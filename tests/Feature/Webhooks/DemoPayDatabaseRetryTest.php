<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedWebhookTestCase;

class DemoPayDatabaseRetryTest extends CommittedWebhookTestCase
{
    #[DataProvider('concurrencyStates')]
    public function test_retries_simulated_postgres_concurrency_failure_then_commits_once(string $state): void
    {
        // Server-injected errors exercise Laravel retry/rollback, not an organic deadlock or race.
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $fault = $this->fault($state, 1);
        config(['webhooks.transaction_attempts' => 3]);
        try {
            $this->assertSame(0, DB::transactionLevel());
            $this->deliverDecision($raw, 'processed');
            $this->assertSame(2, $fault->attempts());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
            $this->assertCount(1, $this->logs->getRecords());
            $this->assertSame('processed', $this->logs->getRecords()[0]->context['outcome']);
            $this->assertSame('INFO', $this->logs->getRecords()[0]['level_name']);
        } finally {
            $fault->drop();
        }
    }

    #[DataProvider('concurrencyStates')]
    public function test_returns_503_after_bounded_postgres_concurrency_attempts(string $state): void
    {
        $order = $this->insertOrder();
        $fault = $this->fault($state);
        config(['webhooks.transaction_attempts' => 3]);
        try {
            $this->sendRaw($this->paymentBody($order))->assertStatus(503)->assertExactJson(['error' => 'temporarily_unavailable']);
            $this->assertSame(3, $fault->attempts());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTables([$order], [], []);
            $this->assertFailureLog($state);
        } finally {
            $fault->drop();
        }
    }

    public static function concurrencyStates(): array
    {
        return [['40001'], ['40P01']];
    }

    #[DataProvider('nonretryableStates')]
    public function test_does_not_retry_nonretryable_postgres_errors(string $state, int $status): void
    {
        $order = $this->insertOrder();
        $fault = $this->fault($state);
        config(['webhooks.transaction_attempts' => 3]);
        try {
            $this->sendRaw($this->paymentBody($order))->assertStatus($status)
                ->assertExactJson(['error' => $status === 503 ? 'temporarily_unavailable' : 'internal_error']);
            $this->assertSame(1, $fault->attempts());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTables([$order], [], []);
            $this->assertFailureLog($state, $status);
        } finally {
            $fault->drop();
        }
    }

    public static function nonretryableStates(): array
    {
        return [['55P03', 503], ['57014', 503], ['23514', 500], ['23505', 500], ['42601', 500], ['P0001', 500]];
    }

    #[DataProvider('attemptLimits')]
    public function test_accepts_transaction_attempt_boundaries(int|string $limit): void
    {
        config(['webhooks.transaction_attempts' => $limit]);
        $order = $this->insertOrder();
        $fault = $this->fault('40001');
        try {
            $this->sendRaw($this->paymentBody($order))->assertStatus(503)->assertExactJson(['error' => 'temporarily_unavailable']);
            $this->assertSame((int) $limit, $fault->attempts());
            $this->assertTables([$order], [], []);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $fault->drop();
        }
    }

    public static function attemptLimits(): array
    {
        return [[1], ['5']];
    }

    #[DataProvider('invalidAttempts')]
    public function test_rejects_invalid_transaction_attempts_before_business_sql(mixed $limit): void
    {
        $order = $this->insertOrder();
        config(['webhooks.transaction_attempts' => $limit]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->sendRaw($this->paymentBody($order))->assertStatus(500)->assertExactJson(['error' => 'internal_error']);
        $this->assertSame([], $queries);
        $this->assertTables([$order], [], []);
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function invalidAttempts(): array
    {
        return [[0], [-1], [6], [true], [false], [1.5], [3.0], [null], [''], ['3x'], ['03'], [' 3'], ['999999999999999999999999999']];
    }
}

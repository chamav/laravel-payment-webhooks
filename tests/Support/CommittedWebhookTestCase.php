<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Tests\TestCase;

abstract class CommittedWebhookTestCase extends TestCase
{
    use InteractsWithWebhooks;

    protected TestHandler $logs;

    /** @var list<WebhookFailureFixtures> */
    private array $faults = [];

    /** @var list<string> */
    private array $auxiliaryConnections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardTestDatabase();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->clearDomain();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00.123456 UTC'));
        config(['services.demo_pay.webhook_secret' => self::SECRET, 'app.debug' => true]);
        $this->logs = new TestHandler;
        Log::swap(new Logger(new Monolog('webhook-test', [$this->logs])));
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->auxiliaryConnections as $name) {
                $connection = DB::connection($name);
                try {
                    $connection->rollBack(0);
                } finally {
                    DB::purge($name);
                    config(['database.connections.'.$name => null]);
                }
            }
            DB::connection()->rollBack(0);
            foreach ($this->faults as $fault) {
                $fault->drop();
                $this->assertFalse($fault->exists());
            }
            $this->clearDomain();
            $this->assertDomainEmpty();
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $this->travelBack();
            parent::tearDown();
        }
    }

    protected function auxiliaryConnection(string $name): Connection
    {
        config(['database.connections.'.$name => config('database.connections.pgsql')]);
        $this->auxiliaryConnections[] = $name;
        $connection = DB::connection($name);
        TestDatabaseGuard::assertSafe(app()->environment(), $connection);
        $connection->statement("SET lock_timeout = '1000ms'");
        $connection->statement("SET statement_timeout = '3000ms'");
        $connection->statement("SET idle_in_transaction_session_timeout = '8000ms'");

        return $connection;
    }

    protected function fault(string $state, int $failures = 100, bool $sleep = false): WebhookFailureFixtures
    {
        $fault = new WebhookFailureFixtures;
        $this->faults[] = $fault;
        $fault->install($state, $failures, $sleep);

        return $fault;
    }

    protected function assertFailureLog(string $state, int $status = 503): void
    {
        $this->assertCount(1, $this->logs->getRecords());
        $record = $this->logs->getRecords()[0];
        $this->assertSame('ERROR', $record['level_name']);
        $this->assertSame($state, $record->context['sqlstate']);
        $this->assertSame($status === 503 ? 'temporarily_unavailable' : 'internal_error', $record->context['error_code']);
        $this->assertFalse($record->context['duplicate']);
        $visible = json_encode($record->toArray(), JSON_THROW_ON_ERROR);
        foreach (['LEAK_MARKER', 'pgsql:', 'SELECT', self::SECRET, 'trace', 'bindings'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $visible);
        }
    }

    private function clearDomain(): void
    {
        $this->guardTestDatabase();
        DB::statement("SET lock_timeout = '1000ms'");
        DB::statement("SET statement_timeout = '5000ms'");
        DB::statement('TRUNCATE webhook_receipts, payments, orders');
    }
}

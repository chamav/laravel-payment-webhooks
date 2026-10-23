<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    #[DataProvider('unsafeConnections')]
    public function test_p03_rejects_unsafe_connection_before_cleanup(string $environment, string $driver, string $database, ?string $actual, string $message): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConfig')->willReturnMap([
            ['driver', $driver], ['database', $database],
        ]);
        $connection->expects($actual === null ? $this->never() : $this->once())
            ->method('selectOne')->with('SELECT current_database() AS name', [], false)->willReturn((object) ['name' => $actual]);
        $connection->expects($this->never())->method('delete');
        $connection->expects($this->never())->method('statement');
        $cleanupCalls = 0;

        try {
            TestDatabaseGuard::assertSafe($environment, $connection);
            $cleanupCalls++;
            $this->fail('Unsafe cleanup was allowed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertSame(0, $cleanupCalls);
        }
    }

    public static function unsafeConnections(): array
    {
        return [
            'P03_non_testing_environment' => ['local', 'pgsql', 'webhooks_test', null, 'APP_ENV'],
            'P03_wrong_database' => ['testing', 'pgsql', 'webhooks', null, 'configured database'],
            'P03_non_pgsql' => ['testing', 'sqlite', 'webhooks_test', null, 'driver'],
            'P03_actual_database' => ['testing', 'pgsql', 'webhooks_test', 'webhooks', 'actual database'],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_p03_guard_precedes_framework_cleanup(): void
    {
        $harness = new class('test_placeholder') extends \Tests\TestCase
        {
            use RefreshDatabase;

            public int $cleanupCalls = 0;

            public function runLifecycle(): void
            {
                try {
                    $this->setUp();
                } finally {
                    $this->tearDown();
                }
            }

            protected function guardTestDatabase(?Application $app = null): void
            {
                TestDatabaseGuard::assertSafe('local', new Connection(null));
            }

            public function refreshDatabase(): void
            {
                $this->cleanupCalls++;
            }

            public function test_placeholder(): void {}
        };

        try {
            $harness->runLifecycle();
            $this->fail('The lifecycle guard did not run.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APP_ENV', $exception->getMessage());
            $this->assertSame(0, $harness->cleanupCalls);
        }
    }
}

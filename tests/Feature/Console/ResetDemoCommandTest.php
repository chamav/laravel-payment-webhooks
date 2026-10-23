<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\ResetDemoData;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResetDemoCommandTest extends TestCase
{
    public function test_p03_refuses_testing_environment_without_deletion(): void
    {
        $this->mock(ResetDemoData::class)->shouldNotReceive('handle');
        $this->artisan('demo:reset')->expectsOutput('Demo reset refused: environment. Only the local demo database is allowed.')->assertExitCode(2);
    }

    #[DataProvider('unsafeConnections')]
    public function test_p03_refuses_unsafe_demo_connection(string $driver, string $host, string $database, ?string $actual, string $reason): void
    {
        $this->app->instance('env', 'local');
        config(['database.connections.pgsql.database' => 'webhooks']);
        $this->mock(ResetDemoData::class)->shouldNotReceive('handle');
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getConfig')->with('driver')->andReturn($driver);
        $connection->shouldReceive('getConfig')->with('host')->andReturn($host);
        $connection->shouldReceive('getConfig')->with('database')->andReturn($database);
        if ($actual !== null) {
            $connection->shouldReceive('selectOne')->once()->with('SELECT current_database() AS name', [], false)->andReturn((object) ['name' => $actual]);
        } else {
            $connection->shouldNotReceive('selectOne');
        }
        $connection->shouldNotReceive('delete');
        $connection->shouldNotReceive('transaction');
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->artisan('demo:reset')->expectsOutput('Demo reset refused: '.$reason.'. Only the local demo database is allowed.')->assertExitCode(2);
    }

    public static function unsafeConnections(): array
    {
        return [
            'P03_driver' => ['sqlite', 'db', 'webhooks', null, 'connection'],
            'P03_host' => ['pgsql', 'db-test', 'webhooks', null, 'connection'],
            'P03_name' => ['pgsql', 'db', 'unexpected', null, 'connection'],
            'P03_test_database' => ['pgsql', 'db', 'webhooks_test', null, 'connection'],
            'P03_actual_database' => ['pgsql', 'db', 'webhooks', 'webhooks_test', 'actual_database'],
        ];
    }
}

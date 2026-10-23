<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use RuntimeException;

final class TestDatabaseGuard
{
    public static function assertSafe(string $environment, Connection $connection): void
    {
        if ($environment !== 'testing') {
            throw new RuntimeException('test_database_guard: APP_ENV must be testing before database cleanup.');
        }

        if ($connection->getConfig('driver') !== 'pgsql') {
            throw new RuntimeException('test_database_guard: the database driver must be pgsql.');
        }

        if ($connection->getConfig('database') !== 'webhooks_test') {
            throw new RuntimeException('test_database_guard: the configured database must be webhooks_test.');
        }

        if (($connection->selectOne('SELECT current_database() AS name', [], false)->name ?? null) !== 'webhooks_test') {
            throw new RuntimeException('test_database_guard: the actual database must be webhooks_test.');
        }
    }
}

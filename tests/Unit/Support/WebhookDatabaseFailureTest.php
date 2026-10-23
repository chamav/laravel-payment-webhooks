<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\WebhookDatabaseFailure;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WebhookDatabaseFailureTest extends TestCase
{
    #[DataProvider('states')]
    public function test_classifies_raw_and_wrapped_database_states(string $state, string $type, bool $temporary): void
    {
        $pdo = new PDOException('LEAK_MARKER server has gone away', 7);
        $pdo->errorInfo = [$state, 7, 'LEAK_MARKER'];
        $query = new QueryException('pgsql', 'SELECT ?', ['LEAK_MARKER'], $pdo);
        foreach ([$pdo, $query, new RuntimeException('outer', 0, $query)] as $exception) {
            $failure = WebhookDatabaseFailure::from($exception);
            $this->assertSame($state, $failure->sqlstate);
            $this->assertSame($type, $failure->type);
            $this->assertSame($temporary, $failure->temporary);
        }
    }

    public static function states(): array
    {
        return [
            ['55P03', 'lock_timeout', true], ['57014', 'statement_timeout', true],
            ['08006', 'connection_failure', true], ['08001', 'connection_failure', true],
            ['08P01', 'connection_failure', true], ['40001', 'concurrency_failure', true],
            ['40P01', 'concurrency_failure', true], ['23505', 'database_error', false],
            ['23514', 'database_error', false], ['42601', 'database_error', false],
            ['P0001', 'database_error', false],
            ['57P01', 'connection_failure', true], ['57P02', 'connection_failure', true],
            ['57P03', 'connection_failure', true],
        ];
    }

    #[DataProvider('genericMessages')]
    public function test_hy000_uses_only_the_original_pdo_message(string $message, bool $temporary): void
    {
        $pdo = new PDOException($message, 7);
        $pdo->errorInfo = ['HY000', 7, $message];
        $query = new QueryException('pgsql', 'SELECT ?', ['server closed the connection unexpectedly'], $pdo);
        foreach ([$pdo, $query, new RuntimeException('outer', 0, $query)] as $exception) {
            $failure = WebhookDatabaseFailure::from($exception);
            $this->assertSame($temporary, $failure->temporary);
            $this->assertSame($temporary ? 'connection_failure' : 'database_error', $failure->type);
        }
    }

    public static function genericMessages(): array
    {
        return [
            'lost_connection' => ['SQLSTATE[HY000]: General error: 7 FATAL: terminating connection due to administrator command / server closed the connection unexpectedly', true],
            'unrelated_error' => ['SQLSTATE[HY000]: General error: unrelated database failure', false],
        ];
    }

    public function test_normalizes_numeric_sqlstate_code_but_not_driver_code(): void
    {
        $failure = WebhookDatabaseFailure::from(new PDOException('serialization', 40001));
        $this->assertSame('40001', $failure->sqlstate);
        $this->assertTrue($failure->temporary);
        $failure = WebhookDatabaseFailure::from(new PDOException('driver failure', 7));
        $this->assertNull($failure->sqlstate);
        $this->assertFalse($failure->temporary);
    }

    public function test_fallback_requires_database_context_and_never_uses_bindings(): void
    {
        $lost = new PDOException('server closed the connection unexpectedly', 7);
        $failure = WebhookDatabaseFailure::from(new RuntimeException('outer', 0, $lost));
        $this->assertSame('connection_failure', $failure->type);
        $this->assertNull($failure->sqlstate);
        $this->assertTrue($failure->temporary);
        $this->assertNull(WebhookDatabaseFailure::from(new RuntimeException($lost->getMessage())));
        $query = new QueryException('pgsql', 'SELECT ?', [$lost->getMessage()], new PDOException('invalid SQL'));
        $this->assertFalse(WebhookDatabaseFailure::from($query)->temporary);
    }

    public function test_known_state_in_previous_exception_prevents_message_fallback(): void
    {
        $pdo = new PDOException('server has gone away');
        $pdo->errorInfo = ['23514'];
        $query = new QueryException('pgsql', 'SELECT ?', [], $pdo);
        $query->errorInfo = null;
        $this->assertFalse(WebhookDatabaseFailure::from($query)->temporary);
    }
}

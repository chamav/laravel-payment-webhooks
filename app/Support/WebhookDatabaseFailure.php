<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

final readonly class WebhookDatabaseFailure
{
    private function __construct(
        public string $type,
        public ?string $sqlstate,
        public bool $temporary,
    ) {}

    public static function from(Throwable $exception): ?self
    {
        $databaseExceptions = [];
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException) {
                $databaseExceptions[] = $cause;
                $state = self::sqlstate($cause);
                if ($state !== null && $state !== 'HY000') {
                    $type = match (true) {
                        $state === '55P03' => 'lock_timeout',
                        $state === '57014' => 'statement_timeout',
                        str_starts_with($state, '08') || in_array($state, ['57P01', '57P02', '57P03'], true) => 'connection_failure',
                        in_array($state, ['40001', '40P01'], true) => 'concurrency_failure',
                        default => 'database_error',
                    };

                    // A specific SQLSTATE is decisive; generic HY000 still needs the original cause.
                    return new self($type, $state, $type !== 'database_error');
                }
            }
        }

        if ($databaseExceptions === []) {
            return null;
        }

        $detector = new LostConnectionDetector;
        foreach ($databaseExceptions as $databaseException) {
            // QueryException messages include SQL/bindings; inspect the original cause instead.
            $cause = $databaseException instanceof QueryException
                ? $databaseException->getPrevious() : $databaseException;
            if ($cause !== null && $detector->causedByLostConnection($cause)) {
                return new self('connection_failure', null, true);
            }
        }

        return new self('database_error', null, false);
    }

    private static function sqlstate(PDOException $exception): ?string
    {
        foreach ([$exception->errorInfo[0] ?? null, $exception->getCode()] as $value) {
            if ((is_string($value) || is_int($value)) && preg_match('/^[0-9A-Z]{5}$/D', (string) $value) === 1) {
                return (string) $value;
            }
        }

        return null;
    }
}

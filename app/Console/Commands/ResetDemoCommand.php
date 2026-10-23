<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ResetDemoData;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResetDemoCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Delete all orders, payments and receipts from the local demo database only';

    public function handle(ResetDemoData $reset): int
    {
        Log::debug('Demo data reset requested.');
        if (! app()->environment('local')) {
            return $this->refuse('environment');
        }

        $connection = DB::connection();
        $expectedDatabase = config('database.connections.pgsql.database');
        if ($connection->getConfig('driver') !== 'pgsql' || $connection->getConfig('host') !== 'db'
            || ! is_string($expectedDatabase) || $expectedDatabase === '' || $expectedDatabase === 'webhooks_test'
            || $connection->getConfig('database') !== $expectedDatabase) {
            return $this->refuse('connection');
        }

        try {
            if (($connection->selectOne('SELECT current_database() AS name', [], false)->name ?? null) !== $expectedDatabase) {
                return $this->refuse('actual_database');
            }

            $counts = $reset->handle($connection);
        } catch (QueryException $exception) {
            Log::error('Demo data reset failed.', ['sqlstate' => $exception->errorInfo[0] ?? null]);
            $this->output->getErrorStyle()->writeln('Demo reset failed: database_error; no deletion was committed.');

            return self::FAILURE;
        }

        Log::info('Demo data deleted.', $counts);
        $this->line('Deleted local demo data: '.json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function refuse(string $reason): int
    {
        Log::warning('Demo data reset refused.', ['reason_code' => $reason]);
        $this->output->getErrorStyle()->writeln('Demo reset refused: '.$reason.'. Only the local demo database is allowed.');

        return self::INVALID;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Database\Connection;

class ResetDemoData
{
    /** @return array{webhook_receipts: int, payments: int, orders: int} */
    public function handle(Connection $connection): array
    {
        return $connection->transaction(function () use ($connection): array {
            $connection->statement("SET LOCAL lock_timeout = '3s'");
            $connection->statement("SET LOCAL statement_timeout = '10s'");

            return [
                'webhook_receipts' => $connection->table('webhook_receipts')->delete(),
                'payments' => $connection->table('payments')->delete(),
                'orders' => $connection->table('orders')->delete(),
            ];
        }, 1);
    }
}

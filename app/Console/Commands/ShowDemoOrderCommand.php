<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShowDemoOrderCommand extends Command
{
    protected $signature = 'demo:order:show {order_id}';

    protected $description = 'Show a demo order, payment and receipts as JSON';

    public function handle(): int
    {
        $id = $this->argument('order_id');
        if (! Str::isUuid($id)) {
            Log::debug('Demo order lookup refused.', ['reason_code' => 'invalid_order_id']);
            $this->output->getErrorStyle()->writeln('Invalid order_id: a UUID is required.');

            return self::INVALID;
        }

        Log::debug('Demo order lookup started.', ['order_id' => $id]);
        try {
            $order = Order::with(['payment', 'receipts' => fn ($query) => $query->orderBy('completed_at')->orderBy('id')])->find($id);
        } catch (QueryException $exception) {
            Log::error('Demo order lookup failed.', ['sqlstate' => $exception->errorInfo[0] ?? null]);
            $this->output->getErrorStyle()->writeln('Unable to show the demo order: database_error.');

            return self::FAILURE;
        }

        if ($order === null) {
            $this->output->getErrorStyle()->writeln('Order not found.');

            return self::FAILURE;
        }

        $this->line($order->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        Log::debug('Demo order lookup completed.', ['order_id' => $id, 'receipt_count' => $order->receipts->count()]);

        return self::SUCCESS;
    }
}

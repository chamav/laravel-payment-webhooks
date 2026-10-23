<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Currency;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class CreateDemoOrderCommand extends Command
{
    protected $signature = 'demo:order:create {amount_minor} {currency}';

    protected $description = 'Create a pending demo order and print its UUID';

    public function handle(): int
    {
        $amount = $this->argument('amount_minor');
        $currency = $this->argument('currency');
        Log::debug('Demo order creation started.');

        if (preg_match('/\A[1-9][0-9]{0,9}\z/', $amount) !== 1 || (int) $amount > 1000000000
            || Currency::tryFrom($currency) === null) {
            Log::debug('Demo order validation failed.', ['reason_code' => 'invalid_order_arguments']);
            $this->output->getErrorStyle()->writeln('Invalid order arguments: amount_minor must be 1..1000000000; currency must be EUR or USD.');

            return self::INVALID;
        }

        try {
            $order = Order::create(['amount_minor' => (int) $amount, 'currency' => Currency::from($currency), 'status' => OrderStatus::Pending]);
        } catch (QueryException $exception) {
            Log::error('Demo order creation failed.', ['sqlstate' => $exception->errorInfo[0] ?? null]);
            $this->output->getErrorStyle()->writeln('Unable to create the demo order: database_error.');

            return self::FAILURE;
        }

        Log::info('Demo order created.', ['order_id' => $order->id, 'amount_minor' => $order->amount_minor, 'currency' => $currency]);
        $this->line($order->id);

        return self::SUCCESS;
    }
}

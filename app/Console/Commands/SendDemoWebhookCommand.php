<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SendDemoWebhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class SendDemoWebhookCommand extends Command
{
    protected $signature = 'demo:webhook:send {file : Path to the raw JSON body}';

    protected $description = 'Send a signed demo webhook with bounded delivery retries';

    public function handle(SendDemoWebhook $sender): int
    {
        $file = $this->argument('file');
        // Bound the read as well as the accepted body size; preserve the bytes for every attempt.
        $raw = is_file($file) && is_readable($file) ? @file_get_contents($file, false, null, 0, 65537) : false;
        if ($raw === false || $raw === '' || strlen($raw) > 65536) {
            $this->output->getErrorStyle()->writeln('Invalid webhook body file: expected 1..65536 readable bytes.');

            return self::INVALID;
        }

        try {
            $result = $sender->send($raw);
        } catch (InvalidArgumentException) {
            $this->output->getErrorStyle()->writeln('Invalid webhook sender configuration.');

            return self::INVALID;
        } catch (Throwable) {
            Log::error('Webhook delivery failed unexpectedly.', ['error_code' => 'unexpected_error']);
            $this->output->getErrorStyle()->writeln('Webhook delivery failed unexpectedly.');

            return self::FAILURE;
        }

        $status = $result->httpStatus !== null ? 'http_status='.$result->httpStatus : (string) $result->errorCode;
        $this->line('Webhook delivery '.($result->successful() ? 'succeeded' : 'failed').': '.$status.'; attempts='.$result->attempts.'.');

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }
}

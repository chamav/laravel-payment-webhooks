<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\WebhookDeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

final class SendDemoWebhook
{
    public function send(string $raw): WebhookDeliveryResult
    {
        $url = config('services.demo_pay.webhook_url');
        $secret = config('services.demo_pay.webhook_secret');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || ! is_string($secret) || trim($secret) === '' || $raw === '' || strlen($raw) > 65536) {
            throw new InvalidArgumentException('Invalid webhook sender configuration or body.');
        }
        $attempts = $this->integerSetting('sender_attempts', 5);
        $backoffMs = $this->integerSetting('sender_backoff_ms', 2000);
        $timeout = $this->integerSetting('sender_timeout_seconds', 60);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $timestamp = (string) now()->getTimestamp();
            $status = null;
            $error = null;
            try {
                $response = Http::withBody($raw, 'application/json')->withHeaders([
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret),
                ])->withoutRedirecting()->connectTimeout(min(2, $timeout))->timeout($timeout)->post($url);
                $status = $response->status();
                $retryable = in_array($status, [500, 503, 429], true);
                $error = $response->successful() ? null : 'http_error';
            } catch (ConnectionException) {
                $error = 'network_error';
                $retryable = true;
            } catch (RequestException $exception) {
                $status = $exception->response->status();
                $error = 'transport_error';
                $retryable = in_array($status, [500, 503, 429], true);
            }

            $result = new WebhookDeliveryResult($attempt, $status, $error);
            $retry = $retryable && $attempt < $attempts;
            $level = match (true) {
                $result->successful() => 'info',
                $retryable && ! $retry => 'error',
                default => 'warning',
            };
            Log::log($level, 'Webhook delivery attempt completed.', [
                'attempt' => $attempt, 'http_status' => $status, 'error_code' => $error, 'retry' => $retry,
            ]);
            if (! $retry) {
                return $result;
            }

            Sleep::for(min($backoffMs * (2 ** ($attempt - 1)), 2000))->milliseconds();
        }

        throw new \LogicException('Webhook delivery attempts were not configured.');
    }

    private function integerSetting(string $key, int $maximum): int
    {
        $value = config('services.demo_pay.'.$key);
        if ((! is_int($value) && ! is_string($value)) || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException('Sender limits must be positive integers.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);
        if ($integer === false) {
            throw new InvalidArgumentException('Sender limit exceeds its allowed range.');
        }

        return $integer;
    }
}

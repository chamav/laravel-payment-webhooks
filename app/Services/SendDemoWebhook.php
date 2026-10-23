<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\WebhookDeliveryResult;
use App\Enums\WebhookOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use JsonException;
use stdClass;

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
            $summary = null;
            try {
                $response = Http::withBody($raw, 'application/json')->withHeaders([
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret),
                ])->withoutRedirecting()->connectTimeout(min(2, $timeout))->timeout($timeout)->post($url);
                $summary = $this->responseSummary($response);
                $status = $response->status();
                $retryable = in_array($status, [500, 503, 429], true);
                $error = $response->successful() ? null : 'http_error';
            } catch (ConnectionException) {
                $error = 'network_error';
                $retryable = true;
            } catch (RequestException $exception) {
                $summary = $this->responseSummary($exception->response);
                $status = $exception->response->status();
                $error = 'transport_error';
                $retryable = in_array($status, [500, 503, 429], true);
            }

            $result = new WebhookDeliveryResult($attempt, $status, $error, $summary);
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

    /** @return array<string, bool|string>|null */
    private function responseSummary(Response $response): ?array
    {
        $stream = $response->toPsrResponse()->getBody();
        $raw = '';
        while (strlen($raw) < 4097 && ! $stream->eof()) {
            $chunk = $stream->read(4097 - strlen($raw));
            if ($chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        if (strlen($raw) > 4096) {
            return null;
        }
        try {
            $body = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! $body instanceof stdClass) {
            return null;
        }

        $summary = [];
        foreach (['event_id', 'outcome', 'duplicate', 'reason', 'error'] as $key) {
            if (! property_exists($body, $key)) {
                continue;
            }
            $value = $body->{$key};
            $valid = match ($key) {
                'event_id' => is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $value) === 1,
                'outcome' => is_string($value) && WebhookOutcome::tryFrom($value) !== null,
                'duplicate' => is_bool($value),
                'reason' => in_array($value, ['order_not_found', 'amount_mismatch', 'currency_mismatch', 'payment_conflict', 'order_already_paid'], true),
                'error' => in_array($value, ['invalid_json', 'invalid_signature', 'method_not_allowed', 'event_conflict', 'payload_too_large', 'unsupported_media_type', 'validation_failed', 'temporarily_unavailable', 'internal_error'], true),
            };
            if (! $valid || (! is_string($value) && ! is_bool($value))) {
                return null;
            }
            $summary[$key] = $value;
        }

        return $summary === [] ? null : $summary;
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

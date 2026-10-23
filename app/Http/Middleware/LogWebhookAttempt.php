<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\WebhookResult;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogWebhookAttempt
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/webhooks/demo-pay')) {
            return $next($request);
        }

        $requestId = (string) Str::uuid7();
        $started = hrtime(true);
        $response = $next($request);
        $result = $request->attributes->get('webhook_result');
        $context = [
            'request_id' => $requestId,
            'event_id' => $request->attributes->get('webhook_event_id'),
            'duplicate' => $result instanceof WebhookResult && $result->duplicate,
            'duration_ms' => (hrtime(true) - $started) / 1000000,
        ];

        if ($result instanceof WebhookResult) {
            $context['outcome'] = $result->outcome->value;
        } else {
            $context['error_code'] = $request->attributes->get('webhook_error_code', 'internal_error');
        }

        if ($response->getStatusCode() === 500) {
            $context['exception_class'] = $request->attributes->get('webhook_exception_class');
        }

        $level = match (true) {
            $response->getStatusCode() >= 500 => 'error',
            $response->getStatusCode() >= 400 => 'warning',
            default => 'info',
        };
        Log::log($level, 'Webhook attempt completed.', $context);

        return $response;
    }
}

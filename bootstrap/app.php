<?php

declare(strict_types=1);

use App\Http\Middleware\LogWebhookAttempt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// This API has no HTML forms and must preserve the original HTTP method.
Request::setAllowedHttpMethodOverride([]);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $isWebhook = fn (Request $request): bool => $request->is('api/webhooks/demo-pay');
        $middleware->prepend(LogWebhookAttempt::class);
        $middleware->trimStrings(except: [$isWebhook]);
        $middleware->convertEmptyStringsToNull(except: [$isWebhook]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->report(function (Throwable $exception): ?bool {
            return request()->is('api/webhooks/demo-pay') ? false : null;
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/webhooks/demo-pay')) {
                return null;
            }

            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $code = match ($status) {
                400 => 'invalid_json',
                401 => 'invalid_signature',
                405 => 'method_not_allowed',
                413 => 'payload_too_large',
                415 => 'unsupported_media_type',
                422 => 'validation_failed',
                503 => 'temporarily_unavailable',
                default => 'internal_error',
            };
            $request->attributes->set('webhook_error_code', $code);
            if ($status === 500) {
                $request->attributes->set('webhook_exception_class', get_class($exception));
            }
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            return response()->json(['error' => $code], $status, $headers);
        });
    })->create();

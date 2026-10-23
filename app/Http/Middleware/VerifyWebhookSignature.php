<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.demo_pay.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new ServiceUnavailableHttpException;
        }

        $timestamp = $request->header('X-Webhook-Timestamp', '');
        $signature = $request->header('X-Webhook-Signature', '');

        if (count($request->headers->all('X-Webhook-Timestamp')) !== 1
            || count($request->headers->all('X-Webhook-Signature')) !== 1
            || preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/', $timestamp) !== 1
            || preg_match('/\Av1=[0-9a-f]{64}\z/', $signature) !== 1
            || abs((int) $timestamp - Date::now()->getTimestamp()) > 300) {
            throw new HttpException(401);
        }

        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            throw new HttpException(401);
        }

        return $next($request);
    }
}

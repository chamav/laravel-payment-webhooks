<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

class EnsureWebhookRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (strlen($request->getContent()) > 65536) {
            throw new HttpException(413);
        }

        $mediaType = strtolower(trim(explode(';', $request->header('Content-Type', ''), 2)[0]));

        if ($mediaType !== 'application/json') {
            throw new UnsupportedMediaTypeHttpException;
        }

        return $next($request);
    }
}

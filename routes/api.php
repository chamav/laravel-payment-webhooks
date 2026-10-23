<?php

declare(strict_types=1);

use App\Http\Controllers\DemoPayWebhookController;
use App\Http\Middleware\EnsureWebhookRequest;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/demo-pay', DemoPayWebhookController::class)
    ->middleware([EnsureWebhookRequest::class, VerifyWebhookSignature::class]);

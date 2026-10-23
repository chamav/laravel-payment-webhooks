<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\WebhookEvent;
use App\Data\WebhookResult;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class ProcessWebhook
{
    public function handle(WebhookEvent $event): WebhookResult
    {
        throw new ServiceUnavailableHttpException;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProcessWebhook;
use App\Enums\WebhookOutcome;
use App\Validation\DecodeWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoPayWebhookController extends Controller
{
    public function __invoke(Request $request, DecodeWebhook $decoder, ProcessWebhook $action): JsonResponse
    {
        $event = $decoder->handle($request->getContent());
        $request->attributes->set('webhook_event_id', $event->eventId);
        $result = $action->handle($event);
        $request->attributes->set('webhook_result', $result);

        $body = [
            'event_id' => $result->eventId,
            'outcome' => $result->outcome->value,
            'duplicate' => $result->duplicate,
        ];
        if ($result->outcome === WebhookOutcome::Rejected) {
            $body['reason'] = $result->reason;
        }

        return response()->json($body, $result->httpStatus);
    }
}

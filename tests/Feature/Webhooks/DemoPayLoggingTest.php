<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Actions\ProcessWebhook;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\SignedWebhookRequests;
use Tests\TestCase;

class DemoPayLoggingTest extends TestCase
{
    use SignedWebhookRequests;

    #[DataProvider('attempts')]
    public function test_logs_one_safe_outcome_per_attempt(string $scenario, int $status, string $code, string $level): void
    {
        $handler = new TestHandler;
        Log::swap(new Logger(new Monolog('webhook-test', [$handler])));
        $raw = self::BODY;
        $headers = [];
        $method = 'POST';
        $eventId = 'evt_demo_001';

        switch ($scenario) {
            case 'success':
                $this->expectAction();
                break;
            case 'signature':
                $headers['HTTP_X_WEBHOOK_SIGNATURE'] = 'v1='.str_repeat('0', 64);
                $eventId = null;
                $this->rejectAction();
                break;
            case 'validation':
                $raw = str_replace('evt_demo_001', 'LEAK_MARKER invalid id', $raw);
                $eventId = null;
                $this->rejectAction();
                break;
            case 'method':
                $method = 'GET';
                $eventId = null;
                $this->rejectAction();
                break;
            case 'exception':
                config(['app.debug' => true]);
                $this->mock(ProcessWebhook::class)->shouldReceive('handle')->once()
                    ->andThrow(new RuntimeException('LEAK_MARKER pgsql:host=private-db SELECT * FROM payments '.$raw.self::SECRET));
                break;
        }

        $response = $this->sendRaw($raw, $headers, $method);
        if ($status === 200) {
            $response->assertOk();
            $this->assertDomainEmpty();
        } else {
            $this->assertRejected($response, $status, $code);
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $record = $records[0];
        $context = $record['context'];
        $codeKey = $status === 200 ? 'outcome' : 'error_code';
        $expectedKeys = ['request_id', 'event_id', 'duplicate', 'duration_ms', $codeKey];
        if ($status === 500) {
            $expectedKeys[] = 'exception_class';
            $this->assertSame(RuntimeException::class, $context['exception_class']);
        }
        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($context));
        $this->assertSame('Webhook attempt completed.', $record['message']);
        $this->assertSame($level, $record['level_name']);
        $this->assertTrue(Str::isUuid($context['request_id']));
        $this->assertGreaterThanOrEqual(0, $context['duration_ms']);
        $this->assertSame($eventId, $context['event_id']);
        $this->assertFalse($context['duplicate']);
        $this->assertSame($code, $context[$codeKey]);

        $visible = $response->getContent().json_encode($context, JSON_THROW_ON_ERROR);
        foreach (['LEAK_MARKER', 'pgsql:', 'SELECT', self::SECRET, $raw, $this->signedHeaders($raw)['HTTP_X_WEBHOOK_SIGNATURE']] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $visible);
        }
    }

    public static function attempts(): array
    {
        return [
            'H01 success' => ['success', 200, 'processed', 'INFO'],
            'H02 signature' => ['signature', 401, 'invalid_signature', 'WARNING'],
            'H08 validation' => ['validation', 422, 'validation_failed', 'WARNING'],
            'H12 method' => ['method', 405, 'method_not_allowed', 'WARNING'],
            'unavailable action' => ['unavailable', 503, 'temporarily_unavailable', 'ERROR'],
            'unexpected exception' => ['exception', 500, 'internal_error', 'ERROR'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Actions\ProcessWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
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
            case 'unavailable':
                $this->mock(ProcessWebhook::class)->shouldReceive('handle')->once()
                    ->andThrow(new ServiceUnavailableHttpException);
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

    public function test_p07_logs_final_domain_decisions_once_per_attempt(): void
    {
        $order = $this->insertOrder();
        $first = $this->paymentBody($order);
        $duplicatePayment = $this->paymentBody($order, 'evt_duplicate_payment');
        $rejected = $this->paymentBody($order, 'evt_rejected', 'pay_new');
        $ignored = '{"id":"evt_ignored","type":"payment.refunded","data":{"marker":"LEAK_MARKER"}}';
        $attempts = [
            [$first, 'processed', null, false], [$first, 'processed', null, true],
            [$duplicatePayment, 'duplicate_payment', null, false],
            [$duplicatePayment, 'duplicate_payment', null, true],
            [$ignored, 'ignored', null, false], [$ignored, 'ignored', null, true],
            [$rejected, 'rejected', 'order_already_paid', false],
            [$rejected, 'rejected', 'order_already_paid', true],
        ];
        $handler = new TestHandler;
        $logger = new Monolog('webhook-test', [$handler]);
        $observed = [];
        $logger->pushProcessor(function (LogRecord $record) use (&$observed): LogRecord {
            $observed[] = [$this->domainState(), DB::transactionLevel()];

            return $record;
        });
        Log::swap(new Logger($logger));
        $outerLevel = DB::transactionLevel();
        $requestIds = $payments = $receipts = [];
        $paid = $order;

        foreach ($attempts as $index => [$raw, $outcome, $reason, $duplicate]) {
            $handler->clear();
            $observed = [];
            $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00.123456 UTC')->addMinutes($index * 10));
            $response = $this->deliverDecision($raw, $outcome, $reason, $duplicate);
            if ($outcome === 'processed' && ! $duplicate) {
                $paid = $this->expectedPaidOrder($order);
                $payments[] = $this->expectedPayment($raw);
            }
            if (! $duplicate) {
                $receipts[] = $this->expectedReceipt($raw, $outcome, $reason);
            }
            $this->assertTables([$paid], $payments, $receipts);
            // RefreshDatabase owns the outer transaction; the action's transaction has finished.
            $this->assertSame([[$this->domainState(), $outerLevel]], $observed);
            $this->assertCount(1, $handler->getRecords());
            $record = $handler->getRecords()[0];
            $this->assertDomainLog($record, $response, $raw, $outcome, $duplicate, $outcome === 'rejected' ? 'WARNING' : 'INFO');
            $this->assertNotContains($record->context['request_id'], $requestIds);
            $requestIds[] = $record->context['request_id'];
        }
    }

    public function test_p07_logs_event_conflict_without_sensitive_context(): void
    {
        $order = $this->insertOrder();
        $raw = $this->paymentBody($order);
        $this->deliverDecision($raw, 'processed');
        $this->assertTables([$this->expectedPaidOrder($order)], [$this->expectedPayment($raw)], [$this->expectedReceipt($raw, 'processed')]);
        $before = $this->domainState();
        $handler = new TestHandler;
        $logger = new Monolog('webhook-test', [$handler]);
        $observed = [];
        $logger->pushProcessor(function (LogRecord $record) use (&$observed): LogRecord {
            $observed[] = [$this->domainState(), DB::transactionLevel()];

            return $record;
        });
        Log::swap(new Logger($logger));
        $outerLevel = DB::transactionLevel();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 13:00:00.345678 UTC'));
        $changed = substr($raw, 0, -1).',"extra":"LEAK_MARKER"}';
        $response = $this->sendRaw($changed)->assertStatus(409)->assertExactJson(['error' => 'event_conflict']);
        $this->assertSame($before, $this->domainState());
        $this->assertSame([[$before, $outerLevel]], $observed);
        $this->assertCount(1, $handler->getRecords());
        $this->assertDomainLog($handler->getRecords()[0], $response, $changed, 'event_conflict', false, 'WARNING', 'error_code');
    }

    private function assertDomainLog(LogRecord $record, TestResponse $response, string $raw, string $code, bool $duplicate, string $level, string $codeKey = 'outcome'): void
    {
        $context = $record->context;
        $this->assertEqualsCanonicalizing(['request_id', 'event_id', 'duplicate', 'duration_ms', $codeKey], array_keys($context));
        $this->assertSame('Webhook attempt completed.', $record->message);
        $this->assertSame($level, $record->level->getName());
        $this->assertTrue(Str::isUuid($context['request_id']));
        $this->assertSame(json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['id'], $context['event_id']);
        $this->assertSame($duplicate, $context['duplicate']);
        $this->assertGreaterThanOrEqual(0, $context['duration_ms']);
        $this->assertSame($code, $context[$codeKey]);
        $visible = $response->getContent().json_encode($context, JSON_THROW_ON_ERROR);
        foreach (['LEAK_MARKER', 'pgsql:', 'SELECT', 'bindings', 'DSN', self::SECRET, $raw, $this->signedHeaders($raw)['HTTP_X_WEBHOOK_SIGNATURE']] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $visible);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\SignedWebhookRequests;
use Tests\TestCase;

class SendDemoWebhookCommandTest extends TestCase
{
    use SignedWebhookRequests {
        setUp as private setUpSignedWebhooks;
    }

    private const string URL = 'http://app:8080/api/webhooks/demo-pay';

    private string $file;

    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->setUpSignedWebhooks();
        config([
            'services.demo_pay.webhook_url' => self::URL,
            'services.demo_pay.sender_attempts' => 3,
            'services.demo_pay.sender_backoff_ms' => 250,
            'services.demo_pay.sender_timeout_seconds' => 15,
        ]);
        $this->file = tempnam(storage_path('framework/testing'), 'webhook-body-');
        file_put_contents($this->file, self::BODY);
        Sleep::fake();
        Http::preventStrayRequests();
        $this->logs = new TestHandler;
        Log::swap(new Logger(new Monolog('sender-test', [$this->logs])));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->file) && is_file($this->file)) {
                unlink($this->file);
            }
        } finally {
            Sleep::fake(false);
            $this->travelBack();
            parent::tearDown();
        }
    }

    #[DataProvider('retryableFailures')]
    public function test_retries_only_allowed_failures_then_succeeds(int|string $failure): void
    {
        $requests = [];
        Http::fake(function (Request $request, array $options) use (&$requests, $failure) {
            $requests[] = $request;
            $this->assertSame(15, $options['timeout']);
            $this->assertSame(2, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);
            if (count($requests) === 1) {
                return $failure === 'network' ? Http::failedConnection('LEAK_MARKER')($request) : Http::response('LEAK_MARKER', $failure);
            }

            return Http::response('', 200);
        });
        $before = $this->domainState();
        $this->assertSame(0, $this->runSender());
        $this->assertCount(2, $requests);
        $this->assertSame($before, $this->domainState());
        Sleep::assertSequence([Sleep::for(250)->milliseconds()]);
        $this->assertCount(2, $this->logs->getRecords());
        $this->assertTrue($this->logs->getRecords()[0]->context['retry']);
        $this->assertFalse($this->logs->getRecords()[1]->context['retry']);
        $this->assertSafeOutput();
    }

    #[DataProvider('retryableFailures')]
    public function test_exhausts_exact_attempt_budget_with_bounded_backoff(int|string $failure): void
    {
        config(['services.demo_pay.sender_attempts' => 5, 'services.demo_pay.sender_backoff_ms' => 1000]);
        $requests = [];
        Http::fake(function (Request $request) use (&$requests, $failure) {
            $requests[] = $request;

            return $failure === 'network' ? Http::failedConnection('LEAK_MARKER')($request) : Http::response('LEAK_MARKER', $failure);
        });
        $this->assertSame(1, $this->runSender());
        $this->assertCount(5, $requests);
        Sleep::assertSequence(array_map(fn ($ms) => Sleep::for($ms)->milliseconds(), [1000, 2000, 2000, 2000]));
        $this->assertCount(5, $this->logs->getRecords());
        $this->assertSame('ERROR', $this->logs->getRecords()[4]['level_name']);
        $this->assertFalse($this->logs->getRecords()[4]->context['retry']);
        $this->assertStringContainsString('attempts=5', Artisan::output());
        $this->assertSafeOutput();
        $this->assertDomainEmpty();
    }

    public static function retryableFailures(): array
    {
        return [[500], [503], [429], ['network']];
    }

    #[DataProvider('terminalStatuses')]
    public function test_stops_after_terminal_response_without_sleep(int $status, int $exit): void
    {
        Http::fake(['*' => Http::response('LEAK_MARKER', $status, ['Location' => 'http://untrusted.invalid'])]);
        $this->assertSame($exit, $this->runSender());
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        $this->assertCount(1, $this->logs->getRecords());
        $this->assertSame($exit === 0 ? 'INFO' : 'WARNING', $this->logs->getRecords()[0]['level_name']);
        $this->assertSafeOutput();
        $this->assertDomainEmpty();
    }

    public static function terminalStatuses(): array
    {
        return [[200, 0], [201, 0], [204, 0], [299, 0], [400, 1], [401, 1], [403, 1], [409, 1], [422, 1],
            [404, 1], [501, 1], [502, 1], [504, 1], [301, 1], [302, 1], [307, 1], [308, 1]];
    }

    public function test_r04_retries_after_simulated_response_loss_with_same_body_and_fresh_signature(): void
    {
        // The fake simulates response loss; remote commit is proved separately by receiver tests.
        $raw = "{\n  \"type\":\"payment.succeeded\", \"id\":\"evt_loss\", \"data\":{\"payment_id\":\"pay_loss\"}\n}\n";
        file_put_contents($this->file, $raw);
        $requests = [];
        $timestamps = [];
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00.123456 UTC'));
        Sleep::whenFakingSleep(function (): void {
            $this->travel(2)->seconds();
        });
        Http::fake(function (Request $request) use (&$requests, &$timestamps) {
            // Capture inputs explicitly: failed transports may not appear in Http::recorded().
            $requests[] = $request;
            $timestamps[] = (string) now()->getTimestamp();
            if (count($requests) === 1) {
                file_put_contents($this->file, 'LEAK_MARKER changed source file');

                return Http::failedConnection('LEAK_MARKER response lost, secret='.self::SECRET)($request);
            }

            return Http::response('LEAK_MARKER untrusted body', 200);
        });

        $order = $this->insertOrder();
        $before = $this->domainState();
        $this->assertSame(0, $this->runSender());
        $this->assertSame($before, $this->domainState());
        $this->assertCount(2, $requests);
        $this->assertNotSame($timestamps[0], $timestamps[1]);
        foreach ($requests as $index => $request) {
            $this->assertSame($raw, $request->body());
            $this->assertSame(self::URL, $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame(['application/json'], $request->header('Content-Type'));
            $this->assertSame([$timestamps[$index]], $request->header('X-Webhook-Timestamp'));
            $this->assertSame(['v1='.hash_hmac('sha256', $timestamps[$index].'.'.$raw, self::SECRET)], $request->header('X-Webhook-Signature'));
            $data = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('evt_loss', $data['id']);
            $this->assertSame('pay_loss', $data['data']['payment_id']);
        }
        Sleep::assertSequence([Sleep::for(250)->milliseconds()]);
        $this->assertTables([$order], [], []);
        $this->assertSafeOutput();
    }

    #[DataProvider('responseExceptionStatuses')]
    public function test_handles_response_bearing_transport_exception_without_leaking_body(int $status, int $exit, int $attempts): void
    {
        $raw = file_get_contents($this->file);
        $requests = [];
        $timestamps = [];
        Sleep::whenFakingSleep(function (): void {
            $this->travel(2)->seconds();
        });
        Http::fake(function (Request $request) use (&$requests, &$timestamps, $status) {
            $requests[] = $request;
            $timestamps[] = (string) now()->getTimestamp();
            if (count($requests) === 1) {
                throw new RequestException(new Response(new Psr7Response($status, [], 'LEAK_MARKER untrusted response body')));
            }

            return Http::response('', 200);
        });

        $this->assertSame($exit, $this->runSender());
        $this->assertCount($attempts, $requests);
        $this->assertStringContainsString('attempts='.$attempts, Artisan::output());
        foreach ($requests as $index => $request) {
            $this->assertSame($raw, $request->body());
            $this->assertSame([$timestamps[$index]], $request->header('X-Webhook-Timestamp'));
            $this->assertSame(['v1='.hash_hmac('sha256', $timestamps[$index].'.'.$raw, self::SECRET)], $request->header('X-Webhook-Signature'));
        }
        if ($attempts === 2) {
            $this->assertNotSame($timestamps[0], $timestamps[1]);
            $this->assertNotSame($requests[0]->header('X-Webhook-Signature'), $requests[1]->header('X-Webhook-Signature'));
            Sleep::assertSequence([Sleep::for(250)->milliseconds()]);
        } else {
            Sleep::assertNeverSlept();
        }
        $records = $this->logs->getRecords();
        $this->assertCount($attempts, $records);
        $this->assertSame($status, $records[0]->context['http_status']);
        $this->assertSame('transport_error', $records[0]->context['error_code']);
        $this->assertSame($attempts === 2, $records[0]->context['retry']);
        $this->assertSafeOutput();
        $this->assertDomainEmpty();
    }

    public static function responseExceptionStatuses(): array
    {
        return ['retry_503' => [503, 0, 2], 'terminal_400' => [400, 1, 1]];
    }

    public function test_unexpected_sender_exception_returns_safe_failure(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new RuntimeException('LEAK_MARKER unexpected HTTP failure');
        });

        $this->assertSame(1, $this->runSender());
        $this->assertSame(1, $attempts);
        $this->assertSame('Webhook delivery failed unexpectedly.'.PHP_EOL, Artisan::output());
        Sleep::assertNeverSlept();
        $this->assertCount(1, $this->logs->getRecords());
        $this->assertSame('unexpected_error', $this->logs->getRecords()[0]->context['error_code']);
        $this->assertSafeOutput();
        $this->assertDomainEmpty();
    }

    #[DataProvider('invalidConfigurations')]
    public function test_rejects_invalid_configuration_without_sending(string $key, mixed $value): void
    {
        config(['services.demo_pay.'.$key => $value]);
        Http::fake();
        $this->assertSame(2, $this->runSender());
        Http::assertNothingSent();
        Sleep::assertNeverSlept();
        $this->assertDomainEmpty();
        $this->assertSafeOutput();
    }

    public static function invalidConfigurations(): iterable
    {
        foreach (['sender_attempts' => 5, 'sender_backoff_ms' => 2000, 'sender_timeout_seconds' => 60] as $key => $max) {
            foreach ([0, -1, $max + 1, true, false, 1.5, 3.0, null, '', '03', ' 3', '3x', '9999999999999999999999999999'] as $index => $value) {
                yield $key.'_'.$index => [$key, $value];
            }
        }
        foreach (['', null, 'LEAK_MARKER', 'ftp://app/file', '/relative/path', 'http:///missing-host'] as $index => $value) {
            yield 'url_'.$index => ['webhook_url', $value];
        }
        yield 'empty_secret' => ['webhook_secret', ''];
        yield 'missing_secret' => ['webhook_secret', null];
    }

    #[DataProvider('invalidFiles')]
    public function test_rejects_invalid_files_without_sending(string $kind): void
    {
        if ($kind === 'missing') {
            unlink($this->file);
        } elseif ($kind === 'directory') {
            unlink($this->file);
            $this->file = storage_path('framework/testing');
        } else {
            file_put_contents($this->file, $kind === 'empty' ? '' : str_repeat('x', 65537));
        }
        Http::fake();
        $this->assertSame(2, $this->runSender());
        Http::assertNothingSent();
        Sleep::assertNeverSlept();
        $this->assertSafeOutput();
    }

    public static function invalidFiles(): array
    {
        return [['missing'], ['empty'], ['oversized'], ['directory']];
    }

    public function test_accepts_maximum_body_and_one_attempt_without_waiting(): void
    {
        config(['services.demo_pay.sender_attempts' => '1', 'services.demo_pay.sender_timeout_seconds' => '1']);
        $raw = str_repeat('x', 65536);
        file_put_contents($this->file, $raw);
        Http::fake(function (Request $request, array $options) use ($raw) {
            $this->assertSame($raw, $request->body());
            $this->assertSame(1, $options['timeout']);
            $this->assertSame(1, $options['connect_timeout']);

            return Http::response('', 503);
        });
        $this->assertSame(1, $this->runSender());
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    private function runSender(): int
    {
        return Artisan::call('demo:webhook:send', ['file' => $this->file]);
    }

    private function assertSafeOutput(): void
    {
        $visible = Artisan::output().json_encode(array_map(fn ($record) => $record->toArray(), $this->logs->getRecords()), JSON_THROW_ON_ERROR);
        foreach (['LEAK_MARKER', self::SECRET, self::BODY, self::URL, 'X-Webhook-Signature', 'pgsql:', 'trace'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $visible);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Actions\ProcessWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\SignedWebhookRequests;
use Tests\TestCase;

class DemoPayHttpContractTest extends TestCase
{
    use SignedWebhookRequests;

    #[DataProvider('unsupportedMediaTypes')]
    public function test_h11_rejects_unsupported_media_type(?string $contentType): void
    {
        $this->rejectAction();
        $this->assertRejected($this->sendRaw(overrides: ['CONTENT_TYPE' => $contentType]), 415, 'unsupported_media_type');
    }

    public static function unsupportedMediaTypes(): array
    {
        return [
            'H11 missing' => [null], 'H11 empty' => [''], 'H11 plain' => ['text/plain'],
            'H11 text json' => ['text/json'], 'H11 suffix json' => ['application/problem+json'],
            'H11 jsonp' => ['application/jsonp'], 'H11 media list' => ['application/json,text/plain'],
        ];
    }

    #[DataProvider('bodySizes')]
    public function test_h11_enforces_byte_limit(int $size, bool $multibyte, bool $accepted): void
    {
        $prefix = substr(self::BODY, 0, -1).',"extra":"';
        $length = $size - strlen($prefix) - 2;
        $padding = $multibyte ? str_repeat('é', intdiv($length, 2)).str_repeat('x', $length % 2) : str_repeat('x', $length);
        $raw = $prefix.$padding.'"}';
        $this->assertSame($size, strlen($raw));
        if ($multibyte) {
            $this->assertLessThan($size, mb_strlen($raw));
        }

        if ($accepted) {
            $this->expectAction();
            $this->sendRaw($raw)->assertOk();
            $this->assertDomainEmpty();
        } else {
            $this->rejectAction();
            $this->assertRejected($this->sendRaw($raw, ['CONTENT_LENGTH' => '1']), 413, 'payload_too_large');
        }
    }

    public static function bodySizes(): array
    {
        return [
            'H11 ASCII at limit' => [65536, false, true], 'H11 ASCII over limit' => [65537, false, false],
            'H11 multibyte at limit' => [65536, true, true], 'H11 multibyte over limit' => [65537, true, false],
        ];
    }

    #[DataProvider('jsonMediaTypes')]
    public function test_h12_accepts_charset_without_accept(string $contentType): void
    {
        $this->expectAction();
        $this->sendRaw(overrides: ['CONTENT_TYPE' => $contentType])->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertDomainEmpty();
    }

    public static function jsonMediaTypes(): array
    {
        return ['H12 charset' => ['application/json; charset=utf-8'], 'H12 mixed case' => ['Application/JSON; charset=UTF-8']];
    }

    #[DataProvider('unsupportedMethods')]
    public function test_h12_returns_json_for_unsupported_methods(string $method): void
    {
        $this->rejectAction();
        $response = $this->call($method, self::URI);
        $this->assertRejected($response, 405, 'method_not_allowed');
        $response->assertHeader('Allow', 'POST');
    }

    public static function unsupportedMethods(): array
    {
        return ['H12 GET' => ['GET'], 'H12 PUT' => ['PUT']];
    }

    #[DataProvider('methodOverrides')]
    public function test_h12_ignores_method_override_for_signed_posts(string $raw, string $uri, array $server): void
    {
        $this->expectAction();
        $server = array_replace($this->signedHeaders($raw), $server);

        $this->call('POST', $uri, [], [], [], $server, $raw)->assertOk();
        $this->assertDomainEmpty();
    }

    #[DataProvider('methodOverrides')]
    public function test_h12_rejects_unsigned_posts_with_method_override(string $raw, string $uri, array $server): void
    {
        $this->rejectAction();
        $server = array_replace(['CONTENT_TYPE' => 'application/json'], $server);

        $this->assertRejected($this->call('POST', $uri, [], [], [], $server, $raw), 401, 'invalid_signature');
    }

    public static function methodOverrides(): array
    {
        return [
            'H12 JSON method override' => [substr(self::BODY, 0, -1).',"_method":"PUT"}', self::URI, []],
            'H12 header method override' => [self::BODY, self::URI, ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT']],
            'H12 query method override' => [self::BODY, self::URI.'?_method=PUT', []],
        ];
    }

    #[DataProvider('rejectionPrecedence')]
    public function test_rejection_precedence(string $raw, string $contentType, bool $emptySecret, bool $validSignature, int $status, string $error): void
    {
        if ($emptySecret) {
            config(['services.demo_pay.webhook_secret' => '']);
        }
        $headers = ['CONTENT_TYPE' => $contentType];
        if (! $validSignature) {
            $headers['HTTP_X_WEBHOOK_SIGNATURE'] = null;
        }
        $this->rejectAction();
        $this->assertRejected($this->sendRaw($raw, $headers), $status, $error);
    }

    public static function rejectionPrecedence(): array
    {
        return [
            'H11 size before media and secret' => [str_repeat('x', 65537), 'text/plain', true, false, 413, 'payload_too_large'],
            'H11 media before secret' => [self::BODY, 'text/plain', true, false, 415, 'unsupported_media_type'],
            'H06 secret before headers' => [self::BODY, 'application/json', true, false, 503, 'temporarily_unavailable'],
            'H02 signature before JSON' => ['{', 'application/json', false, false, 401, 'invalid_signature'],
            'H07 JSON after signature' => ['{', 'application/json', false, true, 400, 'invalid_json'],
            'H08 schema after signature' => ['[]', 'application/json', false, true, 422, 'validation_failed'],
        ];
    }

    #[DataProvider('debugModes')]
    public function test_unexpected_exception_returns_safe_json(bool $debug): void
    {
        config(['app.debug' => $debug]);
        $this->mock(ProcessWebhook::class)->shouldReceive('handle')->once()
            ->andThrow(new RuntimeException('LEAK_MARKER SELECT * FROM payments; pgsql:host=private-db secret='.self::SECRET));
        $this->assertRejected($this->sendRaw(), 500, 'internal_error');
    }

    public static function debugModes(): array
    {
        return ['debug disabled' => [false], 'debug enabled' => [true]];
    }

    public function test_d08_default_action_records_missing_order_rejection(): void
    {
        $this->sendRaw()->assertStatus(409)->assertExactJson([
            'event_id' => 'evt_demo_001', 'outcome' => 'rejected',
            'reason' => 'order_not_found', 'duplicate' => false,
        ]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('webhook_receipts', 1);
        $receipt = (array) DB::table('webhook_receipts')->sole();
        $this->assertTrue(Str::isUuid($receipt['id']));
        $this->assertSame([
            'id' => $receipt['id'], 'provider' => 'demo-pay', 'event_id' => 'evt_demo_001',
            'event_type' => 'payment.succeeded', 'body_sha256' => hash('sha256', self::BODY),
            'outcome' => 'rejected', 'reason_code' => 'order_not_found', 'http_status' => 409,
            'completed_at' => '2026-10-23 12:00:00.123456+00',
            'order_id' => '0199a600-0000-7000-8000-000000000001',
        ], $receipt);
    }
}

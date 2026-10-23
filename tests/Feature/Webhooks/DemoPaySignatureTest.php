<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Data\WebhookEvent;
use App\Enums\Currency;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SignedWebhookRequests;
use Tests\TestCase;

class DemoPaySignatureTest extends TestCase
{
    use SignedWebhookRequests;

    public function test_h01_passes_verified_dto_to_action(): void
    {
        $raw = "\n ".self::BODY."\n";
        $this->expectAction(function (WebhookEvent $event) use ($raw): void {
            $this->assertSame('evt_demo_001', $event->eventId);
            $this->assertSame('payment.succeeded', $event->eventType);
            $this->assertSame(hash('sha256', $raw), $event->bodySha256);
            $this->assertSame('pay_demo_001', $event->payment->paymentId);
            $this->assertSame('0199a600-0000-7000-8000-000000000001', $event->payment->orderId);
            $this->assertSame(12990, $event->payment->amountMinor);
            $this->assertSame(Currency::EUR, $event->payment->currency);
        });

        $this->sendRaw($raw)->assertOk()->assertExactJson([
            'event_id' => 'evt_demo_001', 'outcome' => 'processed', 'duplicate' => false,
        ])->assertHeaderMissing('Set-Cookie');
        $this->assertDomainEmpty();
    }

    #[DataProvider('invalidSignatures')]
    public function test_h02_rejects_invalid_signature(?string $signature): void
    {
        $this->rejectAction();
        $this->assertRejected($this->sendRaw(overrides: ['HTTP_X_WEBHOOK_SIGNATURE' => $signature]), 401, 'invalid_signature');
    }

    public static function invalidSignatures(): array
    {
        return [
            'H02 missing' => [null], 'H02 empty' => [''],
            'H02 prefix' => ['v2='.str_repeat('a', 64)],
            'H02 short' => ['v1='.str_repeat('a', 63)],
            'H02 long' => ['v1='.str_repeat('a', 65)],
            'H02 uppercase' => ['v1='.str_repeat('A', 64)],
            'H02 nonhex' => ['v1='.str_repeat('g', 64)],
            'H02 multiple' => ['v1='.str_repeat('a', 64).',v1='.str_repeat('a', 64)],
            'H02 trailing newline' => ['v1='.str_repeat('a', 64)."\n"],
            'H02 whitespace' => [' v1='.str_repeat('a', 64)],
        ];
    }

    public function test_h02_rejects_foreign_secret(): void
    {
        $this->rejectAction();
        $this->assertRejected($this->sendRaw(overrides: $this->signedHeaders(self::BODY, secret: 'foreign-secret')), 401, 'invalid_signature');
    }

    public function test_h02_rejects_multiple_header_values(): void
    {
        $headers = $this->signedHeaders(self::BODY);
        $headers['HTTP_X_WEBHOOK_SIGNATURE'] = [$headers['HTTP_X_WEBHOOK_SIGNATURE'], $headers['HTTP_X_WEBHOOK_SIGNATURE']];
        $this->rejectAction();
        $this->assertRejected($this->sendRaw(overrides: $headers), 401, 'invalid_signature');
    }

    public function test_h03_rejects_body_changed_after_signing(): void
    {
        $this->rejectAction();
        $this->assertRejected($this->sendRaw(self::BODY.' ', $this->signedHeaders(self::BODY)), 401, 'invalid_signature');
    }

    #[DataProvider('invalidTimestamps')]
    public function test_h04_rejects_noncanonical_timestamp(?string $timestamp): void
    {
        $this->rejectAction();
        $headers = $this->signedHeaders(self::BODY, $timestamp ?? '');
        $headers['HTTP_X_WEBHOOK_TIMESTAMP'] = $timestamp;
        $this->assertRejected($this->sendRaw(overrides: $headers), 401, 'invalid_signature');
    }

    public static function invalidTimestamps(): array
    {
        return [
            'H04 missing' => [null], 'H04 empty' => [''], 'H04 leading zero' => ['01792756800'],
            'H04 negative' => ['-1'], 'H04 plus' => ['+1'], 'H04 float' => ['1.0'],
            'H04 exponent' => ['1e3'], 'H04 space' => [' 1'], 'H04 newline' => ["1\n"],
            'H04 non ASCII' => ['١'], 'H04 too long' => ['1000000000000'],
        ];
    }

    public function test_h04_accepts_zero_at_epoch(): void
    {
        $this->travelTo(CarbonImmutable::createFromTimestampUTC(0));
        $this->expectAction();
        $this->sendRaw(overrides: $this->signedHeaders(self::BODY, '0'))->assertOk();
        $this->assertDomainEmpty();
    }

    #[DataProvider('timestampOffsets')]
    public function test_h05_checks_inclusive_timestamp_window(int $offset, bool $accepted): void
    {
        $headers = $this->signedHeaders(self::BODY, (string) (now()->getTimestamp() + $offset));
        if ($accepted) {
            $this->expectAction();
            $this->sendRaw(overrides: $headers)->assertOk();
            $this->assertDomainEmpty();
        } else {
            $this->rejectAction();
            $this->assertRejected($this->sendRaw(overrides: $headers), 401, 'invalid_signature');
        }
    }

    public static function timestampOffsets(): array
    {
        return ['H05 -300' => [-300, true], 'H05 +300' => [300, true], 'H05 -301' => [-301, false], 'H05 +301' => [301, false]];
    }

    #[DataProvider('missingSecrets')]
    public function test_h06_rejects_missing_secret(?string $secret): void
    {
        $headers = $this->signedHeaders(self::BODY);
        config(['services.demo_pay.webhook_secret' => $secret]);
        $this->rejectAction();
        $this->assertRejected($this->sendRaw(overrides: $headers), 503, 'temporarily_unavailable');
    }

    public static function missingSecrets(): array
    {
        return ['H06 empty' => [''], 'H06 absent' => [null]];
    }

    #[DataProvider('literalSecrets')]
    public function test_h01_uses_secret_as_literal_string(string $secret): void
    {
        config(['services.demo_pay.webhook_secret' => $secret]);
        $this->expectAction();
        $this->sendRaw(overrides: $this->signedHeaders(self::BODY, secret: $secret))->assertOk();
        $this->assertDomainEmpty();
    }

    public static function literalSecrets(): array
    {
        return ['H01 zero' => ['0'], 'H01 whitespace' => ['  literal key  ']];
    }
}

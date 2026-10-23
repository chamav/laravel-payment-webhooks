<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Data\WebhookEvent;
use App\Enums\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SignedWebhookRequests;
use Tests\TestCase;

class DemoPayValidationTest extends TestCase
{
    use SignedWebhookRequests;

    #[DataProvider('invalidJson')]
    public function test_h07_rejects_invalid_json(string $raw): void
    {
        $this->rejectAction();
        $this->assertRejected($this->sendRaw($raw), 400, 'invalid_json');
    }

    public static function invalidJson(): array
    {
        return [
            'H07 truncated' => ['{"id":'], 'H07 empty' => [''], 'H07 whitespace' => [' '],
            'H07 trailing comma' => ['{"id":"evt",}'], 'H07 invalid UTF8' => ["{\"id\":\"\xff\"}"],
            'H07 excessive depth' => [str_repeat('[', 513).str_repeat(']', 513)],
        ];
    }

    #[DataProvider('invalidEnvelopes')]
    public function test_h08_rejects_invalid_envelope(string $raw): void
    {
        $this->rejectAction();
        $this->assertRejected($this->sendRaw($raw), 422, 'validation_failed');
    }

    public static function invalidEnvelopes(): iterable
    {
        foreach (['[]', '[{}]', 'null', 'true', '1', '"event"', '{}'] as $index => $raw) {
            yield 'H08 root '.$index => [$raw];
        }
        foreach (['[]', '[1]', 'null', 'false', '1', '"data"'] as $index => $data) {
            yield 'H08 data '.$index => ['{"id":"evt","type":"other","data":'.$data.'}'];
        }
        foreach (['id', 'type', 'data'] as $field) {
            $body = ['id' => 'evt', 'type' => 'other', 'data' => (object) []];
            unset($body[$field]);
            yield 'H08 missing '.$field => [json_encode($body, JSON_THROW_ON_ERROR)];
        }
        foreach (['id', 'type'] as $field) {
            foreach (['', null, 12, true, [], (object) [], str_repeat('a', 101), 'has space', "evt\n", 'é', '/'] as $index => $value) {
                $body = ['id' => 'evt', 'type' => 'other', 'data' => (object) [], $field => $value];
                yield 'H08 '.$field.' invalid '.$index => [json_encode($body, JSON_THROW_ON_ERROR)];
            }
        }
        yield 'H08 id disallows dot' => ['{"id":"evt.1","type":"other","data":{}}'];
    }

    #[DataProvider('unknownObjects')]
    public function test_h08_accepts_unknown_type_with_object_data(string $raw): void
    {
        $this->expectAction(function (WebhookEvent $event): void {
            $this->assertNull($event->payment);
            $this->assertSame('payment.refunded', $event->eventType);
        });
        $this->sendRaw($raw)->assertOk();
        $this->assertDomainEmpty();
    }

    public static function unknownObjects(): array
    {
        return [
            'H08 empty object' => ['{"id":"evt","type":"payment.refunded","data":{}}'],
            'H08 numeric property' => ['{"id":"evt","type":"payment.refunded","data":{"0":"object"}}'],
            'H08 no payment schema' => ['{"id":"evt","type":"payment.refunded","data":{"amount_minor":false}}'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_h09_rejects_non_strict_amount(string $token): void
    {
        $raw = str_replace('"amount_minor":12990', '"amount_minor":'.$token, self::BODY);
        $this->rejectAction();
        $this->assertRejected($this->sendRaw($raw), 422, 'validation_failed');
    }

    public static function invalidAmounts(): array
    {
        return [
            'H09 zero' => ['0'], 'H09 negative' => ['-1'], 'H09 over limit' => ['1000000001'],
            'H09 fractional' => ['1.5'], 'H09 whole float' => ['12990.0'], 'H09 exponent' => ['1e3'],
            'H09 string' => ['"12990"'], 'H09 true' => ['true'], 'H09 false' => ['false'],
            'H09 null' => ['null'], 'H09 array' => ['[]'], 'H09 object' => ['{}'],
            'H09 integer overflow' => ['999999999999999999999999999999'],
        ];
    }

    #[DataProvider('invalidPaymentFields')]
    public function test_h10_rejects_invalid_payment_fields(string $raw): void
    {
        $this->rejectAction();
        $this->assertRejected($this->sendRaw($raw), 422, 'validation_failed');
    }

    public static function invalidPaymentFields(): iterable
    {
        $base = json_decode(self::BODY, true, flags: JSON_THROW_ON_ERROR);
        foreach (['payment_id', 'order_id', 'amount_minor', 'currency'] as $field) {
            $body = $base;
            unset($body['data'][$field]);
            yield 'H10 missing '.$field => [json_encode($body, JSON_THROW_ON_ERROR)];
        }
        foreach ([
            'payment_id' => ['', null, true, 12, [], (object) [], str_repeat('a', 101), 'pay.1', ' pay', "pay\n", 'é'],
            'order_id' => ['', 'not-uuid', 12, true, null, [], (object) []],
            'currency' => ['eur', 'usd', 'GBP', '', true, 12, null, [], (object) []],
        ] as $field => $values) {
            foreach ($values as $index => $value) {
                $body = $base;
                $body['data'][$field] = $value;
                yield 'H10 '.$field.' invalid '.$index => [json_encode($body, JSON_THROW_ON_ERROR)];
            }
        }
    }

    #[DataProvider('validPaymentBoundaries')]
    public function test_h01_accepts_payment_boundaries_and_ignores_extra_fields(int $amount, int $length, string $currency): void
    {
        $body = json_decode(self::BODY, true, flags: JSON_THROW_ON_ERROR);
        $body['id'] = str_repeat('a', $length);
        $body['data']['payment_id'] = str_repeat('b', $length);
        $body['data']['amount_minor'] = $amount;
        $body['data']['currency'] = $currency;
        $body['extra'] = ['ignored' => true];
        $body['data']['extra'] = 'ignored';
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $this->expectAction(function (WebhookEvent $event) use ($amount, $length, $currency, $raw): void {
            $this->assertSame(str_repeat('a', $length), $event->eventId);
            $this->assertSame(str_repeat('b', $length), $event->payment->paymentId);
            $this->assertSame($amount, $event->payment->amountMinor);
            $this->assertSame(Currency::from($currency), $event->payment->currency);
            $this->assertSame(hash('sha256', $raw), $event->bodySha256);
        });
        $this->sendRaw($raw)->assertOk();
        $this->assertDomainEmpty();
    }

    public static function validPaymentBoundaries(): array
    {
        return ['H01 minimum EUR' => [1, 1, 'EUR'], 'H01 maximum USD' => [1000000000, 100, 'USD']];
    }

    public function test_h08_accepts_maximum_type_length_and_all_allowed_characters(): void
    {
        $type = str_repeat('a', 95).'._-Z9';
        $raw = '{"data":{},"type":"'.$type.'","id":"AZaz09_-"}';
        $this->expectAction(function (WebhookEvent $event) use ($type): void {
            $this->assertSame($type, $event->eventType);
            $this->assertSame('AZaz09_-', $event->eventId);
        });
        $this->sendRaw($raw)->assertOk();
        $this->assertDomainEmpty();
    }
}

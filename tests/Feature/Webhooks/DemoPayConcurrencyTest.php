<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Carbon\CarbonImmutable;
use Tests\Support\CommittedWebhookTestCase;
use Tests\Support\ConcurrentWebhookRunner;

class DemoPayConcurrencyTest extends CommittedWebhookTestCase
{
    // Separate clocks expose accidental rewrites by the duplicate or rejected worker.
    private const array CLOCKS = [
        'a' => '2026-10-23 12:00:00.123456 UTC',
        'b' => '2026-10-23 12:00:07.654321 UTC',
    ];

    public function test_c01_replays_identical_event_in_overlapping_processes(): void
    {
        $order = $this->committedOrder();
        $raw = $this->paymentBody($order);

        $this->assertRace(['a' => $raw, 'b' => $raw], [$order], [
            [200, ['outcome' => 'processed', 'duplicate' => false]],
            [200, ['outcome' => 'processed', 'duplicate' => true]],
        ], sameEvent: true);
    }

    public function test_c02_deduplicates_payment_for_same_order_in_overlapping_processes(): void
    {
        $order = $this->committedOrder();

        $this->assertRace([
            'a' => $this->paymentBody($order, 'evt_a', 'pay_shared'),
            'b' => $this->paymentBody($order, 'evt_b', 'pay_shared'),
        ], [$order], [
            [200, ['outcome' => 'processed', 'duplicate' => false]],
            [200, ['outcome' => 'duplicate_payment', 'duplicate' => false]],
        ]);
    }

    public function test_c03_rejects_second_payment_for_same_order_in_overlapping_processes(): void
    {
        $order = $this->committedOrder();

        $this->assertRace([
            'a' => $this->paymentBody($order, 'evt_a', 'pay_a'),
            'b' => $this->paymentBody($order, 'evt_b', 'pay_b'),
        ], [$order], [
            [200, ['outcome' => 'processed', 'duplicate' => false]],
            [409, ['outcome' => 'rejected', 'duplicate' => false, 'reason' => 'order_already_paid']],
        ]);
    }

    public function test_c04_rejects_shared_payment_for_different_orders_in_overlapping_processes(): void
    {
        $first = $this->committedOrder();
        $second = $this->committedOrder();
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($first['amount_minor'], $second['amount_minor']);
        $this->assertSame($first['currency'], $second['currency']);

        $this->assertRace([
            'a' => $this->paymentBody($first, 'evt_a', 'pay_shared'),
            'b' => $this->paymentBody($second, 'evt_b', 'pay_shared'),
        ], [$first, $second], [
            [200, ['outcome' => 'processed', 'duplicate' => false]],
            [409, ['outcome' => 'rejected', 'duplicate' => false, 'reason' => 'payment_conflict']],
        ]);
    }

    public function test_c05_rejects_changed_body_for_same_event_in_overlapping_processes(): void
    {
        $order = $this->committedOrder();
        // Either body alone fully pays this fixture; only the provider payment identity differs.
        $this->assertRace([
            'a' => $this->paymentBody($order, 'evt_same', 'pay_a'),
            'b' => $this->paymentBody($order, 'evt_same', 'pay_b'),
        ], [$order], [
            [200, ['outcome' => 'processed', 'duplicate' => false]],
            [409, ['error' => 'event_conflict']],
        ], sameEvent: true);
    }

    private function committedOrder(): array
    {
        $timestamp = now()->subHour()->format('Y-m-d H:i:s.uP');

        return $this->insertOrder(['created_at' => $timestamp, 'updated_at' => $timestamp]);
    }

    private function assertRace(array $bodies, array $orders, array $expected, bool $sameEvent = false): void
    {
        $requests = [];
        foreach ($bodies as $label => $raw) {
            $clock = CarbonImmutable::parse(self::CLOCKS[$label]);
            $requests[$label] = [
                'raw' => $raw, 'clock' => $clock->toISOString(),
                'headers' => $this->signedHeaders($raw, (string) $clock->getTimestamp()),
            ];
        }
        $runner = new ConcurrentWebhookRunner(fn () => $this->auxiliaryConnection('concurrency_blocker'));
        $results = $runner->run($requests, array_column($orders, 'id'), $sameEvent);
        $outcomes = [];
        foreach ($results as $label => $result) {
            $body = $result['body'];
            if (! isset($body['error'])) {
                $event = json_decode($bodies[$label], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($event['id'], $body['event_id'] ?? null, 'Response event identity for worker '.$label);
                unset($body['event_id']);
            }
            $outcomes[] = [$result['status'], $body];
        }
        $this->assertSame($this->responseMultiset($expected), $this->responseMultiset($outcomes));
        $this->assertFinalState($bodies, $orders, $results);
    }

    private function responseMultiset(array $responses): array
    {
        $keys = array_map(function (array $response): string {
            ksort($response[1]);

            return json_encode($response, JSON_THROW_ON_ERROR);
        }, $responses);
        sort($keys);

        return $keys;
    }

    private function assertFinalState(array $bodies, array $orders, array $results): void
    {
        $expectedOrders = array_column($orders, null, 'id');
        $payments = [];
        $receipts = [];
        foreach ($results as $label => $result) {
            $body = $result['body'];
            if (isset($body['error']) || $body['duplicate']) {
                continue;
            }
            $this->travelTo(CarbonImmutable::parse(self::CLOCKS[$label]));
            $raw = $bodies[$label];
            $receipts[] = $this->expectedReceipt($raw, $body['outcome'], $body['reason'] ?? null);
            if ($body['outcome'] === 'processed') {
                $event = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                $orderId = $event['data']['order_id'];
                $payments[] = $this->expectedPayment($raw);
                $expectedOrders[$orderId] = $this->expectedPaidOrder($expectedOrders[$orderId]);
            }
        }
        // Full rows include hashes, decisions, links and microsecond timestamps; losing orders stay intact.
        $this->assertCount(1, $payments);
        $this->assertTables(array_values($expectedOrders), $payments, $receipts);
    }
}

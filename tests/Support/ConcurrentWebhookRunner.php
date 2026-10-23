<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use Throwable;

final class ConcurrentWebhookRunner
{
    // Boot before locking; the 30000 ms start gate covers readiness plus blocker setup.
    // Every lock-window deadline < worker lock timeout (15000 ms) < blocker idle timeout (20000 ms).
    // Readiness and results are outside the lock window; the 60 s process budget covers all phases.
    private const int READY_MS = 20000;

    private const int OVERLAP_MS = 6000;

    private const int RESULT_MS = 15000;

    private const int CLEANUP_MS = 5000;

    private const int POLL_US = 25000;

    private array $workers = [];

    private array $identities = [];

    private array $output = [];

    private array $outputBytes = [];

    private array $evidence = [];

    private array $responses = [];

    private string $phase = 'startup';

    private string $token;

    private string $directory;

    /** @param Closure(): Connection $blockerFactory */
    public function __construct(private readonly Closure $blockerFactory) {}

    /** @param array<string, array{raw: string, headers: array, clock: string}> $requests */
    public function run(array $requests, array $orderIds, bool $sameEvent): array
    {
        $this->token = bin2hex(random_bytes(8));
        $this->directory = storage_path('framework/testing/webhook-concurrency/'.$this->token);
        $observer = DB::connection();
        $blocker = null;
        $oldTimeout = null;
        $failure = null;
        $results = [];
        try {
            Assert::assertSame(['a', 'b'], array_keys($requests));
            Assert::assertSame(0, $observer->transactionLevel(), 'Fixtures must be committed before worker startup.');
            TestDatabaseGuard::assertSafe(app()->environment(), $observer);
            $oldTimeout = $observer->selectOne('SHOW statement_timeout')->statement_timeout;
            $observer->statement("SET statement_timeout = '1000ms'");
            Assert::assertTrue(mkdir($this->directory, 0700, true));
            foreach ($requests as $label => $request) {
                $this->start($label, $request);
            }
            $this->phase = 'readiness';
            $this->poll(self::READY_MS, function (): bool {
                foreach ($this->workers as $label => $worker) {
                    $path = $this->directory.'/'.$label.'.ready';
                    if (! isset($this->identities[$label]) && is_file($path)) {
                        Assert::assertLessThanOrEqual(4096, filesize($path), 'Oversized readiness record.');
                        $identity = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                        Assert::assertIsArray($identity);
                        Assert::assertSame($worker->getPid(), $identity['os_pid'] ?? null);
                        Assert::assertIsInt($identity['backend_pid'] ?? null);
                        Assert::assertGreaterThan(0, $identity['backend_pid']);
                        Assert::assertSame($this->applicationName($label), $identity['application_name'] ?? null);
                        $this->identities[$label] = $identity;
                    }
                }

                return count($this->identities) === 2;
            });

            // Four sessions: two independently booted workers, this autocommit observer and a blocker.
            $blocker = ($this->blockerFactory)();
            $blocker->statement("SET idle_in_transaction_session_timeout = '20000ms'");
            Assert::assertSame(0, $blocker->transactionLevel());
            $observerPid = (int) $observer->selectOne('SELECT pg_backend_pid() AS pid')->pid;
            $blockerPid = (int) $blocker->selectOne('SELECT pg_backend_pid() AS pid')->pid;
            $pids = array_column($this->identities, 'backend_pid');
            Assert::assertCount(4, array_unique([...$pids, $observerPid, $blockerPid]));
            Assert::assertCount(3, array_unique([...array_column($this->identities, 'os_pid'), getmypid()]));
            $this->debug('ready', ['workers' => $this->identities, 'observer_pid' => $observerPid, 'blocker_pid' => $blockerPid]);
            $this->phase = 'blocker_setup';
            $blocker->beginTransaction();
            sort($orderIds);
            Assert::assertNotEmpty($orderIds);
            Assert::assertSame($orderIds, $blocker->table('orders')->whereIn('id', $orderIds)
                ->orderBy('id')->lockForUpdate()->pluck('id')->all());

            $this->phase = 'overlap';
            $overlapStarted = hrtime(true);
            Assert::assertNotFalse(file_put_contents($this->directory.'/start', 'start'));
            $this->poll(self::OVERLAP_MS, function () use ($observer, $pids, $blockerPid, $sameEvent): bool {
                $this->evidence = $this->observe($observer, [...$pids, $blockerPid]);

                return $this->provesOverlap($pids, $blockerPid, $sameEvent);
            }, started: $overlapStarted);
            $this->debug('overlap_proven', ['sessions' => $this->evidence, 'elapsed_ms' => (hrtime(true) - $overlapStarted) / 1000000]);
            $blocker->rollBack();

            $this->phase = 'results';
            $this->poll(self::RESULT_MS, function () use (&$results): bool {
                foreach ($this->workers as $label => $worker) {
                    if (! isset($results[$label]) && ! $worker->isRunning()) {
                        $results[$label] = $this->result($label);
                    }
                }

                return count($results) === 2;
            }, allowExit: true);
            ksort($results);
            $this->debug('completed', ['statuses' => array_column($results, 'status')]);
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $cleanup = $this->cleanup($observer, $blocker, $oldTimeout);
        }
        if ($failure !== null || $cleanup !== []) {
            $message = $failure instanceof AssertionFailedError ? $failure->getMessage()
                : ($failure === null ? 'Cleanup failed.' : 'Harness error: '.get_class($failure).' code '.$failure->getCode());
            Assert::fail($message.' '.json_encode([
                'run' => $this->token, 'phase' => $this->phase, 'workers' => $this->identities,
                'sessions' => $this->evidence, 'responses' => $this->responses, 'cleanup' => $cleanup,
                'exit_codes' => array_map(fn (Process $worker) => $worker->getExitCode(), $this->workers),
            ], JSON_THROW_ON_ERROR));
        }

        return $results;
    }

    private function start(string $label, array $request): void
    {
        $environment = [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => 'db-test', 'DB_PORT' => '5432', 'DB_DATABASE' => 'webhooks_test',
            'DB_USERNAME' => 'webhooks_test', 'DB_PASSWORD' => 'webhooks_test',
            'WEBHOOK_SECRET' => config('services.demo_pay.webhook_secret'),
            'WEBHOOK_LOCK_TIMEOUT_MS' => '15000', 'WEBHOOK_STATEMENT_TIMEOUT_MS' => '30000',
            'WEBHOOK_TRANSACTION_ATTEMPTS' => '3', 'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'debug',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'PGCONNECT_TIMEOUT' => '2',
        ];
        $worker = new Process([
            PHP_BINARY, base_path('tests/Support/bin/webhook-worker.php'),
            $this->directory, $label, $this->applicationName($label),
        ], base_path(), $environment, json_encode($request, JSON_THROW_ON_ERROR), 60);
        $this->workers[$label] = $worker;
        $this->output[$label] = '';
        $this->outputBytes[$label] = [Process::OUT => 0, Process::ERR => 0];
        $worker->start(function (string $type, string $chunk) use ($label): void {
            $this->outputBytes[$label][$type] += strlen($chunk);
            if ($type === Process::OUT && strlen($this->output[$label]) < 8192) {
                $this->output[$label] .= substr($chunk, 0, 8192 - strlen($this->output[$label]));
            }
        });
    }

    private function poll(int $milliseconds, Closure $condition, bool $allowExit = false, ?int $started = null): void
    {
        $started ??= hrtime(true);
        $deadline = $started + $milliseconds * 1000000;
        do {
            foreach ($this->workers as $label => $worker) {
                $worker->checkTimeout();
                $running = $worker->isRunning();
                $worker->clearOutput()->clearErrorOutput();
                Assert::assertLessThanOrEqual(8192, $this->outputBytes[$label][Process::OUT], 'Worker stdout limit.');
                Assert::assertLessThanOrEqual(16384, $this->outputBytes[$label][Process::ERR], 'Worker stderr limit.');
                Assert::assertTrue($allowExit || $running, 'Worker '.$label.' exited before '.$this->phase.'.');
                if (! $running) {
                    Assert::assertSame(0, $worker->getExitCode(), 'Worker '.$label.' failed.');
                }
            }
            $done = $condition();
            Assert::assertLessThan($deadline, hrtime(true), 'Deadline exceeded in '.$this->phase
                .' after '.(int) ((hrtime(true) - $started) / 1000000).' ms.');
            if ($done) {
                return;
            }
            // Scheduling backoff, never evidence of overlap.
            usleep(self::POLL_US);
        } while (true);
    }

    private function result(string $label): array
    {
        Assert::assertLessThanOrEqual(8192, $this->outputBytes[$label][Process::OUT], 'Worker stdout limit.');
        Assert::assertLessThanOrEqual(16384, $this->outputBytes[$label][Process::ERR], 'Worker stderr limit.');
        $result = json_decode($this->output[$label], true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($result);
        foreach ($this->identities[$label] as $key => $value) {
            Assert::assertSame($value, $result[$key] ?? null, 'Worker identity changed.');
        }
        Assert::assertSame('application/json', $result['content_type'] ?? null);
        Assert::assertIsInt($result['status'] ?? null);
        Assert::assertIsArray($result['body'] ?? null);
        $this->responses[$label] = ['status' => $result['status'], 'body' => array_intersect_key(
            $result['body'], array_flip(['outcome', 'duplicate', 'reason', 'error']),
        )];
        Assert::assertContains($result['status'], [200, 409], 'Unexpected concurrency response.');

        return $result;
    }

    private function observe(Connection $observer, array $pids): array
    {
        // Only safe phase labels leave PostgreSQL; no SQL text or bindings enter diagnostics.
        $rows = $observer->select(<<<'SQL'
            SELECT pid, state, wait_event_type, wait_event, datname, usename, application_name,
                   to_json(pg_blocking_pids(pid))::text AS blockers,
                   CASE WHEN query ~* '^select .* from "orders" .*for update' THEN 'order_lock'
                        WHEN query ~* '^insert into "webhook_receipts" ' THEN 'receipt_claim'
                        ELSE 'other' END AS phase
            FROM pg_stat_activity WHERE pid IN (?, ?, ?)
            SQL, $pids);
        $sessions = [];
        foreach ($rows as $row) {
            $session = (array) $row;
            $session['blockers'] = json_decode($row->blockers, true, flags: JSON_THROW_ON_ERROR);
            $sessions[(int) $row->pid] = $session;
        }

        return $sessions;
    }

    private function provesOverlap(array $pids, int $blockerPid, bool $sameEvent): bool
    {
        $phases = [];
        foreach ($pids as $pid) {
            $row = $this->evidence[$pid] ?? [];
            $identity = array_values(array_filter($this->identities, fn ($value) => $value['backend_pid'] === $pid))[0];
            if (($row['state'] ?? null) !== 'active' || ($row['wait_event_type'] ?? null) !== 'Lock'
                || ($row['datname'] ?? null) !== 'webhooks_test' || ($row['usename'] ?? null) !== 'webhooks_test'
                || ($row['application_name'] ?? null) !== $identity['application_name']
                || ! $this->reaches($pid, $blockerPid)) {
                return false;
            }
            $phases[$pid] = $row['phase'];
        }
        if (! $sameEvent) {
            return array_values($phases) === ['order_lock', 'order_lock'];
        }
        // Same-event loser waits on the owner's uncommitted receipt, never on the order itself.
        $owner = array_search('order_lock', $phases, true);
        $waiter = array_search('receipt_claim', $phases, true);

        return $owner !== false && $waiter !== false && $this->reaches($waiter, $owner);
    }

    private function reaches(int $pid, int $target, array $visited = []): bool
    {
        if (in_array($pid, $visited, true)) {
            return false;
        }
        foreach ($this->evidence[$pid]['blockers'] ?? [] as $next) {
            if ($next === $target || $this->reaches($next, $target, [...$visited, $pid])) {
                return true;
            }
        }

        return false;
    }

    private function cleanup(Connection $observer, ?Connection $blocker, ?string $oldTimeout): array
    {
        $errors = [];
        $attempt = function (string $label, Closure $action) use (&$errors): void {
            try {
                $action();
            } catch (Throwable $error) {
                $errors[] = $label.':'.get_class($error).':'.$error->getCode();
            }
        };
        // Stop and reap children before releasing the blocker, including partial startup failures.
        foreach ($this->workers as $label => $worker) {
            $attempt('stop_'.$label, function () use ($worker): void {
                $worker->stop(0.2, 9);
                Assert::assertFalse($worker->isRunning(), 'Worker survived termination.');
            });
        }
        $attempt('blocker_rollback', fn () => $blocker?->rollBack(0));
        if ($blocker !== null) {
            // Its registered configuration belongs to the base teardown and must remain resolvable.
            $attempt('blocker_purge', fn () => DB::purge($blocker->getName()));
        }
        $attempt('worker_backends', function () use ($observer): void {
            $deadline = hrtime(true) + self::CLEANUP_MS * 1000000;
            do {
                $remaining = 0;
                foreach ($this->workers as $label => $worker) {
                    // Application name also covers death between connecting and publishing readiness.
                    $pid = $this->identities[$label]['backend_pid'] ?? null;
                    $rows = $observer->select(<<<'SQL'
                        SELECT pid FROM pg_stat_activity
                        WHERE datname = 'webhooks_test' AND usename = 'webhooks_test'
                          AND application_name = ? AND (?::integer IS NULL OR pid = ?::integer)
                        SQL, [$this->applicationName($label), $pid, $pid]);
                    foreach ($rows as $row) {
                        $remaining++;
                        $observer->selectOne(<<<'SQL'
                            SELECT pg_terminate_backend(pid) FROM pg_stat_activity
                            WHERE pid = ? AND datname = 'webhooks_test' AND usename = 'webhooks_test'
                              AND application_name = ?
                            SQL, [$row->pid, $this->applicationName($label)]);
                    }
                }
                Assert::assertLessThan($deadline, hrtime(true), 'Worker backend cleanup deadline exceeded.');
                if ($remaining === 0) {
                    return;
                }
                usleep(self::POLL_US);
            } while (true);
        });
        $attempt('observer_timeout', function () use ($observer, $oldTimeout): void {
            if ($oldTimeout !== null) {
                $observer->statement("SELECT set_config('statement_timeout', ?, false)", [$oldTimeout]);
            }
        });
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            $attempt('remove_file', fn () => Assert::assertTrue(unlink($path)));
        }
        if (is_dir($this->directory)) {
            $attempt('remove_directory', fn () => Assert::assertTrue(rmdir($this->directory)));
        }

        return $errors;
    }

    private function applicationName(string $label): string
    {
        return 'webhook_race_'.$this->token.'_'.$label;
    }

    private function debug(string $phase, array $context): void
    {
        Log::debug('Webhook concurrency '.$phase, ['run' => $this->token] + $context);
    }
}

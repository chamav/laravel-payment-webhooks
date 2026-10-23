<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Tests\Support\TestDatabaseGuard;

// This executable is test support only. It never migrates or cleans a database.
$connection = null;
$exitCode = 0;
try {
    require dirname(__DIR__, 3).'/vendor/autoload.php';

    foreach ([
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
        'DB_HOST' => 'db-test', 'DB_PORT' => '5432', 'DB_DATABASE' => 'webhooks_test',
        'DB_USERNAME' => 'webhooks_test', 'DB_PASSWORD' => 'webhooks_test',
    ] as $key => $expected) {
        if (getenv($key) !== $expected) {
            throw new RuntimeException('unsafe_environment');
        }
    }

    $directory = $argv[1] ?? '';
    $label = $argv[2] ?? '';
    $applicationName = $argv[3] ?? '';
    $root = realpath(dirname(__DIR__, 3).'/storage/framework/testing/webhook-concurrency');
    if ($root === false || realpath(dirname($directory)) !== $root || ! is_dir($directory)
        || ! in_array($label, ['a', 'b'], true)
        || preg_match('/^webhook_race_[a-f0-9]{16}_[ab]$/D', $applicationName) !== 1) {
        throw new RuntimeException('invalid_worker_identity');
    }

    $input = stream_get_contents(STDIN, 131073);
    if ($input === false || strlen($input) > 131072) {
        throw new RuntimeException('invalid_worker_input');
    }
    $descriptor = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($descriptor) || ! is_string($descriptor['raw'] ?? null)
        || ! is_array($descriptor['headers'] ?? null) || ! is_string($descriptor['clock'] ?? null)) {
        throw new RuntimeException('invalid_worker_input');
    }

    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    if ($app->configurationIsCached()) {
        throw new RuntimeException('cached_configuration');
    }
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if ($app->environment() !== 'testing' || config('database.default') !== 'pgsql') {
        throw new RuntimeException('unsafe_configuration');
    }
    foreach ([
        'driver' => 'pgsql', 'host' => 'db-test', 'port' => '5432',
        'database' => 'webhooks_test', 'username' => 'webhooks_test', 'password' => 'webhooks_test',
    ] as $key => $expected) {
        if ((string) config('database.connections.pgsql.'.$key) !== $expected) {
            throw new RuntimeException('unsafe_configuration');
        }
    }
    if (! in_array(config('database.connections.pgsql.url'), [null, ''], true)) {
        throw new RuntimeException('unsafe_configuration');
    }

    $connection = $app->make('db')->connection();
    TestDatabaseGuard::assertSafe($app->environment(), $connection);
    $connection->statement("SELECT set_config('application_name', ?, false)", [$applicationName]);
    $connection->statement("SET statement_timeout = '30000ms'");
    $connection->statement("SET idle_in_transaction_session_timeout = '8000ms'");
    $backendPid = (int) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
    Date::setTestNow(CarbonImmutable::parse($descriptor['clock']));
    $identity = ['os_pid' => getmypid(), 'backend_pid' => $backendPid, 'application_name' => $applicationName];
    $ready = $directory.'/'.$label.'.ready';
    if (file_put_contents($ready.'.tmp', json_encode($identity, JSON_THROW_ON_ERROR)) === false
        || ! rename($ready.'.tmp', $ready)) {
        throw new RuntimeException('readiness_write_failed');
    }

    // The 30000 ms startup gate covers 20000 ms readiness plus blocker setup; lock waits still prove overlap.
    $deadline = hrtime(true) + 30000 * 1000000;
    while (! is_file($directory.'/start')) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('start_gate_deadline');
        }
        usleep(25000);
    }

    $request = Request::create('/api/webhooks/demo-pay', 'POST', server: $descriptor['headers'], content: $descriptor['raw']);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    if ((int) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid !== $backendPid) {
        throw new RuntimeException('backend_changed');
    }
    echo json_encode($identity + [
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        'content_type' => $response->headers->get('Content-Type'),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Exception messages may contain SQL, bindings or credentials. Expose only safe type/code.
    fwrite(STDERR, json_encode(['worker_error' => get_class($error), 'code' => (string) $error->getCode()]).PHP_EOL);
    $exitCode = 1;
} finally {
    try {
        try {
            if (class_exists(Date::class) && Date::getFacadeRoot() !== null) {
                Date::setTestNow();
            }
        } finally {
            $connection?->disconnect();
        }
    } catch (Throwable $error) {
        fwrite(STDERR, json_encode(['cleanup_error' => get_class($error), 'code' => (string) $error->getCode()]).PHP_EOL);
        $exitCode = 1;
    }
}
exit($exitCode);

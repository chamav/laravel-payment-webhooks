<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WebhookFailureFixtures
{
    private readonly string $name;

    public function __construct()
    {
        $this->name = 'webhook_fault_'.str_replace('-', '', (string) Str::uuid7());
    }

    public function install(string $state, int $failures = 100, bool $sleep = false): void
    {
        TestDatabaseGuard::assertSafe(app()->environment(), DB::connection());
        if (preg_match('/^[0-9A-Z]{5}$/D', $state) !== 1 || $failures < 0) {
            throw new InvalidArgumentException('Invalid test fault.');
        }
        $name = $this->name;
        // Identifiers and error states are generated/validated here, never sourced from a request.
        $message = $state === '40P01' ? 'deadlock detected' : 'LEAK_MARKER injected failure';
        $failure = $sleep ? 'PERFORM pg_sleep(5);' : "RAISE EXCEPTION USING ERRCODE = '$state', MESSAGE = '$message';";
        DB::statement("CREATE SEQUENCE {$name}_attempts");
        DB::unprepared(<<<SQL
            CREATE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM payments WHERE order_id = NEW.order_id)
                    OR NOT EXISTS (SELECT 1 FROM orders WHERE id = NEW.order_id AND status = 'paid') THEN
                    RAISE EXCEPTION 'Payment and paid order must precede receipt finalization';
                END IF;
                IF nextval('{$name}_attempts') <= $failures THEN
                    $failure
                END IF;
                RETURN NEW;
            END;
            \$\$
            SQL);
        DB::statement("CREATE TRIGGER $name BEFORE UPDATE ON webhook_receipts FOR EACH ROW EXECUTE FUNCTION $name()");
    }

    public function attempts(): int
    {
        return (int) DB::selectOne("SELECT last_value FROM {$this->name}_attempts")->last_value;
    }

    public function drop(): void
    {
        TestDatabaseGuard::assertSafe(app()->environment(), DB::connection());
        DB::statement("DROP TRIGGER IF EXISTS {$this->name} ON webhook_receipts");
        DB::statement("DROP FUNCTION IF EXISTS {$this->name}()");
        DB::statement("DROP SEQUENCE IF EXISTS {$this->name}_attempts");
    }

    public function exists(): bool
    {
        return DB::table('pg_class')->where('relname', $this->name.'_attempts')->exists()
            || DB::table('pg_proc')->where('proname', $this->name)->exists()
            || DB::table('pg_trigger')->where('tgname', $this->name)->exists();
    }
}

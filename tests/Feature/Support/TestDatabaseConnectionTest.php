<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseConnectionTest extends TestCase
{
    public function test_p03_uses_real_webhooks_test_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('webhooks_test', DB::selectOne('SELECT current_database() AS name')->name);
    }
}

<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->guardTestDatabase($app);

        return $app;
    }

    protected function guardTestDatabase(?Application $app = null): void
    {
        $app ??= $this->app;
        $names = [null];

        foreach (['connectionsToTransact', 'connectionsToTruncate'] as $property) {
            if (property_exists($this, $property)) {
                $names = array_merge($names, $this->{$property});
            }
        }

        foreach (array_unique($names) as $name) {
            TestDatabaseGuard::assertSafe($app->environment(), $app->make('db')->connection($name));
        }
    }
}

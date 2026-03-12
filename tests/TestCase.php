<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private static bool $setUpHasRunOnce = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$setUpHasRunOnce) {
            $this->artisan('migrate:fresh --seed');
            self::$setUpHasRunOnce = true;
        }
        // This tells Laravel to ignore the index error for SQLite
        if (config('database.default') === 'sqlite') {
            \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        }
    }
}

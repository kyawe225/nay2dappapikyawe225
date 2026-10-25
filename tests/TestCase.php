<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'audit', '--path' => 'database/migrations/audit',
            '--force' => true, '--no-interaction' => true,
        ])->assertExitCode(0);
    }
}

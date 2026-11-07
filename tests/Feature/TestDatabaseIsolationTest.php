<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_running_suite_uses_sqlite_in_memory(): void
    {
        $this->assertSame('sqlite', \Illuminate\Support\Facades\DB::connection()->getDriverName());
        $this->assertSame(':memory:', \Illuminate\Support\Facades\DB::connection()->getDatabaseName());
        $this->assertSame('testing', app()->environment());
    }
}

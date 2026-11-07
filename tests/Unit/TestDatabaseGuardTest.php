<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    public function test_blocks_mysql_cached_config_and_wrong_env(): void
    {
        $this->assertNull(TestDatabaseGuard::problem('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], 'testing', false));
        $this->assertNotNull(TestDatabaseGuard::problem('mysql', ['driver' => 'mysql', 'database' => 'app_cassio'], 'testing', false));
        $this->assertNotNull(TestDatabaseGuard::problem('sqlite', ['driver' => 'sqlite', 'database' => '/var/www/html/database/database.sqlite'], 'testing', false));
        $this->assertNotNull(TestDatabaseGuard::problem('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], 'production', false));
        $this->assertNotNull(TestDatabaseGuard::problem('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], 'testing', true));
    }
}

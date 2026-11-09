<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        refreshDatabase as baseRefreshDatabase;
        migrateFreshUsing as baseMigrateFreshUsing;
    }

    /**
     * Refuse to wipe anything but a *_test database (tests read .env.testing).
     */
    public function refreshDatabase()
    {
        $database = config('database.connections.mariadb_ddl.database');

        if (! $this->app->environment('testing') || ! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException('Tests must run with APP_ENV=testing on a *_test database (.env.testing).');
        }

        $this->baseRefreshDatabase();
    }

    /**
     * Migrations run as the DDL user, as in production; app queries use the
     * default (runtime) connection.
     */
    protected function migrateFreshUsing()
    {
        return array_merge($this->baseMigrateFreshUsing(), ['--database' => 'mariadb_ddl']);
    }
}

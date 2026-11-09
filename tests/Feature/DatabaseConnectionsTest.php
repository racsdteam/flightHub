<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseConnectionsTest extends TestCase
{
    /**
     * MariaDB DDL commits implicitly, so these tests run without the
     * RefreshDatabase wrapping transaction.
     */
    protected $connectionsToTransact = [];

    private const SCRATCH = 'zz_scratch_privilege_test';

    protected function tearDown(): void
    {
        Schema::connection('mariadb_ddl')->dropIfExists(self::SCRATCH);

        parent::tearDown();
    }

    public function test_runtime_connection_is_refused_create_table(): void
    {
        $this->assertSame('mariadb', DB::getDefaultConnection());

        try {
            DB::connection('mariadb')->statement('CREATE TABLE '.self::SCRATCH.' (id INT)');
            $this->fail('The runtime connection was allowed to CREATE TABLE.');
        } catch (QueryException $e) {
            $this->assertSame(1142, $e->errorInfo[1]);
            $this->assertStringContainsString('CREATE command denied', $e->getMessage());
        }

        $this->assertFalse(Schema::connection('mariadb_ddl')->hasTable(self::SCRATCH));
    }

    public function test_runtime_connection_is_refused_drop_table(): void
    {
        DB::connection('mariadb_ddl')->statement('CREATE TABLE '.self::SCRATCH.' (id INT)');

        try {
            DB::connection('mariadb')->statement('DROP TABLE '.self::SCRATCH);
            $this->fail('The runtime connection was allowed to DROP TABLE.');
        } catch (QueryException $e) {
            $this->assertSame(1142, $e->errorInfo[1]);
            $this->assertStringContainsString('DROP command denied', $e->getMessage());
        }

        $this->assertTrue(Schema::connection('mariadb_ddl')->hasTable(self::SCRATCH));
    }

    public function test_ddl_connection_can_create_and_drop_a_scratch_table(): void
    {
        $ddl = DB::connection('mariadb_ddl');

        $ddl->statement('CREATE TABLE '.self::SCRATCH.' (id INT)');
        $this->assertTrue(Schema::connection('mariadb_ddl')->hasTable(self::SCRATCH));

        $ddl->statement('DROP TABLE '.self::SCRATCH);
        $this->assertFalse(Schema::connection('mariadb_ddl')->hasTable(self::SCRATCH));
    }

    public function test_both_connections_use_utc_session_time_zone(): void
    {
        foreach (['mariadb', 'mariadb_ddl'] as $connection) {
            $row = DB::connection($connection)->selectOne('SELECT @@session.time_zone AS tz');

            $this->assertSame('+00:00', $row->tz, "$connection session time zone");
        }
    }

    public function test_app_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }
}

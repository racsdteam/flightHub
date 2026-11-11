<?php

namespace Tests\Feature\Api;

use App\Api\ApiKeys;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * G9 (api_calls protection, pruning) and G10 (management commands).
 */
class ApiCallsAndCommandsTest extends TestCase
{
    use ApiHelpers;

    protected $connectionsToTransact = ['mariadb', 'mariadb_ddl'];

    private function logRow(string $connection, int $ageDays): int
    {
        return DB::connection($connection)->table('api_calls')->insertGetId([
            'at' => DB::raw("UTC_TIMESTAMP(6) - INTERVAL {$ageDays} DAY"),
            'method' => 'GET', 'path' => '/api/v1/health', 'status' => 401, 'duration_ms' => 1, 'ip' => '127.0.0.1',
            'error_code' => 'UNAUTHORIZED',
        ]);
    }

    public function test_runtime_is_refused_update_and_recent_delete_on_api_calls(): void
    {
        $db = DB::connection('mariadb');
        $recent = $this->logRow('mariadb', 10);
        $old = $this->logRow('mariadb', 366);

        try {
            $db->update('UPDATE api_calls SET status = 200 WHERE id = ?', [$recent]);
            $this->fail('UPDATE allowed');
        } catch (QueryException $e) {
            $this->assertSame(1142, $e->errorInfo[1]);
        }
        try {
            $db->delete('DELETE FROM api_calls WHERE id = ?', [$recent]);
            $this->fail('DELETE of a recent row allowed');
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
        }
        $this->assertSame(1, $db->delete('DELETE FROM api_calls WHERE id = ?', [$old]));
    }

    public function test_triggers_refuse_update_even_for_ddl(): void
    {
        $id = $this->logRow('mariadb_ddl', 10);

        try {
            DB::connection('mariadb_ddl')->update('UPDATE api_calls SET status = 200 WHERE id = ?', [$id]);
            $this->fail('UPDATE allowed');
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
            $this->assertStringContainsString('api_calls is append-only', $e->errorInfo[2]);
        }
    }

    public function test_prune_api_calls_removes_rows_older_than_a_year(): void
    {
        $this->logRow('mariadb', 366);
        $kept = $this->logRow('mariadb', 364);

        $this->artisan('hub:prune-api-calls')->expectsOutputToContain('1 API calls older than 1 year deleted')->assertExitCode(0);
        $this->assertSame([$kept], DB::table('api_calls')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_consumer_and_key_commands(): void
    {
        $this->artisan('hub:consumer-create', ['code' => 'kia-pulse', 'name' => 'KIA PULSE', '--groups' => 'route,times', '--by' => 'Dany Kabalisa'])->assertExitCode(0);
        $consumer = DB::table('consumers')->where('code', 'kia-pulse')->sole();
        $this->assertSame(['route', 'times'], json_decode($consumer->field_groups, true));
        $this->assertSame(120, (int) $consumer->rate_limit_per_minute);

        $this->artisan('hub:consumer-create', ['code' => 'bad', 'name' => 'x', '--groups' => 'route,secret', '--by' => 'D'])->assertExitCode(1);
        $this->artisan('hub:consumer-update', ['code' => 'kia-pulse', '--rate' => '60', '--groups' => 'route,times,rotation'])->assertExitCode(0);
        $this->assertSame(60, (int) DB::table('consumers')->where('code', 'kia-pulse')->value('rate_limit_per_minute'));

        // The key is printed once, on stdout, and stored only as its hash.
        Artisan::call('hub:key-issue', ['consumer' => 'kia-pulse', '--label' => 'production', '--by' => 'Dany Kabalisa']);
        $output = Artisan::output();
        $this->assertSame(1, preg_match_all('/fdh_[0-9A-Za-z]{8}_[0-9A-Za-z]{40}/', $output, $m));
        $key = $m[0][0];
        $row = DB::table('api_keys')->sole();
        $this->assertSame(ApiKeys::hash($key), $row->secret_hash);
        $this->assertStringNotContainsString(substr($key, 13), json_encode(DB::table('api_keys')->get()).json_encode(DB::table('consumers')->get()));

        $this->api('health', $key)->assertOk();

        // Revoking takes effect on the next call.
        $this->artisan('hub:key-revoke', ['prefix' => $row->prefix, '--by' => 'Dany Kabalisa'])->assertExitCode(0);
        $this->api('health', $key)->assertStatus(401);
        $this->assertSame('Dany Kabalisa', DB::table('api_keys')->value('revoked_by'));

        // Disabling the consumer does the same for all its keys.
        Artisan::call('hub:key-issue', ['consumer' => 'kia-pulse', '--label' => 'second', '--by' => 'Dany Kabalisa']);
        preg_match('/fdh_[0-9A-Za-z]{8}_[0-9A-Za-z]{40}/', Artisan::output(), $m);
        $this->api('health', $m[0])->assertOk();
        $this->artisan('hub:consumer-update', ['code' => 'kia-pulse', '--active' => '0'])->assertExitCode(0);
        $this->api('health', $m[0])->assertStatus(401);
    }

    public function test_key_issue_validates_its_input(): void
    {
        $this->artisan('hub:key-issue', ['consumer' => 'nobody', '--label' => 'x', '--by' => 'D'])->assertExitCode(1);
        $this->artisan('hub:consumer-create', ['code' => 'kia-pulse', 'name' => 'KIA PULSE', '--groups' => 'route', '--by' => 'D'])->assertExitCode(0);
        $this->artisan('hub:key-issue', ['consumer' => 'kia-pulse', '--label' => 'x', '--by' => 'D', '--expires' => '2020-01-01'])->assertExitCode(1);
        $this->artisan('hub:key-issue', ['consumer' => 'kia-pulse', '--label' => '', '--by' => 'D'])->assertExitCode(1);
        $this->assertSame(0, DB::table('api_keys')->count());
    }
}

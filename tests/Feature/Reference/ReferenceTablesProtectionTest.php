<?php

namespace Tests\Feature\Reference;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The runtime user edits reference data through the screens (batch 6b): it may
 * read, insert and update the reference tables, never delete (deactivate).
 */
class ReferenceTablesProtectionTest extends TestCase
{
    public static function tables(): array
    {
        return [
            'airport_code_corrections' => ['airport_code_corrections', ['aodb_value' => 'TST', 'corrected_iata' => 'TST', 'reason' => 'test', 'reviewed_by' => 'x', 'reviewed_on' => '2026-11-11']],
            'aircraft_types' => ['aircraft_types', ['airline_iata' => 'WB', 'ac_type_iata' => 'DH8', 'reviewed_by' => 'x', 'reviewed_on' => '2026-11-11']],
            'stands' => ['stands', ['code' => 'A9', 'reviewed_by' => 'x', 'reviewed_on' => '2026-11-11']],
        ];
    }

    #[DataProvider('tables')]
    public function test_runtime_may_insert_and_update_but_never_delete(string $table, array $row): void
    {
        $db = DB::connection('mariadb');

        $id = $db->table($table)->insertGetId($row);
        $this->assertSame(1, $db->table($table)->where('id', $id)->update(['active' => false]));

        try {
            $db->delete("DELETE FROM {$table} WHERE id = ?", [$id]);
            $this->fail('DELETE was allowed');
        } catch (QueryException $e) {
            $this->assertSame(1142, $e->errorInfo[1]);
            $this->assertStringContainsString('DELETE command denied', $e->getMessage());
        }
    }
}

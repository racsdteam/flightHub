<?php

namespace Tests\Benchmark;

use App\Flights\FlightQuery;
use App\Flights\Window;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * B8: plan and timing of the as_of query on ~90,000 versions. Reports numbers,
 * asserts nothing. Excluded from composer test; run with:
 *   composer test -- --group=benchmark
 * Seeding is committed (ANALYZE commits implicitly), so tearDown truncates.
 */
#[Group('benchmark')]
class AsOfBenchmarkTest extends TestCase
{
    protected $connectionsToTransact = [];

    private const DAYS = 60;

    private const FLIGHTS_PER_DAY = 50;

    private const VERSIONS_PER_FLIGHT = 30;

    protected function tearDown(): void
    {
        $ddl = DB::connection('mariadb_ddl');
        $ddl->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['change_events', 'flight_versions', 'flights', 'raw_responses', 'polls'] as $table) {
            // TRUNCATE fires no DELETE trigger; only the DDL user may run it.
            $ddl->statement("TRUNCATE TABLE {$table}");
        }
        $ddl->statement('SET FOREIGN_KEY_CHECKS = 1');

        parent::tearDown();
    }

    public function test_as_of_plan_and_timing(): void
    {
        $seconds = $this->seedVersions();
        DB::connection('mariadb_ddl')->select('ANALYZE TABLE flights, flight_versions');

        $out = [sprintf(
            'seeded %d flights, %d versions in %.1f s',
            DB::table('flights')->count(),
            DB::table('flight_versions')->count(),
            $seconds,
        )];

        $cases = [
            '1-day' => [Window::kigaliDay('2026-10-01'), '2026-10-01T10:00:00Z'],
            '7-day' => [
                Window::between(new DateTimeImmutable('2026-09-30T22:00:00Z'), new DateTimeImmutable('2026-10-07T22:00:00Z')),
                '2026-10-04T12:00:00Z',
            ],
        ];

        foreach ($cases as $label => [$window, $asOf]) {
            $asOf = new DateTimeImmutable($asOf);
            $query = new FlightQuery;

            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = $query->flightsInWindow($window, $asOf);
            DB::disableQueryLog();
            ['query' => $sql, 'bindings' => $bindings] = DB::getQueryLog()[0];

            $times = [];
            for ($i = 0; $i < 7; $i++) {
                $start = hrtime(true);
                $query->flightsInWindow($window, $asOf);
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            sort($times);

            $out[] = '';
            $out[] = sprintf(
                '%s window [%s, %s) as of %s: %d flights; ms min %.1f / median %.1f / max %.1f (7 runs)',
                $label,
                $window->from->format('Y-m-d\TH:i\Z'),
                $window->to->format('Y-m-d\TH:i\Z'),
                $asOf->format('Y-m-d\TH:i\Z'),
                count($rows),
                $times[0],
                $times[3],
                $times[6],
            );
            $out[] = 'rows examined (Handler_read_* delta, one run): '.$this->rowsExamined($sql, $bindings);
            $out[] = 'ANALYZE (EXPLAIN + actual rows):';
            foreach (DB::select('ANALYZE '.$sql, $bindings) as $row) {
                $out[] = sprintf(
                    '  id=%s %-18s table=%-6s type=%-6s key=%s rows=%s r_rows=%s filtered=%s r_filtered=%s extra=%s',
                    $row->id,
                    $row->select_type,
                    $row->table,
                    $row->type,
                    $row->key ?? 'NULL',
                    $row->rows,
                    $row->r_rows ?? 'NULL',
                    $row->filtered ?? 'NULL',
                    $row->r_filtered ?? 'NULL',
                    $row->Extra ?? '',
                );
            }
        }

        $report = implode("\n", $out)."\n";
        file_put_contents(storage_path('logs/benchmark-as-of.txt'), $report);
        fwrite(STDERR, "\n".$report);

        $this->addToAssertionCount(1);
    }

    private function rowsExamined(string $sql, array $bindings): string
    {
        $read = fn () => collect(DB::select("SHOW SESSION STATUS LIKE 'Handler_read%'"))
            ->mapWithKeys(fn ($r) => [$r->Variable_name => (int) $r->Value]);
        $before = $read();
        DB::select($sql, $bindings);
        $after = $read();
        $delta = $after->map(fn ($v, $k) => $v - $before[$k])->filter();

        return $delta->sum().' ('.$delta->map(fn ($v, $k) => str_replace('Handler_read_', '', $k).'='.$v)->implode(', ').')';
    }

    /**
     * ~60 Kigali days x 50 flights x 30 versions. Every 10th flight is
     * rescheduled 3 h later from its 21st version on.
     */
    private function seedVersions(): float
    {
        $start = hrtime(true);
        $ddl = DB::connection('mariadb_ddl');
        $firstDay = new DateTimeImmutable('2026-08-31T22:00:00Z'); // Kigali 2026-09-01 00:00
        $fmt = 'Y-m-d H:i:s';

        $flights = [];
        for ($d = 0; $d < self::DAYS; $d++) {
            for ($i = 0; $i < self::FLIGHTS_PER_DAY; $i++) {
                $base = $firstDay->modify(sprintf('+%d days +%d minutes', $d, 120 + $i * 20));
                $flights[] = [$d, $i, $base];
            }
        }

        $ddl->transaction(function () use ($ddl, $flights, $fmt) {
            foreach (array_chunk($flights, 1000) as $chunk) {
                $ddl->table('flights')->insert(array_map(fn ($f) => [
                    'aodb_id' => sprintf('BENCH-%02d-%02d', $f[0], $f[1]),
                    'flow' => $f[1] % 2 ? 'ARR' : 'DEP',
                    'full_flight_number' => sprintf('XX%03d', $f[1]),
                    'reference_time' => $f[2]->modify($f[1] % 10 === 0 ? '+3 hours' : '+0 hours')->format($fmt),
                    'reference_time_source' => $f[1] % 2 ? 'sibt' : 'sobt',
                    'first_seen_at' => $f[2]->modify('-48 hours')->format($fmt),
                    'last_seen_at' => $f[2]->format($fmt),
                    'current_version_no' => self::VERSIONS_PER_FLIGHT,
                ], $chunk));
            }
        });

        $ids = $ddl->table('flights')->pluck('id', 'aodb_id');

        $ddl->transaction(function () use ($ddl, $flights, $ids, $fmt) {
            $batch = [];
            foreach ($flights as [$d, $i, $base]) {
                $flightId = $ids[sprintf('BENCH-%02d-%02d', $d, $i)];
                $arr = $i % 2 === 1;
                for ($v = 0; $v < self::VERSIONS_PER_FLIGHT; $v++) {
                    $ref = ($i % 10 === 0 && $v >= 20) ? $base->modify('+3 hours') : $base;
                    $batch[] = [
                        'flight_id' => $flightId,
                        'version_no' => $v + 1,
                        'valid_from' => $base->modify(sprintf('-48 hours +%d minutes', $v * 100))->format('Y-m-d H:i:s.u'),
                        'cause' => 'aodb',
                        'flow' => $arr ? 'ARR' : 'DEP',
                        'full_flight_number' => sprintf('XX%03d', $i),
                        'reg' => sprintf('9XR-%02d', $i),
                        'status' => $v < 25 ? 'SCHEDULED' : 'IN_FLIGHT',
                        'sibt' => $arr ? $ref->format($fmt) : null,
                        'sobt' => $arr ? null : $ref->format($fmt),
                        'reference_time' => $ref->format($fmt),
                        'reference_time_source' => $arr ? 'sibt' : 'sobt',
                        'in_feed' => true,
                    ];
                    if (count($batch) === 1000) {
                        $ddl->table('flight_versions')->insert($batch);
                        $batch = [];
                    }
                }
            }
            if ($batch !== []) {
                $ddl->table('flight_versions')->insert($batch);
            }
        });

        return (hrtime(true) - $start) / 1e9;
    }
}

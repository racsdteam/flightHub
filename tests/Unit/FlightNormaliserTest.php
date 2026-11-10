<?php

namespace Tests\Unit;

use App\Aodb\CanonicalJson;
use App\Collector\FlightNormaliser;
use PHPUnit\Framework\TestCase;

class FlightNormaliserTest extends TestCase
{
    public function test_times_are_parsed_to_utc_or_left_null(): void
    {
        $this->assertSame('2026-10-29 05:04:00', FlightNormaliser::fit('2026-10-29T05:04:00Z', 'datetime', null));
        $this->assertSame('2026-10-29 05:04:00', FlightNormaliser::fit('2026-10-29T07:04:00+02:00', 'datetime', null));
        $this->assertSame('2026-10-29 05:04:00', FlightNormaliser::fit('2026-10-29T05:04:00.123Z', 'datetime', null));
        $this->assertSame('2026-10-29 05:04:00', FlightNormaliser::fit('2026-10-29T05:04Z', 'datetime', null));
        $this->assertNull(FlightNormaliser::fit('2026-10-29T05:04:00', 'datetime', null), 'no zone');
        $this->assertNull(FlightNormaliser::fit('2026-02-30T05:04:00Z', 'datetime', null));
        $this->assertNull(FlightNormaliser::fit('05:04', 'datetime', null));
        $this->assertNull(FlightNormaliser::fit(1761714240, 'datetime', null));
    }

    public function test_integers_strings_and_decimals_fit_or_are_left_null(): void
    {
        $this->assertSame(96, FlightNormaliser::fit(96, 'int', null));
        $this->assertSame(96, FlightNormaliser::fit('96', 'int', null));
        $this->assertNull(FlightNormaliser::fit('abc', 'int', null));
        $this->assertNull(FlightNormaliser::fit(9.5, 'int', null));
        $this->assertNull(FlightNormaliser::fit(3000000000, 'int', null));

        $this->assertSame('A5', FlightNormaliser::fit('A5', 'string', 32));
        $this->assertSame('464', FlightNormaliser::fit(464, 'string', 32));
        $this->assertNull(FlightNormaliser::fit(str_repeat('x', 33), 'string', 32));
        $this->assertNull(FlightNormaliser::fit(true, 'string', 32));
        $this->assertNull(FlightNormaliser::fit(['A5'], 'string', 32));

        $this->assertSame('79.000', FlightNormaliser::fit(79, 'decimal', null));
        $this->assertNull(FlightNormaliser::fit('heavy', 'decimal', null));
    }

    public function test_corrections_fill_served_values_and_keep_twins(): void
    {
        $normaliser = new FlightNormaliser(['NB0' => (object) ['id' => 7, 'corrected_iata' => 'NBO', 'corrected_icao' => 'HKJK']]);

        $flight = $normaliser->normalise(CanonicalJson::decode(
            '{"id":"x","flow":"ARR","adepIata":"NB0","adesIata":"KGL","adesIcao":"HRYR","sibt":"2026-10-29T06:00:00Z","aircraftDetails":{"acTypeName":"Embraer ERJ 190","wtc":"M","mtow":null}}'
        ));

        $this->assertSame(['NB0', 'NBO', null, 'HKJK'], [
            $flight->columns['adep_iata_aodb'], $flight->columns['adep_iata'],
            $flight->columns['adep_icao_aodb'], $flight->columns['adep_icao'],
        ]);
        $this->assertSame(['adepIata' => 'airport_code_corrections:7', 'adepIcao' => 'airport_code_corrections:7'], $flight->corrections);
        $this->assertSame(['KGL', 'HRYR'], [$flight->columns['ades_iata'], $flight->columns['ades_icao']]);
        $this->assertSame('Embraer ERJ 190', $flight->columns['ac_type_name']);
        $this->assertSame(['2026-10-29 06:00:00', 'sibt'], [$flight->columns['reference_time'], $flight->columns['reference_time_source']]);
    }
}

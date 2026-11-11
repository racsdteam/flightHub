<?php

namespace Tests\Feature\Collector;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * D-035: ghrOperatorDetails email, phone, address, zipcode and city are
 * stripped before a record is stored, compared or put in an event; name and id
 * stay; raw responses (90 days) keep everything.
 */
class GhrStrippingTest extends PollTestCase
{
    private const STRIPPED = [
        'email' => 'ops.lead@handler.test',
        'phone' => '+250-700-000-111',
        'address' => 'KN 5 Road, Plot 42',
        'zipcode' => 'ZIP-00042',
        'city' => 'Kigali-Gasabo-Remera',
    ];

    private function withGhr(array $overrides = []): stdClass
    {
        $payload = $this->sample();
        self::record($payload, self::WB711)->ghrOperatorDetails = (object) array_merge([
            'name' => 'Acme Ground Handling',
            'id' => 'GH-001',
            ...self::STRIPPED,
            'country' => 'RW',
        ], $overrides);

        return $payload;
    }

    private function assertNoTrace(string $haystack): void
    {
        foreach (self::STRIPPED as $key => $value) {
            $this->assertStringNotContainsString($value, $haystack);
            $this->assertStringNotContainsString('"'.$key.'"', $haystack);
        }
    }

    public function test_contact_details_leave_no_trace_in_records_or_events(): void
    {
        $this->poll($this->withGhr());

        $flight = $this->flight(self::WB711);
        $this->assertNoTrace($flight->record);
        $this->assertNoTrace($this->versions(self::WB711)[0]->record);
        $this->assertNoTrace(json_encode($this->events(self::WB711)));
        $this->assertSame(['country' => 'RW', 'id' => 'GH-001', 'name' => 'Acme Ground Handling'], (array) json_decode($flight->record)->ghrOperatorDetails);

        // Raw responses are untouched (kept 90 days).
        $this->assertStringContainsString(self::STRIPPED['email'], gzdecode(DB::table('raw_responses')->value('body')));
    }

    public function test_a_change_to_a_stripped_field_is_never_an_event(): void
    {
        $this->poll($this->withGhr());

        $poll = $this->poll($this->withGhr(['email' => 'someone.else@handler.test', 'phone' => '+250-700-999-999']));

        $this->assertSame(1, (int) $poll->content_changed);
        $this->assertSame(0, (int) $poll->events_written);
        $this->assertCount(1, $this->versions(self::WB711));
    }

    public function test_a_change_to_a_kept_field_is_an_event(): void
    {
        $this->poll($this->withGhr());

        $this->poll($this->withGhr(['name' => 'Acme Handling Ltd']));

        $events = $this->events(self::WB711, 'field_changed');
        $this->assertSame(['ghrOperatorDetails.name'], array_column($events, 'field'));
        $this->assertNoTrace(json_encode($events));
    }

    public function test_a_stored_record_holding_them_is_compared_stripped(): void
    {
        $this->poll();
        // A record stored before stripping existed.
        $legacy = json_decode($this->flight(self::WB711)->record);
        $legacy->ghrOperatorDetails = (object) ['email' => self::STRIPPED['email'], 'name' => 'Acme Ground Handling'];
        DB::table('flights')->where('aodb_id', self::WB711)->update(['record' => json_encode($legacy)]);

        $payload = $this->sample();
        self::record($payload, self::WB711)->ghrOperatorDetails = (object) ['name' => 'Acme Ground Handling'];
        $this->poll($payload);

        $this->assertSame([], $this->events(self::WB711, 'field_changed'));
    }
}

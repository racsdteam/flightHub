<?php

namespace Tests\Unit;

use App\Aodb\CanonicalJson;
use PHPUnit\Framework\TestCase;

class CanonicalJsonTest extends TestCase
{
    public function test_keys_are_sorted_recursively_and_key_order_is_not_a_change(): void
    {
        $a = CanonicalJson::decode('{"b":1,"a":{"y":[{"q":1,"p":2}],"x":null}}');
        $b = CanonicalJson::decode('{"a":{"x":null,"y":[{"p":2,"q":1}]},"b":1}');

        $this->assertSame('{"a":{"x":null,"y":[{"p":2,"q":1}]},"b":1}', CanonicalJson::encode($a));
        $this->assertSame(CanonicalJson::sha256($a), CanonicalJson::sha256($b));
    }

    public function test_nothing_is_added_or_removed(): void
    {
        $json = '{"e":{},"l":[],"f":1.0,"s":"Kigali/é","n":null}';

        $this->assertSame('{"e":{},"f":1.0,"l":[],"n":null,"s":"Kigali/é"}', CanonicalJson::encode(CanonicalJson::decode($json)));
    }

    public function test_array_order_matters(): void
    {
        $this->assertNotSame(
            CanonicalJson::sha256(CanonicalJson::decode('[1,2]')),
            CanonicalJson::sha256(CanonicalJson::decode('[2,1]')),
        );
    }

    public function test_leaves_are_dotted_paths_and_arrays_are_one_leaf(): void
    {
        $record = CanonicalJson::decode('{"id":"x","paxDetails":{"pmr":1,"um":null},"dlCodes":{"typeb":[{"code":"93"}]},"empty":{}}');

        $this->assertSame([
            'id' => '"x"',
            'paxDetails.pmr' => '1',
            'paxDetails.um' => 'null',
            'dlCodes.typeb' => '[{"code":"93"}]',
        ], CanonicalJson::leaves($record));
        $this->assertSame(1, CanonicalJson::get($record, 'paxDetails.pmr'));
        $this->assertNull(CanonicalJson::get($record, 'paxDetails.vip'));
        $this->assertNull(CanonicalJson::get($record, 'id.deeper'));
    }
}

<?php

namespace Tests\Unit;

use App\Quality\Condition;
use LengthException;
use PHPUnit\Framework\TestCase;

class ConditionTest extends TestCase
{
    public function test_key_is_rule_field_and_related_internal_id(): void
    {
        $this->assertSame('format_invalid|adepIata|', (new Condition('format_invalid', 'adepIata', null, []))->key());
        $this->assertSame('duplicate_suspected||42', (new Condition('duplicate_suspected', null, (object) ['id' => 42, 'aodb_id' => 'x'], []))->key());
    }

    public function test_key_longer_than_160_is_refused_with_a_clear_exception(): void
    {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessage('Quality flag key for rule format_invalid is longer than 160 characters.');

        Condition::keyOf('format_invalid', str_repeat('a', 150), null);
    }
}

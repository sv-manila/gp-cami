<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\JunkKeyGuard;
use Tests\Support\HubTestCase;

/**
 * Generalizes SsnHashGuard's cardinality idea (a value carried by implausibly
 * many distinct people cannot be one person's identifier) to any column,
 * config-driven, with no dependency on SsnHashGuard/SsnHasher — plan 2 deletes
 * those files. The placeholder half needs no DB; the cardinality half needs a
 * real hub, hence HubTestCase.
 *
 * Assertions go through $this->hub() rather than assertDatabaseHas(): the
 * default connection under phpunit.xml is sqlite :memory:, which holds none of
 * these tables, so assertDatabaseHas() would query the wrong database. Every
 * other DB-backed test in this suite uses the same hub()->table() pattern.
 */
class JunkKeyGuardTest extends HubTestCase
{
    public function test_configured_placeholder_is_blocked_with_no_db_round_trip(): void
    {
        config()->set('golden_profile.junk.placeholders.npi', ['1234567893']);

        // No stg_person rows staged at all — if this needed the cardinality
        // query it would find 0 people and NOT block, so a true pass here
        // proves the placeholder list is checked first.
        $this->assertTrue((new JunkKeyGuard)->isBlocked('npi', '1234567893'));
    }

    public function test_null_and_empty_values_are_never_blocked(): void
    {
        $guard = new JunkKeyGuard;

        $this->assertFalse($guard->isBlocked('npi', null));
        $this->assertFalse($guard->isBlocked('npi', ''));
    }

    public function test_a_value_under_the_cardinality_cap_is_not_blocked(): void
    {
        config()->set('golden_profile.junk.max_identities_per_value.npi', 3);

        $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Ann', 'last_name' => 'Lee']);
        $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Bob', 'last_name' => 'Diaz']);

        $this->assertFalse((new JunkKeyGuard)->isBlocked('npi', '1234567893'));
    }

    public function test_a_value_over_the_cardinality_cap_is_blocked(): void
    {
        config()->set('golden_profile.junk.max_identities_per_value.npi', 3);

        foreach (range(1, 4) as $i) {
            $this->stagePerson(['npi' => 1234567893, 'first_name' => "Person$i", 'last_name' => 'Distinct']);
        }

        $this->assertTrue((new JunkKeyGuard)->isBlocked('npi', '1234567893'));
    }

    public function test_cap_is_at_least_one(): void
    {
        config()->set('golden_profile.junk.max_identities_per_value.npi', 0);

        // A cap of 0 would block every value including real ones, silently
        // disabling the whole tier — same defensive floor as SsnHashGuard's.
        $this->assertGreaterThanOrEqual(1, (new JunkKeyGuard)->maxIdentitiesPerValue('npi'));
    }

    public function test_exclusion_sql_is_constant_size_and_scoped_to_the_column(): void
    {
        $sql = (new JunkKeyGuard)->exclusionSql('npi', 's.`npi`');

        $this->assertStringContainsString('gp_junk_value_blocklist', $sql);
        $this->assertStringContainsString("column_name = 'npi'", $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function test_build_blocklist_table_captures_both_reasons(): void
    {
        config()->set('golden_profile.junk.placeholders.npi', ['1111111111']);
        config()->set('golden_profile.junk.max_identities_per_value.npi', 3);

        foreach (range(1, 4) as $i) {
            $this->stagePerson(['npi' => 9999999990 + $i, 'first_name' => "P$i", 'last_name' => 'X']);
        }
        // Same npi shared by all 4 distinct people above (overwrite so they collide).
        $this->hub()->table('stg_person')->update(['npi' => 1999999992]);

        $blocked = (new JunkKeyGuard)->buildBlocklistTable('npi');

        $this->assertSame(2, $blocked); // 1 cardinality (1999999992) + 1 placeholder (1111111111)

        $this->assertSame(1, $this->hub()->table('gp_junk_value_blocklist')->where([
            'column_name' => 'npi', 'value' => '1999999992', 'reason' => 'cardinality',
        ])->count());
        $this->assertSame(1, $this->hub()->table('gp_junk_value_blocklist')->where([
            'column_name' => 'npi', 'value' => '1111111111', 'reason' => 'placeholder',
        ])->count());
    }
}

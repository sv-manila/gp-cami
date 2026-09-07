<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\DeterministicResolver;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class VersionedResolverTest extends HubTestCase
{
    private function resolve(int $stgPersonId): int
    {
        return (new DeterministicResolver($this->systemId))->resolve($stgPersonId);
    }

    public function test_a_new_identity_is_created_as_version_one_and_current(): void
    {
        $id = $this->resolve($this->stagePerson());

        $row = $this->hub()->table('gp_identity')->where('identity_id', $id)->first();

        $this->assertSame(1, (int) $row->version_no);
        $this->assertSame(1, (int) $row->current);
        $this->assertSame(1, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());
    }

    public function test_re_resolving_an_unchanged_row_mints_no_version(): void
    {
        // The new definition of idempotent. The link half is already covered by
        // ResolverLadderTest (gp_source_link is not versioned, so uq_source still
        // admits one row); this is the version half.
        $stg = $this->stagePerson(['npi' => 1234567893]);
        $this->stageLicense($stg, 'L-77', 'NY');

        $id = $this->resolve($stg);
        $this->resolve($stg);
        $this->resolve($stg);

        $this->assertSame(1, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());
        $this->assertSame(1, (int) $this->hub()->table('gp_license')->count());
        $this->assertSame(1, (int) $this->hub()->table('gp_source_link')->count());
    }

    public function test_a_later_row_supplying_a_missing_key_mints_a_version(): void
    {
        // backfillKeys used to UPDATE gp_identity in place. It is a change to a
        // golden fact, so it must now be a version.
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09']);
        $id = $this->resolve($a);

        $b = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09', 'npi' => 1234567893]);
        $this->assertSame($id, $this->resolve($b));

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $id)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->npi);
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('1234567893', (string) $rows[1]->npi);
        $this->assertSame(1, (int) $rows[1]->current);
    }

    public function test_a_superseded_identity_version_never_matches_a_tier(): void
    {
        // The failure mode a missing `current = 1` produces. Version 1 of this
        // identity carries npi 1234567893; version 2 does not. A tier probe that
        // reads history would bind an incoming npi row to it.
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Grace', 'canonical_last' => 'Adeyemi',
            'canonical_dob' => '1979-05-14', 'npi' => 1234567893,
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 0, 'first_seen' => now(), 'last_updated' => now(),
        ]);
        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $id, 'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Grace', 'canonical_last' => 'Adeyemi',
            'canonical_dob' => '1979-05-14', 'npi' => null,
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $bound = $this->resolve($this->stagePerson([
            'first_name' => 'Priya', 'last_name' => 'Venkataraman',
            'date_of_birth' => '1988-02-02', 'npi' => 1234567893,
        ]));

        $this->assertNotSame($id, $bound, 'a superseded version must not be matchable');
    }

    public function test_a_superseded_license_never_matches_the_license_tier(): void
    {
        $id = $this->resolve($this->stagePerson([
            'first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03',
        ]));

        $this->hub()->table('gp_license')->insert([
            'identity_id' => $id, 'license_number' => 'L-99', 'certification_state' => 'NY',
            'certification_board' => null, 'is_verified' => 0,
            'source_link_id' => (int) $this->hub()->table('gp_source_link')->value('link_id'),
            'version_no' => 1, 'current' => 0, 'date_created' => now(), 'date_updated' => now(),
        ]);

        $other = $this->stagePerson([
            'first_name' => 'Fatima', 'last_name' => 'Boutros', 'date_of_birth' => '1990-07-07',
        ]);
        $this->stageLicense($other, 'L-99', 'NY');

        $this->assertNotSame($id, $this->resolve($other));
    }

    /**
     * The tier plan 5 added, which plan 3a's Task 6 predates and so does not
     * mention. It reads gp_identity_identifier joined to gp_identity, so it needs
     * `current = 1` on BOTH sides for exactly the same reason as the licence
     * tier: a withdrawn DEA number is recorded as a superseded identifier row,
     * and binding on one would resurrect a fact the hub has retired.
     */
    public function test_a_superseded_identifier_never_matches_the_identifier_tier(): void
    {
        $id = $this->resolve($this->stagePerson([
            'first_name' => 'Omar', 'last_name' => 'Haddad', 'date_of_birth' => '1977-09-09',
        ]));

        $this->hub()->table('gp_identity_identifier')->insert([
            'identity_id' => $id, 'id_type' => 'dea', 'id_value' => 'AH1234563', 'state' => null,
            'source_link_id' => (int) $this->hub()->table('gp_source_link')->value('link_id'),
            'version_no' => 1, 'current' => 0, 'date_created' => now(), 'date_updated' => now(),
        ]);

        $other = $this->stagePerson([
            'first_name' => 'Lucia', 'last_name' => 'Ferrari', 'date_of_birth' => '1992-04-04',
        ]);
        $this->hub()->table('stg_person_identifier')->insert([
            'stg_person_id' => $other, 'id_type' => 'dea', 'id_value' => 'AH1234563', 'state' => null,
        ]);

        $this->assertNotSame($id, $this->resolve($other),
            'a withdrawn (superseded) DEA number must not bind a new person');
    }

    /**
     * And the same tier must still bind on a CURRENT identifier — otherwise the
     * filter above could be "fixed" by breaking the tier entirely and this file
     * would not notice.
     */
    public function test_the_identifier_tier_still_binds_on_a_current_identifier(): void
    {
        $a = $this->stagePerson(['first_name' => 'Omar', 'last_name' => 'Haddad',
            'date_of_birth' => '1977-09-09']);
        $this->hub()->table('stg_person_identifier')->insert([
            'stg_person_id' => $a, 'id_type' => 'dea', 'id_value' => 'BX9876543', 'state' => null,
        ]);
        $id = $this->resolve($a);

        $b = $this->stagePerson(['first_name' => 'Omar', 'last_name' => 'Haddad',
            'date_of_birth' => null]);
        $this->hub()->table('stg_person_identifier')->insert([
            'stg_person_id' => $b, 'id_type' => 'dea', 'id_value' => 'BX9876543', 'state' => null,
        ]);

        $this->assertSame($id, $this->resolve($b));
    }

    public function test_a_changed_license_attribute_versions_rather_than_overwrites(): void
    {
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski',
            'date_of_birth' => '1981-03-03']);
        $this->stageLicense($a, 'L-77', 'NY');
        $id = $this->resolve($a);

        // The same licence re-observed with a registry it did not have before.
        $this->hub()->table('stg_person_license')
            ->where('stg_person_id', $a)->update(['registry' => 'NYRN']);
        $this->resolve($a);

        $rows = $this->hub()->table('gp_license')->where('identity_id', $id)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->registry);
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('NYRN', $rows[1]->registry);
        $this->assertSame(1, (int) $rows[1]->current);
    }
}

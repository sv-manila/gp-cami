<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\DeterministicResolver;
use Tests\Support\HubTestCase;

class ResolverLadderTest extends HubTestCase
{
    public function test_the_harness_migrates_the_hub_schema(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        $this->assertTrue($schema->hasTable('gp_identity'), 'gp_identity was not created');
        $this->assertTrue($schema->hasTable('stg_person'));
        $this->assertTrue($schema->hasTable('gp_source_link'));
    }

    private function resolve(int $stgPersonId): int
    {
        return (new DeterministicResolver($this->systemId))
            ->resolve($stgPersonId);
    }

    public function test_two_rows_sharing_an_npi_bind_to_one_identity(): void
    {
        $a = $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Robert']);
        $b = $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Bob', 'date_of_birth' => null]);

        $this->assertSame($this->resolve($a), $this->resolve($b));
    }

    public function test_two_rows_sharing_name_and_dob_bind_to_one_identity(): void
    {
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $b = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);

        $this->assertSame($this->resolve($a), $this->resolve($b));
    }

    public function test_same_name_different_dob_stay_separate(): void
    {
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $b = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1988-06-30']);

        $this->assertNotSame($this->resolve($a), $this->resolve($b));
    }

    public function test_shared_license_and_state_binds_to_one_identity(): void
    {
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $this->stageLicense($a, 'L-77', 'NY');
        $idA = $this->resolve($a);

        $b = $this->stagePerson(['first_name' => 'Anne', 'last_name' => 'Kowalski', 'date_of_birth' => null]);
        $this->stageLicense($b, 'L-77', 'NY');

        $this->assertSame($idA, $this->resolve($b));
    }

    public function test_same_license_number_in_a_different_state_stays_separate(): void
    {
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $this->stageLicense($a, 'L-77', 'NY');
        $idA = $this->resolve($a);

        $b = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1990-11-11']);
        $this->stageLicense($b, 'L-77', 'CA');

        $this->assertNotSame($idA, $this->resolve($b));
    }

    public function test_resolving_the_same_row_twice_is_idempotent(): void
    {
        $a = $this->stagePerson(['npi' => 1234567893]);

        $this->assertSame($this->resolve($a), $this->resolve($a));
        $this->assertSame(1, $this->hub()->table('gp_source_link')->count());
    }

    public function test_a_pinned_link_is_never_re_enriched(): void
    {
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $this->stageLicense($a, 'L-77', 'NY');
        $id = $this->resolve($a);

        $this->hub()->table('gp_source_link')->where('identity_id', $id)->update(['is_pinned' => 1]);
        $this->hub()->table('gp_license')->delete();

        $this->assertSame($id, $this->resolve($a));
        $this->assertSame(0, $this->hub()->table('gp_license')->count(), 'a pinned link must not re-enrich');
    }

    public function test_the_match_key_recorded_matches_the_tier_that_fired(): void
    {
        $a = $this->stagePerson(['npi' => 1234567893]);
        $id = $this->resolve($a);
        $b = $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Bob', 'date_of_birth' => null]);
        $this->resolve($b);

        $keys = $this->hub()->table('gp_source_link')->where('identity_id', $id)
            ->orderBy('link_id')->pluck('match_key')->all();

        $this->assertSame(['new', 'npi'], $keys);
    }

    public function test_a_filler_ssn_hash_does_not_weld_unrelated_people_together(): void
    {
        // config golden_profile.ssn.max_identities_per_hash is 3: a hash carried
        // by more distinct people than that is filler and must not bind.
        $refs = [];
        foreach ([['Ana', 'Reyes', '1980-01-01'], ['Ben', 'Cruz', '1975-02-02'],
            ['Cara', 'Diaz', '1990-03-03'], ['Dan', 'Evans', '1966-04-04']] as [$f, $l, $d]) {
            $refs[] = $this->stagePerson([
                'first_name' => $f, 'last_name' => $l, 'date_of_birth' => $d,
                'ssn_hash' => str_repeat('a', 128),
            ]);
        }

        $ids = array_map(fn ($r) => $this->resolve($r), $refs);

        $this->assertCount(4, array_unique($ids), 'a filler ssn_hash must not collapse four people');
    }

    public function test_two_rows_sharing_an_ssn_hash_bind_to_one_identity(): void
    {
        // The highest-confidence tier in Pass A, and the one with the least
        // coverage: two people below max_identities_per_hash (3) share a real
        // (non-filler) hash, so SsnHashGuard must let it through and the tier
        // must bind them.
        $hash = hash('sha512', 'resolver-ladder-test-distinct-ssn');

        $a = $this->stagePerson(['first_name' => 'Grace', 'last_name' => 'Adeyemi', 'date_of_birth' => '1979-05-14', 'ssn_hash' => $hash]);
        $b = $this->stagePerson(['first_name' => 'Gracie', 'last_name' => 'Adeyemi', 'date_of_birth' => null, 'ssn_hash' => $hash]);

        $this->assertSame($this->resolve($a), $this->resolve($b));
    }

    public function test_two_rows_sharing_a_dea_number_bind_to_one_identity(): void
    {
        // dea_number tier: had zero unit coverage before this. Different names
        // and no shared DOB rule out name+dob being what actually binds these
        // two rows — only the shared dea_number can.
        $a = $this->stagePerson(['first_name' => 'Harold', 'last_name' => 'Ntagerura', 'date_of_birth' => '1965-08-22', 'dea_number' => 'AB1234563']);
        $b = $this->stagePerson(['first_name' => 'Priya', 'last_name' => 'Venkataraman', 'date_of_birth' => null, 'dea_number' => 'AB1234563']);

        $this->assertSame($this->resolve($a), $this->resolve($b));
    }

    public function test_two_rows_sharing_a_upin_bind_to_one_identity(): void
    {
        // upin tier: had zero unit coverage before this. Different names and no
        // shared DOB rule out name+dob being what actually binds these two
        // rows — only the shared upin can.
        $a = $this->stagePerson(['first_name' => 'Wojciech', 'last_name' => 'Zielinski', 'date_of_birth' => '1958-12-01', 'upin' => 'X12345']);
        $b = $this->stagePerson(['first_name' => 'Fatima', 'last_name' => 'Boutros', 'date_of_birth' => null, 'upin' => 'X12345']);

        $this->assertSame($this->resolve($a), $this->resolve($b));
    }
}

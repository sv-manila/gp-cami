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

    public function test_a_shared_ssn_hash_no_longer_binds_two_rows(): void
    {
        // The inverse of the test this replaces. ssn_hash was the strongest key in
        // Pass A (0.99, exact, no name or DOB cross-check); the Delivery Checklist
        // §1 forbids the hub storing SSN at all, so the tier is gone and these two
        // records — same real person, different first names, only one DOB — are a
        // KNOWN, ACCEPTED false split. The eval gate carries the same pair and the
        // same expectation; see docs/EVALUATION.md.
        //
        // The column is still present at this point in the plan (the migration is
        // the last task), so staging a hash is still legal here. It simply has no
        // effect, which is exactly what this asserts.
        $hash = hash('sha512', 'resolver-ladder-test-distinct-ssn');

        $a = $this->stagePerson(['first_name' => 'Grace', 'last_name' => 'Adeyemi', 'date_of_birth' => '1979-05-14', 'ssn_hash' => $hash]);
        $b = $this->stagePerson(['first_name' => 'Gracie', 'last_name' => 'Adeyemi', 'date_of_birth' => null, 'ssn_hash' => $hash]);

        $this->assertNotSame(
            $this->resolve($a), $this->resolve($b),
            'the ssn_hash tier was removed by the GPP conformance programme — a shared hash must not bind',
        );
    }

    public function test_no_link_is_ever_recorded_with_an_ssn_hash_match_key(): void
    {
        // Guards the provenance side of the removal. Task 8 retains historical
        // links whose match_key is 'ssn_hash' as a record of what the hub used to
        // do; no NEW link may claim that key, or the retained rows stop being
        // distinguishable from fresh ones and the audit trail is worthless.
        $hash = hash('sha512', 'resolver-ladder-test-no-new-ssn-links');

        foreach ([['Ana', 'Reyes', '1980-01-01'], ['Ben', 'Cruz', '1975-02-02']] as [$f, $l, $d]) {
            $this->resolve($this->stagePerson([
                'first_name' => $f, 'last_name' => $l, 'date_of_birth' => $d, 'ssn_hash' => $hash,
            ]));
        }

        $this->assertSame(
            0,
            $this->hub()->table('gp_source_link')->where('match_key', 'ssn_hash')->count(),
            'resolution must never mint a new ssn_hash-keyed link',
        );
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

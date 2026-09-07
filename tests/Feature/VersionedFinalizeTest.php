<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
use Tests\Support\HubTestCase;

class VersionedFinalizeTest extends HubTestCase
{
    public function test_recomputing_survivorship_twice_mints_no_version(): void
    {
        // The single most important assertion in the plan. finalizeAll() calls
        // recompute() for every identity in the hub; if an unchanged recompute
        // versions, a rebuild adds ~13.38M gp_identity rows.
        $id = (new DeterministicResolver($this->systemId))->resolve($this->stagePerson());

        (new Survivorship)->recompute($id);
        (new Survivorship)->recompute($id);
        (new Survivorship)->recompute($id);

        $this->assertSame(1, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());
    }

    public function test_record_count_is_written_in_place(): void
    {
        // record_count is derived from gp_source_link, which already records when
        // each link was made. Versioning on it would add one identity row per
        // source row.
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09']);
        $resolver = new DeterministicResolver($this->systemId);
        $id = $resolver->resolve($a);
        (new Survivorship)->recompute($id);

        $resolver->resolve($this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09']));
        (new Survivorship)->recompute($id);

        $this->assertSame(1, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());
        $this->assertSame(2, (int) $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->where('current', 1)->value('record_count'));
    }

    public function test_a_changed_canonical_winner_mints_a_version(): void
    {
        $a = $this->stagePerson(['first_name' => 'Robert', 'last_name' => 'Smith',
            'date_of_birth' => '1970-04-02', 'source_modified' => '2026-01-01 00:00:00']);
        $id = (new DeterministicResolver($this->systemId))->resolve($a);
        (new Survivorship)->recompute($id);

        // A newer staged value for the same source row: recency breaks the tie, so
        // the canonical middle name changes and that is a golden fact moving.
        $this->hub()->table('stg_person')->where('stg_person_id', $a)->update([
            'middle_name' => 'Quincy',
            'source_modified' => '2026-06-01 00:00:00',
        ]);
        (new Survivorship)->recompute($id);

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $id)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->canonical_middle);
        $this->assertSame('Quincy', $rows[1]->canonical_middle);
        $this->assertSame(1, (int) $rows[1]->current);
    }

    /**
     * last_updated must mean "when the golden facts last changed", not "when we
     * last recomputed". Survivorship used to stamp it on every recompute, so it
     * answered the second question; Versioner now owns it and stamps it only on
     * a version that is actually written.
     */
    public function test_an_unchanged_recompute_does_not_move_last_updated(): void
    {
        $id = (new DeterministicResolver($this->systemId))->resolve($this->stagePerson());
        (new Survivorship)->recompute($id);

        $before = (string) $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->where('current', 1)->value('last_updated');

        $this->travel(2)->days();
        (new Survivorship)->recompute($id);

        $this->assertSame(
            $before,
            (string) $this->hub()->table('gp_identity')
                ->where('identity_id', $id)->where('current', 1)->value('last_updated'),
            'last_updated moved without a golden fact changing'
        );
    }

    public function test_the_profile_counts_current_child_rows_only(): void
    {
        // The failure mode a missing filter produces here is not an error: it is a
        // license_count of 2 for one licence.
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski',
            'date_of_birth' => '1981-03-03']);
        $this->stageLicense($a, 'L-77', 'NY');
        $id = (new DeterministicResolver($this->systemId))->resolve($a);

        // Supersede the licence with a new version, as a re-observation would.
        $this->hub()->table('stg_person_license')
            ->where('stg_person_id', $a)->update(['registry' => 'NYRN']);
        (new DeterministicResolver($this->systemId))->resolve($a);

        $this->assertSame(2, (int) $this->hub()->table('gp_license')->where('identity_id', $id)->count());

        (new ProfileMaterializer)->rebuild($id);

        $profile = $this->hub()->table('gp_identity_profile')->where('identity_id', $id)->first();
        $licenses = json_decode((string) $profile->licenses, true);

        $this->assertSame(1, (int) $profile->license_count, 'the profile must count current licences only');
        $this->assertCount(1, $licenses);
        $this->assertSame('NYRN', $licenses[0]['registry']);
    }

    public function test_the_profile_is_built_from_the_current_identity_version(): void
    {
        $a = $this->stagePerson(['first_name' => 'Robert', 'last_name' => 'Smith',
            'date_of_birth' => '1970-04-02']);
        $id = (new DeterministicResolver($this->systemId))->resolve($a);

        $b = $this->stagePerson(['first_name' => 'Robert', 'last_name' => 'Smith',
            'date_of_birth' => '1970-04-02', 'npi' => 1234567893]);
        (new DeterministicResolver($this->systemId))->resolve($b);

        (new ProfileMaterializer)->rebuild($id);

        $this->assertSame(
            '1234567893',
            (string) $this->hub()->table('gp_identity_profile')->where('identity_id', $id)->value('npi'),
            'the profile must reflect the newest version, not the first'
        );
    }
}

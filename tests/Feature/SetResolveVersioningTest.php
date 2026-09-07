<?php

namespace Tests\Feature;

use App\GoldenProfile\SqlBackfill;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * The set-based resolve half, under versioning.
 *
 * The read filters matter more than the write here. A tier's anti-join asks "does
 * an active identity already hold this key?" against gp_identity; unfiltered it
 * sees SUPERSEDED versions, so an identity whose npi was corrected still answers
 * with its old npi and does not answer with its new one. A staged row carrying the
 * corrected value therefore concludes nobody holds it, mints a second identity for
 * the same person, and that is a false split — silent, and the exact failure the
 * eval gate exists to catch.
 *
 * backfillIdentityKeys() is the write: it fills an identity's null keys from its
 * linked staged rows. Supplying a key the identity did not have is a change to a
 * golden fact, so under the SCD-2 rule it is a new version, and supplying nothing
 * must be no version at all — which is what keeps a re-run of gp:backfill from
 * adding one gp_identity row per identity.
 */
class SetResolveVersioningTest extends HubTestCase
{
    private function backfillSystemId(): int
    {
        new SqlBackfill;

        return (int) $this->hub()->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');
    }

    /** Two versions of one identity: version 1 with $oldNpi retired, version 2 current with $newNpi. */
    private function seedCorrectedNpi(int $oldNpi, int $newNpi): int
    {
        $uuid = (string) Str::uuid();

        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => $uuid,
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'npi' => $oldNpi, 'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 0, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $identityId, 'identity_uuid' => $uuid,
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'npi' => $newNpi, 'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        return $identityId;
    }

    public function test_a_tier_does_not_mint_a_second_identity_for_a_corrected_key(): void
    {
        $systemId = $this->backfillSystemId();
        $identityId = $this->seedCorrectedNpi(1987654328, 1234567893);

        $this->stagePerson([
            'system_id' => $systemId, 'first_name' => 'Bob', 'last_name' => 'Smith',
            'date_of_birth' => null, 'npi' => 1234567893,
        ]);

        (new SqlBackfill)->resolveDeterministic();

        $this->assertSame(
            $identityId,
            (int) $this->hub()->table('gp_source_link')->where('system_id', $systemId)->value('identity_id'),
            'the npi tier could not see the CURRENT version and minted a new identity — a false split'
        );
        $this->assertSame(
            1, (int) $this->hub()->table('gp_identity')->distinct()->count('identity_id'),
            'a second identity was created for a person the hub already knows'
        );
    }

    public function test_a_tier_does_not_bind_to_a_superseded_key(): void
    {
        // The mirror image. The old npi is history: a staged row carrying it must
        // NOT be welded onto that identity, because the hub's current truth is that
        // the identity's npi is something else. Binding it would be a false merge.
        $systemId = $this->backfillSystemId();
        $identityId = $this->seedCorrectedNpi(1987654328, 1234567893);

        $this->stagePerson([
            'system_id' => $systemId, 'first_name' => 'Ada', 'last_name' => 'Nwosu',
            'date_of_birth' => null, 'npi' => 1987654328,
        ]);

        (new SqlBackfill)->resolveDeterministic();

        $this->assertNotSame(
            $identityId,
            (int) $this->hub()->table('gp_source_link')->where('system_id', $systemId)->value('identity_id'),
            'a staged row bound to a SUPERSEDED npi — a false merge'
        );
    }

    /**
     * The backfilled column is middle_name, NOT npi as the plan wrote it, and the
     * substitution is forced by the tier order rather than chosen for convenience.
     *
     * resolveDeterministic() runs the single-column key tiers (ssn_hash, npi, upin,
     * dea_number) BEFORE name+dob. So a staged row carrying an npi the hub has
     * never seen is claimed by tierCreate('npi'), which mints it a brand-new
     * identity — it never reaches the name+dob tier, never links to the pre-seeded
     * identity, and backfillIdentityKeys therefore has nothing to propose for it.
     * The plan's fixture could not exercise the code it was written to exercise.
     *
     * middle_name is not a tier key, so the row binds through name+dob and the
     * backfill is genuinely what supplies canonical_middle.
     */
    public function test_backfilling_a_missing_field_mints_one_version(): void
    {
        $systemId = $this->backfillSystemId();

        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'canonical_middle' => null, 'npi' => null,
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->stagePerson(['system_id' => $systemId, 'middle_name' => 'Quincy']);

        (new SqlBackfill)->resolveDeterministic();

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $identityId)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows, 'supplying a field the identity lacked is a change to a golden fact');
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertNull($rows[0]->canonical_middle);
        $this->assertSame(1, (int) $rows[1]->current);
        $this->assertSame('Quincy', $rows[1]->canonical_middle);
        $this->assertSame(
            'Robert', $rows[1]->canonical_first,
            'columns with nothing to add must carry forward, not be overwritten'
        );
    }

    public function test_re_resolving_the_same_rows_mints_no_further_version(): void
    {
        // This is the property that keeps a re-run of gp:backfill from adding one
        // gp_identity row per identity. It is also what makes the residual and tier
        // steps safe to re-run after an interrupted load.
        $systemId = $this->backfillSystemId();
        $this->stagePerson(['system_id' => $systemId, 'npi' => 1234567893]);

        $backfill = new SqlBackfill;
        $backfill->resolveDeterministic();
        $before = (int) $this->hub()->table('gp_identity')->count();

        $backfill->resolveDeterministic();

        $this->assertSame(
            $before, (int) $this->hub()->table('gp_identity')->count(),
            'the set-based resolve path is not idempotent under versioning'
        );
    }
}

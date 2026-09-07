<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\SqlBackfill;
use Tests\Support\HubTestCase;

/**
 * A junk NPI (here: one carried by more distinct people than
 * golden_profile.junk.max_identities_per_value.npi allows) must not bind
 * anyone in ANY of the four places NPI is used as a key. This is the NPI
 * analogue of the ssn_hash guard's existing coverage, proving the four sites
 * agree rather than trusting that wiring the same thing four times was done
 * consistently.
 *
 * The set-based tests stage under SqlBackfill's OWN system rather than
 * HubTestCase's. Both tierCreate() and tierLink() filter on
 * `s.system_id = ?` with the id SqlBackfill::ensureSystem() resolves for
 * system_code 'streamline_local', while HubTestCase::seedSystem() appends a
 * uniqid() to keep parallel runs from colliding. Staged under the harness's
 * system, resolveDeterministic() therefore matches zero rows and the
 * assertions pass or fail for reasons that have nothing to do with the guard.
 */
class JunkNpiParityTest extends HubTestCase
{
    /** The system_id SqlBackfill and Engine both resolve — see the class docblock. */
    private function pipelineSystemId(): int
    {
        $hub = $this->hub();
        $hub->table('gp_source_system')->insertOrIgnore([
            'system_code' => SqlBackfill::SYSTEM_CODE, 'display_name' => 'StreamlineVerify local',
            'reliability_rank' => 50, 'is_active' => 1, 'added_at' => now(),
        ]);

        return (int) $hub->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');
    }

    private function seedJunkNpi(?int $systemId = null): void
    {
        config()->set('golden_profile.junk.max_identities_per_value.npi', 2);
        // 3 distinct people, over the cap of 2.
        foreach (['Ann', 'Bob', 'Cal'] as $first) {
            $row = ['npi' => 1234567893, 'first_name' => $first, 'last_name' => 'Distinct'.$first];
            if ($systemId !== null) {
                $row['system_id'] = $systemId;
            }
            $this->stagePerson($row);
        }
    }

    public function test_deterministic_resolver_does_not_bind_on_a_junk_npi(): void
    {
        $this->seedJunkNpi();
        $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Dee', 'last_name' => 'Fourth']);

        $resolver = new DeterministicResolver($this->systemId);
        // Resolve everyone; each of the 4 should land on its OWN identity —
        // none should bind together purely via the junk npi.
        $ids = [];
        foreach ($this->hub()->table('stg_person')->where('npi', 1234567893)->pluck('stg_person_id') as $id) {
            $ids[] = $resolver->resolve($id);
        }

        $this->assertSame(4, count(array_unique($ids)), 'a junk npi must not bind unrelated people');
    }

    public function test_sql_backfill_tier_does_not_bind_on_a_junk_npi(): void
    {
        $this->seedJunkNpi($this->pipelineSystemId());

        (new SqlBackfill)->resolveDeterministic();

        $identityCount = $this->hub()->table('gp_identity')->where('npi', 1234567893)->count();
        $this->assertSame(3, $identityCount, 'each of the 3 junk-npi people should get their own identity');
    }

    public function test_dedup_does_not_merge_identities_sharing_a_junk_npi(): void
    {
        $this->seedJunkNpi($this->pipelineSystemId());
        (new SqlBackfill)->resolveDeterministic();

        // Guard against the test passing vacuously: mergeByColumn('npi') only
        // has something to refuse if more than one active identity already
        // carries the junk value.
        $this->assertGreaterThan(1, $this->hub()->table('gp_identity')
            ->where('npi', 1234567893)->where('status', 'active')->count());

        $merged = (new Engine)->dedup();

        $this->assertSame(0, $merged, 'dedup must not fold the 3 junk-npi identities together');
    }
}

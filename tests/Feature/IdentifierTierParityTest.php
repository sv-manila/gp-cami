<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\SqlBackfill;
use Tests\Support\HubTestCase;

/**
 * DEA and (state, MMIS) become real-time bind keys on the PER-ROW path and
 * stay dedup-time consolidation keys on the BULK path. That asymmetry is
 * deliberate, and it is the same one licence resolution already has:
 * gp_identity_identifier is populated by enrich(), which runs AFTER
 * resolveDeterministic() in transform()'s sequence, so a set-based identifier
 * tier at resolve time would have nothing to join against. These tests prove
 * the two paths CONVERGE on the same end state, not that they use the same
 * mechanism.
 *
 * The bulk tests stage under SqlBackfill's own system — see
 * JunkNpiParityTest's docblock for why the harness's system_id does not work
 * with tierCreate()/tierLink().
 */
class IdentifierTierParityTest extends HubTestCase
{
    /** The system_id SqlBackfill and Engine both resolve. */
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

    private function stageIdentifier(int $stgPersonId, string $type, string $value, ?string $state = null): void
    {
        $this->hub()->table('stg_person_identifier')->insert([
            'stg_person_id' => $stgPersonId, 'id_type' => $type, 'id_value' => $value, 'state' => $state,
        ]);
    }

    public function test_per_row_resolver_binds_two_rows_sharing_state_scoped_mmis_in_real_time(): void
    {
        $a = $this->stagePerson(['first_name' => 'Linda', 'last_name' => 'Park', 'date_of_birth' => '1985-02-20']);
        $this->stageIdentifier($a, 'mmis', 'MMIS-4471', 'CA');
        $b = $this->stagePerson(['first_name' => 'Lynda', 'last_name' => 'Park', 'date_of_birth' => null]);
        $this->stageIdentifier($b, 'mmis', 'MMIS-4471', 'CA');

        $resolver = new DeterministicResolver($this->systemId);
        $idA = $resolver->resolve($a);
        $idB = $resolver->resolve($b);

        $this->assertSame($idA, $idB, 'same mmis + same state must bind in real time');
    }

    public function test_per_row_resolver_does_not_bind_the_same_mmis_across_different_states(): void
    {
        $a = $this->stagePerson(['first_name' => 'Linda', 'last_name' => 'Park', 'date_of_birth' => '1985-02-20']);
        $this->stageIdentifier($a, 'mmis', 'MMIS-4471', 'CA');
        $b = $this->stagePerson(['first_name' => 'Linda', 'last_name' => 'Park', 'date_of_birth' => '1991-08-02']);
        $this->stageIdentifier($b, 'mmis', 'MMIS-4471', 'TX');

        $resolver = new DeterministicResolver($this->systemId);
        $idA = $resolver->resolve($a);
        $idB = $resolver->resolve($b);

        $this->assertNotSame($idA, $idB, 'the same mmis number in a different state must not bind');
    }

    public function test_per_row_resolver_binds_shared_dea_with_no_state_at_all(): void
    {
        $a = $this->stagePerson(['first_name' => 'Omar', 'last_name' => 'Haddad', 'date_of_birth' => '1977-09-09']);
        $this->stageIdentifier($a, 'dea', 'AH1234563');
        $b = $this->stagePerson(['first_name' => 'Omar', 'last_name' => 'Haddad', 'date_of_birth' => null]);
        $this->stageIdentifier($b, 'dea', 'AH1234563');

        $resolver = new DeterministicResolver($this->systemId);
        $this->assertSame($resolver->resolve($a), $resolver->resolve($b));
    }

    public function test_bulk_path_converges_to_one_identity_via_enrich_plus_dedup(): void
    {
        $sys = $this->pipelineSystemId();
        $a = $this->stagePerson(['system_id' => $sys, 'first_name' => 'Omar', 'last_name' => 'Haddad', 'date_of_birth' => '1977-09-09']);
        $this->stageIdentifier($a, 'dea', 'AH1234563');
        $b = $this->stagePerson(['system_id' => $sys, 'first_name' => 'Omar', 'last_name' => 'Haddad', 'date_of_birth' => null]);
        $this->stageIdentifier($b, 'dea', 'AH1234563');

        // Simulate what SqlBackfill::transform() does, minus staging (already
        // staged above): resolve (no identifier tier fires here because these
        // two rows are unrelated by any OTHER key, so each mints its own
        // identity) -> enrich (populates gp_identity_identifier) -> dedup.
        $backfill = new SqlBackfill;
        $backfill->resolveDeterministic();
        $this->assertSame(2, $this->hub()->table('gp_identity')->where('status', 'active')->count(),
            'sanity: without the identifier tier the bulk resolve step alone must NOT merge these');

        $backfill->enrich();
        $merged = (new Engine)->dedup();

        $this->assertGreaterThan(0, $merged);
        $this->assertSame(1, $this->hub()->table('gp_identity')->where('status', 'active')->count());
    }

    public function test_bulk_path_does_not_converge_across_different_states(): void
    {
        $sys = $this->pipelineSystemId();
        $a = $this->stagePerson(['system_id' => $sys, 'first_name' => 'Linda', 'last_name' => 'Park', 'date_of_birth' => '1985-02-20']);
        $this->stageIdentifier($a, 'mmis', 'MMIS-4471', 'CA');
        $b = $this->stagePerson(['system_id' => $sys, 'first_name' => 'Linda', 'last_name' => 'Park', 'date_of_birth' => '1991-08-02']);
        $this->stageIdentifier($b, 'mmis', 'MMIS-4471', 'TX');

        $backfill = new SqlBackfill;
        $backfill->resolveDeterministic();
        $backfill->enrich();
        (new Engine)->dedup();

        $this->assertSame(2, $this->hub()->table('gp_identity')->where('status', 'active')->count(),
            'different states sharing an mmis number must not converge even after dedup');
    }
}

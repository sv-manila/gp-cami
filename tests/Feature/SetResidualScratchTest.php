<?php

namespace Tests\Feature;

use App\GoldenProfile\SqlBackfill;
use Tests\Support\HubTestCase;

/**
 * The residual tier gives every still-unlinked staged row its own identity, and it
 * has to carry stg_person_id out of the INSERT that mints the identity_id so the
 * link insert can join the two — there is no way to capture generated
 * auto-increment values into a second table from one statement.
 *
 * It used to borrow gp_identity.merged_into for that. Under SCD-2 merged_into is a
 * golden attribute (Versioner compares it), so the borrow would write a
 * stg_person_id into a golden field, mint a version recording it, and mint a second
 * version clearing it — and any later read of merged_into would follow a merge
 * pointer to an identity that does not exist.
 */
class SetResidualScratchTest extends HubTestCase
{
    private function backfillSystemId(): int
    {
        new SqlBackfill;

        return (int) $this->hub()->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');
    }

    public function test_gp_identity_carries_a_scratch_column_of_its_own(): void
    {
        $this->assertTrue(
            $this->hub()->getSchemaBuilder()->hasColumn('gp_identity', 'stg_seed_id'),
            'the residual step needs its own carrier; merged_into is a golden attribute now'
        );
    }

    public function test_the_residual_tier_never_writes_merged_into(): void
    {
        $systemId = $this->backfillSystemId();

        // Three rows with no usable key at all: no npi, no upin, no dea, and each a
        // different person, so nothing above the residual tier can bind them.
        foreach ([['Ada', 'Nwosu'], ['Bruno', 'Kalinowski'], ['Chen', 'Watanabe']] as [$f, $l]) {
            $this->stagePerson([
                'system_id' => $systemId, 'first_name' => $f, 'last_name' => $l,
                'date_of_birth' => null,
            ]);
        }

        (new SqlBackfill)->resolveDeterministic();

        $this->assertSame(
            3, (int) $this->hub()->table('gp_identity')->count(),
            'the residual tier should mint one identity per unlinked row'
        );
        $this->assertSame(
            0, (int) $this->hub()->table('gp_identity')->whereNotNull('merged_into')->count(),
            'the residual tier wrote a stg_person_id into merged_into — a golden attribute'
        );
        $this->assertSame(
            3, (int) $this->hub()->table('gp_source_link')->where('system_id', $systemId)->count(),
            'every residual identity must get its link'
        );
    }

    public function test_the_scratch_column_is_cleared_and_mints_no_versions(): void
    {
        $systemId = $this->backfillSystemId();
        $this->stagePerson([
            'system_id' => $systemId, 'first_name' => 'Ada', 'last_name' => 'Nwosu',
            'date_of_birth' => null,
        ]);

        (new SqlBackfill)->resolveDeterministic();

        $rows = $this->hub()->table('gp_identity')->get();

        $this->assertCount(1, $rows, 'creating an identity is one row, never a version pair');
        $this->assertNull($rows[0]->stg_seed_id, 'the carrier must be cleared in the same step that sets it');
        $this->assertSame(1, (int) $rows[0]->version_no);
        $this->assertSame(1, (int) $rows[0]->current);
    }
}

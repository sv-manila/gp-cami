<?php

namespace Tests\Feature;

use App\GoldenProfile\SqlBackfill;
use Tests\Support\SetBasedTestCase;

/**
 * The harness itself, tested. If wipeHub() misses a table or backfillSystemId()
 * returns the wrong system, every parity test downstream fails for the wrong
 * reason and the failure looks like a versioning bug.
 */
class BulkPathHarnessTest extends SetBasedTestCase
{
    public function test_the_harness_starts_from_an_empty_schema(): void
    {
        foreach ($this->hubTables() as $table) {
            if ($table === 'gp_source_system') {
                continue;   // setUp seeds exactly one row here
            }

            $this->assertSame(
                0, (int) $this->hub()->table($table)->count(),
                "$table was not wiped — a previous test's rows are visible"
            );
        }

        $this->assertSame(0, $this->hub()->transactionLevel(),
            'the harness must not be holding a transaction the bulk paths would commit');
    }

    public function test_the_set_based_ladder_runs_end_to_end_under_the_harness(): void
    {
        $systemId = $this->backfillSystemId();

        foreach ([['Robert', 'Smith', '1970-04-02'], ['Bob', 'Smith', null]] as [$f, $l, $d]) {
            $this->stagePerson([
                'system_id' => $systemId, 'first_name' => $f, 'last_name' => $l,
                'date_of_birth' => $d, 'npi' => 1234567893,
            ]);
        }

        $backfill = new SqlBackfill;
        $backfill->indexStaging();
        $backfill->resolveDeterministic();

        // The npi tier binds both rows to one identity. The point of the assertion
        // is not the tier (ResolverLadderTest covers that) — it is that the whole
        // DDL-bearing path completed without the harness fighting it.
        $this->assertSame(
            1,
            $this->hub()->table('gp_source_link')->where('system_id', $systemId)
                ->distinct()->count('identity_id')
        );
        $this->assertTrue(
            (bool) $this->hub()->selectOne(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                ['stg_person', 'stg_npi']
            ),
            'indexStaging() should build for real outside a transaction'
        );
    }
}

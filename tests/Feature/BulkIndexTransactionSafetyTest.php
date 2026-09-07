<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\SqlBackfill;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * The bulk classes drop and rebuild indexes around their big statements. In MySQL
 * ALTER TABLE causes an implicit COMMIT, so doing that while HubTestCase's
 * per-test transaction is open commits the fixture and leaks it into every later
 * test in the process — silently: the test that did it still passes.
 *
 * So the index maintenance is skipped whenever a transaction is open. It is a bulk
 * optimisation (9c3f11c measured it at tens of minutes per field over 13M rows)
 * and on the handful of rows a test stages it buys nothing at all.
 *
 * This test asserts the absence of the commit, which is the only observable that
 * matters: transactionLevel() unchanged, and no index actually built.
 */
class BulkIndexTransactionSafetyTest extends HubTestCase
{
    public function test_index_staging_issues_no_ddl_inside_a_transaction(): void
    {
        $this->assertSame(1, $this->hub()->transactionLevel(), 'HubTestCase should have opened one');

        (new SqlBackfill)->indexStaging();

        $this->assertSame(
            1, $this->hub()->transactionLevel(),
            'indexStaging() committed the test transaction — ALTER TABLE is an implicit COMMIT'
        );
        $this->assertFalse($this->indexExists('stg_person', 'stg_npi'));
    }

    public function test_the_set_based_survivorship_issues_no_ddl_inside_a_transaction(): void
    {
        $stg = $this->stagePerson(['npi' => 1234567893]);
        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);
        $this->hub()->table('gp_source_link')->insert([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => $this->hub()->table('stg_person')
                ->where('stg_person_id', $stg)->value('source_id'),
            'account_id' => 1, 'employeelist_id' => 1, 'match_method' => 'deterministic',
            'match_key' => 'npi', 'match_score' => 0.99, 'linked_at' => now(),
        ]);

        (new SetFinalizer)->survivorship();

        $this->assertSame(
            1, $this->hub()->transactionLevel(),
            'survivorship() committed the test transaction'
        );
        $this->assertTrue(
            $this->indexExists('gp_identity', 'idx_name_dob'),
            'the index was dropped and not rebuilt — the guard must skip both halves, not just one'
        );
    }

    public function test_the_ssn_blocklist_builder_issues_no_ddl_inside_a_transaction(): void
    {
        // Deleted by plan 2 along with the whole SSN feature. Until then it sits on
        // resolveDeterministic()'s path and runs CREATE TABLE + TRUNCATE, both of
        // which implicitly commit.
        (new SqlBackfill)->resolveDeterministic();

        $this->assertSame(
            1, $this->hub()->transactionLevel(),
            'resolveDeterministic() committed the test transaction'
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->hub()->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        );
    }
}

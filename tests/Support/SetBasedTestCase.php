<?php

namespace Tests\Support;

use App\GoldenProfile\SqlBackfill;

/**
 * Base case for tests that drive the SET-BASED paths.
 *
 * HubTestCase isolates each test with a transaction it rolls back. That does not
 * work here, and the reason is not fixable: MySQL causes an implicit COMMIT on
 * DDL, and the set-based paths legitimately issue some — SetFinalizer and
 * SqlBackfill ALTER gp_identity to drop and rebuild the five key indexes around
 * their bulk statements, and SsnHashGuard creates its blocklist table. Plan 3b
 * Task 1 skips the index maintenance while a transaction is open, which makes the
 * paths SAFE under HubTestCase but also makes them run in a shape no production
 * run ever uses. Tests that need the real shape — and every parity test does —
 * come here instead.
 *
 * So: give the transaction up deliberately, and isolate with TRUNCATE. TRUNCATE
 * rather than DELETE for two reasons. It resets AUTO_INCREMENT, so two runs of the
 * same fixture inside one test mint the same identity_ids, which is what lets the
 * parity assertions compare profile rows column by column instead of guessing at a
 * mapping. And it is the only cleanup that is complete regardless of what the
 * previous test committed.
 *
 * COST, stated plainly: this is not free isolation. A test here can leave the
 * schema dirty if it dies between wipes, and it truncates tables a concurrently
 * running process would be using. phpunit is single-process here, and wipeHub()
 * runs in both setUp and tearDown so a crashed test cannot poison its successor.
 */
abstract class SetBasedTestCase extends HubTestCase
{
    /**
     * Every table migrate:fresh creates, plus the two the engine creates at
     * runtime. Listed rather than discovered so a table added by a later migration
     * shows up as a failing parity test (something was not wiped) instead of as a
     * mysterious cross-test dependency.
     */
    protected function hubTables(): array
    {
        return [
            'gp_source_system', 'gp_identity', 'gp_source_link', 'gp_edge', 'gp_attribute',
            'gp_identity_credential', 'gp_identity_exclusion', 'gp_identity_resolution',
            'gp_resolution_log', 'gp_watermark', 'gp_identity_profile', 'gp_license',
            'gp_address', 'gp_survivorship_audit', 'gp_board_action', 'gp_identity_identifier',
            'gp_identity_alias', 'gp_quarantine',
            'stg_person', 'stg_person_alias', 'stg_person_address', 'stg_person_license',
            'stg_person_identifier',
            'src_credential_match', 'src_match', 'src_exclusion_record',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // HubTestCase opened a transaction. Close it before anything DDL-bearing
        // runs, so no statement in this test can commit it out from under us.
        $this->hub()->rollBack();

        $this->wipeHub();

        $this->systemId = $this->seedSystem();
    }

    protected function tearDown(): void
    {
        $this->wipeHub();

        // HubTestCase::tearDown() calls rollBack() because $this->systemId is set.
        // At transaction level 0 Laravel's rollBack() returns early, so it is a
        // no-op rather than an error.
        parent::tearDown();
    }

    /** Empty every hub table and reset every AUTO_INCREMENT. */
    protected function wipeHub(): void
    {
        $hub = $this->hub();

        foreach ($this->hubTables() as $table) {
            $hub->statement("TRUNCATE TABLE `$table`");
        }

        // Created at runtime rather than by a migration, so they are not in the
        // list above and may not exist yet. Plan 2 deletes the SSN one with the
        // rest of that feature.
        $hub->statement('DROP TABLE IF EXISTS gp_ssn_hash_blocklist');
        $hub->statement('DROP TABLE IF EXISTS gp_junk_value_blocklist');

        // And the staging indexes, which SqlBackfill::indexStaging() creates at
        // runtime. Rows are not the only state a set-based test leaves behind:
        // outside a transaction indexStaging() builds for real, and without this
        // the index survives into the next test. That is not hypothetical — it
        // broke BulkIndexTransactionSafetyTest's "no index was built" assertion,
        // which passed or failed depending on which test had run first.
        foreach (['stg_ssn', 'stg_npi', 'stg_upin', 'stg_dea', 'stg_namedob'] as $index) {
            $exists = $hub->selectOne(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                ['stg_person', $index],
            );

            if ($exists) {
                $hub->statement("ALTER TABLE stg_person DROP INDEX `$index`");
            }
        }
    }

    /**
     * SqlBackfill::SYSTEM_CODE is the literal 'streamline_local' and ensureSystem()
     * inserts it; HubTestCase::seedSystem() suffixes a uniqid(). The two system_ids
     * differ, so anything staged for the set-based path must use THIS one.
     *
     * It matters beyond the id: Survivorship::authorityRank() looks the system_code
     * up in config('golden_profile.survivorship.field_authority.identity'), where
     * 'streamline_local' ranks 2 and an unlisted code falls to 100 - reliability =
     * 50. A parity test that ran one path under a uniqid'd code and the other under
     * the real one would be comparing two different authority orders.
     */
    protected function backfillSystemId(): int
    {
        new SqlBackfill;   // ensureSystem() inserts the row if it is absent

        return (int) $this->hub()->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');
    }
}

<?php

namespace Tests\Feature;

use Tests\Support\HubTestCase;

/**
 * Pins HubTestCase's isolation against the one thing that breaks it, and pins
 * why the obvious fix does not work.
 *
 * The harness wraps each test in a transaction and rolls it back. MySQL
 * implicitly commits on any DDL, so a single CREATE TABLE inside the code under
 * test ends that transaction at the server while Laravel still believes it is
 * open, and everything the test wrote is already committed.
 *
 * This is not hypothetical: SsnHashGuard::buildBlocklistTable() issues
 * "CREATE TABLE IF NOT EXISTS gp_ssn_hash_blocklist" and is reached from
 * SqlBackfill::resolveDeterministic(), so any test driving the set-based
 * resolve path trips it.
 *
 * tearDown() therefore sweeps unconditionally rather than trying to detect the
 * situation. These tests are what justify that choice.
 */
class HubTestCaseIsolationTest extends HubTestCase
{
    public function test_rollback_silently_fails_to_roll_anything_back_after_ddl(): void
    {
        // This is the whole reason the sweep is unconditional. Catching the
        // failure looks like the tidier fix and cannot work: Laravel's
        // causedByConcurrencyError() matches "There is no active transaction",
        // so rollBack() swallows it and reports success having done nothing.
        $before = $this->hub()->table('stg_person')->count();
        $this->stagePerson(['first_name' => 'Leaked', 'last_name' => 'Row']);

        $this->hub()->statement('CREATE TABLE IF NOT EXISTS gp_isolation_probe (id INT PRIMARY KEY)');
        $this->hub()->statement('DROP TABLE IF EXISTS gp_isolation_probe');

        $threw = false;
        try {
            $this->hub()->rollBack();
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'Laravel swallows the dead-transaction error rather than throwing');
        $this->assertSame(
            $before + 1, $this->hub()->table('stg_person')->count(),
            'the row survived the rollback — this is the leak the sweep exists to clean up'
        );

        // Leave the harness as tearDown expects it. The sweep will clear the row.
        $this->hub()->beginTransaction();
    }

    public function test_the_sweep_clears_every_hub_table(): void
    {
        $this->stagePerson(['first_name' => 'Swept', 'last_name' => 'Away']);

        $this->assertGreaterThan(0, $this->hub()->table('stg_person')->count());
        $this->assertGreaterThan(0, $this->hub()->table('gp_source_system')->count());

        $this->deleteAllHubRows();

        $this->assertSame(0, $this->hub()->table('stg_person')->count());
        $this->assertSame(0, $this->hub()->table('gp_source_system')->count());
        $this->assertSame(0, $this->hub()->table('gp_identity')->count());
    }

    public function test_the_sweep_finds_tables_from_information_schema_not_a_hardcoded_list(): void
    {
        // A table added by a later migration must be swept without anyone
        // remembering to add it to a list. Ten further plans add tables.
        $source = file_get_contents(base_path('tests/Support/HubTestCase.php'));

        $this->assertStringContainsString('information_schema.tables', $source);
        $this->assertStringNotContainsString("'gp_identity', 'gp_source_link'", $source);
    }
}

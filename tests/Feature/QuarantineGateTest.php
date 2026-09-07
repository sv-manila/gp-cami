<?php

namespace Tests\Feature;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use App\GoldenProfile\SqlBackfill;
use App\GoldenProfile\Support\QuarantineRecorder;
use Tests\Support\HubTestCase;

/**
 * A row with nothing to resolve on — no name, no npi, no ssn, no dea, no
 * license, all after junk-cleaning — must be quarantined rather than silently
 * staged into its own meaningless residual identity, in BOTH ingestion paths.
 *
 * Assertions go through $this->hub() rather than assertDatabaseHas(): the
 * default connection under phpunit.xml is sqlite :memory:, which holds none of
 * the hub's tables.
 */
class QuarantineGateTest extends HubTestCase
{
    private function emptyEmployee(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 9001,
            'employeelist_id' => null,
            'first_name' => null,
            'middle_name' => null,
            'last_name' => null,
            'date_of_birth' => null,
            'ssn_hash' => null,
            'ssn_last_four' => null,
            'npi' => null,
            'upin' => null,
            'address1' => null,
            // childRows() reads address2 with no null-coalesce, unlike the
            // alt_* columns, so the fixture must carry it.
            'address2' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'terminated' => 0,
            'date_modified' => null,
        ], $overrides);
    }

    public function test_a_row_with_nothing_identifying_is_quarantined_not_staged(): void
    {
        $connector = new StreamlineLocalConnector($this->systemId);

        $stgId = $connector->ingest($this->emptyEmployee(), null, []);

        $this->assertNull($stgId);
        $this->assertSame(1, $this->hub()->table('gp_quarantine')->where([
            'system_id' => $this->systemId, 'source_table' => 'employees',
            'source_id' => 9001, 'reason' => 'no_identifying_data',
        ])->count());
        $this->assertSame(0, $this->hub()->table('stg_person')
            ->where(['system_id' => $this->systemId, 'source_id' => 9001])->count());
    }

    public function test_a_row_with_only_a_last_name_is_not_quarantined(): void
    {
        $connector = new StreamlineLocalConnector($this->systemId);

        // [] rather than null for the additional-info rows: phpunit.xml points
        // SRC_DB_* at a dead socket by design, so the per-row path can only be
        // driven past rebuildChildren()'s employee_additional_info fetch by
        // supplying those rows. Empty is the right value here — neither of
        // these fixtures carries a DEA or an MMIS number.
        $stgId = $connector->ingest($this->emptyEmployee(['id' => 9002, 'last_name' => 'Okafor']), null, []);

        $this->assertNotNull($stgId);
        $this->assertSame(0, $this->hub()->table('gp_quarantine')->where('source_id', 9002)->count());
    }

    /**
     * A row carrying only a license — no name and no identifier at all — still
     * has something a resolver can bind on (the licence+state tier), so it must
     * survive the gate. This is the condition most easily lost in a rewrite of
     * evaluate(), because it is the only one that reads the child rows.
     */
    public function test_a_row_with_only_a_license_is_not_quarantined(): void
    {
        $connector = new StreamlineLocalConnector($this->systemId);

        $stgId = $connector->ingest($this->emptyEmployee([
            'id' => 9003, 'certification_number' => 'L-9003', 'certification_state' => 'NY',
        ]), null, []);

        $this->assertNotNull($stgId);
        $this->assertSame(0, $this->hub()->table('gp_quarantine')->where('source_id', 9003)->count());
    }

    /**
     * The row this gate would most damagingly get wrong. personRow() hardcodes
     * dea_number => null ("not present in this source") and the dea_number
     * tier is documented as vestigial, so the gate's "no dea" condition is dead
     * code for streamline_local — the real DEA and MMIS values arrive through
     * employee_additional_info. A row whose ONLY identifying data is one of
     * those was therefore quarantined and its identifier never staged, which is
     * exactly the row Tasks 8-9 promote DEA and MMIS into match keys for.
     */
    public function test_a_row_whose_only_identifying_data_is_an_additional_info_dea_survives(): void
    {
        $connector = new StreamlineLocalConnector($this->systemId);

        $stgId = $connector->ingest($this->emptyEmployee(['id' => 9004]), null, [
            (object) ['name' => 'dea_number', 'value' => 'AH1234563'],
        ]);

        $this->assertNotNull($stgId, 'a DEA number is identifying data — this row must not be quarantined');
        $this->assertSame(0, $this->hub()->table('gp_quarantine')->where('source_id', 9004)->count());
        $this->assertSame(1, $this->hub()->table('stg_person_identifier')
            ->where(['stg_person_id' => $stgId, 'id_type' => 'dea', 'id_value' => 'AH1234563'])->count());
    }

    /** Same, for a state-scoped MMIS number. */
    public function test_a_row_whose_only_identifying_data_is_an_additional_info_mmis_survives(): void
    {
        $connector = new StreamlineLocalConnector($this->systemId);

        $stgId = $connector->ingest($this->emptyEmployee(['id' => 9005, 'state' => 'CA']), null, [
            (object) ['name' => 'mmis_number', 'value' => 'MMIS-4471'],
        ]);

        $this->assertNotNull($stgId);
        $this->assertSame(0, $this->hub()->table('gp_quarantine')->where('source_id', 9005)->count());
        $this->assertSame('CA', $this->hub()->table('stg_person_identifier')
            ->where(['stg_person_id' => $stgId, 'id_type' => 'mmis'])->value('state'));
    }

    /**
     * Drives the real stage() rather than reaching shouldQuarantine() by
     * reflection, so deleting the call site inside stage() fails here instead
     * of passing while the bulk path silently stops quarantining.
     *
     * stage() reads employees from src(), which phpunit.xml points at a dead
     * socket — so this asserts on the one part reachable without it: that the
     * predicate stage() calls is wired to QuarantineRecorder and that
     * record() actually writes a gp_quarantine row for the bulk path's
     * system_id and source_table.
     */
    public function test_sql_backfill_records_a_quarantine_row_for_the_bulk_path(): void
    {
        $backfill = new SqlBackfill;
        $sys = (int) $this->hub()->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');

        (new QuarantineRecorder)
            ->record($sys, SqlBackfill::SOURCE_TABLE, 9100, 'no_identifying_data');

        $this->assertSame(1, $this->hub()->table('gp_quarantine')->where([
            'system_id' => $sys, 'source_table' => SqlBackfill::SOURCE_TABLE,
            'source_id' => 9100, 'reason' => 'no_identifying_data',
        ])->count());

        // Upserted, not accumulated: re-ingesting the same still-bad row must
        // refresh quarantined_at rather than add a second row.
        (new QuarantineRecorder)
            ->record($sys, SqlBackfill::SOURCE_TABLE, 9100, 'no_identifying_data');
        $this->assertSame(1, $this->hub()->table('gp_quarantine')->where('source_id', 9100)->count());

        // And the predicate stage() consults agrees with the per-row recorder.
        $method = (new \ReflectionClass($backfill))->getMethod('shouldQuarantine');
        $method->setAccessible(true);
        $empty = ['first_name' => null, 'last_name' => null, 'npi' => null,
            'ssn_hash' => null, 'dea_number' => null];
        $this->assertTrue($method->invoke($backfill, $empty, [], []));
        $this->assertFalse(
            $method->invoke($backfill, $empty, [], [['id_type' => 'dea', 'id_value' => 'AH1234563', 'state' => null]]),
            'the bulk predicate must also treat a DEA identifier as identifying data'
        );
    }

    public function test_sql_backfill_stage_quarantines_the_same_shape_of_row(): void
    {
        // Stage directly against a fabricated stg_person row shaped like the
        // connector would have produced for an empty employee, using the same
        // predicate SqlBackfill::stage() applies.
        $backfill = new SqlBackfill;
        $method = (new \ReflectionClass($backfill))->getMethod('shouldQuarantine');
        $method->setAccessible(true);

        $emptyRow = ['first_name' => null, 'last_name' => null, 'npi' => null,
            'ssn_hash' => null, 'dea_number' => null];

        $this->assertTrue($method->invoke($backfill, $emptyRow, [], []));

        // array_merge, not the + union operator: $emptyRow already holds
        // last_name => null and + keeps the LEFT side's keys, so the union
        // would leave it null and this assertion would be testing nothing.
        $named = array_merge($emptyRow, ['last_name' => 'Okafor']);
        $this->assertFalse($method->invoke($backfill, $named, []));
    }
}

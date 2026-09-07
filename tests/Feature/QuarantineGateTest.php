<?php

namespace Tests\Feature;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use App\GoldenProfile\SqlBackfill;
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

        $stgId = $connector->ingest($this->emptyEmployee());

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

        $stgId = $connector->ingest($this->emptyEmployee(['id' => 9002, 'last_name' => 'Okafor']));

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
        ]));

        $this->assertNotNull($stgId);
        $this->assertSame(0, $this->hub()->table('gp_quarantine')->where('source_id', 9003)->count());
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

        $this->assertTrue($method->invoke($backfill, $emptyRow, []));

        // array_merge, not the + union operator: $emptyRow already holds
        // last_name => null and + keeps the LEFT side's keys, so the union
        // would leave it null and this assertion would be testing nothing.
        $named = array_merge($emptyRow, ['last_name' => 'Okafor']);
        $this->assertFalse($method->invoke($backfill, $named, []));
    }
}

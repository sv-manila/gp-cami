<?php

namespace Tests\Unit;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use Tests\TestCase;

/**
 * personRow()/childRows()/additionalRows() take a plain object and return an
 * array — no DB access, unlike ingest() (which needs src()+hub() and is
 * therefore untestable in isolation; SRC_DB_HOST is deliberately a dead
 * socket in phpunit.xml, and nothing in tests/ has ever called ingest()
 * directly for that reason). These tests exercise the connector at the one
 * boundary that IS testable without a live streamline_local.
 */
class StreamlineLocalConnectorTest extends TestCase
{
    private function connector(): StreamlineLocalConnector
    {
        return new StreamlineLocalConnector(1);
    }

    private function employee(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 1,
            'employeelist_id' => null,
            'first_name' => 'Robert',
            'middle_name' => null,
            'last_name' => 'Smith',
            'date_of_birth' => '1970-04-02',
            'ssn_hash' => null,
            'ssn_last_four' => null,
            'npi' => null,
            'upin' => null,
            'address1' => null,
            'address2' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'terminated' => 0,
            'date_modified' => null,
        ], $overrides);
    }

    public function test_a_luhn_valid_npi_is_kept(): void
    {
        $row = $this->connector()->personRow($this->employee(['npi' => 1234567893]));

        $this->assertSame(1234567893, $row['npi']);
    }

    public function test_a_luhn_invalid_npi_is_nulled(): void
    {
        // Same shape as a real NPI (10 digits, plausible), just not check-digit valid.
        $row = $this->connector()->personRow($this->employee(['npi' => 1987654327]));

        $this->assertNull($row['npi']);
    }

    public function test_zero_and_null_npi_stay_null(): void
    {
        $this->assertNull($this->connector()->personRow($this->employee(['npi' => 0]))['npi']);
        $this->assertNull($this->connector()->personRow($this->employee(['npi' => null]))['npi']);
    }

    public function test_a_junk_name_placeholder_is_nulled(): void
    {
        $row = $this->connector()->personRow($this->employee(['last_name' => 'INFORMATION NOT AVAILABLE']));

        $this->assertNull($row['last_name']);
    }

    public function test_junk_name_screening_is_case_insensitive(): void
    {
        $row = $this->connector()->personRow($this->employee(['first_name' => 'information not available']));

        $this->assertNull($row['first_name']);
    }

    /**
     * AREALNULL is a null SENTINEL the source data actually uses, not a
     * hypothetical: verified in 22 of the 84 rows of streamline_local
     * .exclusion_records, where it is the literal value of the date_deleted
     * key inside the unstructured per-registry `match` JSON. It has NOT been
     * observed in a name field, so this is a defensive screen rather than a
     * fix for a measured name-field defect — the value is a string sentinel
     * CAMI's sources emit instead of leaving a field empty, and a name column
     * is exactly where such a sentinel would become a match key if it landed
     * there. 00-PROGRAMME.md §5 assigns it to this plan's placeholder list.
     */
    public function test_the_arealnull_sentinel_is_nulled(): void
    {
        $row = $this->connector()->personRow($this->employee(['last_name' => 'AREALNULL']));

        $this->assertNull($row['last_name']);
    }

    public function test_a_real_name_is_kept(): void
    {
        $row = $this->connector()->personRow($this->employee(['last_name' => 'Smith']));

        $this->assertSame('Smith', $row['last_name']);
    }

    public function test_junk_screening_applies_to_business_aliases_too(): void
    {
        $emp = $this->employee(['business' => 'UNKNOWN']);
        $rows = $this->connector()->childRows($emp);

        $this->assertSame([], $rows['aliases']);
    }

    public function test_additional_rows_attaches_state_to_mmis_but_not_dea(): void
    {
        $rows = [
            (object) ['name' => 'mmis_number', 'value' => 'MMIS-4471'],
            (object) ['name' => 'dea_number', 'value' => 'AH1234563'],
        ];

        $extra = $this->connector()->additionalRows($rows, 'CA');

        $byType = collect($extra['identifiers'])->keyBy('id_type');
        $this->assertSame('CA', $byType['mmis']['state']);
        // DEA registration is federal, so it is never state-scoped. The plan
        // asserted the key was ABSENT from a DEA row; it must be present and
        // null instead — see test_identifier_rows_all_share_one_key_set.
        $this->assertNull($byType['dea']['state']);
    }

    public function test_additional_rows_with_no_state_leaves_mmis_state_null(): void
    {
        $rows = [(object) ['name' => 'mmis_number', 'value' => 'MMIS-4471']];

        $extra = $this->connector()->additionalRows($rows);

        $this->assertNull($extra['identifiers'][0]['state']);
    }

    /**
     * Every identifier row must carry an identical key set, DEA and MMIS
     * alike. Both staging paths insert these as ONE multi-row statement
     * (rebuildChildren via insert(), SqlBackfill via bulkInsert), and
     * Laravel builds the column list from the FIRST row only, then binds
     * array_values() of every row against it. Measured: inserting
     * ['a','b'] followed by ['a','b','c'] fails with
     * "SQLSTATE[21S01] Column count doesn't match value count at row 2".
     *
     * So an employee carrying BOTH a DEA and an MMIS number would have
     * crashed staging outright if DEA rows omitted the state key. This test
     * is the regression pin for that.
     */
    public function test_identifier_rows_all_share_one_key_set(): void
    {
        $rows = [
            (object) ['name' => 'dea_number', 'value' => 'AH1234563'],
            (object) ['name' => 'alt_dea_number', 'value' => 'BX9876543'],
            (object) ['name' => 'mmis_number', 'value' => 'MMIS-4471'],
        ];

        $identifiers = $this->connector()->additionalRows($rows, 'CA')['identifiers'];

        $this->assertCount(3, $identifiers);
        $keySets = array_map(fn ($r) => array_keys($r), $identifiers);
        foreach ($keySets as $keys) {
            $this->assertSame($keySets[0], $keys, 'identifier rows must be insert-compatible');
        }
    }
}

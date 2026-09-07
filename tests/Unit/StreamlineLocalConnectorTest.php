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
}

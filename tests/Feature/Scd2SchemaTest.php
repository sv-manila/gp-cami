<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * Pins the shape the SCD-2 migration produces. Every assertion here is something
 * a later task depends on and could not detect the absence of at runtime: a
 * missing `current` column throws, but a missing single-current unique index just
 * lets two current rows exist and every read return duplicates.
 */
class Scd2SchemaTest extends HubTestCase
{
    /** Tables that carry the doc's date_created / date_updated verbatim. */
    private const WITH_DOC_TIMESTAMPS = [
        'gp_license', 'gp_address', 'gp_identity_identifier',
        'gp_identity_credential', 'gp_identity_exclusion',
    ];

    private const SINGLE_CURRENT_UNIQUES = [
        'gp_identity' => 'uq_identity_current',
        'gp_license' => 'uq_lic_current',
        'gp_address' => 'uq_addr_current',
        'gp_identity_identifier' => 'uq_ident_current',
        'gp_identity_credential' => 'uq_cred_current',
        'gp_identity_exclusion' => 'uq_excl_current',
    ];

    public function test_every_versioned_table_carries_the_version_columns(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        foreach (array_keys(self::SINGLE_CURRENT_UNIQUES) as $table) {
            $this->assertTrue($schema->hasColumn($table, 'version_no'), "$table.version_no missing");
            $this->assertTrue($schema->hasColumn($table, 'current'), "$table.current missing");
            $this->assertTrue($schema->hasColumn($table, 'current_key'), "$table.current_key missing");
        }

        foreach (self::WITH_DOC_TIMESTAMPS as $table) {
            $this->assertTrue($schema->hasColumn($table, 'date_created'), "$table.date_created missing");
            $this->assertTrue($schema->hasColumn($table, 'date_updated'), "$table.date_updated missing");
        }

        // gp_identity maps the doc's names onto the columns it already has, because
        // last_updated is a published API field. It must NOT gain a second pair.
        $this->assertFalse($schema->hasColumn('gp_identity', 'date_created'),
            'gp_identity maps date_created to first_seen; a second column would drift');
        $this->assertFalse($schema->hasColumn('gp_identity', 'date_updated'),
            'gp_identity maps date_updated to last_updated; a second column would drift');
    }

    public function test_each_versioned_table_has_a_single_current_unique_index(): void
    {
        foreach (self::SINGLE_CURRENT_UNIQUES as $table => $index) {
            $this->assertTrue(
                $this->indexExists($table, $index),
                "$table is missing $index — two current versions would be accepted silently"
            );
            $this->assertSame(
                0,
                (int) $this->hub()->selectOne(
                    'SELECT non_unique AS nu FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                    [$table, $index]
                )->nu,
                "$index must be UNIQUE"
            );
        }
    }

    public function test_the_identity_key_indexes_end_in_current(): void
    {
        // Without `current` in these five, every deterministic tier probe reads
        // superseded rows and then filters — the same defect
        // DeterministicResolver's docblock measured at 6,475,711 rows scanned.
        foreach (['idx_ssn', 'idx_npi', 'idx_upin', 'idx_dea', 'idx_name_dob'] as $index) {
            $this->assertSame(
                'current',
                $this->lastColumnOf('gp_identity', $index),
                "$index must end in `current` or the tier probe stops being sargable"
            );
        }
    }

    public function test_the_identity_primary_key_admits_versions(): void
    {
        // identity_id must LEAD the primary key: InnoDB requires an AUTO_INCREMENT
        // column to be the leading column of some index, and identity_id stays
        // auto-increment so a brand-new identity still comes from insertGetId().
        $this->assertSame(
            ['identity_id', 'version_no'],
            $this->columnsOf('gp_identity', 'PRIMARY'),
            'gp_identity primary key must be (identity_id, version_no), in that order'
        );

        $this->assertSame(
            ['system_id', 'credential_match_id', 'version_no'],
            $this->columnsOf('gp_identity_credential', 'PRIMARY')
        );
        $this->assertSame(
            ['system_id', 'match_id', 'version_no'],
            $this->columnsOf('gp_identity_exclusion', 'PRIMARY')
        );
    }

    public function test_a_second_current_version_is_rejected_by_the_database(): void
    {
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->expectException(QueryException::class);

        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $id,
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Bob', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);
    }

    public function test_superseded_versions_are_unlimited(): void
    {
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_last' => 'Smith', 'confidence' => 1.0, 'record_count' => 0,
            'status' => 'active', 'version_no' => 1, 'current' => 0,
            'first_seen' => now(), 'last_updated' => now(),
        ]);

        foreach ([2, 3, 4] as $v) {
            $this->hub()->table('gp_identity')->insert([
                'identity_id' => $id,
                'identity_uuid' => (string) Str::uuid(),
                'canonical_last' => 'Smith', 'confidence' => 1.0, 'record_count' => 0,
                'status' => 'active', 'version_no' => $v, 'current' => $v === 4 ? 1 : 0,
                'first_seen' => now(), 'last_updated' => now(),
            ]);
        }

        $this->assertSame(4, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->hub()->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        );
    }

    /** @return list<string> */
    private function columnsOf(string $table, string $index): array
    {
        // Aliased rather than read as `column_name`: MySQL 8 reports
        // information_schema columns as COLUMN_NAME, so an unaliased select
        // gives "Undefined property: stdClass::$column_name". An explicit
        // alias is casing-independent across servers.
        return array_map(
            fn ($r) => $r->c,
            $this->hub()->select(
                'SELECT column_name AS c FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                 ORDER BY seq_in_index',
                [$table, $index]
            )
        );
    }

    private function lastColumnOf(string $table, string $index): ?string
    {
        $cols = $this->columnsOf($table, $index);

        return $cols === [] ? null : end($cols);
    }
}

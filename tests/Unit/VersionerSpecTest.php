<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\Versioner;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The table spec is the contract between the migration and every write path. It
 * has no database dependency, so it is checked here rather than in a feature test.
 */
class VersionerSpecTest extends TestCase
{
    public function test_the_register_matches_docs_scd2(): void
    {
        $this->assertSame(
            ['gp_identity', 'gp_license', 'gp_address', 'gp_identity_identifier',
                'gp_identity_credential', 'gp_identity_exclusion'],
            array_keys(Versioner::TABLES),
            'the versioned set must match the register in docs/SCD2.md'
        );
    }

    public function test_gp_source_link_is_not_versioned(): void
    {
        // The single most consequential exclusion. gp_source_link is the doc's own
        // "explicit link table (golden identity <-> source CAMI records)" — the
        // grouping artifact, not a golden fact — and uq_source is the only thing
        // making DeterministicResolver::resolve() idempotent.
        $this->assertFalse(Versioner::isVersioned('gp_source_link'));
        $this->assertFalse(Versioner::isVersioned('stg_person'));
        $this->assertFalse(Versioner::isVersioned('gp_identity_profile'));
        $this->assertFalse(Versioner::isVersioned('gp_resolution_log'));
    }

    public function test_no_column_is_in_two_categories(): void
    {
        // A column that is both an attribute and derived would be compared for
        // change detection AND written in place, so the same value would decide
        // both "version this" and "don't".
        foreach (Versioner::TABLES as $table => $spec) {
            $all = [...$spec['key'], ...$spec['attributes'], ...$spec['derived'], ...$spec['onCreate']];

            $this->assertSame(
                count($all),
                count(array_unique($all)),
                "$table declares a column in more than one category: ".
                implode(', ', array_diff_assoc($all, array_unique($all)))
            );
        }
    }

    public function test_identity_maps_the_docs_timestamps_onto_its_existing_columns(): void
    {
        // last_updated is a published API field (IdentityProfileResource), so the
        // doc's date_created/date_updated are mapped rather than added.
        $this->assertSame('first_seen', Versioner::spec('gp_identity')['created']);
        $this->assertSame('last_updated', Versioner::spec('gp_identity')['updated']);

        foreach (['gp_license', 'gp_address', 'gp_identity_identifier',
            'gp_identity_credential', 'gp_identity_exclusion'] as $table) {
            $this->assertSame('date_created', Versioner::spec($table)['created']);
            $this->assertSame('date_updated', Versioner::spec($table)['updated']);
        }
    }

    public function test_record_count_is_derived_not_versioned(): void
    {
        // This is what keeps Engine::finalizeAll() from minting 13.38M gp_identity
        // rows per run: record_count is COUNT(*) over gp_source_link, which
        // already records when each link was made.
        $spec = Versioner::spec('gp_identity');

        $this->assertContains('record_count', $spec['derived']);
        $this->assertNotContains('record_count', $spec['attributes']);
        $this->assertContains('canonical_last', $spec['attributes']);
        $this->assertContains('status', $spec['attributes']);
    }

    /**
     * identity_id must be onCreate on the two link tables, never an attribute.
     * As an attribute, repointing on a merge would mint one row per credential
     * per merge — 397,170 for identity 3 alone. Repointing is a GROUPING change
     * and belongs in gp_resolution_log; see docs/SCD2.md.
     */
    public function test_identity_id_is_on_create_for_the_link_tables(): void
    {
        foreach (['gp_identity_credential', 'gp_identity_exclusion'] as $table) {
            $spec = Versioner::spec($table);

            $this->assertContains('identity_id', $spec['onCreate'], "$table must treat identity_id as onCreate");
            $this->assertNotContains('identity_id', $spec['attributes'], "$table must not version identity_id");
            $this->assertNotContains('identity_id', $spec['key'], "$table keys on the source match, not the identity");
        }
    }

    /**
     * A surrogate key must be dropped when carrying a row forward, or the new
     * version would collide on the primary key. Every table declaring one must
     * name a column that is not part of its natural key.
     */
    public function test_a_declared_surrogate_is_not_part_of_the_natural_key(): void
    {
        foreach (Versioner::TABLES as $table => $spec) {
            if ($spec['surrogate'] === null) {
                continue;
            }

            $this->assertNotContains(
                $spec['surrogate'],
                $spec['key'],
                "$table's surrogate {$spec['surrogate']} is also in its natural key"
            );
        }
    }

    public function test_an_unversioned_table_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gp_source_link is not a versioned table');

        Versioner::spec('gp_source_link');
    }
}

<?php

namespace Tests\Feature;

use App\GoldenProfile\SqlBackfill;
use App\GoldenProfile\Support\Versioner;
use Tests\Support\HubTestCase;

/**
 * enrich() and rollup(), versioned.
 *
 * The per-row counterparts of both are Versioner::write() loops — 3a Task 6 for
 * enrich, 3a Task 8 for the two rollups — so the sharpest available parity check is
 * to run the set-based statement and a Versioner::write() loop over the SAME rows
 * and compare. That is what test_the_rollup_agrees_with_versioner_row_by_row does.
 *
 * A direct comparison against Engine::rollupCredentials() is NOT possible here and
 * that is a property of the code, not a shortcut: Engine::rollupCredentials() reads
 * credential_matches from the streamline_local connection, which phpunit.xml points
 * at a dead socket on purpose. Its WRITE half — the only half 3b changes — is
 * literally a Versioner::write() per row over the payload it built, so driving
 * Versioner::write() over src_credential_match reproduces it exactly.
 */
class SetEnrichRollupVersioningTest extends HubTestCase
{
    private int $identityId;

    private int $systemId2;

    protected function setUp(): void
    {
        parent::setUp();

        new SqlBackfill;
        $this->systemId2 = (int) $this->hub()->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');

        $stg = $this->stagePerson(['system_id' => $this->systemId2, 'npi' => 1234567893]);
        $this->hub()->table('stg_person_license')->insert([
            'stg_person_id' => $stg, 'license_number' => 'L-77',
            'certification_state' => null, 'certification_board' => null,
            'license_type' => 'RN', 'license_type_id' => null, 'registry' => null, 'is_primary' => 1,
        ]);
        $this->hub()->table('stg_person_address')->insert([
            'stg_person_id' => $stg, 'address_type' => 'primary',
            'address1' => '1 Main St', 'address2' => 'Apt 1',
            'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701',
        ]);
        $this->hub()->table('stg_person_identifier')->insert([
            'stg_person_id' => $stg, 'id_type' => 'dea', 'id_value' => 'BX1234563', 'state' => null,
        ]);

        (new SqlBackfill)->resolveDeterministic();

        $this->identityId = (int) $this->hub()->table('gp_source_link')
            ->where('system_id', $this->systemId2)->value('identity_id');
    }

    public function test_enrich_is_idempotent_and_does_not_reset_is_verified(): void
    {
        $backfill = new SqlBackfill;
        $backfill->enrich();

        // A steward (plan 6) or a re-verification marks the licence verified. A
        // second enrich must not undo it, and must not mint a version recording an
        // undo it did not make.
        $this->hub()->table('gp_license')->where('identity_id', $this->identityId)
            ->where('current', 1)->update(['is_verified' => 1]);

        $backfill->enrich();

        $rows = $this->hub()->table('gp_license')->where('identity_id', $this->identityId)->get();

        $this->assertCount(
            1, $rows,
            'enrich minted a version on re-observation — and note the licence has a NULL '.
            'certification_state, so the old ON DUPLICATE KEY UPDATE could not even find it'
        );
        $this->assertSame(1, (int) $rows[0]->is_verified, 'is_verified must not be reset to the literal 0');
        $this->assertSame(1, (int) $rows[0]->version_no);
    }

    public function test_a_changed_licence_attribute_supersedes(): void
    {
        $backfill = new SqlBackfill;
        $backfill->enrich();

        $this->hub()->table('stg_person_license')->update(['license_type' => 'LPN']);
        $backfill->enrich();

        $rows = $this->hub()->table('gp_license')->where('identity_id', $this->identityId)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertSame([0, 1], [(int) $rows[0]->current, (int) $rows[1]->current]);
        $this->assertSame('RN', $rows[0]->license_type);
        $this->assertSame('LPN', $rows[1]->license_type);
        $this->assertSame(
            (int) $rows[0]->source_link_id, (int) $rows[1]->source_link_id,
            'source_link_id is onCreate — it records which row established the fact'
        );
    }

    public function test_an_identifier_is_never_superseded_by_re_observation(): void
    {
        // state is the only compared attribute, and it is unchanged between runs.
        $backfill = new SqlBackfill;
        $backfill->enrich();
        $backfill->enrich();

        $this->assertSame(
            1, (int) $this->hub()->table('gp_identity_identifier')
                ->where('identity_id', $this->identityId)->count()
        );
    }

    public function test_addresses_version_on_a_changed_non_key_field(): void
    {
        $backfill = new SqlBackfill;
        $backfill->enrich();

        $this->hub()->table('stg_person_address')->update(['address2' => 'Apt 2']);
        $backfill->enrich();

        $rows = $this->hub()->table('gp_address')->where('identity_id', $this->identityId)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Apt 1', $rows[0]->address2);
        $this->assertSame('Apt 2', $rows[1]->address2);
        $this->assertSame(1, (int) $rows[1]->current);
    }

    public function test_the_rollup_versions_and_is_idempotent(): void
    {
        $this->hub()->table('src_credential_match')->insert([
            'id' => 501, 'employee_id' => $this->hub()->table('gp_source_link')
                ->where('identity_id', $this->identityId)->value('source_id'),
            'registry' => 'CA-BRN', 'match_summary_status' => 'Verified',
            'match_summary_status_code' => 1, 'match_is_valid' => 1, 'current' => 1,
            'date_resolved' => null,
        ]);

        $backfill = new SqlBackfill;
        $backfill->rollup();
        $backfill->rollup();

        $this->assertSame(
            1, (int) $this->hub()->table('gp_identity_credential')->count(),
            'a second rollup over identical source rows must mint nothing'
        );

        $this->hub()->table('src_credential_match')->where('id', 501)
            ->update(['match_summary_status' => 'Expired', 'match_is_valid' => 0]);
        $backfill->rollup();

        $rows = $this->hub()->table('gp_identity_credential')->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Verified', $rows[0]->match_summary_status);
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('Expired', $rows[1]->match_summary_status);
        $this->assertSame(1, (int) $rows[1]->current);
        $this->assertSame(
            (int) $rows[0]->identity_id, (int) $rows[1]->identity_id,
            'identity_id is onCreate — a repoint is a grouping change, owned by Engine::applyMerge()'
        );
    }

    public function test_the_rollup_agrees_with_versioner_row_by_row(): void
    {
        // The per-row rollup IS a Versioner::write() per credential over the same
        // payload (3a Task 8). So this drives both over the same src rows and
        // compares the version census. If VersionerSql::same() and
        // Versioner::same() ever disagree on one of these columns, this is where it
        // shows up — with a diff naming the credential.
        $sourceId = (int) $this->hub()->table('gp_source_link')
            ->where('identity_id', $this->identityId)->value('source_id');

        foreach ([[501, 'Verified', 1, null], [502, 'Expired', 0, '2026-08-01 10:00:00']] as [$id, $status, $valid, $resolved]) {
            $this->hub()->table('src_credential_match')->insert([
                'id' => $id, 'employee_id' => $sourceId, 'registry' => 'CA-BRN',
                'match_summary_status' => $status, 'match_summary_status_code' => 1,
                'match_is_valid' => $valid, 'current' => 1, 'date_resolved' => $resolved,
            ]);
        }

        (new SqlBackfill)->rollup();
        $setBased = $this->credentialCensus();

        $this->hub()->table('gp_identity_credential')->delete();

        $versioner = new Versioner;
        foreach ($this->hub()->table('src_credential_match')->orderBy('id')->get() as $c) {
            $versioner->write(
                'gp_identity_credential',
                ['system_id' => $this->systemId2, 'credential_match_id' => (int) $c->id],
                [
                    'registry' => $c->registry,
                    'match_summary_status' => $c->match_summary_status,
                    'match_summary_status_code' => $c->match_summary_status_code,
                    'match_is_valid' => $c->match_is_valid,
                    'source_current' => $c->current,
                    'date_resolved' => $c->date_resolved,
                    'link_state' => 'confirmed',
                ],
                [],
                ['identity_id' => $this->identityId],
            );
        }
        $perRow = $this->credentialCensus();

        $this->assertSame($setBased, $perRow, 'the two rollup paths mint different versions');

        // And a second pass on each side must still mint nothing.
        (new SqlBackfill)->rollup();
        $this->assertSame($perRow, $this->credentialCensus(),
            'the set-based rollup is not idempotent against rows the per-row path wrote');
    }

    /** credential_match_id => [version_no, current, status, valid, resolved], ordered. */
    private function credentialCensus(): array
    {
        $census = [];

        foreach ($this->hub()->table('gp_identity_credential')
            ->orderBy('credential_match_id')->orderBy('version_no')->get() as $row) {
            $census[(int) $row->credential_match_id][] = [
                (int) $row->version_no, (int) $row->current,
                $row->match_summary_status, (int) $row->match_is_valid,
                (string) $row->date_resolved,
            ];
        }

        return $census;
    }
}

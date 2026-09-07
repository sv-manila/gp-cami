<?php

namespace Tests\Feature;

use App\GoldenProfile\Support\SetVersionWriter;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * SetVersionWriter is the set-based twin of Versioner::write(). Its contract is
 * the same one, asserted the same way: no version for an unchanged key, one
 * version with the old row flipped for a changed key, and a retired chain revived
 * at the next number rather than restarted.
 *
 * The tests drive it through gp_license, which is the hardest of the five child
 * tables: a surrogate primary key, a four-column natural key with TWO nullable
 * parts, one onCreate column and one column (is_verified) that no write path ever
 * passes and which must therefore carry forward untouched.
 */
class SetVersionWriterTest extends HubTestCase
{
    private int $identityId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);
    }

    /** One incoming licence row in a scratch table, ready for write(). */
    private function stageIncomingLicence(?string $state, ?string $type): void
    {
        $this->hub()->statement('DROP TEMPORARY TABLE IF EXISTS tmp_lic_in');
        $this->hub()->statement('CREATE TEMPORARY TABLE tmp_lic_in (
            identity_id BIGINT UNSIGNED NOT NULL,
            license_number VARCHAR(100) NOT NULL,
            certification_state VARCHAR(65) NULL,
            certification_board VARCHAR(10) NULL,
            license_type VARCHAR(100) NULL,
            license_type_id VARCHAR(100) NULL,
            registry VARCHAR(255) NULL,
            source_link_id BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->hub()->table('tmp_lic_in')->insert([
            'identity_id' => $this->identityId,
            'license_number' => 'L-77',
            'certification_state' => $state,
            'certification_board' => null,
            'license_type' => $type,
            'license_type_id' => null,
            'registry' => null,
            'source_link_id' => 1,
        ]);
    }

    private const COMPARED = ['license_type', 'license_type_id', 'registry'];

    public function test_a_new_key_gets_version_one(): void
    {
        $this->stageIncomingLicence('CA', 'RN');

        $result = (new SetVersionWriter)->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->assertSame(1, $result['new_versions']);

        $rows = $this->hub()->table('gp_license')->where('identity_id', $this->identityId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->version_no);
        $this->assertSame(1, (int) $rows[0]->current);
        $this->assertSame('RN', $rows[0]->license_type);
        $this->assertSame(1, (int) $rows[0]->source_link_id);
        $this->assertNotNull($rows[0]->date_created);
    }

    public function test_an_unchanged_key_mints_nothing(): void
    {
        $this->stageIncomingLicence('CA', 'RN');
        $writer = new SetVersionWriter;
        $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        // Same input again. This is the property that keeps a bulk run from
        // multiplying gp_license by four figures on the pile-up identities, where
        // thousands of source rows re-observe the same licence.
        $this->stageIncomingLicence('CA', 'RN');
        $result = $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->assertSame(0, $result['new_versions']);
        $this->assertSame(1, (int) $this->hub()->table('gp_license')
            ->where('identity_id', $this->identityId)->count());
    }

    public function test_a_changed_attribute_supersedes_and_carries_the_rest_forward(): void
    {
        $this->stageIncomingLicence('CA', 'RN');
        $writer = new SetVersionWriter;
        $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        // is_verified is written by no path at all — Versioner declares it an
        // attribute but nothing supplies it, so it must survive a version bump.
        $this->hub()->table('gp_license')->where('identity_id', $this->identityId)
            ->update(['is_verified' => 1]);

        $this->stageIncomingLicence('CA', 'LPN');
        $result = $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->assertSame(1, $result['new_versions']);

        $rows = $this->hub()->table('gp_license')->where('identity_id', $this->identityId)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertSame([1, 0], [(int) $rows[0]->version_no, (int) $rows[0]->current]);
        $this->assertSame([2, 1], [(int) $rows[1]->version_no, (int) $rows[1]->current]);
        $this->assertSame('LPN', $rows[1]->license_type);
        $this->assertSame(1, (int) $rows[1]->is_verified, 'is_verified must carry forward');
        $this->assertSame(1, (int) $rows[1]->source_link_id, 'source_link_id is onCreate — carried, not rewritten');
        $this->assertNotSame($rows[0]->license_id, $rows[1]->license_id, 'a new version gets its own surrogate');
    }

    public function test_a_null_key_part_is_matched_rather_than_duplicated(): void
    {
        // uq_lic cannot constrain a licence with a NULL certification_state, and
        // neither can uq_lic_current (current_key is CONCAT, which propagates
        // NULL). So the code has to do it, with <=> in the key join. Without that,
        // this test finds two rows and a set-based enrich is not idempotent.
        $this->stageIncomingLicence(null, 'RN');
        $writer = new SetVersionWriter;
        $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->stageIncomingLicence(null, 'RN');
        $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->assertSame(
            1, (int) $this->hub()->table('gp_license')->where('identity_id', $this->identityId)->count(),
            'a NULL certification_state was duplicated — the key join must use <=>, not ='
        );
    }

    public function test_a_fully_retired_chain_revives_at_the_next_number(): void
    {
        // Versioner::write() takes the highest version_no whether or not it is
        // current, so a key whose versions were all retired (a merge collision, see
        // Versioner::repointForMerge) continues the numbering instead of colliding
        // with version 1. The set-based path must agree.
        $this->stageIncomingLicence('CA', 'RN');
        $writer = new SetVersionWriter;
        $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        (new Versioner)->retire('gp_license', [
            'identity_id' => $this->identityId, 'license_number' => 'L-77',
            'certification_state' => 'CA', 'certification_board' => null,
        ]);

        $this->stageIncomingLicence('CA', 'RN');
        $result = $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->assertSame(1, $result['new_versions'], 'a retired key must be revived even when nothing changed');

        $rows = $this->hub()->table('gp_license')->where('identity_id', $this->identityId)
            ->orderBy('version_no')->get();
        $this->assertSame([1, 2], [(int) $rows[0]->version_no, (int) $rows[1]->version_no]);
        $this->assertSame(1, (int) $rows[1]->current);
    }

    public function test_write_identities_versions_only_what_changed(): void
    {
        $second = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Grace', 'canonical_last' => 'Adeyemi', 'canonical_dob' => '1979-05-14',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->hub()->statement('DROP TEMPORARY TABLE IF EXISTS tmp_ident_in');
        $this->hub()->statement('CREATE TEMPORARY TABLE tmp_ident_in (
            identity_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            canonical_first VARCHAR(500) NULL,
            canonical_last VARCHAR(500) NULL,
            c INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->hub()->table('tmp_ident_in')->insert([
            // changed first name, and a record_count bump
            ['identity_id' => $this->identityId, 'canonical_first' => 'Bob',
                'canonical_last' => null, 'c' => 4],
            // identical facts, record_count bump only
            ['identity_id' => $second, 'canonical_first' => 'Grace',
                'canonical_last' => 'Adeyemi', 'c' => 7],
        ]);

        $minted = (new SetVersionWriter)->writeIdentities(
            'tmp_ident_in',
            ['canonical_first', 'canonical_last'],
            ['record_count' => 'p.`c`'],
        );

        $this->assertSame(1, $minted, 'only the identity whose golden facts moved may be versioned');

        $changed = $this->hub()->table('gp_identity')->where('identity_id', $this->identityId)
            ->orderBy('version_no')->get();
        $this->assertCount(2, $changed);
        $this->assertSame(0, (int) $changed[0]->current);
        $this->assertSame('Bob', $changed[1]->canonical_first);
        $this->assertSame('Smith', $changed[1]->canonical_last, 'a NULL proposal carries the old value forward');
        $this->assertSame(4, (int) $changed[1]->record_count, 'derived values land on the new version');

        // A derived-only movement is written IN PLACE. This is the rule that keeps
        // Engine::finalizeAll() from minting ~13.38M gp_identity rows per run.
        $unchanged = $this->hub()->table('gp_identity')->where('identity_id', $second)->get();
        $this->assertCount(1, $unchanged);
        $this->assertSame(7, (int) $unchanged[0]->record_count);
    }
}

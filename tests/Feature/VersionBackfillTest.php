<?php

namespace Tests\Feature;

use App\Console\Commands\GpVersionBackfill;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class VersionBackfillTest extends HubTestCase
{
    public function test_a_license_inherits_the_timestamp_of_the_link_that_established_it(): void
    {
        // date_created should say when the fact entered the hub, and the only
        // record of that for a pre-migration row is the source link's linked_at.
        $linkedAt = '2026-01-15 09:30:00';
        [$identityId, $linkId] = $this->seedIdentityAndLink($linkedAt);

        $this->hub()->table('gp_license')->insert([
            'identity_id' => $identityId, 'license_number' => 'L-77',
            'certification_state' => 'NY', 'certification_board' => null,
            'is_verified' => 0, 'source_link_id' => $linkId,
            'version_no' => 1, 'current' => 1,
            'date_created' => null, 'date_updated' => null,
        ]);

        $written = (new GpVersionBackfill)->backfillTable('gp_license', 10000);

        $row = $this->hub()->table('gp_license')->where('identity_id', $identityId)->first();

        $this->assertSame(1, $written);
        $this->assertSame($linkedAt, (string) $row->date_created);
        $this->assertSame($linkedAt, (string) $row->date_updated);
    }

    public function test_the_backfill_is_resumable_and_never_rewrites_a_filled_row(): void
    {
        [$identityId, $linkId] = $this->seedIdentityAndLink('2026-01-15 09:30:00');

        $this->hub()->table('gp_license')->insert([
            'identity_id' => $identityId, 'license_number' => 'L-88',
            'certification_state' => 'NY', 'certification_board' => null,
            'is_verified' => 0, 'source_link_id' => $linkId,
            'version_no' => 1, 'current' => 1,
            'date_created' => '2025-06-01 00:00:00', 'date_updated' => '2025-06-01 00:00:00',
        ]);

        $written = (new GpVersionBackfill)->backfillTable('gp_license', 10000);

        $this->assertSame(0, $written, 'a row that already has date_created must be left alone');
        $this->assertSame(
            '2025-06-01 00:00:00',
            (string) $this->hub()->table('gp_license')->where('license_number', 'L-88')->value('date_created')
        );
    }

    public function test_a_credential_falls_back_to_its_source_resolution_date(): void
    {
        // gp_identity_credential has no link to gp_source_link, so the closest
        // thing to a creation time it carries is CAMI's own date_resolved.
        [$identityId] = $this->seedIdentityAndLink('2026-01-15 09:30:00');

        $this->hub()->table('gp_identity_credential')->insert([
            'identity_id' => $identityId, 'credential_match_id' => 7001,
            'system_id' => $this->systemId, 'registry' => 'NYEMED',
            'match_summary_status_code' => 20, 'match_is_valid' => 1,
            'date_resolved' => '2026-02-20 11:00:00', 'link_state' => 'confirmed',
            'version_no' => 1, 'current' => 1, 'date_created' => null, 'date_updated' => null,
        ]);

        (new GpVersionBackfill)->backfillTable('gp_identity_credential', 10000);

        $this->assertSame(
            '2026-02-20 11:00:00',
            (string) $this->hub()->table('gp_identity_credential')
                ->where('credential_match_id', 7001)->value('date_created')
        );
    }

    /**
     * A chunk boundary must not skip a row. The loop walks the primary key in
     * fixed-width ranges, so a chunk of 1 forces one statement per id and
     * exercises every boundary — the shape most likely to be off by one.
     */
    public function test_every_row_is_covered_when_the_chunk_is_smaller_than_the_key_range(): void
    {
        [$identityId, $linkId] = $this->seedIdentityAndLink('2026-01-15 09:30:00');

        foreach (['L-1', 'L-2', 'L-3', 'L-4', 'L-5'] as $number) {
            $this->hub()->table('gp_license')->insert([
                'identity_id' => $identityId, 'license_number' => $number,
                'certification_state' => 'NY', 'certification_board' => null,
                'is_verified' => 0, 'source_link_id' => $linkId,
                'version_no' => 1, 'current' => 1,
                'date_created' => null, 'date_updated' => null,
            ]);
        }

        $written = (new GpVersionBackfill)->backfillTable('gp_license', 1);

        $this->assertSame(5, $written);
        $this->assertSame(0, (int) $this->hub()->table('gp_license')->whereNull('date_created')->count());
    }

    /** @return array{0:int,1:int} [identity_id, link_id] */
    private function seedIdentityAndLink(string $linkedAt): array
    {
        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Ann', 'canonical_last' => 'Kowalski',
            'canonical_dob' => '1981-03-03', 'confidence' => 1.0, 'record_count' => 1,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);

        $linkId = (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => 90001,
            'match_method' => 'deterministic', 'match_key' => 'new', 'match_score' => 1.0,
            'match_state' => 'auto_match', 'is_pinned' => 0, 'linked_at' => $linkedAt,
        ]);

        return [$identityId, $linkId];
    }
}

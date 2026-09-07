<?php

namespace Tests\Feature;

use App\GoldenProfile\Support\Versioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class VersionerTest extends HubTestCase
{
    private Versioner $versioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versioner = new Versioner;
    }

    public function test_writing_an_unchanged_attribute_mints_no_version(): void
    {
        // The property the whole plan rests on. Without it, finalizeAll() — which
        // recomputes survivorship for every identity — adds one gp_identity row
        // per identity per run.
        $id = $this->seedIdentity();

        $result = $this->versioner->write('gp_identity', ['identity_id' => $id], [
            'canonical_first' => 'Robert',
            'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02',
        ]);

        $this->assertFalse($result['new_version']);
        $this->assertSame(1, $result['version_no']);
        $this->assertSame(1, $this->versionCount($id));
    }

    public function test_a_changed_attribute_inserts_version_two_and_flips_version_one(): void
    {
        $id = $this->seedIdentity();

        $result = $this->versioner->write('gp_identity', ['identity_id' => $id], [
            'canonical_first' => 'Bob',
        ]);

        $this->assertTrue($result['new_version']);
        $this->assertSame(2, $result['version_no']);
        $this->assertSame(2, $this->versionCount($id));

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $id)
            ->orderBy('version_no')->get();

        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('Robert', $rows[0]->canonical_first);
        $this->assertSame(1, (int) $rows[1]->current);
        $this->assertSame('Bob', $rows[1]->canonical_first);
    }

    public function test_absent_attributes_carry_forward(): void
    {
        // backfillKeys() supplies one column at a time; the new version must still
        // be a complete row.
        $id = $this->seedIdentity();

        $this->versioner->write('gp_identity', ['identity_id' => $id], ['npi' => 1234567893]);

        $row = $this->versioner->current('gp_identity', ['identity_id' => $id]);

        $this->assertSame('Robert', $row->canonical_first);
        $this->assertSame('Smith', $row->canonical_last);
        $this->assertSame('1234567893', (string) $row->npi);
        // The uuid identifies the logical identity and is the same across versions.
        $this->assertSame(
            $this->hub()->table('gp_identity')->where('identity_id', $id)
                ->where('version_no', 1)->value('identity_uuid'),
            $row->identity_uuid
        );
    }

    public function test_a_derived_value_is_written_in_place_without_a_version(): void
    {
        $id = $this->seedIdentity();

        $this->versioner->write(
            'gp_identity',
            ['identity_id' => $id],
            ['canonical_first' => 'Robert'],
            ['record_count' => 42],
        );

        $this->assertSame(1, $this->versionCount($id));
        $this->assertSame(
            42,
            (int) $this->versioner->current('gp_identity', ['identity_id' => $id])->record_count
        );
    }

    public function test_first_seen_is_preserved_and_last_updated_moves(): void
    {
        $id = $this->seedIdentity();
        $firstSeen = (string) $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->value('first_seen');

        $this->travel(2)->days();
        $this->versioner->write('gp_identity', ['identity_id' => $id], ['canonical_first' => 'Bob']);

        $row = $this->versioner->current('gp_identity', ['identity_id' => $id]);

        $this->assertSame($firstSeen, (string) $row->first_seen, 'first_seen is the doc date_created');
        $this->assertNotSame($firstSeen, (string) $row->last_updated);
    }

    public function test_a_superseded_row_keeps_its_own_timestamps(): void
    {
        $id = $this->seedIdentity();
        $before = (string) $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->value('last_updated');

        $this->travel(2)->days();
        $this->versioner->write('gp_identity', ['identity_id' => $id], ['canonical_first' => 'Bob']);

        $this->assertSame(
            $before,
            (string) $this->hub()->table('gp_identity')->where('identity_id', $id)
                ->where('version_no', 1)->value('last_updated'),
            'restamping a superseded row destroys the trail it exists to keep'
        );
    }

    public function test_a_license_version_is_keyed_on_the_natural_key(): void
    {
        $id = $this->seedIdentity();
        $linkId = $this->seedLink($id);

        $key = [
            'identity_id' => $id, 'license_number' => 'L-77',
            'certification_state' => 'NY', 'certification_board' => null,
        ];

        $first = $this->versioner->write('gp_license', $key, ['registry' => 'NYRN'], [], ['source_link_id' => $linkId]);
        $again = $this->versioner->write('gp_license', $key, ['registry' => 'NYRN'], [], ['source_link_id' => 999]);
        $moved = $this->versioner->write('gp_license', $key, ['registry' => 'NYEMED'], [], ['source_link_id' => 999]);

        $this->assertTrue($first['new_version']);
        $this->assertFalse($again['new_version'], 'a re-observation with the same facts is not a change');
        $this->assertTrue($moved['new_version']);
        $this->assertSame(2, (int) $this->hub()->table('gp_license')->where($key)->count());

        // source_link_id is onCreate: it records which source row ESTABLISHED the
        // fact, so the second call's 999 must not have overwritten it.
        $this->assertSame(
            $linkId,
            (int) $this->versioner->current('gp_license', $key)->source_link_id
        );
    }

    public function test_a_merge_repoints_the_current_version_and_retires_a_clash(): void
    {
        $survivor = $this->seedIdentity('Ann', 'Kowalski');
        $loser = $this->seedIdentity('Anne', 'Kowalski');
        $link = $this->seedLink($loser, 90101);

        // Only the loser holds L-88, so it moves.
        $this->versioner->write('gp_license', [
            'identity_id' => $loser, 'license_number' => 'L-88',
            'certification_state' => 'NY', 'certification_board' => null,
        ], [], [], ['source_link_id' => $link]);

        // Both hold L-77, so the loser's copy is retired rather than repointed.
        foreach ([$survivor, $loser] as $owner) {
            $this->versioner->write('gp_license', [
                'identity_id' => $owner, 'license_number' => 'L-77',
                'certification_state' => 'NY', 'certification_board' => null,
            ], [], [], ['source_link_id' => $link]);
        }

        $result = $this->versioner->repointForMerge('gp_license', $survivor, $loser);

        $this->assertSame(['repointed' => 1, 'retired' => 1], $result);
        $this->assertSame(
            ['L-77', 'L-88'],
            $this->hub()->table('gp_license')->where('identity_id', $survivor)->where('current', 1)
                ->orderBy('license_number')->pluck('license_number')->all()
        );
        // Retired, not deleted: the trail stays attached to the merged identity.
        $this->assertSame(
            1,
            (int) $this->hub()->table('gp_license')->where('identity_id', $loser)->count()
        );
        $this->assertSame(
            0,
            (int) $this->hub()->table('gp_license')->where('identity_id', $loser)->where('current', 1)->count()
        );
    }

    public function test_two_current_versions_cannot_be_forced_past_the_database(): void
    {
        // The reason uq_identity_current exists: a bug in a write path must fail
        // loudly rather than leave duplicate rows every read then returns twice.
        $id = $this->seedIdentity();

        $this->expectException(QueryException::class);

        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $id, 'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Bob', 'canonical_last' => 'Smith',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);
    }

    /**
     * retire() must leave the chain reachable and re-writable. A key whose
     * versions were all retired can be revived, and reviving it has to CONTINUE
     * the numbering — (key, version_no) is unique, so restarting at 1 collides
     * with the retired version 1.
     */
    public function test_a_retired_key_can_be_revived_and_keeps_counting(): void
    {
        $id = $this->seedIdentity();
        $link = $this->seedLink($id);
        $key = [
            'identity_id' => $id, 'license_number' => 'L-99',
            'certification_state' => 'NY', 'certification_board' => null,
        ];

        // source_link_id is NOT NULL, so a version-1 write must supply it. That
        // it fails loudly rather than inserting a partial row is the right
        // behaviour; only the revival below can omit it, because the column
        // carries forward.
        $this->versioner->write('gp_license', $key, ['registry' => 'NYRN'], [], ['source_link_id' => $link]);
        $this->assertSame(1, $this->versioner->retire('gp_license', $key));
        $this->assertNull($this->versioner->current('gp_license', $key));

        $revived = $this->versioner->write('gp_license', $key, ['registry' => 'NYRN']);

        $this->assertTrue($revived['new_version'], 'a retired key has no current version, so this is a new one');
        $this->assertSame(2, $revived['version_no'], 'numbering continues past the retired version');
        $this->assertSame(2, (int) $this->hub()->table('gp_license')->where($key)->count());
    }

    /**
     * A merged-away identity is not deleted and not merely flipped: it gets a NEW
     * current version saying status = merged. `current` is not `alive` — see
     * docs/SCD2.md — so this is the shape every read that filters
     * status = 'active' AND current = 1 depends on.
     */
    public function test_a_merged_identity_gets_a_current_version_recording_the_merge(): void
    {
        $survivor = $this->seedIdentity('Ann', 'Kowalski');
        $loser = $this->seedIdentity('Anne', 'Kowalski');

        $this->versioner->write('gp_identity', ['identity_id' => $loser], [
            'status' => 'merged', 'merged_into' => $survivor,
        ]);

        $row = $this->versioner->current('gp_identity', ['identity_id' => $loser]);

        $this->assertSame('merged', $row->status);
        $this->assertSame($survivor, (int) $row->merged_into);
        $this->assertSame(1, (int) $row->current, 'the latest truth about it is that it was merged');
        $this->assertSame(2, (int) $row->version_no);
        // The pre-merge state is still readable.
        $this->assertSame(
            'active',
            $this->hub()->table('gp_identity')->where('identity_id', $loser)
                ->where('version_no', 1)->value('status')
        );
    }

    private function seedIdentity(string $first = 'Robert', string $last = 'Smith'): int
    {
        return (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => $first, 'canonical_last' => $last,
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 0,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);
    }

    private function seedLink(int $identityId, int $sourceId = 90100): int
    {
        return (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => $sourceId,
            'match_method' => 'deterministic', 'match_key' => 'new', 'match_score' => 1.0,
            'match_state' => 'auto_match', 'is_pinned' => 0, 'linked_at' => now(),
        ]);
    }

    private function versionCount(int $identityId): int
    {
        return (int) $this->hub()->table('gp_identity')->where('identity_id', $identityId)->count();
    }
}

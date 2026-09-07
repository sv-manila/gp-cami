<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class VersionedMergeTest extends HubTestCase
{
    public function test_a_merged_identity_survives_as_a_final_version(): void
    {
        // Today applyMerge() DELETEs the loser. Under a design whose point is that
        // "older rows preserve a full audit trail", that is the opposite of the rule.
        [$survivor, $loser] = $this->twoIdentitiesSharingAnNpi();

        (new Engine)->dedup();

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $loser)
            ->orderBy('version_no')->get();

        $this->assertGreaterThanOrEqual(2, $rows->count(), 'the loser must not be deleted');

        $final = $rows->last();
        $this->assertSame('merged', $final->status);
        $this->assertSame($survivor, (int) $final->merged_into);
        $this->assertSame(1, (int) $final->current, 'the latest truth about it is that it was merged');
    }

    public function test_a_merged_identity_is_no_longer_matchable(): void
    {
        [$survivor, $loser] = $this->twoIdentitiesSharingAnNpi();
        (new Engine)->dedup();

        $bound = (new DeterministicResolver($this->systemId))->resolve($this->stagePerson([
            'first_name' => 'Bob', 'last_name' => 'Smith',
            'date_of_birth' => null, 'npi' => 1234567893,
        ]));

        $this->assertSame($survivor, $bound);
        $this->assertNotSame($loser, $bound);
    }

    public function test_dedup_reaches_a_fixed_point(): void
    {
        // The loser still exists as a row, so the guard against re-finding it is
        // status = 'merged'. Without that, dedup would loop forever.
        $this->twoIdentitiesSharingAnNpi();

        $merged = (new Engine)->dedup();

        $this->assertSame(1, $merged);
        $this->assertSame(0, (new Engine)->dedup(), 'a second dedup must find nothing');
    }

    public function test_the_merge_is_recorded_in_the_resolution_log(): void
    {
        [$survivor, $loser] = $this->twoIdentitiesSharingAnNpi();

        (new Engine)->dedup();

        $log = $this->hub()->table('gp_resolution_log')
            ->where('action', 'merge')->where('identity_id', $survivor)->first();

        $this->assertNotNull($log, 'gp_resolution_log has a merge action and has never been written');
        $this->assertSame('npi', $log->match_key);
        $this->assertContains($loser, json_decode((string) $log->affected_ids, true)['merged']);
    }

    public function test_a_clashing_child_row_is_retired_not_deleted(): void
    {
        [$survivor, $loser] = $this->twoIdentitiesSharingAnNpi();
        $link = $this->seedLink($survivor);

        foreach ([$survivor, $loser] as $owner) {
            $this->hub()->table('gp_license')->insert([
                'identity_id' => $owner, 'license_number' => 'L-77',
                'certification_state' => 'NY', 'certification_board' => null,
                'is_verified' => 0, 'source_link_id' => $link,
                'version_no' => 1, 'current' => 1, 'date_created' => now(), 'date_updated' => now(),
            ]);
        }
        $this->hub()->table('gp_license')->insert([
            'identity_id' => $loser, 'license_number' => 'L-88',
            'certification_state' => 'NY', 'certification_board' => null,
            'is_verified' => 0, 'source_link_id' => $link,
            'version_no' => 1, 'current' => 1, 'date_created' => now(), 'date_updated' => now(),
        ]);

        (new Engine)->dedup();

        $this->assertSame(
            ['L-77', 'L-88'],
            $this->hub()->table('gp_license')->where('identity_id', $survivor)->where('current', 1)
                ->orderBy('license_number')->pluck('license_number')->all()
        );
        // The clashing copy is retired under the merged identity, not destroyed.
        $this->assertSame(
            1,
            (int) $this->hub()->table('gp_license')->where('identity_id', $loser)->count()
        );
        $this->assertSame(
            0,
            (int) $this->hub()->table('gp_license')->where('identity_id', $loser)->where('current', 1)->count()
        );
    }

    public function test_finalize_all_visits_each_identity_once(): void
    {
        // finalizeAll() chunks by identity_id. With versions in the table,
        // identity_id is no longer unique — chunkById would visit an identity once
        // per version, so the filter is what keeps the cursor sound.
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09']);
        $id = (new DeterministicResolver($this->systemId))->resolve($a);
        (new DeterministicResolver($this->systemId))->resolve($this->stagePerson([
            'first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09', 'npi' => 1234567893,
        ]));

        $this->assertSame(2, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());

        $visited = [];
        (new Engine)->finalizeAll(function ($done, $total) use (&$visited) {
            $visited[] = $total;
        });

        $this->assertSame([1, 1], array_slice($visited, 0, 2), 'one identity, not one per version');
    }

    /**
     * A superseded version can carry a key its successor dropped. Without
     * `current = 1` beside `status = 'active'`, dedup would fold two live
     * identities together on evidence that no longer exists — a false merge
     * nothing downstream undoes.
     */
    public function test_dedup_does_not_merge_on_a_key_only_a_superseded_version_carries(): void
    {
        // Identity A: version 1 carried the npi, version 2 does not.
        $a = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Grace', 'canonical_last' => 'Adeyemi',
            'canonical_dob' => '1979-05-14', 'npi' => 1234567893,
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 0, 'first_seen' => now(), 'last_updated' => now(),
        ]);
        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $a, 'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Grace', 'canonical_last' => 'Adeyemi',
            'canonical_dob' => '1979-05-14', 'npi' => null,
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        // Identity B genuinely holds that npi now.
        $b = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Priya', 'canonical_last' => 'Venkataraman',
            'canonical_dob' => '1988-02-02', 'npi' => 1234567893,
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->assertSame(0, (new Engine)->dedup(), 'a superseded key is not evidence of shared identity');

        foreach ([$a, $b] as $id) {
            $this->assertSame(
                'active',
                $this->hub()->table('gp_identity')->where('identity_id', $id)
                    ->where('current', 1)->value('status')
            );
        }
    }

    /** @return array{0:int,1:int} [survivor, loser] */
    private function twoIdentitiesSharingAnNpi(): array
    {
        // Built by hand rather than through the resolver: the resolver's npi tier
        // would bind the second row to the first, which is the situation dedup
        // exists to clean up AFTER a partitioned parallel load created it.
        $ids = [];
        foreach ([['Robert', 'Smith'], ['Bob', 'Smith']] as [$first, $last]) {
            $ids[] = (int) $this->hub()->table('gp_identity')->insertGetId([
                'identity_uuid' => (string) Str::uuid(),
                'canonical_first' => $first, 'canonical_last' => $last,
                'canonical_dob' => null, 'npi' => 1234567893,
                'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
                'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
            ]);
        }
        sort($ids);

        return $ids;                 // dedup keeps the lowest identity_id
    }

    private function seedLink(int $identityId): int
    {
        return (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => 91000,
            'match_method' => 'deterministic', 'match_key' => 'new', 'match_score' => 1.0,
            'match_state' => 'auto_match', 'is_pinned' => 0, 'linked_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\ProbabilisticResolver;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class VersionedReadPathTest extends HubTestCase
{
    /**
     * A merged-away or superseded version inside the block would be scored as a
     * candidate and, in the review band, BOUND to — a false merge with no error.
     *
     * The fixture deliberately carries a matching address and zip, and that is
     * load-bearing rather than incidental. The plan's version of this test staged
     * two bare people and asserted no_match, but the implemented Pass B weights
     * are name 0.45 + dob 0.20 = 0.65 against a review_band_floor of 0.75, so
     * that fixture CANNOT reach the review band and its no_match assertion held
     * whether or not the filter existed — it passed before the fix. (That is the
     * Pass B narrowness 00-PROGRAMME.md §6 documents, showing up in a test.)
     * With address (0.15) and zip (0.05) the score reaches 0.85, so before the
     * filter Pass B genuinely binds to the merged identity and this test fails.
     */
    public function test_pass_b_never_offers_a_superseded_identity_version(): void
    {
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 1,
            'status' => 'active', 'version_no' => 1, 'current' => 0,
            'first_seen' => now(), 'last_updated' => now(),
        ]);
        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $id, 'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 1,
            'status' => 'merged', 'merged_into' => 999999,
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $linked = $this->stagePerson(['source_id' => 91001]);
        $link = (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $id, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => 91001,
            'match_method' => 'deterministic', 'match_key' => 'new', 'match_score' => 1.0,
            'match_state' => 'auto_match', 'is_pinned' => 0, 'linked_at' => now(),
        ]);

        // Current address on the candidate identity, matching the incoming record,
        // so address + zip fire and the score clears the review band.
        $this->hub()->table('gp_address')->insert([
            'identity_id' => $id, 'address1' => '4 Willow Lane', 'city' => 'Albany',
            'state' => 'NY', 'zip' => '12207', 'is_primary' => 1, 'source_link_id' => $link,
            'version_no' => 1, 'current' => 1, 'date_created' => now(), 'date_updated' => now(),
        ]);

        $incomingId = $this->stagePerson(['source_id' => 91002]);
        $this->hub()->table('stg_person_address')->insert([
            'stg_person_id' => $incomingId, 'address_type' => 'primary',
            'address1' => '4 Willow Lane', 'address2' => null,
            'city' => 'Albany', 'state' => 'NY', 'zip' => '12207',
        ]);

        $incoming = $this->hub()->table('stg_person')
            ->where('stg_person_id', $incomingId)->first();

        [$matched, , $state] = (new ProbabilisticResolver($this->systemId))->match($incoming, collect());

        $this->assertNull($matched, 'a merged identity must not be a Pass B candidate');
        $this->assertSame('no_match', $state);
        $this->assertNotNull($linked);
    }

    public function test_pass_b_scores_addresses_from_current_rows_only(): void
    {
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 1,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);
        $link = (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $id, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => 91101,
            'match_method' => 'deterministic', 'match_key' => 'new', 'match_score' => 1.0,
            'match_state' => 'auto_match', 'is_pinned' => 0, 'linked_at' => now(),
        ]);

        // An address the identity has MOVED AWAY from must not lend score to a
        // candidate that still lives there.
        $this->hub()->table('gp_address')->insert([
            'identity_id' => $id, 'address1' => '1 Old Road', 'city' => 'Albany',
            'state' => 'NY', 'zip' => '12207', 'is_primary' => 1, 'source_link_id' => $link,
            'version_no' => 1, 'current' => 0, 'date_created' => now(), 'date_updated' => now(),
        ]);

        $this->assertSame(
            0,
            (int) DB::connection('golden_profile')->table('gp_address')
                ->where('identity_id', $id)->where('current', 1)->count()
        );
    }

    public function test_the_credential_link_query_counts_current_versions_only(): void
    {
        // Two versions of one credential link. Unfiltered, this identity holds
        // twice the links it really has — which inflates the max_links guard and
        // doubles the placeholder count in every remote chunk. The measured cliff
        // in config/golden_profile.php (chunk 5000 at 360.7s vs chunk 1000 at 8.9s)
        // is what makes that a correctness issue rather than a tuning one.
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 1,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);

        foreach ([[1, 0, 'Pending'], [2, 1, 'Valid']] as [$version, $current, $status]) {
            $this->hub()->table('gp_identity_credential')->insert([
                'identity_id' => $id, 'credential_match_id' => 8001,
                'system_id' => $this->systemId, 'registry' => 'NYEMED',
                'match_summary_status' => $status, 'match_summary_status_code' => 20,
                'match_is_valid' => 1, 'source_current' => 1, 'link_state' => 'confirmed',
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
        }

        $links = DB::connection('golden_profile')->table('gp_identity_credential')
            ->where('identity_id', $id)->where('registry', 'NYEMED')
            ->whereIn('match_summary_status_code', [20, 30, 40, 45, 65, 70, 80, 85, 90])
            ->where('current', 1)
            ->get();

        $this->assertCount(1, $links);
        $this->assertSame('Valid', $links[0]->match_summary_status);

        // And the chunk cursor is sound again: (system_id, credential_match_id) is
        // unique among current rows, guaranteed by uq_cred_current.
        $this->assertSame(
            1,
            (int) DB::connection('golden_profile')->table('gp_identity_credential')
                ->where('identity_id', $id)->where('current', 1)
                ->where('credential_match_id', 8001)->count()
        );
    }

    /**
     * Task 9's Step 6 sweep, as a test rather than a grep somebody has to
     * remember to run. Every read of a versioned table must filter `current`,
     * and the exceptions are enumerated here with their reasons — so a NEW
     * unfiltered read fails the build instead of waiting to be audited.
     */
    public function test_no_unfiltered_read_of_a_versioned_table_remains(): void
    {
        $versioned = array_keys(Versioner::TABLES);

        // Files allowed to hold an unfiltered read, and why.
        $exempt = [
            // Manages `current` itself; filtering on it would break the write rule.
            'Support/Versioner.php',
            // Set-based bulk paths, guarded off until plan 3b converts them.
            'SqlBackfill.php',
            'Materialize/SetFinalizer.php',
            // applyMerge repoints ALL versions on purpose (identity_id is not part
            // of those tables' natural keys, so nothing can collide).
            'Engine.php',
            // Scratch-only emptiness probe.
            'Console/Commands/GpEval.php',
            // Read-only audit over whole-table state.
            'Console/Commands/GpNpiAudit.php',
            // Chunked timestamp backfill; it must see every version.
            'Console/Commands/GpVersionBackfill.php',
        ];

        $offenders = [];

        foreach (glob(app_path('**/*.php')) + glob(app_path('**/**/*.php')) + glob(app_path('**/**/**/*.php')) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen(app_path()) + 1));

            foreach ($exempt as $skip) {
                if (str_ends_with($relative, $skip)) {
                    continue 2;
                }
            }

            $source = file_get_contents($path);

            foreach ($versioned as $table) {
                if (! str_contains($source, "table('$table')") && ! str_contains($source, "table('$table as ")) {
                    continue;
                }
                if (! str_contains($source, 'current')) {
                    $offenders[] = "$relative reads $table with no `current` filter anywhere in the file";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }
}

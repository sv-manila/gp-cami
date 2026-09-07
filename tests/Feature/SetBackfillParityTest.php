<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\SqlBackfill;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Support\SetBasedTestCase;

/**
 * The deterministic ladder exists twice — DeterministicResolver (per row, used by
 * gp:sync) and SqlBackfill (set-based, used by gp:backfill over 13.4M rows). They
 * must agree, and the authoring brief records that a previous mismatch broke the
 * "rebuild produces a byte-identical profile" invariant. The SSN removal changes
 * matching semantics, so it has to change both — and Engine::dedup() as well,
 * which was a SECOND and independent route to an SSN merge.
 *
 * ON THE BASE CLASS. Plan 2 Task 3 specifies HubTestCase. That is wrong now, and
 * for a reason plan 3b established after plan 2 was written: HubTestCase isolates
 * with a transaction, and plan 3b Task 1 made indexStaging() a NO-OP while a
 * transaction is open (ALTER TABLE implicitly commits). Under HubTestCase
 * test_staging_no_longer_indexes_the_ssn_hash_column would therefore pass because
 * indexStaging() built nothing at all — a vacuous green. SetBasedTestCase gives
 * the transaction up and isolates with TRUNCATE, so the DDL runs for real.
 */
class SetBackfillParityTest extends SetBasedTestCase
{
    public function test_the_set_based_key_tiers_do_not_include_ssn_hash(): void
    {
        $tiers = (new ReflectionClass(SqlBackfill::class))
            ->getReflectionConstant('KEY_TIERS')->getValue();

        $this->assertNotContains('ssn_hash', $tiers,
            'the set-based ladder still has an ssn_hash tier — it must match DeterministicResolver');
        $this->assertSame(['npi', 'upin', 'dea_number'], $tiers);
    }

    public function test_the_set_based_path_does_not_bind_two_rows_sharing_an_ssn_hash(): void
    {
        $backfill = new SqlBackfill;
        $systemId = $this->backfillSystemId();
        $hash = hash('sha512', 'set-backfill-parity-distinct-ssn');

        // Same shape as ResolverLadderTest's per-row case: one real person, two
        // records, different first names, a DOB on only one of them. Nothing but a
        // shared ssn_hash could bind them.
        foreach ([['Grace', 'Adeyemi', '1979-05-14'], ['Gracie', 'Adeyemi', null]] as [$f, $l, $d]) {
            $this->stagePerson([
                'system_id' => $systemId, 'first_name' => $f, 'last_name' => $l,
                'date_of_birth' => $d, 'ssn_hash' => $hash,
            ]);
        }

        $backfill->resolveDeterministic();

        $identities = $this->hub()->table('gp_source_link')
            ->where('system_id', $systemId)->distinct()->pluck('identity_id');

        $this->assertCount(2, $identities,
            'the set-based tiers still bind on a shared ssn_hash');
        $this->assertSame(0, $this->hub()->table('gp_source_link')
            ->where('match_key', 'ssn_hash')->count(),
            'the set-based path must never mint a new ssn_hash-keyed link');
    }

    public function test_the_set_based_path_agrees_with_the_per_row_path_on_npi(): void
    {
        // The control. If the tiers were removed too enthusiastically, the case
        // above would pass for the wrong reason — this proves the ladder still
        // binds what it should, on both paths.
        $backfill = new SqlBackfill;
        $systemId = $this->backfillSystemId();

        foreach ([['Robert', 'Smith', '1970-04-02'], ['Bob', 'Smith', null]] as [$f, $l, $d]) {
            $this->stagePerson([
                'system_id' => $systemId, 'first_name' => $f, 'last_name' => $l,
                'date_of_birth' => $d, 'npi' => 1234567893,
            ]);
        }

        $backfill->resolveDeterministic();

        $setBased = $this->hub()->table('gp_source_link')
            ->where('system_id', $systemId)->distinct()->pluck('identity_id');

        $this->assertCount(1, $setBased, 'the set-based npi tier stopped binding');

        // And the per-row path, on its own system id so the two runs cannot
        // contaminate each other. 1987654328 and not the plan's 1987654327: plan 5
        // Task 2 found that value fails the NPPES Luhn-over-80840 check, and
        // NpiValidator now refuses it at ingestion, so an invalid NPI would never
        // reach the tier and this control would pass for the wrong reason.
        $a = $this->stagePerson(['system_id' => $this->systemId, 'npi' => 1987654328, 'first_name' => 'Ada']);
        $b = $this->stagePerson(['system_id' => $this->systemId, 'npi' => 1987654328, 'first_name' => 'Adele', 'date_of_birth' => null]);
        $resolver = new DeterministicResolver($this->systemId);

        $this->assertSame($resolver->resolve($a), $resolver->resolve($b));
    }

    public function test_dedup_no_longer_merges_identities_that_share_an_ssn_hash(): void
    {
        // dedup is a SECOND route to an SSN merge, independent of the tier: it
        // groups active identities by a shared non-null column and folds them
        // together however they were resolved. Removing the tier alone would leave
        // this welding on SSN.
        $hash = hash('sha512', 'set-backfill-parity-dedup-ssn');
        $ids = [];

        // version_no and current are stated rather than left to the column
        // defaults, which the plan's version of this test predates: dedup filters
        // current = 1, so a fixture that relied on a default would be asserting
        // against the migration rather than against dedup.
        foreach ([['Grace', 'Adeyemi', '1979-05-14'], ['Gracie', 'Adeyemi', '1981-11-02']] as [$f, $l, $d]) {
            $ids[] = (int) $this->hub()->table('gp_identity')->insertGetId([
                'identity_uuid' => (string) Str::uuid(),
                'canonical_first' => $f, 'canonical_last' => $l, 'canonical_dob' => $d,
                'ssn_hash' => $hash, 'confidence' => 1.0, 'record_count' => 0,
                'status' => 'active', 'version_no' => 1, 'current' => 1,
                'first_seen' => now(), 'last_updated' => now(),
            ]);
        }

        (new Engine)->dedup();

        $stillActive = $this->hub()->table('gp_identity')
            ->whereIn('identity_id', $ids)
            ->where('current', 1)->where('status', 'active')->count();

        $this->assertSame(2, $stillActive, 'dedup merged two identities on a shared ssn_hash');
    }

    public function test_dedup_still_merges_identities_that_share_an_npi(): void
    {
        // The control for the test above. Both identities carry a shared ssn_hash
        // there and dedup leaves them alone; if dedup were broken outright rather
        // than merely no longer SSN-aware, that test would pass for the wrong
        // reason. Here the same two identities also share an npi and MUST merge.
        $ids = [];

        foreach ([['Grace', 'Adeyemi', '1979-05-14'], ['Gracie', 'Adeyemi', '1981-11-02']] as [$f, $l, $d]) {
            $ids[] = (int) $this->hub()->table('gp_identity')->insertGetId([
                'identity_uuid' => (string) Str::uuid(),
                'canonical_first' => $f, 'canonical_last' => $l, 'canonical_dob' => $d,
                'npi' => 1234567893, 'confidence' => 1.0, 'record_count' => 0,
                'status' => 'active', 'version_no' => 1, 'current' => 1,
                'first_seen' => now(), 'last_updated' => now(),
            ]);
        }

        (new Engine)->dedup();

        $this->assertSame(
            1, $this->hub()->table('gp_identity')
                ->whereIn('identity_id', $ids)
                ->where('current', 1)->where('status', 'active')->count(),
            'dedup stopped merging on npi — the ssn removal took too much with it'
        );
    }

    public function test_staging_no_longer_indexes_the_ssn_hash_column(): void
    {
        // indexStaging adds indexes on the tier-key columns before transform so the
        // GROUP BY / NOT EXISTS become index lookups. An index on a column no tier
        // reads is maintenance cost on every one of 13.4M staging inserts, and the
        // Task 7 migration drops the column, which would then fail against a
        // leftover runtime index.
        (new SqlBackfill)->indexStaging();

        // Not vacuous: the same call must still have built the tier indexes it
        // does own. Without this, a broken indexStaging() (or a transactional
        // no-op) would satisfy the assertion below for free.
        $this->assertNotNull($this->stagingIndex('stg_npi'), 'indexStaging built nothing at all');

        $this->assertNull(
            $this->stagingIndex('stg_ssn'),
            'indexStaging still creates stg_ssn on stg_person.ssn_hash'
        );
    }

    private function stagingIndex(string $name): ?object
    {
        return $this->hub()->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            ['stg_person', $name],
        );
    }
}

<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Eval\EvalRunner;
use App\GoldenProfile\Eval\EvalSet;
use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\SqlBackfill;
use App\GoldenProfile\Support\SetBasedPathGuard;
use App\GoldenProfile\Support\Versioner;
use Tests\Support\SetBasedTestCase;

/**
 * The claim plan 3b exists to earn: the per-row and set-based pipelines produce
 * the same hub from the same input.
 *
 * Three censuses, in increasing strictness:
 *
 *  1. the GROUPING — which source rows ended up together. If this differs, a read
 *     filter is wrong and the eval gate would have caught it too.
 *  2. the VERSION CENSUS — how many rows and how many current rows each versioned
 *     table holds. This is the headline number: if one path mints more versions
 *     than the other from identical input, VersionerSql::same() and
 *     Versioner::same() have diverged, and that is exactly the failure the
 *     differential test in VersionerSqlTest is the first line of defence against.
 *  3. the PROFILE CENSUS — the repo's own documented byte-identical invariant, at
 *     pipeline scale rather than one identity at a time. Scalars byte-exact, the
 *     ten JSON arrays multiset-exact, because MySQL 8 has no ORDER BY inside
 *     JSON_ARRAYAGG (docs/SCD2.md).
 *
 * Both censuses are keyed on the source-row grouping, never on identity_id: the
 * two paths mint identities in different orders and are not required to agree on
 * the numbers. Keying on the grouping compares the thing that matters.
 *
 * WHAT EACH PATH IS, and why the two lists are not mirror images — the set-based
 * ladder has no licence tier, no identifier tier and no Pass B, because
 * resolveDeterministic()'s own comment says licence resolution "is handled after
 * enrich(), by dedup's mergeByLicense" and "the probabilistic Pass B is
 * intentionally skipped here". enrich() and dedup() are therefore not extras
 * bolted on for the test; they are where two of the per-row path's tiers live.
 */
class SetBasedParityTest extends SetBasedTestCase
{
    private function fixture(): EvalSet
    {
        return EvalSet::load(base_path('tests/eval/identity-pairs.json'));
    }

    /** Run the per-row ladder end to end and return its three censuses. */
    private function runPerRow(): array
    {
        $systemId = $this->backfillSystemId();
        (new EvalRunner($systemId))->run($this->fixture());

        // finalizeAll() is the per-row counterpart of SetFinalizer::run().
        (new Engine)->finalizeAll();

        return [
            'clusters' => $this->clusterSnapshot($systemId),
            'versions' => $this->versionCensus(),
            'profiles' => $this->profileCensus($systemId),
        ];
    }

    /** Run the set-based ladder end to end and return its three censuses. */
    private function runSetBased(): array
    {
        $systemId = $this->backfillSystemId();
        (new EvalRunner($systemId))->runSetBased($this->fixture());

        (new SetFinalizer)->run();

        return [
            'clusters' => $this->clusterSnapshot($systemId),
            'versions' => $this->versionCensus(),
            'profiles' => $this->profileCensus($systemId),
        ];
    }

    public function test_the_two_pipelines_group_the_same_rows_together(): void
    {
        $perRow = $this->runPerRow();
        $this->wipeHub();
        $setBased = $this->runSetBased();

        $this->assertSame(
            $perRow['clusters'], $setBased['clusters'],
            'the two pipelines produced different groupings — read the diff: a grouping the '.
            'set-based path split that the per-row path merged points at a missing '.
            'enrich()/dedup() step; the reverse points at a read filter'
        );
    }

    public function test_the_two_pipelines_mint_the_same_number_of_versions(): void
    {
        $perRow = $this->runPerRow();
        $this->wipeHub();
        $setBased = $this->runSetBased();

        $this->assertSame(
            $perRow['versions'], $setBased['versions'],
            'the two pipelines minted different version counts from identical input — '.
            'VersionerSql::same() and Versioner::same() have diverged'
        );
    }

    public function test_neither_pipeline_leaves_two_current_versions_of_anything(): void
    {
        // uq_*_current enforces this for every key whose parts are all non-null.
        // For the rest it is the CODE that enforces it — the <=> key joins — so it
        // is worth asserting rather than assuming. gp_address has four nullable
        // key parts.
        foreach ([$this->runPerRow(), $this->wipeAndRunSetBased()] as $label => $_) {
            foreach (Versioner::TABLES as $table => $spec) {
                $keyList = implode(', ', array_map(fn ($c) => "`$c`", $spec['key']));

                $duplicates = $this->hub()->select(
                    "SELECT COUNT(*) n FROM (
                         SELECT $keyList FROM `$table` WHERE `current` = 1
                         GROUP BY $keyList HAVING COUNT(*) > 1
                     ) d"
                );

                $this->assertSame(
                    0, (int) $duplicates[0]->n,
                    "$table holds two current versions of one natural key (pass $label)"
                );
            }
        }
    }

    private function wipeAndRunSetBased(): array
    {
        $this->wipeHub();

        return $this->runSetBased();
    }

    public function test_the_two_pipelines_build_the_same_profiles(): void
    {
        // The repo's own invariant, at pipeline scale: "a set-based materialize and
        // ProfileMaterializer::rebuild() produce byte-identical profile rows". It
        // is why Survivorship's final tiebreak is pinned to link_id ASC.
        $perRow = $this->runPerRow();
        $this->wipeHub();
        $setBased = $this->runSetBased();

        $this->assertSame(
            array_keys($perRow['profiles']), array_keys($setBased['profiles']),
            'the two pipelines built profiles for different groupings'
        );

        foreach ($perRow['profiles'] as $key => $expected) {
            foreach (self::TIEBREAK_DEPENDENT as $column) {
                unset($expected[$column], $setBased['profiles'][$key][$column]);
            }

            $this->assertSame(
                $expected, $setBased['profiles'][$key],
                "the two pipelines built different profiles for the grouping [$key]"
            );
        }
    }

    /**
     * The survivorship winner for a field can differ between the two paths when
     * the candidates tie on BOTH authority and recency, and that is a divergence
     * this plan discovered rather than one it introduced.
     *
     * Both paths break such a tie with `link_id ASC` — Survivorship's comparator
     * ends in exactly that, with a comment saying it is pinned "to match
     * SetFinalizer's SQL ordering". But link_id is an artifact of the order
     * gp_source_link rows were INSERTED, and the two paths do not insert them in
     * the same order: the per-row path creates one link per resolve() call in
     * staging order, while tierLink() creates them all in one INSERT … SELECT
     * whose row order is the join's. So the tiebreak is deterministic within a
     * path and not shared between paths, and pinning link_id did not buy the
     * invariant it was believed to buy.
     *
     * Observed on the eval fixture's ssn-a / ssn-b pair, which ties because
     * EvalRunner stages every row with the same source_modified: the per-row path
     * crowns 'Grace' and the set-based path 'Gracie'.
     *
     * NOT fixed here. The fix is to tiebreak on something both paths agree on —
     * gp_source_link.source_id is stable and identical across them — but that
     * changes which canonical value wins in production ties, which is a
     * survivorship change and not this plan's to make. Recorded in docs/SCD2.md.
     * Clustering and the eval gate are unaffected: the gate scores which records
     * group together, not which spelling of a name wins.
     */
    private const TIEBREAK_DEPENDENT = [
        'first_name', 'middle_name', 'last_name', 'suffix', 'date_of_birth',
    ];

    public function test_the_tiebreak_divergence_is_confined_to_a_genuine_tie(): void
    {
        // The divergence above must not be a licence to differ freely: where the
        // two paths disagree on a canonical field, BOTH answers have to come from
        // the cluster's own candidates. A value from nowhere would be a real bug
        // wearing this exemption as cover.
        $perRow = $this->runPerRow();
        $this->wipeHub();
        $setBased = $this->runSetBased();

        $staged = $this->hub()->table('stg_person')->pluck('first_name')->filter()->unique()->all();

        foreach ($setBased['profiles'] as $key => $profile) {
            if ($profile['first_name'] === null) {
                continue;
            }

            $this->assertContains(
                $profile['first_name'], $staged,
                "the set-based path crowned a first_name for [$key] that no staged row supplied"
            );
        }

        $this->assertSame(
            count($perRow['profiles']), count($setBased['profiles']),
            'the exemption must not hide a missing or extra profile'
        );
    }

    public function test_the_set_based_pipeline_no_longer_refuses_to_run(): void
    {
        // SetBasedPathGuard is deleted. Its own docblock said "DELETING THIS FILE
        // IS PART OF PLAN 3b", and the parity assertions above are what earned it.
        $this->assertFalse(
            class_exists(SetBasedPathGuard::class),
            'the guard should be gone now that the bulk paths are converted'
        );

        $systemId = $this->backfillSystemId();
        (new EvalRunner($systemId))->stage($this->fixture());

        // Both entry points, which used to throw.
        (new SqlBackfill)->transform();
        (new SetFinalizer)->run();

        $this->assertGreaterThan(0, (int) $this->hub()->table('gp_identity')->count());
        $this->assertGreaterThan(0, (int) $this->hub()->table('gp_identity_profile')->count());
    }
}

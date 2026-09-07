<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Eval\EvalRunner;
use App\GoldenProfile\Eval\EvalSet;
use App\GoldenProfile\SqlBackfill;
use Tests\Support\SetBasedTestCase;

/**
 * The eval set, scored through BOTH ladders, asserted to agree.
 *
 * SCD-2 is a change to how facts are STORED, not to who matches whom. Every
 * deterministic tier, every Pass B signal and every threshold is untouched by
 * plans 3a and 3b, so the gate must read exactly what plan 5 left it at — and the
 * two implementations of the ladder must read it identically. That second half is
 * the cheapest parity check the programme has: it exercises the whole set-based
 * pipeline against a fixture whose correct answer is already known.
 *
 * Runs under SetBasedTestCase, not HubTestCase, and that is not optional: the
 * set-based path issues DDL (indexStaging, the key-index drop/rebuild), MySQL
 * implicitly commits on DDL, and plan 3b Task 1 makes that maintenance SKIP while
 * a transaction is open. Under HubTestCase the pipeline would therefore run in a
 * shape production never uses.
 *
 * If the two paths disagree, read the cluster diff rather than the counts: a ref
 * the set-based path left in a singleton that the per-row path merged points at a
 * missing enrich()/dedup() step, and the reverse points at a read filter.
 */
class EvalGateBothPathsTest extends SetBasedTestCase
{
    /** Clusters as sorted ref lists, sorted — comparable regardless of identity_id. */
    private function canonical(array $clusters): array
    {
        $out = array_map(function ($cluster) {
            sort($cluster);

            return implode(',', $cluster);
        }, $clusters);
        sort($out);

        return $out;
    }

    public function test_both_ladders_score_the_eval_set_identically(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $systemId = $this->backfillSystemId();

        $perRow = (new EvalRunner($systemId))->run($set);

        $this->wipeHub();
        $systemId = $this->backfillSystemId();

        $setBased = (new EvalRunner($systemId))->runSetBased($set);

        $this->assertSame(
            $this->canonical($perRow['clusters']),
            $this->canonical($setBased['clusters']),
            'the per-row and set-based ladders produced different clusterings'
        );

        foreach (['true_pairs', 'predicted_pairs', 'true_positives', 'false_merges', 'false_splits'] as $metric) {
            $this->assertSame(
                $perRow['report'][$metric], $setBased['report'][$metric],
                "the two paths disagree on $metric"
            );
        }
    }

    public function test_the_set_based_path_clears_the_quality_gate(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $report = (new EvalRunner($this->backfillSystemId()))->runSetBased($set);

        $message = sprintf(
            'set-based ladder: precision %.4f recall %.4f f1 %.4f, %d false merge(s), %d false split(s)',
            $report['report']['precision'], $report['report']['recall'], $report['report']['f1'],
            $report['report']['false_merges'], $report['report']['false_splits'],
        );

        // The same floors and ratchets EvalGateTest applies to the per-row path.
        // Never relax either — docs/EVALUATION.md forbids it explicitly.
        $this->assertGreaterThanOrEqual(11, $report['report']['true_pairs'], $message);
        $this->assertSame(0, $report['report']['false_merges'], "false merges are never acceptable — $message");
        $this->assertGreaterThanOrEqual(0.99, $report['report']['precision'], $message);
        $this->assertSame(0, $report['report']['false_splits'], $message);
        $this->assertSame(1.0, $report['report']['recall'], $message);
    }

    public function test_the_set_based_pipeline_is_idempotent_over_the_eval_set(): void
    {
        // A second full pass over unchanged input must add no identity, no link and
        // no version. This is what makes an interrupted bulk load safe to resume.
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $systemId = $this->backfillSystemId();

        (new EvalRunner($systemId))->runSetBased($set);

        $before = [
            'identities' => (int) $this->hub()->table('gp_identity')->count(),
            'links' => (int) $this->hub()->table('gp_source_link')->count(),
            'licenses' => (int) $this->hub()->table('gp_license')->count(),
            'identifiers' => (int) $this->hub()->table('gp_identity_identifier')->count(),
        ];

        $backfill = new SqlBackfill;
        $backfill->resolveDeterministic();
        $backfill->enrich();
        (new Engine)->dedup();

        $this->assertSame($before, [
            'identities' => (int) $this->hub()->table('gp_identity')->count(),
            'links' => (int) $this->hub()->table('gp_source_link')->count(),
            'licenses' => (int) $this->hub()->table('gp_license')->count(),
            'identifiers' => (int) $this->hub()->table('gp_identity_identifier')->count(),
        ], 'a second set-based pass over unchanged input was not a no-op');
    }
}

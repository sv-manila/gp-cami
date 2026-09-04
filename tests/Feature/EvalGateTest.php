<?php

namespace Tests\Feature;

use App\GoldenProfile\Eval\EvalRunner;
use App\GoldenProfile\Eval\EvalSet;
use Tests\Support\HubTestCase;

class EvalGateTest extends HubTestCase
{
    public function test_the_resolver_clears_the_quality_gate_on_the_eval_set(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $report = (new EvalRunner($this->systemId))->run($set)['report'];

        $message = sprintf(
            'precision %.4f recall %.4f f1 %.4f — %d false merge(s), %d false split(s)',
            $report['precision'], $report['recall'], $report['f1'],
            $report['false_merges'], $report['false_splits']
        );

        // The eval set must not shrink. Deleting records raises every ratio for
        // free, so a floor on the metrics alone is not a regression net — this
        // pins the denominator. 9 true pairs = smith(3) + garcia(1) + kowalski(1)
        // + chain(3) + ssn(1).
        $this->assertGreaterThanOrEqual(
            9, $report['true_pairs'],
            'the eval set shrank — pairs were removed, not the matcher improved'
        );

        // Precision-first per config golden_profile.tracks.identity. A false merge
        // welds two real providers together and nothing downstream undoes it, so
        // the gate on merges is absolute.
        $this->assertSame(0, $report['false_merges'], "false merges are never acceptable — $message");
        $this->assertGreaterThanOrEqual(0.99, $report['precision'], $message);

        // Recall floor sits below the PROJECT_PLAN 0.95 target: Pass B cannot
        // auto-merge today (its implemented weights sum to exactly auto_merge_at),
        // so the deterministic ladder alone sets the ceiling. Raise this as
        // calibration lands. Never lower it to make a build pass.
        $this->assertGreaterThanOrEqual(0.80, $report['recall'], $message);

        // Ratchet. The resolver scores a perfect 1.0/1.0 today, and a floor 20%
        // below that would let the whole ssn_hash tier be deleted silently
        // (recall would fall to 0.889 and still clear 0.80). Plan 2 removes that
        // tier deliberately and MUST re-baseline these two lines in the same PR,
        // stating the new numbers. Never relax them to make an unrelated change pass.
        $this->assertSame(0, $report['false_splits'], "regression against the measured baseline — $message");
        $this->assertSame(1.0, $report['recall'], "regression against the measured baseline — $message");
    }
}

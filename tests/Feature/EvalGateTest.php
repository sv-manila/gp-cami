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
    }
}

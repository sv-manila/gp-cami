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
        $result = (new EvalRunner($this->systemId))->run($set);
        $report = $result['report'];

        $message = sprintf(
            'precision %.4f recall %.4f f1 %.4f — %d false merge(s), %d false split(s)',
            $report['precision'], $report['recall'], $report['f1'],
            $report['false_merges'], $report['false_splits']
        );

        // The eval set must not shrink. Deleting records raises every ratio for
        // free, so a floor on the metrics alone is not a regression net — this
        // pins the denominator. 11 true pairs = smith(3) + garcia(1) + kowalski(1)
        // + chain(3) + ssn(1) + mmis(1) + dea(1) — the last two added by plan 5,
        // which runs before this plan under 00-PROGRAMME.md §2. The ssn pair
        // stays in the set even though the matcher can no longer find it; see
        // the block below.
        $this->assertGreaterThanOrEqual(
            11, $report['true_pairs'],
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

        // RE-BASELINED by the GPP conformance programme's SSN removal (plan 2),
        // onto plan 5's fixture, not plan 1's — see 00-PROGRAMME.md §2 and §4.
        // Plan 5 runs first under the canonical order and raises true_pairs to
        // 11 (an mmis pair and a dea pair, both newly bindable) with recall
        // still 1.0. This task's own baseline is therefore 11, not 9.
        //
        // Was: assertSame(0, false_splits) and assertSame(1.0, recall), measured
        // against plan 5's 11-pair fixture. The ssn_hash tier was the only thing
        // binding ssn-a to ssn-b, so removing it turns that pair into a false
        // split: true_pairs 11 (unchanged), true_positives 11 -> 10, recall
        // 1.0 -> 10/11 (0.9091), f1 1.0 -> 20/21 (0.9524), false_splits 0 -> 1.
        // Precision stays 1.0000 — the tier only ever produced correct merges,
        // so nothing it used to do was wrong; the hub is simply not allowed to
        // do it. 00-PROGRAMME.md §4 calls precision 1.0000 absolute at every
        // step, and it is met exactly here, not merely above the 0.99 floor.
        //
        // The ratchet is on the integer counts, not the float. 10/11 has no
        // exact decimal form, and a float ratchet is an invitation to widen the
        // tolerance later; these two are exact, and together with
        // true_pairs >= 11 above they pin the numerator and the denominator.
        $this->assertSame(1, $report['false_splits'], "regression against the measured baseline — $message");
        $this->assertSame(10, $report['true_positives'], "regression against the measured baseline — $message");

        // WHICH pair is allowed to be split. false_splits === 1 on its own would
        // accept a run that split garcia and simultaneously merged the ssn pair —
        // same count, two new defects. This names the accepted split.
        $clusterOf = function (string $ref) use ($result): array {
            foreach ($result['clusters'] as $cluster) {
                if (in_array($ref, $cluster, true)) {
                    return $cluster;
                }
            }

            return [];
        };

        $this->assertNotContains('ssn-b', $clusterOf('ssn-a'),
            'the ssn pair is the ONE accepted false split; it must be this pair and no other');
        foreach ([['smith-a', 'smith-b'], ['smith-a', 'smith-c'], ['garcia-a', 'garcia-b'],
            ['kowalski-a', 'kowalski-b'], ['chain-a', 'chain-b'], ['chain-a', 'chain-c']] as [$x, $y]) {
            $this->assertContains($y, $clusterOf($x), "$x and $y must still resolve together — $message");
        }
    }
}

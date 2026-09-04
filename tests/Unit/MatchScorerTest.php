<?php

namespace Tests\Unit;

use App\GoldenProfile\Eval\MatchScorer;
use Tests\TestCase;

class MatchScorerTest extends TestCase
{
    public function test_a_perfect_prediction_scores_one(): void
    {
        $r = MatchScorer::score([['a', 'b'], ['c']], [['a', 'b'], ['c']]);

        $this->assertSame(1, $r['true_positives']);
        $this->assertSame(0, $r['false_merges']);
        $this->assertSame(0, $r['false_splits']);
        $this->assertSame(1.0, $r['precision']);
        $this->assertSame(1.0, $r['recall']);
        $this->assertSame(1.0, $r['f1']);
    }

    public function test_a_false_merge_costs_precision_not_recall(): void
    {
        // truth: a,b together and c alone. predicted: all three together.
        $r = MatchScorer::score([['a', 'b', 'c']], [['a', 'b'], ['c']]);

        $this->assertSame(1, $r['true_positives']);
        $this->assertSame(2, $r['false_merges']);   // a-c and b-c
        $this->assertSame(0, $r['false_splits']);
        $this->assertEqualsWithDelta(1 / 3, $r['precision'], 1e-9);
        $this->assertSame(1.0, $r['recall']);
    }

    public function test_a_false_split_costs_recall_not_precision(): void
    {
        // truth: a,b,c together. predicted: a,b together and c alone.
        $r = MatchScorer::score([['a', 'b'], ['c']], [['a', 'b', 'c']]);

        $this->assertSame(1, $r['true_positives']);
        $this->assertSame(0, $r['false_merges']);
        $this->assertSame(2, $r['false_splits']);   // a-c and b-c
        $this->assertSame(1.0, $r['precision']);
        $this->assertEqualsWithDelta(1 / 3, $r['recall'], 1e-9);
    }

    public function test_all_singletons_on_both_sides_score_one(): void
    {
        // No pairs anywhere. Vacuously perfect, and must not divide by zero.
        $r = MatchScorer::score([['a'], ['b']], [['a'], ['b']]);

        $this->assertSame(0, $r['true_pairs']);
        $this->assertSame(1.0, $r['precision']);
        $this->assertSame(1.0, $r['recall']);
        $this->assertSame(1.0, $r['f1']);
    }

    public function test_predicting_nothing_together_when_truth_has_pairs_scores_zero_recall(): void
    {
        $r = MatchScorer::score([['a'], ['b']], [['a', 'b']]);

        $this->assertSame(1.0, $r['precision']);  // made no wrong merges
        $this->assertSame(0.0, $r['recall']);
        $this->assertSame(0.0, $r['f1']);
    }

    public function test_the_metrics_are_always_floats(): void
    {
        // PHP returns int from an exact int division: 1/1 is int(1), not 1.0.
        // The declared contract is float, and EvalRunner/GpEval format on it.
        $r = MatchScorer::score([['a', 'b']], [['a', 'b']]);

        $this->assertIsFloat($r['precision']);
        $this->assertIsFloat($r['recall']);
        $this->assertIsFloat($r['f1']);
    }

    public function test_cluster_order_does_not_change_the_score(): void
    {
        $a = MatchScorer::score([['b', 'a'], ['c']], [['a', 'b'], ['c']]);
        $b = MatchScorer::score([['c'], ['a', 'b']], [['c'], ['b', 'a']]);

        $this->assertSame($a, $b);
    }

    public function test_refs_containing_the_separator_do_not_collide(): void
    {
        // ['a|b','c'] and ['a','b|c'] both key to "a|b|c" under a naive '|'
        // separator, silently collapsing two distinct pairs into one and
        // undercounting every metric.
        $r = MatchScorer::score([['a|b', 'c'], ['a', 'b|c']], [['a|b', 'c'], ['a', 'b|c']]);

        $this->assertSame(2, $r['predicted_pairs']);
        $this->assertSame(2, $r['true_pairs']);
        $this->assertSame(2, $r['true_positives']);
    }

    public function test_a_repeated_ref_in_one_cluster_does_not_create_a_self_pair(): void
    {
        // Without array_unique(), the duplicated 'a' in ['a','a','b'] would
        // pair with itself ("a\0a") on top of pairing with 'b' twice (both
        // collapsing to the same "a\0b" key), inflating predicted_pairs to 2
        // instead of the 1 pair the deduplicated cluster ['a','b'] actually has.
        $r = MatchScorer::score([['a', 'a', 'b']], [['a', 'b']]);

        $this->assertSame(1, $r['predicted_pairs']);
        $this->assertSame(1, $r['true_positives']);
        $this->assertSame(1.0, $r['precision']);
    }

    public function test_a_four_member_cluster_counts_all_six_pairs(): void
    {
        // Every other test here uses clusters of at most three, so an
        // off-by-one in the nested loop bounds (e.g. $j < $n - 1) would still
        // pass them all. A 4-member cluster has 4 choose 2 = 6 pairs.
        $r = MatchScorer::score([['a', 'b', 'c', 'd']], [['a', 'b', 'c', 'd']]);

        $this->assertSame(6, $r['true_pairs']);
        $this->assertSame(6, $r['predicted_pairs']);
        $this->assertSame(6, $r['true_positives']);
    }
}

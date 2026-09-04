<?php

namespace App\GoldenProfile\Eval;

/**
 * Pairwise scoring of a clustering against an answer key.
 *
 * Entity resolution is judged on pairs, not clusters: for every pair of records,
 * did we put them together, and was that right? That makes the two error modes
 * nameable and separately countable, which the GPP wiki asks for explicitly — a
 * false SPLIT (two records of one provider left apart) is how an excluded
 * provider gets missed, and costs more than a false MERGE.
 *
 *   false_merge = predicted together, truth apart -> hurts precision
 *   false_split = predicted apart, truth together -> hurts recall
 *
 * Both sides empty is defined as a perfect score rather than 0/0: a set of
 * genuine singletons the matcher correctly kept apart is a pass, not undefined.
 *
 * Every ratio is cast to float. PHP returns int from an exact int division
 * (1/1 is int(1)), which would break the declared float contract and any
 * assertSame against 1.0 downstream.
 */
class MatchScorer
{
    /**
     * @param  list<list<string>>  $predicted
     * @param  list<list<string>>  $truth
     * @return array{true_pairs:int,predicted_pairs:int,true_positives:int,false_merges:int,false_splits:int,precision:float,recall:float,f1:float}
     */
    public static function score(array $predicted, array $truth): array
    {
        $p = self::pairs($predicted);
        $t = self::pairs($truth);

        $tp = count(array_intersect_key($p, $t));
        $falseMerges = count($p) - $tp;
        $falseSplits = count($t) - $tp;

        $precision = count($p) === 0 ? 1.0 : (float) $tp / count($p);
        $recall = count($t) === 0 ? 1.0 : (float) $tp / count($t);
        $f1 = ($precision + $recall) === 0.0
            ? 0.0
            : (float) (2 * $precision * $recall / ($precision + $recall));

        return [
            'true_pairs' => count($t),
            'predicted_pairs' => count($p),
            'true_positives' => $tp,
            'false_merges' => $falseMerges,
            'false_splits' => $falseSplits,
            'precision' => $precision,
            'recall' => $recall,
            'f1' => $f1,
        ];
    }

    /**
     * Every unordered within-cluster pair, keyed "a|b" with the refs sorted so
     * the key is order-independent.
     *
     * @param  list<list<string>>  $clusters
     * @return array<string,true>
     */
    private static function pairs(array $clusters): array
    {
        $out = [];
        foreach ($clusters as $cluster) {
            $members = array_values(array_unique($cluster));
            sort($members);
            $n = count($members);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $out[$members[$i].'|'.$members[$j]] = true;
                }
            }
        }

        return $out;
    }
}

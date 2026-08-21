<?php

namespace Tests\Unit;

use App\GoldenProfile\Resolution\ProbabilisticResolver;
use Tests\TestCase;

class ProbabilisticScoringTest extends TestCase
{
    public function test_jaro_winkler_bounds_and_identity(): void
    {
        $this->assertSame(1.0, ProbabilisticResolver::jaroWinkler('smith john', 'smith john'));
        $this->assertSame(0.0, ProbabilisticResolver::jaroWinkler('', 'smith'));
        $this->assertSame(0.0, ProbabilisticResolver::jaroWinkler('smith', ''));

        foreach ([['martha', 'marhta'], ['dixon', 'dicksonx'], ['smith', 'jones']] as [$a, $b]) {
            $score = ProbabilisticResolver::jaroWinkler($a, $b);
            $this->assertGreaterThanOrEqual(0.0, $score, "$a/$b below 0");
            $this->assertLessThanOrEqual(1.0, $score, "$a/$b above 1");
        }
    }

    public function test_jaro_winkler_is_symmetric(): void
    {
        // Asymmetry would make a match depend on which record was staged first.
        foreach ([['martha', 'marhta'], ['adkins paula', 'adkins paul']] as [$a, $b]) {
            $this->assertEqualsWithDelta(
                ProbabilisticResolver::jaroWinkler($a, $b),
                ProbabilisticResolver::jaroWinkler($b, $a),
                1e-9,
                "jaroWinkler($a,$b) is not symmetric",
            );
        }
    }

    public function test_prefix_boost_rewards_shared_leading_characters(): void
    {
        $this->assertGreaterThan(
            ProbabilisticResolver::jaroWinkler('smith', 'xmith'),
            ProbabilisticResolver::jaroWinkler('smith', 'smitx'),
        );
    }

    /**
     * Regression guard on the auto-merge reachability gap: config declares a
     * provider_type weight (0.08) that score() never fires because stg_person has
     * no provider-type column. The implemented weights therefore top out at 0.92,
     * exactly auto_merge_at, so 'auto_match' requires a perfect score on every
     * other signal at once.
     *
     * This test does not assert the gap is fixed — rebalancing weights changes
     * merge behaviour hub-wide and is a calibration decision. It asserts the gap
     * stays *visible*, so nobody silently reintroduces it after calibration.
     */
    public function test_declared_weights_and_implemented_weights_are_reconciled(): void
    {
        $weights = config('golden_profile.probabilistic.weights');
        $implemented = config('golden_profile.probabilistic.implemented_weights');

        $this->assertIsArray($implemented, 'implemented_weights must be declared');

        foreach ($implemented as $name) {
            $this->assertArrayHasKey($name, $weights, "implemented weight $name is not declared");
        }

        $implementedTotal = array_sum(array_intersect_key($weights, array_flip($implemented)));
        $autoMergeAt = (float) config('golden_profile.probabilistic.auto_merge_at');

        $this->assertGreaterThanOrEqual(
            $autoMergeAt,
            round($implementedTotal, 4),
            'The signals score() actually implements cannot reach auto_merge_at, so auto_match is unreachable.',
        );

        // Everything declared but not implemented is dead weight; name them so the
        // failure message is actionable rather than just a number mismatch.
        $dead = array_diff(array_keys($weights), $implemented);
        $this->assertSame(
            ['provider_type'],
            array_values($dead),
            'Declared-but-unimplemented weights changed. Implement them or drop them from config.',
        );
    }

    public function test_review_band_is_below_auto_merge_threshold(): void
    {
        $cfg = config('golden_profile.probabilistic');

        $this->assertLessThan($cfg['auto_merge_at'], $cfg['review_band_floor']);
        $this->assertGreaterThan(0, $cfg['block_size_cap'], 'an uncapped block scores whole soundex buckets');
    }
}

<?php

namespace Tests\Unit;

use App\GoldenProfile\Eval\EvalSet;
use Tests\TestCase;

class EvalSetShapeTest extends TestCase
{
    private function set(): EvalSet
    {
        return EvalSet::load(base_path('tests/eval/identity-pairs.json'));
    }

    public function test_every_record_appears_in_exactly_one_truth_cluster(): void
    {
        $set = $this->set();
        $refs = array_column($set->records(), 'ref');
        $clustered = array_merge(...$set->truthClusters());

        sort($refs);
        sort($clustered);

        $this->assertSame($refs, $clustered, 'every record must be in exactly one cluster');
    }

    public function test_the_set_contains_both_error_modes(): void
    {
        $clusters = $this->set()->truthClusters();

        $this->assertGreaterThanOrEqual(
            2, count(array_filter($clusters, fn ($c) => count($c) > 1)),
            'need multi-record clusters to detect false splits'
        );
        $this->assertGreaterThanOrEqual(
            3, count(array_filter($clusters, fn ($c) => count($c) === 1)),
            'need singleton clusters to detect false merges'
        );
    }

    public function test_a_duplicate_ref_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("duplicate ref 'x'");

        EvalSet::loadArray([
            'records' => [['ref' => 'x'], ['ref' => 'x']],
            'truth' => [['x']],
        ], 'memory');
    }

    public function test_a_record_missing_from_truth_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('records missing from truth: y');

        EvalSet::loadArray([
            'records' => [['ref' => 'x'], ['ref' => 'y']],
            'truth' => [['x']],
        ], 'memory');
    }

    public function test_a_truth_entry_naming_an_unknown_record_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("truth references unknown record 'ghost'");

        EvalSet::loadArray([
            'records' => [['ref' => 'x']],
            'truth' => [['x', 'ghost']],
        ], 'memory');
    }
}

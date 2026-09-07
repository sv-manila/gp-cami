<?php

namespace Tests\Unit;

use App\GoldenProfile\Eval\EvalSet;
use App\GoldenProfile\Support\NpiValidator;
use Tests\TestCase;

/**
 * The eval fixture is the only thing standing between "the gate passes" and
 * "the gate means something." An NPI in the fixture that is not actually
 * Luhn-valid is invisible today (nothing checks it) and becomes a silent
 * de-fanging of whatever case it was meant to exercise the moment NPI
 * validation goes live in matching (Task 3) — the value just stops
 * contributing to any bind and the gate never says so. This test makes that
 * class of drift loud instead of silent, permanently.
 */
class EvalFixtureNpiValidityTest extends TestCase
{
    public function test_every_fixture_npi_is_luhn_valid(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));

        foreach ($set->records() as $r) {
            if (! empty($r['npi'])) {
                $this->assertTrue(
                    NpiValidator::isValid((string) $r['npi']),
                    "fixture record '{$r['ref']}' has npi {$r['npi']}, which is not Luhn-valid ".
                    'under the 80840-prefixed NPI check digit — see NpiValidator'
                );
            }
        }
    }
}

<?php

namespace App\GoldenProfile\Support;

/**
 * NPI check-digit validation (CMS/NPPES rule, "How Record Matching Works"):
 * a valid NPI is a 10-digit number whose 10th digit is the Luhn check digit
 * of the 15-digit string formed by prepending the constant "80840" — the
 * ISO/IEC 7812 issuer-identification prefix CMS registered for the US
 * National Provider Identifier — to the 10 NPI digits.
 *
 * This validates FORMAT only: that the number is internally consistent, not
 * that it is actually assigned, active, or belongs to the person carrying it.
 * Confirming that would need an NPPES registry lookup, and NPPES ingestion is
 * out of scope for gp-cami (PROJECT_PLAN.md §8 rejects external government-feed
 * ingestion). See this plan's Self-review for why the "follow the trail if an
 * NPI was replaced" requirement is deferred rather than half-built here.
 *
 * Luhn alone does not reject an all-same-digit number in general (doubling a
 * repeated digit produces a repeated checksum contribution regardless of
 * value), but the fixed, non-zero "80840" prefix breaks that symmetry for
 * this specific 15-digit construction — 0000000000, 1111111111 and
 * 9999999999 all fail this check on their own, verified in
 * NpiValidatorTest::test_all_same_digit_numbers_are_rejected(). No separate
 * "looks like all zeros" rule is needed for NPI. A Luhn-VALID but still
 * fabricated NPI (e.g. one value reused as filler across many unrelated
 * people) is a different problem — that is what JunkKeyGuard's cardinality
 * check catches, independent of format.
 */
class NpiValidator
{
    /** NPPES/HIPAA constant issuer-id prefix for the US National Provider Identifier. */
    private const PREFIX = '80840';

    public static function isValid(mixed $npi): bool
    {
        $npi = is_int($npi) ? (string) $npi : $npi;
        if (! is_string($npi) || ! preg_match('/^\d{10}$/', $npi)) {
            return false;
        }

        return self::luhnValid(self::PREFIX.$npi);
    }

    /**
     * Standard Luhn checksum: from the rightmost digit (the digit under test —
     * here the NPI's own check digit, so it is never itself doubled), moving
     * left, double every SECOND digit. Fold any doubled value over 9 by
     * subtracting 9 (equivalent to summing that value's own two digits). Valid
     * iff the total across all digits is a multiple of 10.
     */
    private static function luhnValid(string $digits): bool
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $digits[$len - 1 - $i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}

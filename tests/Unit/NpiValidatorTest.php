<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\NpiValidator;
use Tests\TestCase;

/**
 * Luhn-with-80840 is the NPPES check-digit rule: prepend the constant issuer
 * prefix 80840 to the 10 NPI digits and Luhn-validate the resulting 15-digit
 * string. 1234567893 is the worked example in the NPI final rule and is the
 * standard "this algorithm is implemented correctly" vector; every other
 * vector here was derived from it by hand-computing a fresh check digit for a
 * chosen 9-digit prefix, not guessed.
 */
class NpiValidatorTest extends TestCase
{
    public function test_the_canonical_nppes_example_is_valid(): void
    {
        $this->assertTrue(NpiValidator::isValid('1234567893'));
    }

    public function test_known_valid_vectors(): void
    {
        // 1999999992 and 1987654328/1112223338 are the (corrected, see Task 2)
        // NPIs used by the eval fixture; 1509876540 is independent of the
        // fixture, included so this test does not merely echo the fixture back.
        foreach (['1999999992', '1987654328', '1112223338', '1509876540'] as $npi) {
            $this->assertTrue(NpiValidator::isValid($npi), "$npi should be valid");
        }
    }

    public function test_a_single_digit_check_digit_change_is_rejected(): void
    {
        // Same 9 leading digits as a known-valid vector, wrong check digit.
        $this->assertFalse(NpiValidator::isValid('1509876541'));
    }

    /**
     * These are the values the eval fixture used to carry (see Task 2's docblock)
     * before this class existed to check them. Recorded here as a permanent
     * regression vector: the fixture must never drift back to using an
     * unvalidated NPI as if it were a real one.
     */
    public function test_the_original_broken_fixture_values_are_rejected(): void
    {
        $this->assertFalse(NpiValidator::isValid('1987654327'));
        $this->assertFalse(NpiValidator::isValid('1112223339'));
    }

    public function test_all_same_digit_numbers_are_rejected(): void
    {
        // Not because of a special-case rule — the fixed, non-zero 80840 prefix
        // makes an all-same-digit payload fail the checksum on its own. Pinned
        // here so nobody "simplifies" the algorithm in a way that reintroduces
        // this as an accepted value.
        foreach (['0000000000', '1111111111', '9999999999'] as $npi) {
            $this->assertFalse(NpiValidator::isValid($npi), "$npi should be invalid");
        }
    }

    public function test_wrong_length_is_rejected(): void
    {
        $this->assertFalse(NpiValidator::isValid('123456789'));
        $this->assertFalse(NpiValidator::isValid('12345678901'));
    }

    public function test_non_numeric_is_rejected(): void
    {
        $this->assertFalse(NpiValidator::isValid('123456789A'));
        $this->assertFalse(NpiValidator::isValid(null));
        $this->assertFalse(NpiValidator::isValid(''));
    }

    public function test_accepts_int_input_the_way_stg_person_npi_is_typed(): void
    {
        // stg_person.npi is unsignedBigInteger; callers will pass an int.
        $this->assertTrue(NpiValidator::isValid(1234567893));
        $this->assertFalse(NpiValidator::isValid(1987654327));
    }
}

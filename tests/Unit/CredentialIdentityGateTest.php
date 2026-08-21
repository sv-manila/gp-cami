<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\CredentialSelector;
use Tests\TestCase;

/**
 * A credential match records the parameters its scrape ran with. Where an SSN or
 * DOB is among them, that match is evidence about one specific person, so it must
 * not be returned for a request naming a different one.
 *
 * Measured prevalence in the source: 24 of 3,000 recent rows carry a non-empty
 * request SSN and 4 carry a DOB, so the gate is rarely the deciding factor — but
 * where it fires it is the difference between one person's credential and
 * another's.
 */
class CredentialIdentityGateTest extends TestCase
{
    private const TODAY = '2026-08-10';

    private function link(int $id, ?string $reqSsn = null, ?string $reqDob = null): object
    {
        return (object) [
            'credential_match_id' => $id,
            'current' => 1,
            'date_updated' => '2026-08-01',
            'date_created' => null,
            'expiry_date' => null,
            'req_ssn' => $reqSsn,
            'req_dob' => $reqDob,
        ];
    }

    public function test_a_match_with_no_recorded_identity_always_passes(): void
    {
        // The common case: nothing to contradict, so the gate must not interfere.
        foreach ([null, '', '   '] as $empty) {
            $links = collect([$this->link(1, $empty, $empty)]);

            $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);
            $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, '123456789', '1985-04-23')->credential_match_id);
        }
    }

    public function test_matching_ssn_passes(): void
    {
        $links = collect([$this->link(1, '123456789')]);

        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, '123456789')->credential_match_id);
    }

    public function test_ssn_comparison_ignores_formatting(): void
    {
        // The payload stores 9 bare digits; callers may send 123-45-6789.
        $links = collect([$this->link(1, '123456789')]);

        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, '123-45-6789')->credential_match_id);
        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, '123 45 6789')->credential_match_id);
    }

    public function test_conflicting_ssn_is_excluded(): void
    {
        $links = collect([$this->link(1, '123456789')]);

        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY, '999999999'));
    }

    /**
     * The behaviour change to be aware of: a match tied to an SSN is withheld from
     * a request that cannot name that SSN, because there is no way to confirm the
     * match belongs to the person being asked about.
     */
    public function test_ssn_bearing_match_is_withheld_when_the_request_has_no_ssn(): void
    {
        $links = collect([$this->link(1, '123456789')]);

        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY));
        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY, null, '1985-04-23'));
    }

    /**
     * Rolled-up payloads store a MASKED SSN — "xxx-xx-0503" is what 3 of identity
     * 18's 6 real matches carry. Read as a whole SSN that is the number 0503, which
     * agrees with nothing, so a digits-only comparison silently withheld correct
     * credentials from every request. Compare at the precision the pair shares.
     */
    public function test_masked_payload_ssn_is_compared_on_the_last_four(): void
    {
        $links = collect([$this->link(1, 'xxx-xx-0503')]);

        // Caller sends a full SSN ending 0503 — agrees.
        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, '123450503')->credential_match_id);
        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, '123-45-0503')->credential_match_id);

        // Ends differently — the wrong person.
        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY, '123459999'));
    }

    public function test_a_fully_masked_ssn_carries_no_information_and_passes(): void
    {
        // Nothing to compare, so it cannot contradict anything.
        foreach (['xxx-xx-xxxx', '***-**-****', 'xxx'] as $useless) {
            $links = collect([$this->link(1, $useless)]);

            $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id, $useless);
            $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, '123456789')->credential_match_id, $useless);
        }
    }

    public function test_too_few_digits_to_distinguish_people_is_treated_as_unknown(): void
    {
        // Fewer than four trailing digits cannot separate two people, so gating on
        // it would withhold matches on nearly no evidence.
        $links = collect([$this->link(1, 'xx-1')]);

        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);
    }

    public function test_two_full_ssns_are_compared_in_full_not_just_the_last_four(): void
    {
        // Both sides complete, so the comparison must not degrade to last-four and
        // call two different people a match.
        $links = collect([$this->link(1, '111110503')]);

        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY, '222220503'));
        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, '111110503')->credential_match_id);
    }

    public function test_matching_dob_passes_and_a_conflicting_one_is_excluded(): void
    {
        $links = collect([$this->link(1, null, '1985-04-23')]);

        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, null, '1985-04-23')->credential_match_id);
        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY, null, '1990-01-01'));
        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY));
    }

    public function test_dob_comparison_uses_the_date_part_only(): void
    {
        $links = collect([$this->link(1, null, '1985-04-23 00:00:00')]);

        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, null, '1985-04-23')->credential_match_id);
    }

    public function test_zero_dates_in_the_payload_are_not_treated_as_a_dob(): void
    {
        // '0000-00-00' is MySQL's non-date, not a real value to match against.
        $links = collect([$this->link(1, null, '0000-00-00')]);

        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);
    }

    public function test_both_fields_must_agree_when_both_are_recorded(): void
    {
        $links = collect([$this->link(1, '123456789', '1985-04-23')]);

        $this->assertSame(1, CredentialSelector::pick($links, true, self::TODAY, '123456789', '1985-04-23')->credential_match_id);
        // Right SSN, wrong DOB — still the wrong person.
        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY, '123456789', '1990-01-01'));
        // Right DOB, wrong SSN.
        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY, '999999999', '1985-04-23'));
    }

    public function test_the_gate_selects_among_siblings_rather_than_returning_nothing(): void
    {
        // A conflicting match must be skipped, not abort the whole lookup: the
        // agreeing one behind it is still a valid answer.
        $links = collect([
            $this->link(10, '999999999'),   // newer but the wrong person
            $this->link(11, '123456789'),
            $this->link(12),                // no recorded identity
        ]);

        $picked = CredentialSelector::pick($links, true, self::TODAY, '123456789');
        $this->assertNotNull($picked);
        $this->assertContains($picked->credential_match_id, [11, 12]);
        $this->assertNotSame(10, $picked->credential_match_id);
    }

    public function test_qualifying_drops_conflicting_matches_from_the_whole_list(): void
    {
        $links = collect([
            $this->link(1, '111111111'),
            $this->link(2, '123456789'),
            $this->link(3),
        ]);

        $ids = CredentialSelector::qualifying($links, true, self::TODAY, '123456789')
            ->pluck('credential_match_id')->all();

        $this->assertNotContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertContains(3, $ids);
    }
}

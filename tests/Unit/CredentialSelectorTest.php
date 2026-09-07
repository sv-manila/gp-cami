<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\CredentialSelector;
use Tests\TestCase;

/**
 * Locks the two-query credential selection to the semantics of the cross-database
 * SQL it replaced:
 *
 *   WHERE (cm.expiry_date IS NULL OR cm.expiry_date >= CURDATE())
 *   ORDER BY gc.current DESC, COALESCE(cm.date_updated, cm.date_created) DESC
 *
 * A subtle difference here does not throw — it returns the wrong credential.
 */
class CredentialSelectorTest extends TestCase
{
    private const TODAY = '2026-08-07';

    private function link(int $id, int $current, ?string $updated, ?string $created, ?string $expiry = null): object
    {
        return (object) [
            'credential_match_id' => $id,
            'source_current' => $current,
            'date_updated' => $updated,
            'date_created' => $created,
            'expiry_date' => $expiry,
        ];
    }

    public function test_current_beats_a_newer_non_current_row(): void
    {
        $links = collect([
            $this->link(1, 0, '2026-08-01', null),   // newer, but not current
            $this->link(2, 1, '2020-01-01', null),   // current
        ]);

        $this->assertSame(2, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);
    }

    public function test_date_created_is_used_when_date_updated_is_null(): void
    {
        // COALESCE(date_updated, date_created): id 4's date_created must win.
        $links = collect([
            $this->link(3, 1, '2026-01-01', '2000-01-01'),
            $this->link(4, 1, null, '2026-07-01'),
        ]);

        $this->assertSame(4, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);
    }

    public function test_full_tie_is_broken_by_lowest_credential_match_id(): void
    {
        // The old SQL had no third sort key, so this fell to storage order and the
        // same request could return a different row each time.
        $links = collect([
            $this->link(30, 1, '2026-07-01', null),
            $this->link(9, 1, '2026-07-01', null),
            $this->link(12, 1, '2026-07-01', null),
        ]);

        $this->assertSame(9, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);

        // Order of the input must not change the answer.
        $this->assertSame(9, CredentialSelector::pick($links->reverse(), true, self::TODAY)->credential_match_id);
    }

    public function test_expired_rows_are_filtered_out(): void
    {
        $links = collect([
            $this->link(5, 1, '2026-08-01', null, '2026-08-06'),  // expired yesterday
        ]);

        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY));
    }

    public function test_expiry_today_still_qualifies(): void
    {
        // SQL was >= CURDATE(), so today is inclusive.
        $links = collect([$this->link(6, 1, '2026-08-01', null, self::TODAY)]);

        $this->assertSame(6, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);
    }

    public function test_null_expiry_qualifies(): void
    {
        $links = collect([$this->link(7, 1, '2026-08-01', null, null)]);

        $this->assertSame(7, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);
    }

    public function test_datetime_expiry_compares_on_the_date_part(): void
    {
        // A row expiring later today must not be dropped by a string compare
        // against a bare Y-m-d.
        $links = collect([$this->link(8, 1, '2026-08-01', null, self::TODAY.' 23:59:59')]);

        $this->assertSame(8, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);
    }

    public function test_expiry_filter_is_skipped_when_disabled(): void
    {
        $links = collect([$this->link(9, 1, '2026-08-01', null, '1999-01-01')]);

        $this->assertNull(CredentialSelector::pick($links, true, self::TODAY));
        $this->assertSame(9, CredentialSelector::pick($links, false, self::TODAY)->credential_match_id);
    }

    public function test_missing_source_row_is_treated_as_no_expiry(): void
    {
        // The old LEFT JOIN left cm.* null when the source row was gone, and
        // "cm.expiry_date IS NULL" passed the filter. Same here.
        $links = collect([$this->link(10, 1, null, null, null)]);

        $this->assertSame(10, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);
    }

    public function test_zero_dates_are_treated_as_blank(): void
    {
        // MySQL zero-dates are not real dates; treating '0000-00-00' as an expiry
        // in the past would wrongly drop the row.
        $links = collect([$this->link(11, 1, '0000-00-00 00:00:00', '2026-07-01', '0000-00-00')]);

        $picked = CredentialSelector::pick($links, true, self::TODAY);
        $this->assertNotNull($picked);
        $this->assertSame(11, $picked->credential_match_id);
    }

    public function test_empty_input_returns_null(): void
    {
        $this->assertNull(CredentialSelector::pick(collect(), true, self::TODAY));
    }

    public function test_selection_is_stable_across_repeated_calls(): void
    {
        $links = collect([
            $this->link(21, 1, '2026-07-01', null),
            $this->link(22, 1, '2026-07-01', null),
            $this->link(20, 0, '2026-09-01', null),
        ]);

        $first = CredentialSelector::pick($links, true, self::TODAY)->credential_match_id;
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, CredentialSelector::pick($links, true, self::TODAY)->credential_match_id);
        }
        $this->assertSame(21, $first);
    }

    public function test_qualifying_returns_full_ordering_not_just_the_winner(): void
    {
        $links = collect([
            $this->link(1, 0, '2026-01-01', null),
            $this->link(2, 1, '2026-05-01', null),
            $this->link(3, 1, '2026-07-01', null),
        ]);

        $this->assertSame(
            [3, 2, 1],
            CredentialSelector::qualifying($links, true, self::TODAY)
                ->pluck('credential_match_id')->all(),
        );
    }
}

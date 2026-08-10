<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Collection;

/**
 * Picks the one credential link to return from credential-search.
 *
 * This logic used to live in SQL as
 *
 *     ... LEFT JOIN <src>.credential_matches cm ON cm.id = gc.credential_match_id
 *     WHERE (cm.expiry_date IS NULL OR cm.expiry_date >= CURDATE())
 *     ORDER BY gc.current DESC, COALESCE(cm.date_updated, cm.date_created) DESC
 *
 * but the hub and the CAMI source are separate MySQL servers, so qualifying
 * credential_matches with the source schema raised "1049 Unknown database" and
 * every credential-search that resolved an identity returned a 500. The join was
 * therefore split into two queries with the filter and ordering done here.
 *
 * It lives in its own class rather than inline in the controller so the behaviour
 * is directly testable without a database — this is the step where a subtle
 * ordering difference would silently return the wrong credential rather than fail.
 *
 * Equivalence with the old SQL is exact for every value present in the data today
 * (verified by differential fuzzing against a SQL oracle), with one deliberate
 * divergence: MySQL zero-dates. '0000-00-00' is treated here as "no expiry
 * recorded" and passes the filter, whereas the SQL compared it as a date and so
 * dropped the row. No such value exists in the source today (0 of 3,709,346
 * credential_matches), but if one appeared this errs toward surfacing a credential
 * the SQL would have hidden — see test_zero_dates_are_treated_as_blank.
 *
 * The comparator is a total order, which is what lets the caller fold chunk
 * winners together instead of sorting every link at once.
 */
class CredentialSelector
{
    /**
     * @param  Collection<int,object>  $links  hub rows, each with credential_match_id,
     *                                         current, and the source dates attached
     * @param  bool  $respectExpiry  mirrors golden_profile.credential_search.respect_expiry_date
     * @param  string  $today  CURDATE() equivalent, as Y-m-d
     */
    public static function pick(Collection $links, bool $respectExpiry, string $today): ?object
    {
        return self::qualifying($links, $respectExpiry, $today)->first();
    }

    /**
     * Links passing the expiry filter, in the order the old ORDER BY produced.
     *
     * @param  Collection<int,object>  $links
     * @return Collection<int,object>
     */
    public static function qualifying(Collection $links, bool $respectExpiry, string $today): Collection
    {
        return $links
            // expiry_date IS NULL OR expiry_date >= CURDATE(). A missing source row
            // leaves expiry_date null, which the LEFT JOIN also treated as passing.
            ->filter(fn ($l) => ! $respectExpiry
                || self::blank($l->expiry_date ?? null)
                || self::dateOnly($l->expiry_date) >= $today)
            ->sortBy([
                // current DESC
                fn ($a, $b) => (int) ($b->current ?? 0) <=> (int) ($a->current ?? 0),
                // COALESCE(date_updated, date_created) DESC
                fn ($a, $b) => strcmp(self::effectiveDate($b), self::effectiveDate($a)),
                // credential_match_id ASC — the old SQL had no third key, so a full
                // tie fell to storage order and the same call could return different
                // rows. Pinning it here makes repeat calls agree.
                fn ($a, $b) => (int) ($a->credential_match_id ?? 0) <=> (int) ($b->credential_match_id ?? 0),
            ])
            ->values();
    }

    private static function effectiveDate(object $l): string
    {
        $updated = $l->date_updated ?? null;

        return (string) (self::blank($updated) ? ($l->date_created ?? '') : $updated);
    }

    /** Dates may arrive as 'Y-m-d' or 'Y-m-d H:i:s'; compare on the date part. */
    private static function dateOnly($value): string
    {
        return substr((string) $value, 0, 10);
    }

    private static function blank($value): bool
    {
        return $value === null || $value === '' || $value === '0000-00-00'
            || $value === '0000-00-00 00:00:00';
    }
}

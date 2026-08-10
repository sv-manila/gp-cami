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
    public static function pick(
        Collection $links,
        bool $respectExpiry,
        string $today,
        ?string $ssn = null,
        ?string $dob = null,
    ): ?object {
        return self::qualifying($links, $respectExpiry, $today, $ssn, $dob)->first();
    }

    /**
     * Does this link's own recorded identity agree with what the caller supplied?
     *
     * A credential match records the parameters its scrape was run with. Where an
     * SSN or DOB is among them, that match is evidence about ONE person, so
     * returning it for a request that names a different SSN or DOB — or that
     * cannot name one at all — attributes another person's credential to this one.
     *
     * A match carrying NO ssn/dob is not evidence either way and always passes;
     * that is the overwhelming majority (24 of 3,000 recent rows carry an SSN, 4 a
     * DOB). So this gate changes the answer rarely, and only where the data is
     * specific enough for it to matter.
     *
     * Comparison is on digits for SSN and on the date part for DOB, since the
     * payload stores an SSN as 9 bare digits while callers may send 123-45-6789.
     */
    public static function identityAgrees(object $link, ?string $ssn, ?string $dob): bool
    {
        // SSNs arrive in two shapes and must be compared at the precision actually
        // available. Older rolled-up payloads store a MASKED value ("xxx-xx-0503",
        // 3 of identity 18's 6 real matches); newer ones store 9 bare digits.
        // Reading the mask as a whole SSN would compare "0503" against a caller's
        // full number, never agree, and withhold correct credentials.
        $linkSsn = self::ssnParts($link->req_ssn ?? null);
        if ($linkSsn['known']) {
            $reqSsn = self::ssnParts($ssn);

            if (! $reqSsn['known']) {
                return false;   // the match is SSN-specific; the request is not
            }

            // Full against full when both are complete, otherwise on the last four
            // — the strongest precision the pair has in common.
            $agrees = ($linkSsn['full'] !== null && $reqSsn['full'] !== null)
                ? $linkSsn['full'] === $reqSsn['full']
                : $linkSsn['last4'] === $reqSsn['last4'];

            if (! $agrees) {
                return false;
            }
        }

        $linkDob = self::dobValue($link->req_dob ?? null);
        if ($linkDob !== '') {
            if (self::dobValue($dob) === '') {
                return false;
            }
            if ($linkDob !== self::dobValue($dob)) {
                return false;
            }
        }

        return true;
    }

    /** Date part of a DOB, or '' when absent — blank, whitespace or a zero date. */
    private static function dobValue($value): string
    {
        return self::blank($value) ? '' : self::dateOnly(trim((string) $value));
    }

    /**
     * Decompose an SSN into what is actually known about it.
     *
     * Handles both stored shapes: 9 bare digits, and a mask such as "xxx-xx-0503"
     * that reveals only the last four. Returns ['known'=>bool, 'full'=>?string,
     * 'last4'=>?string] so a comparison can pick the precision the two sides share
     * rather than assuming every value is complete.
     *
     * @return array{known:bool,full:?string,last4:?string}
     */
    private static function ssnParts($value): array
    {
        $absent = ['known' => false, 'full' => null, 'last4' => null];

        if (self::blank($value)) {
            return $absent;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits === '') {
            return $absent;     // a fully masked value carries no information
        }

        if (strlen($digits) >= 9) {
            $full = substr($digits, -9);

            return ['known' => true, 'full' => $full, 'last4' => substr($full, -4)];
        }

        // Partial — a mask. Only the trailing digits are usable, and fewer than
        // four is too weak to distinguish people, so treat it as unknown.
        return strlen($digits) >= 4
            ? ['known' => true, 'full' => null, 'last4' => substr($digits, -4)]
            : $absent;
    }

    /**
     * Links passing the expiry filter, in the order the old ORDER BY produced.
     *
     * @param  Collection<int,object>  $links
     * @return Collection<int,object>
     */
    public static function qualifying(
        Collection $links,
        bool $respectExpiry,
        string $today,
        ?string $ssn = null,
        ?string $dob = null,
    ): Collection {
        return $links
            // Drop matches whose own recorded SSN/DOB does not agree with the
            // request before anything else — a disagreeing match is the wrong
            // person's credential, so it should not even be a ranking candidate.
            ->filter(fn ($l) => self::identityAgrees($l, $ssn, $dob))
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
        if ($value === null) {
            return true;
        }

        // Trimmed: a whitespace-only payload field is absent, not a value. Without
        // this, req_dob="   " read as a recorded date of birth and withheld the
        // match from every request.
        $trimmed = is_string($value) ? trim($value) : $value;

        return $trimmed === '' || $trimmed === '0000-00-00' || $trimmed === '0000-00-00 00:00:00'
            // A zero date can also arrive with a time part or as a bare zero year.
            || (is_string($trimmed) && preg_match('/^0000-00-00/', $trimmed) === 1);
    }
}

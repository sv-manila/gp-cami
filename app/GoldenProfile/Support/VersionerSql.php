<?php

namespace App\GoldenProfile\Support;

/**
 * Versioner's comparison rule, rendered as SQL expressions for the set-based
 * paths.
 *
 * WHY THIS EXISTS
 * ---------------
 * Versioner::write() decides "did any golden fact actually change?" per row, in
 * PHP. SetFinalizer and SqlBackfill have to reach the SAME verdict for millions of
 * rows inside INSERT … SELECT. If the two disagree on one value pair, the per-row
 * and set-based paths mint different numbers of versions from identical input —
 * and the symptom (a version count off by one, or a canonical value frozen at a
 * stale spelling) surfaces nowhere near the cause. VersionerSqlTest pins them
 * together pair by pair.
 *
 * THE FOUR THINGS THAT HAD TO BE CARRIED ACROSS
 * ---------------------------------------------
 * 1. NULL IS STRICT, AND THAT IS NOT WHAT <=> DOES FOR THE LOOSE BRANCHES.
 *    same() is TRUE only when both sides are null. <=> agrees about that, but the
 *    prefix branches must not run when either side is null: LEFT(NULL, 10) = NULL
 *    is NULL, which inside a NOT(...) degrades to "unknown" and then to "no
 *    change". So the null case gets its own leading CASE arm.
 *
 * 2. PHP === IS A BYTE COMPARISON; MySQL = IS NOT. These tables are
 *    utf8mb4_unicode_ci, so 'SMITH' = 'Smith' is TRUE in the server and FALSE in
 *    PHP. Left alone, the per-row path would version a case change and the
 *    set-based path would not — and the set-based canonical value would then stay
 *    at 'SMITH' forever while the per-row path tracked the source. Every
 *    comparison is therefore wrapped in CAST(… AS BINARY). This is the single most
 *    important line in the class.
 *
 * 3. strlen() AND str_starts_with() ARE BYTES; CHAR_LENGTH() AND LEFT() ARE
 *    CHARACTERS. A ten-CHARACTER non-ASCII value has strlen() > 10, so CHAR_LENGTH
 *    would arm the date-prefix branch on a name PHP would never arm it on.
 *    LENGTH() is bytes, and LEFT() over an argument already cast to BINARY is
 *    bytes.
 *
 * 4. TYPE RENDERING HAS TO MATCH PDO'S. It does, column type by column type — see
 *    the audit table in the plan document. The practical consequence is that a
 *    DATE column can be compared against a VARCHAR scratch column holding the same
 *    date and both sides render '1970-04-02'.
 *
 * WHERE THE DATE-PREFIX RULE ACTUALLY FIRES: in the set-based paths, nowhere. Every
 * incoming canonical_dob comes from stg_person.date_of_birth (a DATE, LENGTH 10
 * both sides) and every date_resolved is DATETIME on both sides. It is implemented
 * anyway because the per-row path CAN arm it (a caller handing a Carbon for a DATE
 * column) and because a future column-type change must not silently acquire a
 * divergence.
 *
 * ONE TRAP: never route a DECIMAL through attributes from a PHP float. 0.99 casts
 * to '0.99' and the column round-trips as '0.9900', so same() would report a change
 * on every write and mint a version per write forever. Nothing passes
 * link_confidence today, which is why this has not bitten.
 */
class VersionerSql
{
    /**
     * An expression that is TRUE exactly when Versioner::same($a, $b) is TRUE.
     *
     * $a and $b are SQL, not values — a qualified column (`i`.`canonical_last`), a
     * user variable, or any scalar expression. They are evaluated more than once,
     * so pass column references rather than subqueries; every caller in this
     * codebase materialises the incoming side into a scratch table first, which is
     * what makes that cheap.
     */
    public static function same(string $a, string $b): string
    {
        $ab = "CAST($a AS BINARY)";
        $bb = "CAST($b AS BINARY)";

        return "(CASE
            WHEN $a IS NULL OR $b IS NULL THEN ($a IS NULL AND $b IS NULL)
            WHEN LENGTH($ab) = 10 AND LENGTH($bb) >= 10 AND LEFT($bb, 10) = $ab THEN TRUE
            WHEN LENGTH($bb) = 10 AND LENGTH($ab) >= 10 AND LEFT($ab, 10) = $bb THEN TRUE
            ELSE $ab = $bb
        END)";
    }

    /**
     * Versioner::differs() where EVERY listed column is present in the incoming
     * payload — a NULL incoming value is a comparison, not an absence.
     *
     * This is the shape SqlBackfill::enrich() and ::rollup() need: their per-row
     * counterparts build the attribute array unconditionally, so a credential whose
     * date_resolved was cleared reads as a change and must be versioned.
     *
     * @param  string  $storedAlias  table alias holding the stored row
     * @param  array<string,string>  $incoming  target column => SQL for the incoming value
     */
    public static function differsOnAll(string $storedAlias, array $incoming): string
    {
        $terms = [];

        foreach ($incoming as $column => $expr) {
            $terms[] = 'NOT '.self::same("$storedAlias.`$column`", $expr);
        }

        // FALSE, not '': gp_identity_identifier declares no attributes at all (its
        // key is the whole fact), and an empty string would break the enclosing
        // WHERE rather than saying "nothing can differ".
        return $terms === [] ? 'FALSE' : '('.implode(' OR ', $terms).')';
    }

    /**
     * Versioner::differs() where a NULL incoming value means the column was ABSENT
     * from the payload — carry forward, not a change.
     *
     * This is the shape SetFinalizer::survivorship() and
     * SqlBackfill::backfillIdentityKeys() need: a survivorship field with no
     * non-blank candidate produces no entry in $update at all, and a backfill
     * column with nothing to add is likewise simply not passed. Modelling that as
     * "the incoming expression is NULL" is exact, because a survivorship winner is
     * never NULL (the ranked CTE filters on IS NOT NULL AND TRIM(...) <> '') and a
     * backfill proposal is NULL precisely when there is nothing to propose.
     *
     * @param  array<string,string>  $incoming  target column => SQL for the incoming value
     */
    public static function differsOnPresent(string $storedAlias, array $incoming): string
    {
        $terms = [];

        foreach ($incoming as $column => $expr) {
            $terms[] = "($expr IS NOT NULL AND NOT ".self::same("$storedAlias.`$column`", $expr).')';
        }

        return $terms === [] ? 'FALSE' : '('.implode(' OR ', $terms).')';
    }

    /**
     * NULL-safe natural-key equality between two aliases carrying the same column
     * names.
     *
     * <=> and never =. Versioner::current() matches a key with ->where($key), and
     * Laravel converts a null value under '=' into IS NULL, so the per-row path
     * treats two NULL key parts as equal. A set-based join with = would not, and
     * gp_license's certification_state / certification_board and gp_address's
     * city / state / zip are all nullable — so every run would insert a duplicate
     * row for a licence with a NULL state instead of finding the existing one.
     * That is not hypothetical: it is what the ON DUPLICATE KEY UPDATE code this
     * replaces already does, because uq_lic cannot match a NULL either.
     *
     * @param  list<string>  $columns
     */
    public static function keysEqual(string $left, string $right, array $columns): string
    {
        return implode(' AND ', array_map(
            fn ($c) => "$left.`$c` <=> $right.`$c`",
            $columns
        ));
    }
}

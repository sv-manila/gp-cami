<?php

namespace App\GoldenProfile\Support;

/**
 * Name/DOB compatibility rules from the NPPES + Board Actions profiling guidelines.
 * Encodes the "strict" gate used by both the deterministic name+dob key and as a
 * prerequisite before probabilistic scoring.
 */
class NameMatcher
{
    /** First+last must be equal; middle + suffix + dob must be *compatible* (not conflicting). */
    public static function compatible(object $a, object $b): bool
    {
        if (! self::eq($a->first_name ?? null, $b->first_name ?? null)) {
            return false;
        }
        if (! self::eq($a->last_name ?? null, $b->last_name ?? null)) {
            return false;
        }

        return self::middleCompatible($a->middle_name ?? null, $b->middle_name ?? null)
            && self::suffixCompatible($a->name_suffix ?? null, $b->name_suffix ?? null)
            && self::dobCompatible($a->date_of_birth ?? null, $b->date_of_birth ?? null);
    }

    /** Middle: null on either side is OK; initial-vs-full matches on first letter; full-vs-full exact. */
    public static function middleCompatible(?string $m1, ?string $m2): bool
    {
        $m1 = self::norm($m1);
        $m2 = self::norm($m2);
        if ($m1 === null || $m2 === null) {
            return true;
        }
        $initial1 = strlen($m1) === 1;
        $initial2 = strlen($m2) === 1;
        if ($initial1 || $initial2) {
            return $m1[0] === $m2[0];
        }

        return $m1 === $m2;
    }

    /** Suffix: null on either side OK; if both present must be equal. */
    public static function suffixCompatible(?string $s1, ?string $s2): bool
    {
        $s1 = self::norm($s1);
        $s2 = self::norm($s2);
        if ($s1 === null || $s2 === null) {
            return true;
        }

        return $s1 === $s2;
    }

    /** DOB: null on either side OK; both present must match; fall back to birth year. */
    public static function dobCompatible(?string $d1, ?string $d2): bool
    {
        if (! $d1 || ! $d2) {
            return true;
        }
        if ($d1 === $d2) {
            return true;
        }

        return substr($d1, 0, 4) === substr($d2, 0, 4); // birth-year fallback
    }

    /** Hard conflict: both DOBs present and different year. Blocks any merge. */
    public static function dobConflicts(?string $d1, ?string $d2): bool
    {
        if (! $d1 || ! $d2) {
            return false;
        }

        return substr($d1, 0, 4) !== substr($d2, 0, 4);
    }

    private static function eq(?string $a, ?string $b): bool
    {
        $a = self::norm($a);
        $b = self::norm($b);

        return $a !== null && $a === $b;
    }

    private static function norm(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = strtolower(trim(preg_replace('/[.\s]+/', ' ', $v)));
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}

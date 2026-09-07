<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;

/**
 * General placeholder + cardinality guard, parameterized by column. Generalizes
 * SsnHashGuard's cardinality idea rather than extending that class: plan 2
 * deletes SsnHashGuard/SsnHasher entirely (SSN removal), so anything meant to
 * outlive that change cannot depend on them.
 *
 * Two independent checks, same as SSN's:
 *  1. Known placeholders (config golden_profile.junk.placeholders.$column) —
 *     exact, but only catches values someone has already identified.
 *  2. Cardinality: a value carried by more than
 *     golden_profile.junk.max_identities_per_value.$column *distinct people*
 *     — distinct (last_name, first_name, date_of_birth) triples in staging —
 *     cannot be one person's identifier. Needs no prior knowledge and catches
 *     junk nobody has listed, including a value that is individually
 *     well-formed (a real NPI's worth of digits, a Luhn-valid check digit)
 *     but reused as filler.
 *
 * Deliberately counts distinct PEOPLE in stg_person, not identities in
 * gp_identity: the deterministic tiers mint one identity per distinct value
 * and link every row carrying it, so a filler value ends up on exactly one
 * identity — the damage is invisible from the identity side (see
 * SsnHashGuard's original docblock for the same reasoning, which is why this
 * class keeps it).
 */
class JunkKeyGuard
{
    /** @var array<string,bool> */
    private array $decisions = [];

    public function maxIdentitiesPerValue(string $column): int
    {
        return max(1, (int) config("golden_profile.junk.max_identities_per_value.$column", 3));
    }

    /** @return list<string> */
    public function placeholders(string $column): array
    {
        return array_map('strval', (array) config("golden_profile.junk.placeholders.$column", []));
    }

    /** True when this value must NOT be used as a deterministic identity key. */
    public function isBlocked(string $column, ?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $key = $column.'|'.$value;

        return $this->decisions[$key] ??= $this->decide($column, $value);
    }

    private function decide(string $column, string $value): bool
    {
        if (in_array($value, $this->placeholders($column), true)) {
            return true;
        }

        $cap = $this->maxIdentitiesPerValue($column);

        // $column is always a fixed, code-controlled string (never user input;
        // see callers in DeterministicResolver/SqlBackfill/Engine) — safe to
        // interpolate, same rule Engine::mergeByColumn already documents for
        // its own $col interpolation.
        $distinctPeople = $this->hub()
            ->table('stg_person')
            ->where($column, $value)
            ->distinct()
            ->limit($cap + 1)
            ->pluck(DB::raw("CONCAT_WS('|', last_name, first_name, date_of_birth)"))
            ->count();

        return $distinctPeople > $cap;
    }

    private function hub()
    {
        return DB::connection(config('golden_profile.connections.hub', 'golden_profile'));
    }

    /**
     * Materialise the blocklist for one column into gp_junk_value_blocklist so
     * the set-based backfill can anti-join against it instead of threading a
     * NOT IN list through every statement. Rebuilt from scratch for THIS
     * column only — other columns' rows are untouched.
     *
     * @return int number of blocked values for this column
     */
    public function buildBlocklistTable(string $column): int
    {
        $hub = $this->hub();
        $cap = $this->maxIdentitiesPerValue($column);

        $hub->statement('CREATE TABLE IF NOT EXISTS gp_junk_value_blocklist (
            column_name VARCHAR(64) NOT NULL,
            value VARCHAR(255) NOT NULL,
            reason VARCHAR(32) NOT NULL,
            distinct_people INT NOT NULL DEFAULT 0,
            PRIMARY KEY (column_name, value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $hub->table('gp_junk_value_blocklist')->where('column_name', $column)->delete();

        $hub->statement(
            "INSERT INTO gp_junk_value_blocklist (column_name, value, reason, distinct_people)
             SELECT ?, v, 'cardinality', people FROM (
                 SELECT `$column` v,
                        COUNT(DISTINCT CONCAT_WS('|', last_name, first_name, date_of_birth)) people
                 FROM stg_person
                 WHERE `$column` IS NOT NULL AND `$column` <> ''
                 GROUP BY `$column`
             ) g WHERE g.people > ?",
            [$column, $cap]
        );

        foreach (array_chunk($this->placeholders($column), 200) as $chunk) {
            $hub->table('gp_junk_value_blocklist')->insertOrIgnore(array_map(
                fn ($v) => ['column_name' => $column, 'value' => $v, 'reason' => 'placeholder', 'distinct_people' => 0],
                $chunk
            ));
        }

        return (int) $hub->table('gp_junk_value_blocklist')->where('column_name', $column)->count();
    }

    /**
     * SQL fragment excluding blocked values for one column, for the set-based
     * backfill tiers. Anti-joins gp_junk_value_blocklist (see
     * buildBlocklistTable) so the fragment stays constant-size regardless of
     * how many values are blocked.
     */
    public function exclusionSql(string $column, string $sqlColumnRef): string
    {
        return ' AND NOT EXISTS (SELECT 1 FROM gp_junk_value_blocklist b '.
            "WHERE b.column_name = '$column' AND b.value = $sqlColumnRef)";
    }
}

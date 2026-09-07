<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;

/**
 * The set-based twin of Versioner::write().
 *
 * Versioner exists because eight per-row call sites would otherwise each grow
 * their own flip-and-insert and drift apart — and this codebase already has a
 * documented instance of exactly that (Survivorship's tiebreak had to be pinned to
 * link_id ASC to match SetFinalizer's SQL "because a mismatch broke the
 * rebuild-produces-a-byte-identical-profile invariant"). Seven SET-BASED write
 * sites are about to appear. Same argument, same answer: the rule lives here.
 *
 * THE SHAPE, AND WHY IT IS FOUR STATEMENTS AND NOT ONE
 * ---------------------------------------------------
 * uq_<t>_current makes at most one CURRENT row per natural key, so an insert that
 * ran before the flip would collide with the row it is about to supersede. And the
 * new version's carried-forward columns have to be read BEFORE the flip, because
 * after it there is no current row to read them from. So:
 *
 *   1. latest   — one row per natural key: the highest version_no, current or not.
 *                 "Or not" matters: Versioner::write() takes the highest version
 *                 regardless of currency, so a key whose chain was fully retired
 *                 (a merge collision — see Versioner::repointForMerge) revives at
 *                 the next number rather than colliding with version 1.
 *   2. todo     — the keys that need a successor: their latest is not current, OR
 *                 an attribute differs. Materialised so the predicate is evaluated
 *                 ONCE and the flip and the insert cannot disagree about it.
 *   3. flip     — current = 0 for those keys. Only `current`: date_updated on a
 *                 version means "when this version was written", and an audit trail
 *                 whose rows get restamped every time they are superseded has lost
 *                 the thing it was keeping.
 *   4. insert   — the successors, plus (separately) version 1 for keys with no
 *                 history at all. Two statements because Versioner::write() itself
 *                 has two branches: with no $latest it applies onCreate and takes
 *                 the column defaults for everything else, and with one it carries
 *                 the previous row forward. A single statement would have to
 *                 COALESCE every NOT NULL column against its own default, read out
 *                 of information_schema, to survive strict mode.
 *
 * Steps 3 and 4 run inside one transaction with SqlBackfill's deadlock retry
 * count. Not for the exclusive bulk case — transform() and SetFinalizer::run()
 * hold a named lock — but for the concurrent gp:sync case, where a per-row
 * Versioner::write() on one identity contends with the bulk flip.
 * Versioner::write() has no retry; the bulk side does, so the bulk side yields.
 *
 * A crash between the flip and the insert cannot half-apply. A crash between
 * TABLES leaves a key whose latest version has current = 0, which both paths
 * handle by design (step 2's `l.current = 0` arm, and Versioner::write()'s
 * $isCurrent = false branch) — so there is no repair step.
 *
 * SCRATCH TABLES ARE TEMPORARY, AND THAT IS LOAD-BEARING TWICE OVER.
 * CREATE/ALTER/DROP TEMPORARY TABLE are the exemptions to MySQL's
 * implicit-commit-on-DDL rule, so this class is legal inside a caller's
 * transaction. They are also per-session, so two concurrent transforms cannot see
 * each other's scratch — which is why the exclusivity guarantee has to come from
 * the advisory lock at the entry points and not from here. Each is dropped before
 * creation as well as after: a temporary table created inside a transaction is NOT
 * rolled back with it, so a failed run leaves one behind.
 */
class SetVersionWriter
{
    /**
     * transaction() retry attempts for InnoDB deadlocks. Same sizing as
     * SqlBackfill::DEADLOCK_RETRIES, which was measured against 16 parallel
     * staging workers.
     */
    private const DEADLOCK_RETRIES = 5;

    public function __construct(private ?string $connection = null) {}

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }

    /**
     * A table's real (non-generated) columns, in ordinal order.
     *
     * Read from the catalogue rather than hard-coded so the carry-forward cannot
     * silently miss a column a later migration adds — which would write a NULL into
     * it on every version bump. current_key is excluded because it is VIRTUAL and
     * cannot be inserted into.
     *
     * @return list<string>
     */
    public function realColumns(string $table): array
    {
        return array_map(
            fn ($r) => $r->c,
            $this->db()->select(
                "SELECT column_name AS c FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ?
                   AND extra NOT LIKE '%GENERATED%'
                 ORDER BY ordinal_position",
                [$table],
            ),
        );
    }

    /**
     * Version a whole set of one child table's natural keys at once.
     *
     * $incoming must name a table (temporary is fine) holding EXACTLY ONE ROW PER
     * NATURAL KEY, with columns named as the target's: every key column, every
     * column in $compared, and every onCreate column from Versioner's spec. More
     * than one row per key is a caller bug and shows up as a duplicate-key error
     * from uq_<t>_current, which is the loud failure the index exists for.
     *
     * $compared uses differsOnAll semantics — a NULL incoming value is compared,
     * not treated as absent — because every per-row counterpart of these writers
     * builds its attribute array unconditionally. Attributes NOT listed in
     * $compared carry forward untouched; gp_license.is_verified is the example,
     * declared an attribute by Versioner but supplied by no write path, and passing
     * it here as a literal 0 would reset a verified licence and mint a version
     * doing it.
     *
     * @param  string  $table  one of Versioner::TABLES
     * @param  string  $incoming  scratch table name
     * @param  list<string>  $compared
     * @return array{new_versions: int}
     */
    public function write(string $table, string $incoming, array $compared): array
    {
        $spec = Versioner::spec($table);
        $db = $this->db();
        $key = $spec['key'];
        $keyList = implode(', ', array_map(fn ($c) => "`$c`", $key));
        $all = $this->realColumns($table);

        $latest = "tmp_ver_{$table}_latest";
        $todo = "tmp_ver_{$table}_todo";

        // 1. latest — highest version per key, current or not.
        $latestCols = implode(', ', array_map(fn ($c) => "g.`$c`", $all));
        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$latest`");
        $db->statement("CREATE TEMPORARY TABLE `$latest` (INDEX idx_key ($keyList)) ENGINE=InnoDB AS
            SELECT $latestCols
            FROM `$table` g
            JOIN ( SELECT $keyList, MAX(`version_no`) mx FROM `$table` GROUP BY $keyList ) m
              ON ".VersionerSql::keysEqual('g', 'm', $key).' AND g.`version_no` = m.mx');

        // 2. todo — the keys that need a successor, decided once.
        $incomingMap = [];
        foreach ($compared as $column) {
            $incomingMap[$column] = "n.`$column`";
        }
        $todoCols = implode(', ', array_map(fn ($c) => "l.`$c`", $key));
        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$todo`");
        $db->statement("CREATE TEMPORARY TABLE `$todo` (INDEX idx_key ($keyList)) ENGINE=InnoDB AS
            SELECT $todoCols
            FROM `$incoming` n
            JOIN `$latest` l ON ".VersionerSql::keysEqual('l', 'n', $key).'
            WHERE l.`current` = 0 OR '.VersionerSql::differsOnAll('l', $incomingMap));

        // 4a. successors — carry the previous version forward, overlay the incoming
        //     attributes, bump the version. The surrogate is omitted so the new
        //     version gets its own; onCreate columns come from `latest`, never from
        //     `incoming`, because they record which source row ESTABLISHED the fact.
        $successorCols = array_values(array_diff($all, [$spec['surrogate']]));
        $successorSelect = [];
        foreach ($successorCols as $column) {
            $successorSelect[] = match (true) {
                $column === 'version_no' => 'l.`version_no` + 1',
                $column === 'current' => '1',
                $column === $spec['updated'] => 'NOW()',
                in_array($column, $compared, true) => "n.`$column`",
                default => "l.`$column`",
            }." AS `$column`";
        }
        $successorList = implode(', ', array_map(fn ($c) => "`$c`", $successorCols));

        // 4b. version 1 for keys with no history. Only the columns we actually have
        //     are named, so every other column takes its schema default — which is
        //     what Versioner::write() does when $latest is null.
        $firstCols = array_values(array_unique([
            ...$key, ...$compared, ...$spec['onCreate'],
            'version_no', 'current', $spec['created'], $spec['updated'],
        ]));
        $firstSelect = [];
        foreach ($firstCols as $column) {
            $firstSelect[] = match (true) {
                $column === 'version_no' => '1',
                $column === 'current' => '1',
                $column === $spec['created'], $column === $spec['updated'] => 'NOW()',
                default => "n.`$column`",
            }." AS `$column`";
        }
        $firstList = implode(', ', array_map(fn ($c) => "`$c`", $firstCols));

        $minted = $db->transaction(function () use (
            $db, $table, $incoming, $latest, $todo, $key,
            $successorList, $successorSelect, $firstList, $firstSelect
        ) {
            // 3. flip. A key whose chain was already fully retired matches nothing
            //    here, which is the correct no-op.
            $db->affectingStatement("
                UPDATE `$table` t
                JOIN `$todo` d ON ".VersionerSql::keysEqual('t', 'd', $key).'
                SET t.`current` = 0
                WHERE t.`current` = 1');

            $successors = (int) $db->affectingStatement("
                INSERT INTO `$table` ($successorList)
                SELECT ".implode(', ', $successorSelect)."
                FROM `$todo` d
                JOIN `$latest` l ON ".VersionerSql::keysEqual('l', 'd', $key)."
                JOIN `$incoming` n ON ".VersionerSql::keysEqual('n', 'd', $key));

            $created = (int) $db->affectingStatement("
                INSERT INTO `$table` ($firstList)
                SELECT ".implode(', ', $firstSelect)."
                FROM `$incoming` n
                LEFT JOIN `$latest` l ON ".VersionerSql::keysEqual('l', 'n', $key).'
                WHERE l.`version_no` IS NULL');

            return $successors + $created;
        }, self::DEADLOCK_RETRIES);

        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$todo`");
        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$latest`");

        return ['new_versions' => $minted];
    }

    /**
     * Version gp_identity from a scratch table of PROPOSED values.
     *
     * gp_identity is different from the five child tables in three ways that make a
     * separate method cheaper than a parameter:
     *
     *   - its natural key IS its surrogate, so there is no "new key" branch. An
     *     identity is minted by a resolve tier, never by a versioned write.
     *   - the carry-forward is the WHOLE row, so the scratch table is built LIKE
     *     gp_identity to get its exact types (see below).
     *   - NULL in $proposals means "nothing proposed for this column" — carry
     *     forward — not "the value is NULL". That is Versioner::differs()'s
     *     absent-key branch, and it is what a survivorship field with no non-blank
     *     candidate, or a backfill column with nothing to add, produces.
     *
     * WHY LIKE AND NOT AS SELECT. CREATE TEMPORARY TABLE … AS SELECT infers column
     * types from the expressions, so COALESCE(p.canonical_dob, i.canonical_dob) —
     * a VARCHAR(500) scratch column against a DATE — would land as a string and
     * convert on the way into gp_identity. Under strict mode (Laravel sets
     * STRICT_TRANS_TABLES) one malformed date then fails a 13M-row insert halfway.
     * LIKE copies the exact types, defaults and NOT NULL flags, so the conversion
     * happens once, in the scratch insert, where it is cheap to see. The copied
     * secondary indexes are pure cost on a write-once scratch table and are dropped
     * immediately — read out of information_schema for gp_identity rather than
     * listed, so the drop cannot break when plan 2 removes idx_ssn.
     *
     * @param  string  $proposals  scratch table: identity_id plus a column per proposed fact
     * @param  list<string>  $columns  the proposed columns
     * @param  array<string,string>  $derived  target column => SQL over alias `p`
     * @return int versions minted
     */
    public function writeIdentities(string $proposals, array $columns, array $derived = []): int
    {
        $db = $this->db();
        $all = $this->realColumns('gp_identity');
        $next = 'tmp_ver_identity_next';

        $select = [];
        foreach ($all as $column) {
            $select[] = match (true) {
                $column === 'version_no' => 'i.`version_no` + 1',
                $column === 'current' => '1',
                $column === 'last_updated' => 'NOW()',
                isset($derived[$column]) => $derived[$column],
                in_array($column, $columns, true) => "COALESCE(p.`$column`, i.`$column`)",
                default => "i.`$column`",
            }." AS `$column`";
        }
        $list = implode(', ', array_map(fn ($c) => "`$c`", $all));

        $incomingMap = [];
        foreach ($columns as $column) {
            $incomingMap[$column] = "p.`$column`";
        }

        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$next`");
        $db->statement("CREATE TEMPORARY TABLE `$next` LIKE gp_identity");
        foreach ($db->select(
            "SELECT DISTINCT index_name AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'gp_identity'
               AND index_name <> 'PRIMARY'"
        ) as $index) {
            $db->statement("ALTER TABLE `$next` DROP INDEX `{$index->n}`");
        }

        $db->statement("
            INSERT INTO `$next` ($list)
            SELECT ".implode(', ', $select)."
            FROM gp_identity i
            JOIN `$proposals` p ON p.`identity_id` = i.`identity_id`
            WHERE i.`current` = 1
              AND ".VersionerSql::differsOnPresent('i', $incomingMap));

        $minted = $db->transaction(function () use ($db, $next, $list) {
            $db->affectingStatement("
                UPDATE gp_identity i
                JOIN `$next` n ON n.`identity_id` = i.`identity_id`
                SET i.`current` = 0
                WHERE i.`current` = 1");

            return (int) $db->affectingStatement(
                "INSERT INTO gp_identity ($list) SELECT $list FROM `$next`"
            );
        }, self::DEADLOCK_RETRIES);

        // Derived values are written onto the CURRENT version in place — the new
        // versions above already carry them, so this catches the identities that
        // did not change. The <=> guard is a pure optimisation with an identical
        // outcome: Versioner::write() writes derived unconditionally, and writing a
        // value equal to the one already there is indistinguishable from not.
        foreach ($derived as $column => $expr) {
            $db->affectingStatement("
                UPDATE gp_identity i
                JOIN `$proposals` p ON p.`identity_id` = i.`identity_id`
                SET i.`$column` = $expr
                WHERE i.`current` = 1 AND NOT (i.`$column` <=> ($expr))");
        }

        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$next`");

        return $minted;
    }
}

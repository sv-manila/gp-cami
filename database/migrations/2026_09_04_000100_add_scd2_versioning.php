<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slowly-changing-dimension (Type 2) versioning for the six tables that hold
 * golden facts — Data Flow by CAMI (DEV page 4099997697):
 *
 *   "Nearly every table carries a current tinyint(1) flag plus date_created /
 *    date_updated. Every sync process follows the same rule: insert a new row
 *    with current = 1, and set all preexisting rows to current = 0."
 *
 * See docs/SCD2.md for which tables are versioned, which are not, and why. The
 * short version: golden facts are versioned; the grouping link table
 * (gp_source_link), the append-only logs, staging, and the rebuildable read
 * models are not.
 *
 * WHY RAW STATEMENTS AND NOT BLUEPRINT
 * ------------------------------------
 * The ALGORITHM/LOCK clauses are load-bearing. gp_identity is ~13.4M rows and
 * gp_identity_credential is the largest table in the hub, so the difference
 * between INSTANT/INPLACE and a rebuild is the difference between a deploy and an
 * outage. Blueprint emits neither the clauses nor the generated-column
 * expressions.
 *
 *   ADD COLUMN with a default, at the end of the row  -> ALGORITHM=INSTANT
 *                                                        (MySQL 8.0.12+), so
 *                                                        EVERY EXISTING ROW
 *                                                        BECOMES version 1,
 *                                                        current 1 with no
 *                                                        UPDATE pass at all.
 *                                                        That is the backfill.
 *   ADD COLUMN ... VIRTUAL (generated)                -> INPLACE, no rebuild
 *   ADD/DROP secondary INDEX                          -> INPLACE, LOCK=NONE
 *   DROP PRIMARY KEY, ADD PRIMARY KEY                 -> ALGORITHM=COPY. Only
 *                                                        three statements need
 *                                                        it; see the runbook in
 *                                                        docs/SCD2.md for the
 *                                                        gh-ost route.
 *
 * date_created / date_updated cannot be defaulted to a per-row historical value,
 * so they are added NULL here and populated by `php artisan gp:version-backfill`,
 * which is chunked and resumable. A 40-minute UPDATE inside a migration leaves a
 * failed deploy wedged halfway.
 *
 * WHY current_key IS A GENERATED COLUMN
 * -------------------------------------
 * "At most one CURRENT version per natural key" is a partial uniqueness
 * constraint and MySQL 8 has no partial indexes. current_key is
 * IF(current = 1, CONCAT(<key parts>), NULL): NULL for every superseded row (so
 * they are unlimited), the key for the current one (so a second one is a
 * duplicate-key error rather than a silent duplicate row).
 *
 * CONCAT, not CONCAT_WS: CONCAT returns NULL if ANY argument is NULL, which
 * reproduces the existing multi-column uniques exactly — those are equally
 * NULL-permissive, since MySQL never treats two NULLs as equal in a unique index.
 * Matching that behaviour is what guarantees this index can be built on any data
 * that already satisfies the old constraint. Parts are joined with
 * CHAR(31 USING utf8mb4) — the ASCII unit separator, which cannot appear in a
 * licence number, a state, a board code or an address — so no field value can
 * forge a key boundary. The column takes the table's utf8mb4_unicode_ci
 * collation, so comparison stays case- and accent-insensitive exactly as the
 * multi-column unique was; a hash over raw bytes would NOT (it would let 'L-77'
 * and 'l-77' coexist as two current versions where today they collide).
 *
 * gp_identity IS THE EXCEPTION, and MySQL forces it. Its natural key is
 * identity_id, which is the table's AUTO_INCREMENT column, and MySQL refuses a
 * generated column whose expression references one:
 *
 *   ERROR 3109: Generated column 'current_key' cannot refer to auto-increment
 *               column.
 *
 * Measured on the 8.0.43 test server, not inferred. Dropping AUTO_INCREMENT is
 * not an option — insertGetId() mints new identities. So on gp_identity
 * current_key is a MARKER over `current` alone, IF(current = 1, 1, NULL), and
 * the uniqueness comes from the composite index uq_identity_current
 * (identity_id, current_key): a second current row collides on (id, 1) while
 * superseded rows all carry NULL and stay unlimited. Same guarantee, same
 * NULL-permissive behaviour, expressed in the only shape the server accepts.
 *
 * KNOWN GAP, recorded in docs/SCD2.md: because CONCAT propagates NULL, a row
 * whose natural key has a NULL part gets a NULL current_key, and MySQL never
 * constrains NULLs in a unique index — so uq_*_current does NOT enforce
 * single-current for those rows. gp_address has four nullable key parts. Plan 3b
 * closes it in code on both write paths; the schema-level COALESCE-sentinel fix
 * is unowned (00-PROGRAMME.md §9/§10 defer it to plan 5, which shipped without
 * it).
 *
 * Every statement is guarded, so this migration is safe to re-run and safe to run
 * after an operator has already applied one of the COPY statements out of band
 * with gh-ost.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    /** Unit separator, joined into every composite current_key. */
    private const SEP = 'CHAR(31 USING utf8mb4)';

    /**
     * table => [
     *   current_key column type,
     *   the CONCAT parts of the natural key — [] means "marker only", used where
     *     the key is an AUTO_INCREMENT column a generated column may not touch,
     *   the columns of the single-current unique index.
     * ]
     * A single-part key needs no CONCAT at all.
     */
    private const CURRENT_KEY = [
        'gp_identity' => ['TINYINT UNSIGNED', [], ['identity_id', 'current_key']],
        'gp_license' => ['VARCHAR(220)', ['identity_id', 'license_number', 'certification_state', 'certification_board'], ['current_key']],
        'gp_address' => ['VARCHAR(320)', ['identity_id', 'address1', 'city', 'state', 'zip'], ['current_key']],
        'gp_identity_identifier' => ['VARCHAR(160)', ['identity_id', 'id_type', 'id_value'], ['current_key']],
        'gp_identity_credential' => ['VARCHAR(48)', ['system_id', 'credential_match_id'], ['current_key']],
        'gp_identity_exclusion' => ['VARCHAR(48)', ['system_id', 'match_id'], ['current_key']],
    ];

    private const CURRENT_UNIQUE = [
        'gp_identity' => 'uq_identity_current',
        'gp_license' => 'uq_lic_current',
        'gp_address' => 'uq_addr_current',
        'gp_identity_identifier' => 'uq_ident_current',
        'gp_identity_credential' => 'uq_cred_current',
        'gp_identity_exclusion' => 'uq_excl_current',
    ];

    /** Natural-key uniques that must admit versions: index name => its columns. */
    private const VERSIONED_UNIQUE = [
        'gp_license' => ['uq_lic', ['identity_id', 'license_number', 'certification_state', 'certification_board']],
        'gp_address' => ['uq_addr', ['identity_id', 'address1', 'city', 'state', 'zip']],
        'gp_identity_identifier' => ['uq_identity_identifier', ['identity_id', 'id_type', 'id_value']],
    ];

    /** Primary keys that must admit versions: table => the new PK columns. */
    private const VERSIONED_PK = [
        'gp_identity' => ['identity_id', 'version_no'],
        'gp_identity_credential' => ['system_id', 'credential_match_id', 'version_no'],
        'gp_identity_exclusion' => ['system_id', 'match_id', 'version_no'],
    ];

    /**
     * Secondary indexes that gain a trailing `current`. Every one of these leads a
     * read that now filters on it; leaving them alone makes each probe read the
     * whole version history and filter in the server.
     */
    private const CURRENT_APPENDED = [
        'gp_identity' => [
            'idx_ssn' => ['ssn_hash'],
            'idx_npi' => ['npi'],
            'idx_upin' => ['upin'],
            'idx_dea' => ['dea_number'],
            'idx_name_dob' => ['canonical_last', 'canonical_first', 'canonical_dob'],
        ],
        'gp_license' => [
            'idx_identity' => ['identity_id'],
            'idx_number_state' => ['license_number', 'certification_state'],
        ],
        'gp_address' => ['idx_identity' => ['identity_id']],
        'gp_identity_identifier' => ['idx_type_value' => ['id_type', 'id_value']],
        'gp_identity_credential' => ['idx_identity' => ['identity_id']],
        'gp_identity_exclusion' => ['idx_identity' => ['identity_id']],
    ];

    public function up(): void
    {
        // 1. Version columns. INSTANT, and the DEFAULTs are the backfill: every
        //    existing row is, by definition, version 1 and current.
        foreach (array_keys(self::CURRENT_KEY) as $table) {
            $this->addColumnIfMissing($table, 'version_no', 'INT UNSIGNED NOT NULL DEFAULT 1');
            $this->addColumnIfMissing($table, 'current', 'TINYINT(1) NOT NULL DEFAULT 1');
        }

        // 2. The doc's timestamps, on the five tables that had none. NULL for now;
        //    gp:version-backfill fills them. gp_identity is absent on purpose: it
        //    maps to first_seen / last_updated, which it already has, because
        //    last_updated is a published API field (IdentityProfileResource).
        foreach (['gp_license', 'gp_address', 'gp_identity_identifier',
            'gp_identity_credential', 'gp_identity_exclusion'] as $table) {
            $this->addColumnIfMissing($table, 'date_created', 'DATETIME NULL');
            $this->addColumnIfMissing($table, 'date_updated', 'DATETIME NULL');
        }

        // 3. Primary keys that must admit versions. THE EXPENSIVE PART —
        //    ALGORITHM=COPY, writes blocked for the duration. Skipped when an
        //    operator has already applied it with gh-ost (see docs/SCD2.md).
        foreach (self::VERSIONED_PK as $table => $cols) {
            if (! in_array('version_no', $this->indexColumns($table, 'PRIMARY'), true)) {
                $list = implode(', ', array_map(fn ($c) => "`$c`", $cols));
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `$table` DROP PRIMARY KEY, ADD PRIMARY KEY ($list)"
                );
            }
        }

        // 4. identity_uuid stops being globally unique and becomes unique per
        //    version — the uuid is a property of the logical identity, so every
        //    version of it carries the same one.
        $uuidIndex = $this->uniqueIndexOn('gp_identity', ['identity_uuid']);
        if ($uuidIndex !== null && $uuidIndex !== 'uq_identity_uuid') {
            DB::connection($this->connection)->statement(
                "ALTER TABLE `gp_identity` DROP INDEX `$uuidIndex`,
                 ADD UNIQUE INDEX `uq_identity_uuid` (`identity_uuid`, `version_no`),
                 ALGORITHM=INPLACE, LOCK=NONE"
            );
        }

        // 5. Natural-key uniques gain version_no, keeping their names so they stay
        //    the natural-key lookup index they already are.
        foreach (self::VERSIONED_UNIQUE as $table => [$index, $cols]) {
            if ($this->indexColumns($table, $index) === $cols) {
                $list = implode(', ', array_map(fn ($c) => "`$c`", [...$cols, 'version_no']));
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `$table` DROP INDEX `$index`,
                     ADD UNIQUE INDEX `$index` ($list), ALGORITHM=INPLACE, LOCK=NONE"
                );
            }
        }

        // 6. The single-current guarantee. VIRTUAL so the column add is metadata
        //    only; the unique index build that follows is INPLACE with no write
        //    lock. Two statements, not one: MySQL will not combine adding a
        //    generated column with other operations.
        foreach (self::CURRENT_KEY as $table => [$type, $parts, $indexCols]) {
            if (! Schema::connection($this->connection)->hasColumn($table, 'current_key')) {
                $collate = str_starts_with($type, 'VARCHAR') ? ' COLLATE utf8mb4_unicode_ci' : '';
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `$table`
                     ADD COLUMN `current_key` $type$collate
                     GENERATED ALWAYS AS (IF(`current` = 1, {$this->keyExpression($parts)}, NULL)) VIRTUAL,
                     ALGORITHM=INPLACE, LOCK=NONE"
                );
            }

            $unique = self::CURRENT_UNIQUE[$table];
            if (! $this->indexExists($table, $unique)) {
                $list = implode(', ', array_map(fn ($c) => "`$c`", $indexCols));
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `$table` ADD UNIQUE INDEX `$unique` ($list),
                     ALGORITHM=INPLACE, LOCK=NONE"
                );
            }
        }

        // 7. Read paths now filter on `current`, so the indexes they use must
        //    include it. Guarded on the exact old column list: an index an
        //    operator has already extended by hand is left alone rather than
        //    dropped and narrowed.
        foreach (self::CURRENT_APPENDED as $table => $indexes) {
            foreach ($indexes as $index => $cols) {
                if ($this->indexColumns($table, $index) !== $cols) {
                    continue;
                }
                $list = implode(', ', array_map(fn ($c) => "`$c`", [...$cols, 'current']));
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `$table` DROP INDEX `$index`,
                     ADD INDEX `$index` ($list), ALGORITHM=INPLACE, LOCK=NONE"
                );
            }
        }
    }

    public function down(): void
    {
        $c = DB::connection($this->connection);

        foreach (self::CURRENT_APPENDED as $table => $indexes) {
            foreach ($indexes as $index => $cols) {
                if ($this->indexColumns($table, $index) === [...$cols, 'current']) {
                    $list = implode(', ', array_map(fn ($x) => "`$x`", $cols));
                    $c->statement("ALTER TABLE `$table` DROP INDEX `$index`, ADD INDEX `$index` ($list)");
                }
            }
        }

        foreach (self::CURRENT_UNIQUE as $table => $unique) {
            if ($this->indexExists($table, $unique)) {
                $c->statement("ALTER TABLE `$table` DROP INDEX `$unique`");
            }
            if (Schema::connection($this->connection)->hasColumn($table, 'current_key')) {
                $c->statement("ALTER TABLE `$table` DROP COLUMN `current_key`");
            }
        }

        foreach (self::VERSIONED_UNIQUE as $table => [$index, $cols]) {
            if ($this->indexColumns($table, $index) === [...$cols, 'version_no']) {
                $list = implode(', ', array_map(fn ($x) => "`$x`", $cols));
                $c->statement("ALTER TABLE `$table` DROP INDEX `$index`, ADD UNIQUE INDEX `$index` ($list)");
            }
        }

        if ($this->indexExists('gp_identity', 'uq_identity_uuid')) {
            $c->statement(
                'ALTER TABLE `gp_identity` DROP INDEX `uq_identity_uuid`,
                 ADD UNIQUE INDEX `gp_identity_identity_uuid_unique` (`identity_uuid`)'
            );
        }

        // Reversing the PK requires the table to hold one row per natural key
        // again. It will not if anything has been versioned, which is why this is
        // guarded rather than attempted: an SCD-2 rollback on live data is a data
        // decision (which version survives?), not a schema one.
        foreach (self::VERSIONED_PK as $table => $cols) {
            $natural = array_values(array_diff($cols, ['version_no']));
            $extra = (int) $c->selectOne("SELECT COUNT(*) n FROM `$table` WHERE `version_no` <> 1")->n;
            if ($extra > 0) {
                throw new RuntimeException(
                    "$table holds $extra superseded version(s); rolling back SCD-2 would have to ".
                    'discard them. Decide which versions survive, delete them, then re-run down().'
                );
            }
            $list = implode(', ', array_map(fn ($x) => "`$x`", $natural));
            $c->statement("ALTER TABLE `$table` DROP PRIMARY KEY, ADD PRIMARY KEY ($list)");
        }

        foreach (['gp_license', 'gp_address', 'gp_identity_identifier',
            'gp_identity_credential', 'gp_identity_exclusion'] as $table) {
            $c->statement("ALTER TABLE `$table` DROP COLUMN `date_created`, DROP COLUMN `date_updated`");
        }
        foreach (array_keys(self::CURRENT_KEY) as $table) {
            $c->statement("ALTER TABLE `$table` DROP COLUMN `current`, DROP COLUMN `version_no`");
        }
    }

    /**
     * CONCAT of the natural key, unit-separated. A single part needs no CONCAT.
     *
     * No parts at all means a marker: the natural key is an AUTO_INCREMENT
     * column, which MySQL forbids a generated column from referencing (error
     * 3109), so the constant 1 carries the "this row is current" bit and the
     * key columns join it in the unique index instead. See the class docblock.
     */
    private function keyExpression(array $parts): string
    {
        if ($parts === []) {
            return '1';
        }

        if (count($parts) === 1) {
            return '`'.$parts[0].'`';
        }

        $joined = [];
        foreach ($parts as $i => $p) {
            if ($i > 0) {
                $joined[] = self::SEP;
            }
            $joined[] = '`'.$p.'`';
        }

        return 'CONCAT('.implode(', ', $joined).')';
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (Schema::connection($this->connection)->hasColumn($table, $column)) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE `$table` ADD COLUMN `$column` $definition, ALGORITHM=INSTANT"
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) DB::connection($this->connection)->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        );
    }

    /** @return list<string> the index's columns in order, or [] when absent */
    private function indexColumns(string $table, string $index): array
    {
        return array_map(
            fn ($r) => $r->c,
            DB::connection($this->connection)->select(
                'SELECT column_name AS c FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                 ORDER BY seq_in_index',
                [$table, $index]
            )
        );
    }

    /**
     * The name of the unique index whose columns are exactly $cols. Laravel's
     * default here is gp_identity_identity_uuid_unique, but the live hub carries
     * indexes added by hand and absent from every migration (CredentialSearch-
     * Controller's measurement comment names idx_identity_registry_match), so the
     * name is discovered rather than assumed.
     */
    private function uniqueIndexOn(string $table, array $cols): ?string
    {
        $rows = DB::connection($this->connection)->select(
            'SELECT index_name AS n, GROUP_CONCAT(column_name ORDER BY seq_in_index) cols
             FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0
               AND index_name <> \'PRIMARY\'
             GROUP BY index_name',
            [$table]
        );

        foreach ($rows as $row) {
            if ($row->cols === implode(',', $cols)) {
                return $row->n;
            }
        }

        return null;
    }
};

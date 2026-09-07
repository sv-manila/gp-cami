<?php

namespace App\GoldenProfile\Materialize;

use App\GoldenProfile\Support\SetBasedPathGuard;
use App\GoldenProfile\Support\SetVersionWriter;
use Illuminate\Support\Facades\DB;

/**
 * Set-based finalize — the whole-hub replacement for Engine::finalizeAll's
 * per-identity loop (Survivorship::recompute + ProfileMaterializer::rebuild
 * run once per identity, ~25 hub round-trips each).
 *
 * Same output, produced with a fixed handful of INSERT…SELECT / UPDATE…JOIN
 * statements over the entire graph:
 *   1. survivorship() — per-field authority+recency winner into gp_identity
 *      canonical_*, with provenance rewritten in gp_attribute (all candidates,
 *      winner flagged) and gp_survivorship_audit (winner only).
 *   2. materialize()  — one wide gp_identity_profile row per identity, JSON
 *      children aggregated with JSON_ARRAYAGG in the database.
 *
 * Whole-hub only (finalizes every identity): it rewrites the survivorship
 * attribute/audit rows and the profile table wholesale. For incremental
 * per-identity finalize (sync mode) keep using Engine::finalize.
 *
 * Requires MySQL 8+ (window functions, JSON_ARRAYAGG, JSON_OBJECT, CTEs).
 */
class SetFinalizer
{
    /** identity canonical column <= staged column (same map as Survivorship). */
    private const IDENTITY_FIELDS = [
        'canonical_first' => 'first_name',
        'canonical_middle' => 'middle_name',
        'canonical_last' => 'last_name',
        'canonical_suffix' => 'name_suffix',
        'canonical_dob' => 'date_of_birth',
        'npi' => 'npi',
        'upin' => 'upin',
        'dea_number' => 'dea_number',
        'ssn_hash' => 'ssn_hash',
    ];

    private AliasIndexer $aliasIndexer;

    public function __construct(?AliasIndexer $aliasIndexer = null)
    {
        $this->aliasIndexer = $aliasIndexer ?? new AliasIndexer;
    }

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    public function run(?callable $log = null): void
    {
        (new SetBasedPathGuard)->assertConverted(self::class);

        $log ??= fn ($p, $d) => null;
        $log('finalize', 'survivorship (set-based)');
        $this->survivorship();
        $log('finalize', 'materialize profiles (set-based)');
        $this->materialize();
    }

    // ---- 1. SURVIVORSHIP --------------------------------------------------

    /**
     * Per-field winner = highest field_authority (by source system_code), then
     * newest source_modified, then link_id ASC. Mirrors Resolution\Survivorship
     * exactly, but as one pass over every identity instead of per identity.
     *
     * WHY THIS IS ONE ALL-FIELDS PASS AND NOT NINE
     * -------------------------------------------
     * It used to be nine independent statements: for each canonical field, one
     * UPDATE gp_identity SET <field> = <winner>. Under SCD-2 not one of them can
     * mint a version without minting up to NINE per identity per run — and
     * Engine::finalizeAll() recomputes every identity, so that is up to ~120M
     * gp_identity rows on a hub of 13.38M identities. So the nine winners are
     * pivoted into ONE row per identity, compared against the current version as a
     * whole, and written as a single version for the identities that actually
     * differ. Versioner::write() makes the same decision per row; the two have to
     * reach the same verdict, which is what Support\VersionerSql is for.
     *
     * WHAT THE THREE CATEGORIES BECOME HERE
     * -------------------------------------
     *   attributes  the nine canonical fields. A field with no non-blank candidate
     *               is ABSENT, not NULL — it carries the previous version's value
     *               forward, exactly as ->update($update) used to leave it alone.
     *               VersionerSql::differsOnPresent() models that.
     *   derived     record_count. Written onto the current version IN PLACE and
     *               never a reason to version: gp_source_link already records when
     *               each link was made with better resolution than a version row
     *               would, and versioning on a bump would add one identity row per
     *               source row (~13.4M on a backfill).
     *   last_updated  no longer written unconditionally. This method used to set it
     *               to NOW() on every finalize, so it answered "when did we last
     *               look"; SetVersionWriter stamps it only on a version that is
     *               actually written, so it now answers "when did the golden facts
     *               last change". That is the doc's date_updated meaning and it is
     *               a visible change in both API endpoints — see docs/SCD2.md.
     *
     * gp_attribute and gp_survivorship_audit are NOT versioned and are unchanged in
     * content: they are per-observation provenance, i.e. they ARE the history, so
     * they do not have one. Both are still fully rebuilt each pass for these nine
     * attribute names, which is what keeps them idempotent.
     */
    public function survivorship(): void
    {
        $hub = $this->hub();
        $names = array_keys(self::IDENTITY_FIELDS);
        $nameList = "'".implode("','", $names)."'";

        // Provenance is fully rebuilt for these identity fields (idempotent).
        $hub->statement("DELETE FROM gp_attribute WHERE attr_name IN ($nameList)");
        $hub->statement("DELETE FROM gp_survivorship_audit WHERE attribute_name IN ($nameList)");

        $this->withoutIdentityKeyIndexes(function () {
            $this->buildWinners();

            // One version per changed identity; record_count in place for the rest.
            (new SetVersionWriter)->writeIdentities(
                'tmp_surv_winner',
                array_keys(self::IDENTITY_FIELDS),
                ['record_count' => 'p.`c`'],
            );
        });

        $this->dropWinnerTables();
    }

    /**
     * Ranked candidates for one staged column: non-blank values, best authority
     * then newest, link_id as a deterministic final tiebreak.
     *
     * The link_id ASC tail is not cosmetic. Resolution\Survivorship's comparator
     * ends in the same tiebreak specifically to match this ordering, "because a
     * mismatch broke the rebuild-produces-a-byte-identical-profile invariant". Two
     * candidates tied on authority and recency must crown the same winner on both
     * paths or the two mint different versions from identical input.
     */
    private function rankedCandidatesSql(string $srcCol): string
    {
        $rank = $this->authorityRankSql('ss');

        return "
            SELECT l.identity_id, l.link_id, l.system_id, ss.system_code,
                   sp.`$srcCol` AS v,
                   ROW_NUMBER() OVER (
                       PARTITION BY l.identity_id
                       ORDER BY ($rank) ASC, sp.source_modified DESC, l.link_id ASC
                   ) rn
            FROM gp_source_link l
            JOIN stg_person sp
              ON sp.system_id = l.system_id AND sp.source_table = l.source_table AND sp.source_id = l.source_id
            JOIN gp_source_system ss ON ss.system_id = l.system_id
            WHERE sp.`$srcCol` IS NOT NULL AND TRIM(sp.`$srcCol`) <> ''";
    }

    /**
     * Build tmp_surv_field (winner per identity per field) and tmp_surv_winner (one
     * pivoted row per identity, plus its record_count), and rewrite provenance.
     *
     * TEMPORARY tables on purpose, twice over: CREATE/DROP TEMPORARY TABLE are the
     * exemptions to MySQL's implicit-commit-on-DDL rule, so this is legal inside a
     * caller's transaction, and they are per-session so two concurrent runs cannot
     * collide on them. They are dropped before creation as well as after, because a
     * temporary table created inside a transaction is not rolled back with it.
     *
     * COST, relative to what this replaces. Before: 27 evaluations of the ranked
     * window function (three statements per field) plus nine 13M-row UPDATEs of
     * indexed columns. After: 18 evaluations (two per field), one pivot, one audit
     * insert, and two gp_identity statements RESTRICTED TO THE IDENTITIES THAT
     * CHANGED — near zero on a steady-state re-finalize.
     */
    private function buildWinners(): void
    {
        $hub = $this->hub();

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_surv_field');
        $hub->statement('CREATE TEMPORARY TABLE tmp_surv_field (
            identity_id BIGINT UNSIGNED   NOT NULL,
            attr_name   VARCHAR(64)       NOT NULL,
            v           VARCHAR(500)      NULL,
            link_id     BIGINT UNSIGNED   NOT NULL,
            system_id   SMALLINT UNSIGNED NOT NULL,
            system_code VARCHAR(32)       NULL,
            PRIMARY KEY (identity_id, attr_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        foreach (self::IDENTITY_FIELDS as $canonical => $srcCol) {
            $ranked = $this->rankedCandidatesSql($srcCol);

            // The winner. LEFT(v, 500) matches gp_survivorship_audit.surviving_value,
            // which is what this column feeds; every one of the nine source columns
            // is at most 255 wide, so it never actually truncates.
            $hub->statement("
                INSERT INTO tmp_surv_field (identity_id, attr_name, v, link_id, system_id, system_code)
                SELECT identity_id, '$canonical', LEFT(v, 500), link_id, system_id, system_code
                FROM ($ranked) r WHERE rn = 1");

            // every candidate -> gp_attribute (winner flagged is_canonical)
            $hub->statement("
                INSERT INTO gp_attribute (identity_id, attr_name, attr_value, source_link_id, is_canonical, observed_at)
                SELECT identity_id, '$canonical', LEFT(v, 255), link_id, IF(rn = 1, 1, 0), NOW()
                FROM ($ranked) r");
        }

        // winners -> gp_survivorship_audit. One statement for all nine fields now
        // that the winners are materialised, where it used to be one per field.
        $hub->statement("
            INSERT INTO gp_survivorship_audit
                (identity_id, attribute_name, surviving_value, system_id, source_link_id, rule_applied, decided_at)
            SELECT identity_id, attr_name, v, system_id, link_id,
                   CONCAT('authority[', system_code, '] + recency'), NOW()
            FROM tmp_surv_field");

        // Pivot: one row per identity, a column per canonical field, plus the
        // record_count Survivorship folds in per identity.
        //
        // MAX() is a pivot here, not a choice of value: tmp_surv_field's primary key
        // is (identity_id, attr_name), so at most one row can match each CASE.
        //
        // Driven from gp_source_link, not from tmp_surv_field, and LEFT JOINed: an
        // identity with links but no non-blank value anywhere still needs its
        // record_count, and Resolution\Survivorship::recompute() likewise returns
        // early only when the identity has NO links at all. An identity with zero
        // links appears in neither and is left completely alone by both paths.
        $pivot = [];
        foreach (array_keys(self::IDENTITY_FIELDS) as $canonical) {
            $pivot[] = "MAX(CASE WHEN f.attr_name = '$canonical' THEN f.v END) AS `$canonical`";
        }

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_surv_winner');
        $hub->statement('CREATE TEMPORARY TABLE tmp_surv_winner (INDEX idx_id (identity_id)) ENGINE=InnoDB AS
            SELECT k.identity_id, k.c, '.implode(', ', $pivot).'
            FROM ( SELECT identity_id, COUNT(*) c FROM gp_source_link GROUP BY identity_id ) k
            LEFT JOIN tmp_surv_field f ON f.identity_id = k.identity_id
            GROUP BY k.identity_id, k.c');
    }

    private function dropWinnerTables(): void
    {
        $this->hub()->statement('DROP TEMPORARY TABLE IF EXISTS tmp_surv_winner');
        $this->hub()->statement('DROP TEMPORARY TABLE IF EXISTS tmp_surv_field');
    }

    /** gp_identity key indexes — mirror of SqlBackfill::IDENTITY_KEY_INDEXES. */
    private const IDENTITY_KEY_INDEXES = [
        // Every definition ends in `current`, and must. The SCD-2 migration
        // (2026_09_04_000100) creates these five with a trailing `current` so
        // the tier probes stay sargable once every read filters on it — and
        // this bulk path DROPS them before its load and re-ADDs them from this
        // constant afterwards. A definition that omits `current` here silently
        // reverts the migration: no error, the index simply comes back narrower
        // and every probe starts reading the whole version history. Measured
        // exactly that way — Scd2SchemaTest's index assertion passed in
        // isolation and failed in the full suite, because a Feature test had
        // run this path in between.
        'idx_ssn' => 'ssn_hash, `current`',
        'idx_npi' => 'npi, `current`',
        'idx_upin' => 'upin, `current`',
        'idx_dea' => 'dea_number, `current`',
        'idx_name_dob' => 'canonical_last, canonical_first, canonical_dob, `current`',
    ];

    /**
     * Run $fn with gp_identity's five key indexes dropped, then rebuilt.
     *
     * Why this still pays after SCD-2: 2026_09_04_000100_add_scd2_versioning
     * appended `current` to all five, so the flip (UPDATE … SET current = 0)
     * rewrites one entry in every one of them per superseded identity, and the
     * insert that follows builds five entries per new version. The reason
     * changed; the conclusion did not — 9c3f11c measured the un-dropped version
     * of this pass at "~tens of min per field".
     *
     * uq_identity_current is deliberately NOT dropped. It is the only thing that
     * turns "two current versions of one identity" from a silent duplicate row
     * into a duplicate-key error, and the flip-then-insert ORDER exists because
     * it is enforced. Dropping it for speed would remove the guarantee at exactly
     * the moment this code starts depending on it.
     *
     * Skipped entirely while a transaction is open. ALTER TABLE causes an implicit
     * COMMIT in MySQL, so dropping an index mid-transaction commits whatever the
     * caller had open — for HubTestCase that is the fixture of the running test,
     * which then leaks into every later test in the process without anything
     * failing. Inside a transaction the data set is a handful of rows and the
     * optimisation is worth nothing, so skipping loses nothing either.
     */
    private function withoutIdentityKeyIndexes(callable $fn): void
    {
        $bulk = $this->hub()->transactionLevel() === 0;

        if ($bulk) {
            foreach (array_keys(self::IDENTITY_KEY_INDEXES) as $name) {
                if ($this->indexExists('gp_identity', $name)) {
                    $this->hub()->statement("ALTER TABLE gp_identity DROP INDEX `$name`");
                }
            }
        }

        try {
            $fn();
        } finally {
            if ($bulk) {
                foreach (self::IDENTITY_KEY_INDEXES as $name => $cols) {
                    if (! $this->indexExists('gp_identity', $name)) {
                        $this->hub()->statement("ALTER TABLE gp_identity ADD INDEX `$name` ($cols)");
                    }
                }
            }
        }
    }

    private function dropIdentityKeyIndexes(): void
    {
        foreach (array_keys(self::IDENTITY_KEY_INDEXES) as $name) {
            if ($this->indexExists('gp_identity', $name)) {
                $this->hub()->statement("ALTER TABLE gp_identity DROP INDEX `$name`");
            }
        }
    }

    private function addIdentityKeyIndexes(): void
    {
        foreach (self::IDENTITY_KEY_INDEXES as $name => $cols) {
            if (! $this->indexExists('gp_identity', $name)) {
                $this->hub()->statement("ALTER TABLE gp_identity ADD INDEX `$name` ($cols)");
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->hub()->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index],
        );
    }

    /**
     * CASE mapping a source system_code to its authority rank for the identity
     * track: explicit field_authority order first, then unknown systems after
     * the listed ones ordered by reliability_rank (100 - rank), matching
     * Resolution\Survivorship::authorityRank.
     */
    private function authorityRankSql(string $ssAlias): string
    {
        $order = config('golden_profile.survivorship.field_authority.identity', []);
        $when = '';
        foreach (array_values($order) as $i => $code) {
            $safe = str_replace("'", "''", (string) $code);
            $when .= " WHEN '$safe' THEN $i";
        }

        return "CASE $ssAlias.system_code$when ELSE 100 - COALESCE($ssAlias.reliability_rank, 50) END";
    }

    // ---- 2. MATERIALIZE ---------------------------------------------------

    /**
     * Identities per materialize chunk (each chunk commits independently).
     *
     * 25k, not 250k: a chunk is DELETE-then-INSERT, and the low id ranges hold
     * the seeded pile-up identities whose profile rows carry ~100MB of rollup
     * JSON each. At 250k a single chunk's DELETE ran over 8 minutes and built an
     * undo log big enough that interrupting it was expensive. Smaller chunks
     * mean more statements but far less undo per statement, and the job can be
     * stopped cleanly between chunks.
     */
    private const MATERIALIZE_CHUNK = 25000;

    /**
     * Rebuild gp_identity_profile for every identity, aggregating each child
     * collection with JSON_ARRAYAGG. Same JSON shape and scalar picks as
     * Materialize\ProfileMaterializer::rebuild.
     *
     * Chunked by identity_id range: each range is DELETE+INSERT in its own
     * autocommitted statement, so an interruption (crash / power-off) loses only
     * the in-flight chunk instead of forcing a multi-hour rollback of a single
     * 13M-row INSERT, and a re-run resumes cheaply (completed chunks just get
     * rewritten). Each chunk's aggregate subqueries also scan only their slice.
     *
     * @param  callable|null  $progress  fn(int $lo, int $hi, int $done)
     */
    public function materialize(?callable $progress = null): void
    {
        $hub = $this->hub();
        $b = $hub->selectOne('SELECT MIN(identity_id) lo, MAX(identity_id) hi FROM gp_identity');
        if (! $b || $b->lo === null) {
            return;
        }
        $min = (int) $b->lo;
        $max = (int) $b->hi;
        $done = 0;
        for ($lo = $min; $lo <= $max; $lo += self::MATERIALIZE_CHUNK) {
            $hi = $lo + self::MATERIALIZE_CHUNK;   // exclusive upper bound
            $done += $this->materializeRange($lo, $hi);

            // The searchable alias index is rebuilt for the same slice, in the same
            // pass. identity-search reads gp_identity_alias rather than the aliases
            // JSON, so a materialize that refreshed only the JSON would leave the
            // endpoint answering from stale aliases. Same range bounds, so an
            // interrupted run resumes both consistently.
            $this->aliasIndexer->rebuildAll($lo, $hi - 1);

            if ($progress) {
                $progress($lo, $hi, $done);
            }
        }
    }

    /** Build gp_identity_profile rows for identity_id in [$lo, $hi). Returns rows written. */
    private function materializeRange(int $lo, int $hi): int
    {
        $hub = $this->hub();
        $jb = fn (string $e) => "CASE WHEN ($e) THEN CAST('true' AS JSON) ELSE CAST('false' AS JSON) END";
        $link = 'sp.system_id = l.system_id AND sp.source_table = l.source_table AND sp.source_id = l.source_id';
        // Chunk range predicates. $r for tables with an identity_id column,
        // $rL for gp_source_link-based subqueries (aliased l).
        $r = "identity_id >= $lo AND identity_id < $hi";
        $rL = "l.identity_id >= $lo AND l.identity_id < $hi";

        // Per-identity aggregate CTEs (each one row per identity_id), range-scoped.
        $lic = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('number',license_number,'state',certification_state,
                        'board',certification_board,'type',license_type,'registry',registry,
                        'verified',{$jb('is_verified=1')})) js
                FROM gp_license WHERE $r GROUP BY identity_id";

        $idt = "SELECT identity_id,
                    COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('type',id_type,'value',id_value)) js,
                    MAX(CASE WHEN id_type='dea' THEN id_value END) dea
                FROM (SELECT DISTINCT identity_id,id_type,id_value FROM gp_identity_identifier WHERE $r) u
                GROUP BY identity_id";

        $addr = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('type', IF(is_primary=1,'primary','alt'),
                        'address1',address1,'address2',address2,'city',city,'state',state,'zip',zip)) js
                 FROM gp_address WHERE $r GROUP BY identity_id";

        // primary address scalars: is_primary first, then lowest address_id.
        $prim = "SELECT identity_id, address1, city, state, zip FROM (
                    SELECT identity_id, address1, city, state, zip,
                        ROW_NUMBER() OVER (PARTITION BY identity_id ORDER BY is_primary DESC, address_id ASC) rn
                    FROM gp_address WHERE $r ) t WHERE rn = 1";

        $cred = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('credential_match_id',credential_match_id,'registry',registry,
                        'status',match_summary_status,'status_code',match_summary_status_code,
                        'valid',{$jb('match_is_valid=1')},'current',{$jb('source_current=1')},'link_state',link_state)) js
                 FROM gp_identity_credential WHERE $r GROUP BY identity_id";

        $excl = "SELECT identity_id, COUNT(*) cnt,
                    MAX(link_state <> 'rejected') act,
                    JSON_ARRAYAGG(JSON_OBJECT('match_id',match_id,'registry',registry,
                        'is_ssn_match',{$jb('is_ssn_match=1')},'is_npi_match',{$jb('is_npi_match=1')},
                        'is_canonical_name_match',{$jb('is_canonical_name_match=1')},
                        'is_license_number_match',{$jb('is_license_number_match=1')},'link_state',link_state)) js
                 FROM gp_identity_exclusion WHERE $r GROUP BY identity_id";

        $board = "SELECT identity_id, COUNT(*) cnt,
                    MAX(resolution_date IS NULL) act,
                    JSON_ARRAYAGG(JSON_OBJECT('registry',registry,'action_type',action_type,
                        'action_date',COALESCE(CAST(action_date AS CHAR),''),
                        'resolution_date',COALESCE(CAST(resolution_date AS CHAR),''))) js
                  FROM gp_board_action WHERE $r GROUP BY identity_id";

        $res = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('domain',domain,'target_key',target_key,'decision',decision,
                        'resolved_by',resolved_by,'resolved_at',COALESCE(CAST(resolved_at AS CHAR),''),
                        'auto_resolvable',{$jb('is_auto_resolvable=1')})) js
                 FROM gp_identity_resolution WHERE is_current = 1 AND $r GROUP BY identity_id";

        $src = "SELECT l.identity_id, COUNT(*) record_count, COUNT(DISTINCT l.system_id) system_count,
                    JSON_ARRAYAGG(JSON_OBJECT('system_code',COALESCE(ss.system_code,CAST(l.system_id AS CHAR)),
                        'source_table',l.source_table,'source_id',l.source_id,
                        'account_id', IF(l.account_id, l.account_id, NULL))) js
                FROM gp_source_link l
                LEFT JOIN gp_source_system ss ON ss.system_id = l.system_id
                WHERE $rL
                GROUP BY l.identity_id";

        $acct = "SELECT identity_id, COUNT(*) account_count, JSON_ARRAYAGG(account_id) js FROM (
                    SELECT DISTINCT identity_id, account_id FROM gp_source_link
                    WHERE account_id IS NOT NULL AND account_id <> 0 AND $r ) a
                 GROUP BY identity_id";

        $alias = "SELECT identity_id, JSON_ARRAYAGG(JSON_OBJECT('type',alias_type,'first',first_name,'last',last_name)) js
                  FROM (
                    SELECT DISTINCT l.identity_id, a.alias_type, a.first_name, a.last_name
                    FROM gp_source_link l
                    JOIN stg_person sp ON $link
                    JOIN stg_person_alias a ON a.stg_person_id = sp.stg_person_id
                    WHERE $rL ) u
                  GROUP BY identity_id";

        $term = "SELECT identity_id, termd FROM (
                    SELECT l.identity_id, sp.terminated AS termd,
                        ROW_NUMBER() OVER (PARTITION BY l.identity_id ORDER BY sp.source_modified DESC, sp.stg_person_id DESC) rn
                    FROM gp_source_link l JOIN stg_person sp ON $link WHERE $rL ) t WHERE rn = 1";

        $ssn4 = "SELECT identity_id, ssn_last_four FROM (
                    SELECT l.identity_id, sp.ssn_last_four,
                        ROW_NUMBER() OVER (PARTITION BY l.identity_id ORDER BY sp.stg_person_id ASC) rn
                    FROM gp_source_link l JOIN stg_person sp ON $link
                    WHERE sp.ssn_last_four IS NOT NULL AND $rL ) t WHERE rn = 1";

        // Idempotent per chunk: clear the slice, then rebuild it.
        $hub->statement("DELETE FROM gp_identity_profile WHERE $r");

        $hub->statement("
        INSERT INTO gp_identity_profile
            (identity_id, identity_uuid, first_name, middle_name, last_name, suffix, date_of_birth,
             ssn_hash, ssn_last_four, npi, upin, dea_number, identifier_count, identifiers,
             address1, city, state, zip, address_count, addresses, `terminated`,
             license_count, licenses, confidence, record_count, account_count, system_count,
             aliases, source_records, accounts, credential_count, credentials,
             exclusion_count, has_active_exclusion, exclusions,
             board_action_count, has_active_board_action, board_actions,
             resolution_count, resolutions, first_seen, last_updated, profile_built_at)
        SELECT
            i.identity_id, i.identity_uuid, i.canonical_first, i.canonical_middle, i.canonical_last,
            i.canonical_suffix, i.canonical_dob, i.ssn_hash, ssn4.ssn_last_four, i.npi, i.upin,
            COALESCE(NULLIF(i.dea_number,''), idt.dea) dea_number,
            COALESCE(idt.cnt,0), COALESCE(idt.js, JSON_ARRAY()),
            prim.address1, prim.city, prim.state, prim.zip,
            COALESCE(addr.cnt,0), COALESCE(addr.js, JSON_ARRAY()),
            term.termd,
            COALESCE(lic.cnt,0), COALESCE(lic.js, JSON_ARRAY()),
            i.confidence,
            COALESCE(src.record_count,0), COALESCE(acct.account_count,0), COALESCE(src.system_count,0),
            COALESCE(alias.js, JSON_ARRAY()), COALESCE(src.js, JSON_ARRAY()), COALESCE(acct.js, JSON_ARRAY()),
            COALESCE(cred.cnt,0), COALESCE(cred.js, JSON_ARRAY()),
            COALESCE(excl.cnt,0), COALESCE(excl.act,0), COALESCE(excl.js, JSON_ARRAY()),
            COALESCE(board.cnt,0), COALESCE(board.act,0), COALESCE(board.js, JSON_ARRAY()),
            COALESCE(res.cnt,0), COALESCE(res.js, JSON_ARRAY()),
            i.first_seen, i.last_updated, NOW()
        FROM gp_identity i
        LEFT JOIN ($lic) lic     ON lic.identity_id = i.identity_id
        LEFT JOIN ($idt) idt     ON idt.identity_id = i.identity_id
        LEFT JOIN ($addr) addr   ON addr.identity_id = i.identity_id
        LEFT JOIN ($prim) prim   ON prim.identity_id = i.identity_id
        LEFT JOIN ($cred) cred   ON cred.identity_id = i.identity_id
        LEFT JOIN ($excl) excl   ON excl.identity_id = i.identity_id
        LEFT JOIN ($board) board ON board.identity_id = i.identity_id
        LEFT JOIN ($res) res     ON res.identity_id = i.identity_id
        LEFT JOIN ($src) src     ON src.identity_id = i.identity_id
        LEFT JOIN ($acct) acct   ON acct.identity_id = i.identity_id
        LEFT JOIN ($alias) alias ON alias.identity_id = i.identity_id
        LEFT JOIN ($term) term   ON term.identity_id = i.identity_id
        LEFT JOIN ($ssn4) ssn4   ON ssn4.identity_id = i.identity_id
        WHERE i.identity_id >= $lo AND i.identity_id < $hi");

        return (int) $hub->selectOne("SELECT COUNT(*) c FROM gp_identity_profile WHERE $r")->c;
    }
}

<?php

namespace App\GoldenProfile\Materialize;

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

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    public function run(?callable $log = null): void
    {
        $log ??= fn ($p, $d) => null;
        $log('finalize', 'survivorship (set-based)');
        $this->survivorship();
        $log('finalize', 'materialize profiles (set-based)');
        $this->materialize();
    }

    // ---- 1. SURVIVORSHIP --------------------------------------------------

    /**
     * Per-field winner = highest field_authority (by source system_code), then
     * newest source_modified. Mirrors Resolution\Survivorship exactly, but as
     * one pass per field over every identity instead of per identity.
     */
    public function survivorship(): void
    {
        $hub = $this->hub();
        $rank = $this->authorityRankSql('ss');           // authority CASE over gp_source_system alias ss
        $names = array_keys(self::IDENTITY_FIELDS);
        $nameList = "'".implode("','", $names)."'";

        // Provenance is fully rebuilt for these identity fields (idempotent).
        $hub->statement("DELETE FROM gp_attribute WHERE attr_name IN ($nameList)");
        $hub->statement("DELETE FROM gp_survivorship_audit WHERE attribute_name IN ($nameList)");

        foreach (self::IDENTITY_FIELDS as $canonical => $srcCol) {
            // Ranked candidates for this field: non-blank staged values, best
            // authority then newest, link_id as a deterministic final tiebreak.
            $ranked = "
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

            // canonical winner -> gp_identity
            $hub->statement("
                UPDATE gp_identity i
                JOIN ( SELECT identity_id, v FROM ($ranked) r WHERE rn = 1 ) w
                  ON w.identity_id = i.identity_id
                SET i.`$canonical` = w.v");

            // every candidate -> gp_attribute (winner flagged is_canonical)
            $hub->statement("
                INSERT INTO gp_attribute (identity_id, attr_name, attr_value, source_link_id, is_canonical, observed_at)
                SELECT identity_id, '$canonical', LEFT(v, 255), link_id, IF(rn = 1, 1, 0), NOW()
                FROM ($ranked) r");

            // winner -> gp_survivorship_audit
            $hub->statement("
                INSERT INTO gp_survivorship_audit
                    (identity_id, attribute_name, surviving_value, system_id, source_link_id, rule_applied, decided_at)
                SELECT identity_id, '$canonical', LEFT(v, 500), system_id, link_id,
                       CONCAT('authority[', system_code, '] + recency'), NOW()
                FROM ($ranked) r WHERE rn = 1");
        }

        // record_count + last_updated (Survivorship folds these in per identity).
        $hub->statement("
            UPDATE gp_identity i
            JOIN ( SELECT identity_id, COUNT(*) c FROM gp_source_link GROUP BY identity_id ) k
              ON k.identity_id = i.identity_id
            SET i.record_count = k.c, i.last_updated = NOW()");
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
     * Rebuild gp_identity_profile for every identity in one INSERT…SELECT,
     * aggregating each child collection with JSON_ARRAYAGG. Same JSON shape and
     * scalar picks as Materialize\ProfileMaterializer::rebuild.
     */
    public function materialize(): void
    {
        $hub = $this->hub();
        $hub->statement('DELETE FROM gp_identity_profile');

        $jb = fn (string $e) => "CASE WHEN ($e) THEN CAST('true' AS JSON) ELSE CAST('false' AS JSON) END";
        $link = 'sp.system_id = l.system_id AND sp.source_table = l.source_table AND sp.source_id = l.source_id';

        // Per-identity aggregate CTEs (each one row per identity_id).
        $lic = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('number',license_number,'state',certification_state,
                        'board',certification_board,'type',license_type,'registry',registry,
                        'verified',{$jb('is_verified=1')})) js
                FROM gp_license GROUP BY identity_id";

        $idt = "SELECT identity_id,
                    COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('type',id_type,'value',id_value)) js,
                    MAX(CASE WHEN id_type='dea' THEN id_value END) dea
                FROM (SELECT DISTINCT identity_id,id_type,id_value FROM gp_identity_identifier) u
                GROUP BY identity_id";

        $addr = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('type', IF(is_primary=1,'primary','alt'),
                        'address1',address1,'address2',address2,'city',city,'state',state,'zip',zip)) js
                 FROM gp_address GROUP BY identity_id";

        // primary address scalars: is_primary first, then lowest address_id.
        $prim = "SELECT identity_id, address1, city, state, zip FROM (
                    SELECT identity_id, address1, city, state, zip,
                        ROW_NUMBER() OVER (PARTITION BY identity_id ORDER BY is_primary DESC, address_id ASC) rn
                    FROM gp_address ) t WHERE rn = 1";

        $cred = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('credential_match_id',credential_match_id,'registry',registry,
                        'status',match_summary_status,'status_code',match_summary_status_code,
                        'valid',{$jb('match_is_valid=1')},'current',{$jb('`current`=1')},'link_state',link_state)) js
                 FROM gp_identity_credential GROUP BY identity_id";

        $excl = "SELECT identity_id, COUNT(*) cnt,
                    MAX(link_state <> 'rejected') act,
                    JSON_ARRAYAGG(JSON_OBJECT('match_id',match_id,'registry',registry,
                        'is_ssn_match',{$jb('is_ssn_match=1')},'is_npi_match',{$jb('is_npi_match=1')},
                        'is_canonical_name_match',{$jb('is_canonical_name_match=1')},
                        'is_license_number_match',{$jb('is_license_number_match=1')},'link_state',link_state)) js
                 FROM gp_identity_exclusion GROUP BY identity_id";

        $board = "SELECT identity_id, COUNT(*) cnt,
                    MAX(resolution_date IS NULL) act,
                    JSON_ARRAYAGG(JSON_OBJECT('registry',registry,'action_type',action_type,
                        'action_date',COALESCE(CAST(action_date AS CHAR),''),
                        'resolution_date',COALESCE(CAST(resolution_date AS CHAR),''))) js
                  FROM gp_board_action GROUP BY identity_id";

        $res = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('domain',domain,'target_key',target_key,'decision',decision,
                        'resolved_by',resolved_by,'resolved_at',COALESCE(CAST(resolved_at AS CHAR),''),
                        'auto_resolvable',{$jb('is_auto_resolvable=1')})) js
                 FROM gp_identity_resolution WHERE is_current = 1 GROUP BY identity_id";

        $src = "SELECT l.identity_id, COUNT(*) record_count, COUNT(DISTINCT l.system_id) system_count,
                    JSON_ARRAYAGG(JSON_OBJECT('system_code',COALESCE(ss.system_code,CAST(l.system_id AS CHAR)),
                        'source_table',l.source_table,'source_id',l.source_id,
                        'account_id', IF(l.account_id, l.account_id, NULL))) js
                FROM gp_source_link l
                LEFT JOIN gp_source_system ss ON ss.system_id = l.system_id
                GROUP BY l.identity_id";

        $acct = "SELECT identity_id, COUNT(*) account_count, JSON_ARRAYAGG(account_id) js FROM (
                    SELECT DISTINCT identity_id, account_id FROM gp_source_link
                    WHERE account_id IS NOT NULL AND account_id <> 0 ) a
                 GROUP BY identity_id";

        $alias = "SELECT identity_id, JSON_ARRAYAGG(JSON_OBJECT('type',alias_type,'first',first_name,'last',last_name)) js
                  FROM (
                    SELECT DISTINCT l.identity_id, a.alias_type, a.first_name, a.last_name
                    FROM gp_source_link l
                    JOIN stg_person sp ON $link
                    JOIN stg_person_alias a ON a.stg_person_id = sp.stg_person_id ) u
                  GROUP BY identity_id";

        $term = "SELECT identity_id, termd FROM (
                    SELECT l.identity_id, sp.terminated AS termd,
                        ROW_NUMBER() OVER (PARTITION BY l.identity_id ORDER BY sp.source_modified DESC, sp.stg_person_id DESC) rn
                    FROM gp_source_link l JOIN stg_person sp ON $link ) t WHERE rn = 1";

        $ssn4 = "SELECT identity_id, ssn_last_four FROM (
                    SELECT l.identity_id, sp.ssn_last_four,
                        ROW_NUMBER() OVER (PARTITION BY l.identity_id ORDER BY sp.stg_person_id ASC) rn
                    FROM gp_source_link l JOIN stg_person sp ON $link
                    WHERE sp.ssn_last_four IS NOT NULL ) t WHERE rn = 1";

        $sql = "
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
        LEFT JOIN ($ssn4) ssn4   ON ssn4.identity_id = i.identity_id";

        $hub->statement($sql);
    }
}

<?php

namespace App\GoldenProfile;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use Illuminate\Support\Facades\DB;

/**
 * Set-based backfill (Mode 1, bulk). Instead of resolving 13.4M employees one
 * row at a time (~26 network round-trips each), it:
 *   1. STAGE  — bulk-copy employees into hub-local stg_person (+ children) and
 *      mirror credential_matches / matches / exclusion_records to the hub.
 *   2. RESOLVE — assign identities with a handful of in-database INSERT…SELECT
 *      statements, one create+link pair per deterministic key tier.
 *   3. ROLLUP  — credential/exclusion links as set-based joins.
 *   4. FINALIZE — reuse Engine::finalizeAll (survivorship + profile), shardable.
 *
 * Latency stops mattering: ~a dozen big statements instead of ~350M tiny ones.
 * The probabilistic Pass B is intentionally skipped here — on this single
 * source Pass A resolves every non-distinct record; Pass B otherwise just
 * creates a new identity, which the residual step does anyway.
 */
class SqlBackfill
{
    public const SYSTEM_CODE = 'streamline_local';

    public const SOURCE_TABLE = 'employees';

    private int $systemId;

    private StreamlineLocalConnector $connector;

    /** Single-column deterministic key tiers, in confidence order. */
    private const KEY_TIERS = ['ssn_hash', 'npi', 'upin', 'dea_number'];

    public function __construct()
    {
        $this->systemId = $this->ensureSystem();
        $this->connector = new StreamlineLocalConnector($this->systemId);
    }

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    private function src()
    {
        return DB::connection('streamline_local');
    }

    private function ensureSystem(): int
    {
        $hub = DB::connection('golden_profile');
        // insertOrIgnore is atomic — parallel workers constructing this class
        // concurrently won't collide on the system_code unique key.
        $hub->table('gp_source_system')->insertOrIgnore([
            'system_code' => self::SYSTEM_CODE, 'display_name' => 'StreamlineVerify local',
            'reliability_rank' => 50, 'is_active' => 1, 'added_at' => now(),
        ]);

        return (int) $hub->table('gp_source_system')->where('system_code', self::SYSTEM_CODE)->value('system_id');
    }

    /**
     * @param  array{fromId?:int,toId?:int,chunk?:int}  $opts
     * @param  callable|null  $log  fn(string $phase, string $detail)
     */
    public function run(array $opts = [], ?callable $log = null): array
    {
        $log ??= fn ($p, $d) => null;
        $staged = $this->stage($opts['fromId'] ?? null, $opts['toId'] ?? null, $opts['chunk'] ?? 5000, $log);
        $this->transform($log);
        $log('finalize', 'survivorship + materialize');
        (new Engine)->finalizeAll();

        return $this->counts() + ['staged' => $staged];
    }

    /**
     * Add indexes on the staged tier-key columns. Run ONCE after staging and
     * before transform: staging inserts stay fast (no index maintenance during
     * bulk load), while the resolve tiers' GROUP BY / NOT EXISTS become index
     * lookups instead of full scans over millions of rows.
     */
    public function indexStaging(?callable $log = null): void
    {
        $indexes = [
            'stg_ssn' => 'ssn_hash',
            'stg_npi' => 'npi',
            'stg_upin' => 'upin',
            'stg_dea' => 'dea_number',
            'stg_namedob' => 'last_name, first_name, date_of_birth',
        ];
        foreach ($indexes as $name => $cols) {
            $exists = $this->hub()->selectOne(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                ['stg_person', $name],
            );
            if (! $exists) {
                if ($log) {
                    $log('index', "stg_person($cols)");
                }
                $this->hub()->statement("ALTER TABLE stg_person ADD INDEX `$name` ($cols)");
            }
        }
    }

    /** Post-staging transform: resolve → enrich → dedup → rollup (single process). */
    public function transform(?callable $log = null): void
    {
        $log ??= fn ($p, $d) => null;
        $this->indexStaging($log);
        $log('resolve', 'deterministic tiers');
        $this->resolveDeterministic();
        $log('enrich', 'licenses + addresses + identifiers');
        $this->enrich();
        $log('dedup', 'merge duplicate identities');
        (new Engine)->dedup();
        $log('rollup', 'credentials + exclusions');
        $this->rollup();
    }

    public function counts(): array
    {
        return [
            'identities' => (int) $this->hub()->table('gp_identity')->count(),
            'links' => (int) $this->hub()->table('gp_source_link')->count(),
        ];
    }

    // ---- 1. STAGE ---------------------------------------------------------

    /**
     * Bulk-copy source rows into hub staging + mirror source tables. Returns rows staged.
     *
     * Resumable: after each chunk the max source id staged is checkpointed in
     * gp_watermark under this $segment. A stopped run re-invoked with the same
     * range/segment picks up just past the checkpoint instead of re-reading
     * everything from the source. Staging writes are idempotent (insertOrIgnore),
     * so re-covering the final in-flight chunk on resume is harmless.
     */
    public function stage(?int $fromId, ?int $toId, int $chunk, ?callable $log = null, string $segment = 'default'): int
    {
        $count = 0;
        $cursor = $this->stageCursor($segment);
        if ($cursor !== null) {
            $fromId = max((int) $fromId, $cursor + 1);
        }
        $q = $this->src()->table(self::SOURCE_TABLE)->orderBy('id');
        if ($fromId) {
            $q->where('id', '>=', $fromId);
        }
        if ($toId) {
            $q->where('id', '<=', $toId);
        }
        $q->chunkById($chunk, function ($rows) use (&$count, $log, $segment) {
            $accountMap = $this->accountMapFor($rows);

            // stg_person (bulk). insertOrIgnore keeps it idempotent on re-run.
            $persons = [];
            foreach ($rows as $emp) {
                $persons[] = $this->connector->personRow($emp, $accountMap);
            }
            $this->bulkInsert('stg_person', $persons);

            // resolve source_id -> stg_person_id for this chunk, attach children.
            $ids = $this->hub()->table('stg_person')
                ->where('system_id', $this->systemId)->where('source_table', self::SOURCE_TABLE)
                ->whereIn('source_id', $rows->pluck('id')->all())
                ->pluck('stg_person_id', 'source_id')->all();

            // employee_additional_info (EAV) for this chunk, grouped by employee.
            $aiByEmp = $this->src()->table('employee_additional_info')
                ->whereIn('employee_id', $rows->pluck('id')->all())
                ->where('value', '<>', '')
                ->get(['employee_id', 'name', 'value'])
                ->groupBy('employee_id');

            $aliases = $addresses = $licenses = $identifiers = [];
            foreach ($rows as $emp) {
                $sid = $ids[$emp->id] ?? null;
                if (! $sid) {
                    continue;
                }
                $c = $this->connector->childRows($emp);
                foreach ($c['aliases'] as $a) {
                    $aliases[] = $a + ['stg_person_id' => $sid];
                }
                foreach ($c['addresses'] as $a) {
                    $addresses[] = $a + ['stg_person_id' => $sid];
                }
                foreach ($c['licenses'] as $l) {
                    $licenses[] = $l + ['stg_person_id' => $sid];
                }
                // Additional-info: identifiers (DEA/MMIS) + extra licenses + business aliases.
                if (isset($aiByEmp[$emp->id])) {
                    $extra = $this->connector->additionalRows($aiByEmp[$emp->id]);
                    foreach ($extra['identifiers'] as $r) {
                        $identifiers[] = $r + ['stg_person_id' => $sid];
                    }
                    foreach ($extra['licenses'] as $l) {
                        $licenses[] = $l + ['stg_person_id' => $sid];
                    }
                    foreach ($extra['aliases'] as $a) {
                        $aliases[] = $a + ['stg_person_id' => $sid];
                    }
                }
            }
            // #6: one transaction per chunk for the child writes — a single
            // commit/flush instead of one per insert batch.
            $this->hub()->transaction(function () use ($aliases, $addresses, $licenses, $identifiers) {
                $this->bulkInsert('stg_person_alias', $aliases);
                $this->bulkInsert('stg_person_address', $addresses);
                $this->bulkInsert('stg_person_license', $licenses);
                $this->bulkInsert('stg_person_identifier', $identifiers);
            });

            $this->mirrorSource($rows->pluck('id')->all());

            // Checkpoint this stripe (rows ordered by id -> last = max staged).
            $this->setStageCursor((int) $rows->last()->id, $segment);
            $count += $rows->count();
            if ($log) {
                $log('stage', "staged $count [$segment]");
            }
        }, 'id');

        return $count;
    }

    // ---- staging resume cursors (per stripe, in gp_watermark) -------------

    private function stageKey(string $segment): string
    {
        return 'stg:'.$segment;
    }

    public function stageCursor(string $segment = 'default'): ?int
    {
        $v = $this->hub()->table('gp_watermark')
            ->where(['system_id' => $this->systemId, 'source_table' => $this->stageKey($segment)])
            ->value('high_water');

        return $v !== null ? (int) $v : null;
    }

    private function setStageCursor(int $id, string $segment): void
    {
        $this->hub()->table('gp_watermark')->updateOrInsert(
            ['system_id' => $this->systemId, 'source_table' => $this->stageKey($segment)],
            ['high_water' => (string) $id, 'updated_at' => now()],
        );
    }

    /** Drop all staging cursors — forces a fresh stage on the next run. */
    public function clearStageCursors(): void
    {
        $this->hub()->table('gp_watermark')
            ->where('system_id', $this->systemId)
            ->where('source_table', 'like', 'stg:%')
            ->delete();
    }

    private function accountMapFor($rows): array
    {
        $listIds = [];
        foreach ($rows as $emp) {
            if ($emp->employeelist_id) {
                $listIds[$emp->employeelist_id] = true;
            }
        }
        if (! $listIds) {
            return [];
        }

        return $this->src()->table('employeelists')->whereIn('id', array_keys($listIds))
            ->pluck('account_id', 'id')->all();
    }

    /**
     * Mirror credential_matches / matches / exclusion_records for these
     * employees. Read in small employee sub-batches with a plain indexed
     * whereIn(employee_id) — NO orderBy/offset paging, which on the ~600M-row
     * credential_matches forces a PK scan (80s+ per query). Column-pruned so
     * only needed fields cross the wire.
     */
    private function mirrorSource(array $employeeIds): void
    {
        // #3: filter excluded status codes at the SOURCE so pending/error/invalid
        // credential rows never cross the wire (they're dropped at rollup anyway).
        $excludeCodes = config('golden_profile.credential_search.rollup_exclude_status_codes', []);

        $recordIds = [];
        foreach (array_chunk($employeeIds, 500) as $empBatch) {
            $creds = $this->src()->table('credential_matches')->whereIn('employee_id', $empBatch)
                ->when($excludeCodes, fn ($q) => $q->whereNotIn('match_summary_status_code', $excludeCodes))
                ->get(['id', 'employee_id', 'registry', 'match_summary_status',
                    'match_summary_status_code', 'match_is_valid', 'current', 'date_resolved']);
            $this->bulkInsert('src_credential_match', $creds->map(fn ($c) => [
                'id' => $c->id, 'employee_id' => $c->employee_id, 'registry' => $c->registry,
                'match_summary_status' => $c->match_summary_status,
                'match_summary_status_code' => $c->match_summary_status_code,
                'match_is_valid' => $c->match_is_valid, 'current' => $c->current,
                'date_resolved' => $this->cleanDate($c->date_resolved),
            ])->all());

            $matches = $this->src()->table('matches')->whereIn('employee_id', $empBatch)
                ->get(['id', 'employee_id', 'exclusion_record_id', 'is_ssn_match', 'is_npi_match',
                    'is_canonical_name_match', 'is_upin_match', 'is_license_number_match']);
            $this->bulkInsert('src_match', $matches->map(fn ($m) => [
                'id' => $m->id, 'employee_id' => $m->employee_id, 'exclusion_record_id' => $m->exclusion_record_id,
                'is_ssn_match' => $m->is_ssn_match, 'is_npi_match' => $m->is_npi_match,
                'is_canonical_name_match' => $m->is_canonical_name_match, 'is_upin_match' => $m->is_upin_match,
                'is_license_number_match' => $m->is_license_number_match,
            ])->all());
            foreach ($matches->pluck('exclusion_record_id')->filter()->unique() as $rid) {
                $recordIds[$rid] = true;
            }
        }

        foreach (array_chunk(array_keys($recordIds), 500) as $ridBatch) {
            $recs = $this->src()->table('exclusion_records')->whereIn('id', $ridBatch)
                ->get(['id', 'exclusion_list_prefix']);
            $this->bulkInsert('src_exclusion_record', $recs->map(fn ($r) => [
                'id' => $r->id, 'exclusion_list_prefix' => $r->exclusion_list_prefix,
            ])->all());
        }
    }

    // ---- 2. RESOLVE (set-based) -------------------------------------------

    public function resolveDeterministic(): void
    {
        // Single-column key tiers, highest confidence first. After each tier we
        // backfill identity keys from the just-linked rows so a later tier sees
        // an earlier identity's secondary keys (mirrors row-by-row backfillKeys)
        // — without this, set-based tiers mint duplicate identities.
        foreach (self::KEY_TIERS as $col) {
            $this->tierCreate($col);
            $this->tierLink($col, $col);
            $this->backfillIdentityKeys();
        }
        // Name + DOB tier.
        $this->nameDobCreateAndLink();
        $this->backfillIdentityKeys();
        // Residual: any staged row still unlinked gets its own new identity.
        $this->residualCreateAndLink();
        // NB: license resolution is handled after enrich(), by dedup's
        // mergeByLicense — it needs gp_license populated, which enrich() does.
        // Doing it as a resolve tier would chicken-and-egg (a fresh license
        // identity has no linked row to match against yet).
    }

    /** Populate gp_license + gp_address from the staged children (set-based). */
    public function enrich(): void
    {
        $this->hub()->statement(
            "INSERT INTO gp_license
                (identity_id, license_number, certification_state, certification_board,
                 license_type, license_type_id, registry, is_verified, source_link_id)
             SELECT l.identity_id, spl.license_number, spl.certification_state, spl.certification_board,
                 MAX(spl.license_type), MAX(spl.license_type_id), MAX(spl.registry), 0, MIN(l.link_id)
             FROM stg_person_license spl
             JOIN stg_person sp ON sp.stg_person_id = spl.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spl.license_number, spl.certification_state, spl.certification_board
             ON DUPLICATE KEY UPDATE source_link_id=VALUES(source_link_id)",
            []
        );

        $this->hub()->statement(
            "INSERT INTO gp_address
                (identity_id, address1, address2, city, state, zip, is_primary, source_link_id)
             SELECT l.identity_id, spa.address1, MAX(spa.address2), spa.city, spa.state, spa.zip,
                 MAX(spa.address_type='primary'), MIN(l.link_id)
             FROM stg_person_address spa
             JOIN stg_person sp ON sp.stg_person_id = spa.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spa.address1, spa.city, spa.state, spa.zip
             ON DUPLICATE KEY UPDATE source_link_id=VALUES(source_link_id)",
            []
        );

        // Multi-valued identifiers (DEA, MMIS). dedup then merges identities
        // that share one — this is how DEA/MMIS act as match keys.
        $this->hub()->statement(
            "INSERT INTO gp_identity_identifier (identity_id, id_type, id_value, source_link_id)
             SELECT l.identity_id, spi.id_type, spi.id_value, MIN(l.link_id)
             FROM stg_person_identifier spi
             JOIN stg_person sp ON sp.stg_person_id = spi.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spi.id_type, spi.id_value
             ON DUPLICATE KEY UPDATE source_link_id=VALUES(source_link_id)",
            []
        );
    }

    /** Populate each active identity's null keys from its linked staged rows. */
    private function backfillIdentityKeys(): void
    {
        $this->hub()->statement(
            "UPDATE gp_identity i
             JOIN (
                 SELECT l.identity_id,
                        MAX(s.ssn_hash) ssn_hash, MAX(s.npi) npi, MAX(s.upin) upin, MAX(s.dea_number) dea_number,
                        MAX(s.date_of_birth) dob, MAX(s.first_name) fn, MAX(s.last_name) ln, MAX(s.middle_name) mn
                 FROM gp_source_link l
                 JOIN stg_person s ON s.system_id=l.system_id AND s.source_table=l.source_table AND s.source_id=l.source_id
                 GROUP BY l.identity_id
             ) k ON k.identity_id = i.identity_id
             SET i.ssn_hash=COALESCE(i.ssn_hash,k.ssn_hash), i.npi=COALESCE(i.npi,k.npi),
                 i.upin=COALESCE(i.upin,k.upin), i.dea_number=COALESCE(i.dea_number,k.dea_number),
                 i.canonical_dob=COALESCE(i.canonical_dob,k.dob), i.canonical_first=COALESCE(i.canonical_first,k.fn),
                 i.canonical_last=COALESCE(i.canonical_last,k.ln), i.canonical_middle=COALESCE(i.canonical_middle,k.mn)
             WHERE i.status='active'",
            []
        );
    }

    /** Create one identity per distinct new value of $col among unlinked rows. */
    private function tierCreate(string $col): void
    {
        $this->hub()->statement(
            "INSERT INTO gp_identity
                (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                 ssn_hash, npi, upin, dea_number, confidence, record_count, status, first_seen, last_updated)
             SELECT UUID(), r.first_name, r.middle_name, r.last_name, r.date_of_birth,
                 r.ssn_hash, r.npi, r.upin, r.dea_number, 1.0, 0, 'active', NOW(), NOW()
             FROM stg_person r
             JOIN (
                 SELECT MIN(s.stg_person_id) mid
                 FROM stg_person s
                 WHERE s.system_id = ? AND s.`$col` IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM gp_source_link l
                        WHERE l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id)
                   AND NOT EXISTS (SELECT 1 FROM gp_identity i WHERE i.`$col`=s.`$col` AND i.status='active')
                 GROUP BY s.`$col`
             ) f ON f.mid = r.stg_person_id",
            [$this->systemId]
        );
    }

    /** Link every unlinked row whose $col matches an active identity. */
    private function tierLink(string $col, string $keyName): void
    {
        $this->hub()->statement(
            "INSERT INTO gp_source_link
                (identity_id, system_id, source_table, source_id, account_id, employeelist_id,
                 match_method, match_key, match_score, match_state, is_pinned, linked_at)
             SELECT i.identity_id, s.system_id, s.source_table, s.source_id, s.account_id, s.employeelist_id,
                 'deterministic', ?, 0.99, 'auto_match', 0, NOW()
             FROM stg_person s
             JOIN (SELECT `$col` k, MIN(identity_id) identity_id FROM gp_identity
                   WHERE status='active' AND `$col` IS NOT NULL GROUP BY `$col`) i ON i.k = s.`$col`
             WHERE s.system_id = ? AND s.`$col` IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM gp_source_link l
                    WHERE l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id)",
            [$keyName, $this->systemId]
        );
    }

    /** Name + DOB tier (lowest-confidence deterministic key). */
    private function nameDobCreateAndLink(): void
    {
        $this->hub()->statement(
            "INSERT INTO gp_identity
                (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                 ssn_hash, npi, upin, dea_number, confidence, record_count, status, first_seen, last_updated)
             SELECT UUID(), r.first_name, r.middle_name, r.last_name, r.date_of_birth,
                 r.ssn_hash, r.npi, r.upin, r.dea_number, 1.0, 0, 'active', NOW(), NOW()
             FROM stg_person r
             JOIN (
                 SELECT MIN(s.stg_person_id) mid
                 FROM stg_person s
                 WHERE s.system_id = ? AND s.last_name IS NOT NULL AND s.first_name IS NOT NULL AND s.date_of_birth IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM gp_source_link l
                        WHERE l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id)
                   AND NOT EXISTS (SELECT 1 FROM gp_identity i WHERE i.status='active'
                        AND LOWER(i.canonical_last)=LOWER(s.last_name) AND LOWER(i.canonical_first)=LOWER(s.first_name)
                        AND i.canonical_dob=s.date_of_birth)
                 GROUP BY LOWER(s.last_name), LOWER(s.first_name), s.date_of_birth
             ) f ON f.mid = r.stg_person_id",
            [$this->systemId]
        );

        $this->hub()->statement(
            "INSERT INTO gp_source_link
                (identity_id, system_id, source_table, source_id, account_id, employeelist_id,
                 match_method, match_key, match_score, match_state, is_pinned, linked_at)
             SELECT i.identity_id, s.system_id, s.source_table, s.source_id, s.account_id, s.employeelist_id,
                 'deterministic', 'name_dob', 0.95, 'auto_match', 0, NOW()
             FROM stg_person s
             JOIN (SELECT LOWER(canonical_last) l, LOWER(canonical_first) f, canonical_dob d, MIN(identity_id) identity_id
                   FROM gp_identity WHERE status='active' AND canonical_last IS NOT NULL AND canonical_first IS NOT NULL AND canonical_dob IS NOT NULL
                   GROUP BY LOWER(canonical_last), LOWER(canonical_first), canonical_dob) i
                  ON i.l=LOWER(s.last_name) AND i.f=LOWER(s.first_name) AND i.d=s.date_of_birth
             WHERE s.system_id = ?
               AND NOT EXISTS (SELECT 1 FROM gp_source_link l
                    WHERE l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id)",
            [$this->systemId]
        );
    }

    /**
     * Any staged row still unlinked (no usable key) becomes its own identity.
     * Uses the unused merged_into column as a temporary stg_person_id carrier so
     * the 1:1 create + link stays fully set-based.
     */
    private function residualCreateAndLink(): void
    {
        $this->hub()->statement(
            "INSERT INTO gp_identity
                (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                 ssn_hash, npi, upin, dea_number, confidence, record_count, status, merged_into, first_seen, last_updated)
             SELECT UUID(), s.first_name, s.middle_name, s.last_name, s.date_of_birth,
                 s.ssn_hash, s.npi, s.upin, s.dea_number, 1.0, 0, 'active', s.stg_person_id, NOW(), NOW()
             FROM stg_person s
             WHERE s.system_id = ?
               AND NOT EXISTS (SELECT 1 FROM gp_source_link l
                    WHERE l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id)",
            [$this->systemId]
        );

        $this->hub()->statement(
            "INSERT INTO gp_source_link
                (identity_id, system_id, source_table, source_id, account_id, employeelist_id,
                 match_method, match_key, match_score, match_state, is_pinned, linked_at)
             SELECT i.identity_id, s.system_id, s.source_table, s.source_id, s.account_id, s.employeelist_id,
                 'deterministic', 'new', 1.0, 'auto_match', 0, NOW()
             FROM gp_identity i
             JOIN stg_person s ON s.stg_person_id = i.merged_into
             WHERE i.merged_into IS NOT NULL",
            []
        );

        $this->hub()->statement("UPDATE gp_identity SET merged_into = NULL WHERE merged_into IS NOT NULL", []);
    }

    // ---- 3. ROLLUP (set-based) --------------------------------------------

    public function rollup(): void
    {
        $sys = $this->systemId;
        $exclude = config('golden_profile.credential_search.rollup_exclude_status_codes', []);
        $excludeSql = $exclude ? 'AND c.match_summary_status_code NOT IN ('.implode(',', array_map('intval', $exclude)).')' : '';

        $this->hub()->statement(
            "INSERT INTO gp_identity_credential
                (system_id, credential_match_id, identity_id, registry, match_summary_status,
                 match_summary_status_code, match_is_valid, current, date_resolved, link_state)
             SELECT ?, c.id, l.identity_id, c.registry, c.match_summary_status,
                 c.match_summary_status_code, c.match_is_valid, c.current, c.date_resolved, 'confirmed'
             FROM src_credential_match c
             JOIN gp_source_link l ON l.system_id=? AND l.source_table='employees' AND l.source_id=c.employee_id
             WHERE 1=1 $excludeSql
             ON DUPLICATE KEY UPDATE identity_id=VALUES(identity_id), registry=VALUES(registry),
                 match_summary_status=VALUES(match_summary_status), match_summary_status_code=VALUES(match_summary_status_code),
                 match_is_valid=VALUES(match_is_valid), current=VALUES(current),
                 date_resolved=VALUES(date_resolved), link_state='confirmed'",
            [$sys, $sys]
        );

        $this->hub()->statement(
            "INSERT INTO gp_identity_exclusion
                (system_id, match_id, identity_id, exclusion_record_id, registry, is_ssn_match, is_npi_match,
                 is_canonical_name_match, is_upin_match, is_license_number_match, link_state)
             SELECT ?, m.id, l.identity_id, m.exclusion_record_id, er.exclusion_list_prefix,
                 m.is_ssn_match, m.is_npi_match, m.is_canonical_name_match, m.is_upin_match, m.is_license_number_match, 'candidate'
             FROM src_match m
             JOIN gp_source_link l ON l.system_id=? AND l.source_table='employees' AND l.source_id=m.employee_id
             LEFT JOIN src_exclusion_record er ON er.id = m.exclusion_record_id
             ON DUPLICATE KEY UPDATE identity_id=VALUES(identity_id), exclusion_record_id=VALUES(exclusion_record_id),
                 registry=VALUES(registry), is_ssn_match=VALUES(is_ssn_match), is_npi_match=VALUES(is_npi_match),
                 is_canonical_name_match=VALUES(is_canonical_name_match), is_upin_match=VALUES(is_upin_match),
                 is_license_number_match=VALUES(is_license_number_match), link_state='candidate'",
            [$sys, $sys]
        );
    }

    // ---- helpers ----------------------------------------------------------

    private function bulkInsert(string $table, array $rows): void
    {
        if (! $rows) {
            return;
        }
        // #6: largest multi-row insert that stays under MySQL's 65535-placeholder
        // limit — batch size scales to the row's column count.
        $cols = max(1, count((array) reset($rows)));
        $per = max(1, intdiv(60000, $cols));
        foreach (array_chunk($rows, $per) as $batch) {
            $this->hub()->table($table)->insertOrIgnore($batch);
        }
    }

    private function cleanDate(?string $v): ?string
    {
        if (! $v || str_starts_with((string) $v, '0000')) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($v)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}

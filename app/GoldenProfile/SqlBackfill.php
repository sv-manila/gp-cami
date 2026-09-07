<?php

namespace App\GoldenProfile;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use App\GoldenProfile\Support\JunkKeyGuard;
use App\GoldenProfile\Support\QuarantineRecorder;
use App\GoldenProfile\Support\SetVersionWriter;
use Illuminate\Support\Carbon;
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

    private JunkKeyGuard $junkGuard;

    private QuarantineRecorder $quarantine;

    /** Single-column deterministic key tiers, in confidence order. */
    private const KEY_TIERS = ['npi', 'upin', 'dea_number'];

    /** transaction() retry attempts for InnoDB deadlocks under parallel staging. */
    private const DEADLOCK_RETRIES = 5;

    public function __construct()
    {
        $this->systemId = $this->ensureSystem();
        $this->connector = new StreamlineLocalConnector($this->systemId);
        $this->junkGuard = new JunkKeyGuard;
        $this->quarantine = new QuarantineRecorder;
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
        // ALTER TABLE implicitly COMMITs. Adding a staging index while a caller
        // has a transaction open would commit it, so this is a no-op there — the
        // indexes exist to turn the resolve tiers' GROUP BY / NOT EXISTS into
        // index lookups over millions of rows, which a transactional caller does
        // not have.
        if ($this->hub()->transactionLevel() > 0) {
            return;
        }

        $indexes = [
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
        $this->resolveDeterministic($log);
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
            //
            // childRows() is computed once per row here and carried in
            // $childCache for the children loop below: the quarantine gate
            // needs each row's licences, and recomputing them a second time
            // would double that work across every staged row.
            // Fetched BEFORE the quarantine gate below, not after the stg_person
            // insert: the gate has to see each row's DEA/MMIS identifiers and
            // additional-info licences to judge whether it carries anything
            // resolvable. It depends only on $rows, so moving it up is safe.
            $aiByEmp = $this->src()->table('employee_additional_info')
                ->whereIn('employee_id', $rows->pluck('id')->all())
                ->where('value', '<>', '')
                ->get(['employee_id', 'name', 'value'])
                ->groupBy('employee_id');

            $persons = [];
            $quarantined = [];
            $childCache = [];
            $extraCache = [];
            foreach ($rows as $emp) {
                $row = $this->connector->personRow($emp, $accountMap);
                $childCache[$emp->id] = $this->connector->childRows($emp);
                $extraCache[$emp->id] = $this->connector->additionalRows(
                    $aiByEmp[$emp->id] ?? [], $emp->state ?? null
                );
                $licenses = array_merge(
                    $childCache[$emp->id]['licenses'], $extraCache[$emp->id]['licenses']
                );
                if ($this->shouldQuarantine($row, $licenses, $extraCache[$emp->id]['identifiers'])) {
                    $quarantined[$emp->id] = true;
                    $this->quarantine->record($this->systemId, self::SOURCE_TABLE, (int) $emp->id, 'no_identifying_data');

                    continue;
                }
                $persons[] = $row;
            }
            // Retry on deadlock: 16 workers doing concurrent INSERT IGNOREs take
            // insert-intention gap locks on stg_person's unique/PK indexes and
            // can deadlock even on disjoint id ranges. transaction($fn, N) re-runs
            // the closure on SQLSTATE 40001/1213. Idempotent, so re-running is safe.
            $this->hub()->transaction(fn () => $this->bulkInsert('stg_person', $persons), self::DEADLOCK_RETRIES);

            // resolve source_id -> stg_person_id for this chunk, attach children.
            $ids = $this->hub()->table('stg_person')
                ->where('system_id', $this->systemId)->where('source_table', self::SOURCE_TABLE)
                ->whereIn('source_id', $rows->pluck('id')->all())
                ->pluck('stg_person_id', 'source_id')->all();

            $aliases = $addresses = $licenses = $identifiers = [];
            foreach ($rows as $emp) {
                // Belt and braces. A quarantined row is absent from $persons, so
                // $ids has no entry for it on a first pass — but stage() is
                // idempotent and re-runs over rows an EARLIER pass staged before
                // this gate existed, where $ids WOULD resolve and the children
                // would be re-staged for a row now judged unusable.
                if (isset($quarantined[$emp->id])) {
                    continue;
                }
                $sid = $ids[$emp->id] ?? null;
                if (! $sid) {
                    continue;
                }
                $c = $childCache[$emp->id] ?? $this->connector->childRows($emp);
                foreach ($c['aliases'] as $a) {
                    $aliases[] = $a + ['stg_person_id' => $sid];
                }
                foreach ($c['addresses'] as $a) {
                    $addresses[] = $a + ['stg_person_id' => $sid];
                }
                foreach ($c['licenses'] as $l) {
                    $licenses[] = $l + ['stg_person_id' => $sid];
                }
                // Additional-info: identifiers (DEA/MMIS) + extra licenses +
                // business aliases. Already pivoted above for the quarantine
                // gate — reused rather than recomputed.
                $extra = $extraCache[$emp->id] ?? ['identifiers' => [], 'licenses' => [], 'aliases' => []];
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
            // #6: one transaction per chunk for the child writes — a single
            // commit/flush instead of one per insert batch. Deadlock-retried
            // (concurrent workers contend on the child tables' indexes).
            $this->hub()->transaction(function () use ($aliases, $addresses, $licenses, $identifiers) {
                $this->bulkInsert('stg_person_alias', $aliases);
                $this->bulkInsert('stg_person_address', $addresses);
                $this->bulkInsert('stg_person_license', $licenses);
                $this->bulkInsert('stg_person_identifier', $identifiers);
            }, self::DEADLOCK_RETRIES);

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
            $credRows = $creds->map(fn ($c) => [
                'id' => $c->id, 'employee_id' => $c->employee_id, 'registry' => $c->registry,
                'match_summary_status' => $c->match_summary_status,
                'match_summary_status_code' => $c->match_summary_status_code,
                'match_is_valid' => $c->match_is_valid, 'current' => $c->current,
                'date_resolved' => $this->cleanDate($c->date_resolved),
            ])->all();
            $this->hub()->transaction(fn () => $this->bulkInsert('src_credential_match', $credRows), self::DEADLOCK_RETRIES);

            $matches = $this->src()->table('matches')->whereIn('employee_id', $empBatch)
                ->get(['id', 'employee_id', 'exclusion_record_id', 'is_ssn_match', 'is_npi_match',
                    'is_canonical_name_match', 'is_upin_match', 'is_license_number_match']);
            $matchRows = $matches->map(fn ($m) => [
                'id' => $m->id, 'employee_id' => $m->employee_id, 'exclusion_record_id' => $m->exclusion_record_id,
                'is_ssn_match' => $m->is_ssn_match, 'is_npi_match' => $m->is_npi_match,
                'is_canonical_name_match' => $m->is_canonical_name_match, 'is_upin_match' => $m->is_upin_match,
                'is_license_number_match' => $m->is_license_number_match,
            ])->all();
            $this->hub()->transaction(fn () => $this->bulkInsert('src_match', $matchRows), self::DEADLOCK_RETRIES);
            foreach ($matches->pluck('exclusion_record_id')->filter()->unique() as $rid) {
                $recordIds[$rid] = true;
            }
        }

        foreach (array_chunk(array_keys($recordIds), 500) as $ridBatch) {
            $recs = $this->src()->table('exclusion_records')->whereIn('id', $ridBatch)
                ->get(['id', 'exclusion_list_prefix']);
            $recRows = $recs->map(fn ($r) => [
                'id' => $r->id, 'exclusion_list_prefix' => $r->exclusion_list_prefix,
            ])->all();
            $this->hub()->transaction(fn () => $this->bulkInsert('src_exclusion_record', $recRows), self::DEADLOCK_RETRIES);
        }
    }

    // ---- 2. RESOLVE (set-based) -------------------------------------------

    public function resolveDeterministic(?callable $log = null): void
    {
        $log ??= fn ($p, $d) => null;

        // There is no ssn_hash tier and therefore no filler-SSN blocklist to build.
        // Both existed because ssn_hash was an exact 0.99 key with no name or DOB
        // cross-check, so every person carrying a placeholder SSN hashed to the same
        // value and the tier bound them all to one identity. The tier is gone
        // (Delivery Checklist §1), so the guard has nothing left to guard.

        // The npi tier keeps its screen — a Luhn-valid value reused as filler across
        // unrelated people is invisible to NpiValidator and would bind every
        // one of them at 0.99. Materialised here so the tier SQL can anti-join
        // a table instead of threading a NOT IN list through every statement.
        $npiBlocked = $this->junkGuard->buildBlocklistTable('npi');
        $log('resolve', "npi junk blocklist: $npiBlocked value(s) excluded");

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

    /**
     * Same rule QuarantineRecorder::evaluate() applies per-row. stage() already
     * holds its rows as plain arrays at exactly the point this needs to run, so
     * this delegates rather than duplicating the five-condition check —
     * QuarantineRecorder::evaluate() stays the single source of truth.
     */
    private function shouldQuarantine(array $personRow, array $licenses, array $identifiers = []): bool
    {
        return $this->quarantine->evaluate($personRow, $licenses, $identifiers) !== null;
    }

    /**
     * Populate gp_license + gp_address + gp_identity_identifier from the staged
     * children (set-based).
     *
     * Each statement used to be INSERT … ON DUPLICATE KEY UPDATE
     * source_link_id=VALUES(source_link_id). Neither half of that survives SCD-2:
     *
     *   - the ON DUPLICATE KEY target is gone. uq_lic, uq_addr and
     *     uq_identity_identifier now END IN version_no
     *     (2026_09_04_000100_add_scd2_versioning), so a re-observation does not
     *     collide with the existing row — it inserts a duplicate.
     *   - the UPDATE half overwrote a golden fact in place, which is what this
     *     programme exists to stop, and it rewrote source_link_id, which is
     *     onCreate: it records which source row ESTABLISHED the fact, so treating
     *     it as an attribute would mint a version every time a second account's
     *     employee row re-observed the same licence (thousands of identical
     *     versions on identity 3, which folds 12,463 source rows).
     *
     * So each becomes: build the incoming set into a scratch table, hand it to
     * SetVersionWriter::write(), which compares it against the current version per
     * natural key and flips-and-inserts only what differs. The per-row counterpart
     * (DeterministicResolver::enrich()) makes the same decision through
     * Versioner::write().
     *
     * is_verified IS DELIBERATELY ABSENT from the licence statement, where it used
     * to be a literal 0. The per-row path never passes it, so under versioning the
     * literal would reset a verified licence AND mint a version recording the
     * reset, on every bulk run. Omitted, a new row takes the column default (0, the
     * same value) and an existing one keeps what it has.
     *
     * The GROUP BYs are unchanged, and so is the fact that they pick MAX() where the
     * per-row path takes the last observation. That divergence predates SCD-2 and is
     * left alone — see the divergence table in docs/SCD2.md.
     */
    public function enrich(): void
    {
        $hub = $this->hub();
        $writer = new SetVersionWriter;

        // gp_license
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_license');
        $hub->statement(
            'CREATE TEMPORARY TABLE tmp_enrich_license
                (INDEX idx_key (identity_id, license_number, certification_state, certification_board))
             ENGINE=InnoDB AS
             SELECT l.identity_id, spl.license_number, spl.certification_state, spl.certification_board,
                 MAX(spl.license_type) AS license_type, MAX(spl.license_type_id) AS license_type_id,
                 MAX(spl.registry) AS registry, MIN(l.link_id) AS source_link_id
             FROM stg_person_license spl
             JOIN stg_person sp ON sp.stg_person_id = spl.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spl.license_number, spl.certification_state, spl.certification_board'
        );
        $writer->write('gp_license', 'tmp_enrich_license', ['license_type', 'license_type_id', 'registry']);
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_license');

        // gp_address
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_address');
        $hub->statement(
            "CREATE TEMPORARY TABLE tmp_enrich_address
                (INDEX idx_key (identity_id, address1, city, state, zip))
             ENGINE=InnoDB AS
             SELECT l.identity_id, spa.address1, spa.city, spa.state, spa.zip,
                 MAX(spa.address2) AS address2,
                 MAX(spa.address_type='primary') AS is_primary,
                 MIN(l.link_id) AS source_link_id
             FROM stg_person_address spa
             JOIN stg_person sp ON sp.stg_person_id = spa.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spa.address1, spa.city, spa.state, spa.zip"
        );
        $writer->write('gp_address', 'tmp_enrich_address', ['address2', 'is_primary']);
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_address');

        // gp_identity_identifier. Multi-valued identifiers (DEA, MMIS); dedup then
        // merges identities that share one, which is how DEA/MMIS act as match keys.
        //
        // state is the one compared attribute — plan 5 put it there deliberately,
        // because the 2026_09_05_000000 migration left it out of the unique key, so
        // two sources disagreeing about which state issued an MMIS number is a
        // versionable change rather than part of the row's identity.
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_identifier');
        $hub->statement(
            'CREATE TEMPORARY TABLE tmp_enrich_identifier
                (INDEX idx_key (identity_id, id_type, id_value))
             ENGINE=InnoDB AS
             SELECT l.identity_id, spi.id_type, spi.id_value,
                 MAX(spi.state) AS state, MIN(l.link_id) AS source_link_id
             FROM stg_person_identifier spi
             JOIN stg_person sp ON sp.stg_person_id = spi.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spi.id_type, spi.id_value'
        );
        $writer->write('gp_identity_identifier', 'tmp_enrich_identifier', ['state']);
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_identifier');
    }

    /** Populate each active identity's null keys from its linked staged rows. */
    /**
     * Populate each active identity's null keys from its linked staged rows.
     *
     * This used to be one UPDATE … JOIN with SET col = COALESCE(i.col, k.col).
     * Supplying a key an identity did not have is a change to a golden fact, so
     * under the SCD-2 rule it is a NEW VERSION (Data Flow by CAMI: "insert a new
     * row with current = 1, and set all preexisting rows to current = 0"), and
     * supplying nothing must be no version at all. The per-row counterpart,
     * DeterministicResolver::backfillKeys(), makes exactly that decision through
     * Versioner::write(); this makes it for the whole set through
     * SetVersionWriter::writeIdentities().
     *
     * The proposals table holds NULL for "nothing to add", which is
     * VersionerSql::differsOnPresent()'s absent-column semantics and matches
     * backfillKeys() skipping a column it has nothing for. The IF(i.col IS NULL, …)
     * wrapper is what produces that NULL: a column the identity already has
     * proposes nothing, so it can never be a change and can never be overwritten.
     *
     * TWO DIVERGENCES FROM THE PER-ROW PATH ARE PRE-EXISTING AND LEFT ALONE, because
     * closing either would change which records match and this plan asserts the eval
     * gate does not move (see docs/SCD2.md):
     *
     *   - backfillKeys() tests emptiness with PHP empty(), so '' and '0' count as
     *     missing; IF(… IS NULL) only treats NULL as missing.
     *   - backfillKeys() uses the value from the row being resolved; this uses
     *     MAX() across every linked row.
     *
     * A THIRD was owned by plan 2 and is now closed by deletion: backfillKeys()
     * refused to promote a filler ssn_hash onto an identity that lacked one, and
     * this statement had no blocklist screen at all. Plan 2 removed the ssn_hash
     * tier and both paths stopped carrying the column, so the divergence has no
     * column left to differ over. The npi divergence it was grouped with is real
     * and still open: backfillKeys() screens a junk npi here, this does not.
     *
     * WHY THIS STILL RUNS BETWEEN EVERY TIER. Its call site explains it: "after each
     * tier we backfill identity keys from the just-linked rows so a later tier sees
     * an earlier identity's secondary keys — without this, set-based tiers mint
     * duplicate identities." Versioning it means each of those calls can mint a
     * version, but only for identities that genuinely gained a key on that pass, and
     * a key can only be gained once. The worst case is one version per identity per
     * key it was missing — bounded by the number of key columns, not by the number
     * of source rows.
     */
    private function backfillIdentityKeys(): void
    {
        $hub = $this->hub();

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_backfill_keys');
        $hub->statement('CREATE TEMPORARY TABLE tmp_backfill_keys (INDEX idx_id (identity_id)) ENGINE=InnoDB AS
            SELECT i.identity_id,
                   IF(i.npi             IS NULL, k.npi,        NULL) AS npi,
                   IF(i.upin            IS NULL, k.upin,       NULL) AS upin,
                   IF(i.dea_number      IS NULL, k.dea_number, NULL) AS dea_number,
                   IF(i.canonical_dob   IS NULL, k.dob,        NULL) AS canonical_dob,
                   IF(i.canonical_first IS NULL, k.fn,         NULL) AS canonical_first,
                   IF(i.canonical_last  IS NULL, k.ln,         NULL) AS canonical_last,
                   IF(i.canonical_middle IS NULL, k.mn,        NULL) AS canonical_middle
            FROM gp_identity i
            JOIN (
                SELECT l.identity_id,
                       MAX(s.npi) npi, MAX(s.upin) upin, MAX(s.dea_number) dea_number,
                       MAX(s.date_of_birth) dob, MAX(s.first_name) fn, MAX(s.last_name) ln, MAX(s.middle_name) mn
                FROM gp_source_link l
                JOIN stg_person s ON s.system_id=l.system_id AND s.source_table=l.source_table AND s.source_id=l.source_id
                GROUP BY l.identity_id
            ) k ON k.identity_id = i.identity_id
            WHERE i.status = \'active\' AND i.`current` = 1');

        (new SetVersionWriter)->writeIdentities('tmp_backfill_keys', [
            'npi', 'upin', 'dea_number',
            'canonical_dob', 'canonical_first', 'canonical_last', 'canonical_middle',
        ]);

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_backfill_keys');
    }

    /** Create one identity per distinct new value of $col among unlinked rows. */
    private function tierCreate(string $col): void
    {
        // npi: skip rows whose value is on the filler blocklist so they fall
        // through to the weaker-but-safe name+dob / residual tiers instead of all
        // collapsing onto one identity. ssn_hash had the same screen and both are
        // gone with the tier.
        $guard = match ($col) {
            'npi' => $this->junkGuard->exclusionSql('npi', 's.`npi`'),
            default => '',
        };

        $this->hub()->statement(
            "INSERT INTO gp_identity
                (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                 npi, upin, dea_number, confidence, record_count, status,
                 version_no, `current`, first_seen, last_updated)
             SELECT UUID(), r.first_name, r.middle_name, r.last_name, r.date_of_birth,
                 r.npi, r.upin, r.dea_number, 1.0, 0, 'active',
                 1, 1, NOW(), NOW()
             FROM stg_person r
             JOIN (
                 SELECT MIN(s.stg_person_id) mid
                 FROM stg_person s
                 LEFT JOIN gp_source_link l
                   ON l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id
                 LEFT JOIN (SELECT `$col` k FROM gp_identity
                            WHERE status='active' AND `current` = 1 AND `$col` IS NOT NULL
                            GROUP BY `$col`) gi
                   ON gi.k = s.`$col`
                 WHERE s.system_id = ? AND s.`$col` IS NOT NULL
                   AND l.link_id IS NULL     -- not yet linked (anti-join)
                   AND gi.k IS NULL          -- no active identity has this key yet (anti-join)
                   $guard
                 GROUP BY s.`$col`
             ) f ON f.mid = r.stg_person_id",
            [$this->systemId]
        );
    }

    /** Link every unlinked row whose $col matches an active identity. */
    private function tierLink(string $col, string $keyName): void
    {
        // Same filler screen as tierCreate — see there.
        $guard = match ($col) {
            'npi' => $this->junkGuard->exclusionSql('npi', 's.`npi`'),
            default => '',
        };

        $this->hub()->statement(
            "INSERT INTO gp_source_link
                (identity_id, system_id, source_table, source_id, account_id, employeelist_id,
                 match_method, match_key, match_score, match_state, is_pinned, linked_at)
             SELECT i.identity_id, s.system_id, s.source_table, s.source_id, s.account_id, s.employeelist_id,
                 'deterministic', ?, 0.99, 'auto_match', 0, NOW()
             FROM stg_person s
             JOIN (SELECT `$col` k, MIN(identity_id) identity_id FROM gp_identity
                   WHERE status='active' AND `current` = 1 AND `$col` IS NOT NULL
                   GROUP BY `$col`) i ON i.k = s.`$col`
             WHERE s.system_id = ? AND s.`$col` IS NOT NULL
               $guard
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
                 npi, upin, dea_number, confidence, record_count, status,
                 version_no, `current`, first_seen, last_updated)
             SELECT UUID(), r.first_name, r.middle_name, r.last_name, r.date_of_birth,
                 r.npi, r.upin, r.dea_number, 1.0, 0, 'active',
                 1, 1, NOW(), NOW()
             FROM stg_person r
             JOIN (
                 SELECT MIN(s.stg_person_id) mid
                 FROM stg_person s
                 LEFT JOIN gp_source_link l
                   ON l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id
                 LEFT JOIN (SELECT canonical_last l, canonical_first f, canonical_dob d
                            FROM gp_identity WHERE status='active' AND `current` = 1
                              AND canonical_last IS NOT NULL AND canonical_first IS NOT NULL AND canonical_dob IS NOT NULL
                            GROUP BY canonical_last, canonical_first, canonical_dob) gi
                   ON gi.l=s.last_name AND gi.f=s.first_name AND gi.d=s.date_of_birth
                 WHERE s.system_id = ? AND s.last_name IS NOT NULL AND s.first_name IS NOT NULL AND s.date_of_birth IS NOT NULL
                   AND l.link_id IS NULL     -- not yet linked (anti-join)
                   AND gi.l IS NULL          -- no active identity with this name+dob yet (anti-join)
                 GROUP BY s.last_name, s.first_name, s.date_of_birth
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
             JOIN (SELECT canonical_last l, canonical_first f, canonical_dob d, MIN(identity_id) identity_id
                   FROM gp_identity WHERE status='active' AND `current` = 1 AND canonical_last IS NOT NULL AND canonical_first IS NOT NULL AND canonical_dob IS NOT NULL
                   GROUP BY canonical_last, canonical_first, canonical_dob) i
                  ON i.l=s.last_name AND i.f=s.first_name AND i.d=s.date_of_birth
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
        // This is the single biggest insert — one identity per still-unlinked
        // staged row (potentially millions). Drop gp_identity's key indexes for
        // the duration so the bulk insert doesn't maintain 5 secondary indexes
        // per row; the earlier key tiers already finished (they needed them),
        // and dedup (which needs them) runs after, so we rebuild before returning.
        $this->withoutIdentityKeyIndexes(function () {
            $this->residualBody();
        });
    }

    /**
     * The residual create-and-link statements, extracted so
     * withoutIdentityKeyIndexes() can wrap them.
     *
     * The 1:1 create-then-link stays fully set-based by carrying stg_person_id out
     * of the identity INSERT in gp_identity.stg_seed_id
     * (2026_09_04_000200_add_stg_seed_id_to_gp_identity) and joining back on it.
     * That used to be merged_into; 2026_09_04_000100_add_scd2_versioning made
     * merged_into a golden attribute, so borrowing it would write a stg_person_id
     * into a golden field and mint two versions per identity doing it — see the
     * migration's docblock.
     *
     * version_no and current are stated explicitly even though the column defaults
     * would produce them. The per-row counterpart
     * (DeterministicResolver::createIdentity) does the same, for the same reason: a
     * reader of this statement should not have to open the migration to know which
     * version it produces.
     */
    private function residualBody(): void
    {
        // Anti-join (LEFT JOIN … link_id IS NULL) instead of a correlated NOT EXISTS.
        $this->hub()->statement(
            "INSERT INTO gp_identity
                (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                 npi, upin, dea_number, confidence, record_count, status, stg_seed_id,
                 version_no, `current`, first_seen, last_updated)
             SELECT UUID(), s.first_name, s.middle_name, s.last_name, s.date_of_birth,
                 s.npi, s.upin, s.dea_number, 1.0, 0, 'active', s.stg_person_id,
                 1, 1, NOW(), NOW()
             FROM stg_person s
             LEFT JOIN gp_source_link l
               ON l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id
             WHERE s.system_id = ? AND l.link_id IS NULL",
            [$this->systemId]
        );

        // i.current = 1 is redundant on rows this method just minted, and kept
        // anyway: a re-run after a partial failure would otherwise be able to join
        // a superseded version that still carried a stale seed.
        $this->hub()->statement(
            "INSERT INTO gp_source_link
                (identity_id, system_id, source_table, source_id, account_id, employeelist_id,
                 match_method, match_key, match_score, match_state, is_pinned, linked_at)
             SELECT i.identity_id, s.system_id, s.source_table, s.source_id, s.account_id, s.employeelist_id,
                 'deterministic', 'new', 1.0, 'auto_match', 0, NOW()
             FROM gp_identity i
             JOIN stg_person s ON s.stg_person_id = i.stg_seed_id
             WHERE i.stg_seed_id IS NOT NULL AND i.`current` = 1",
            []
        );

        // A plain UPDATE, not a versioned write, and that is correct: stg_seed_id is
        // absent from Versioner::TABLES, so it is not a golden fact and changing it
        // is not a change to the identity. That is the whole point of giving it its
        // own column.
        $this->hub()->statement(
            'UPDATE gp_identity SET stg_seed_id = NULL WHERE stg_seed_id IS NOT NULL',
            []
        );
    }

    /** gp_identity key indexes, dropped during the residual bulk insert and rebuilt after. */
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
        // Still listed, and deliberately: plan 2 Task 3 said to drop it here
        // because "the column goes in the Task 7 migration" — but Task 7 is four
        // tasks later, and until it runs the migration still creates idx_ssn. A
        // definition missing from this constant is not a no-op: this path DROPS
        // every gp_identity key index and re-ADDs only what is listed here, so
        // omitting idx_ssn would have the bulk path silently revert the migration.
        // Goes when the column goes.
        'idx_ssn' => 'ssn_hash, `current`',
        'idx_npi' => 'npi, `current`',
        'idx_upin' => 'upin, `current`',
        'idx_dea' => 'dea_number, `current`',
        'idx_name_dob' => 'canonical_last, canonical_first, canonical_dob, `current`',
    ];

    /**
     * Run $fn with gp_identity's five key indexes dropped, then rebuilt.
     *
     * residualCreateAndLink()'s insert is the single biggest in the pipeline
     * — one identity per still-unlinked staged row, potentially millions — and
     * after 2026_09_04_000100_add_scd2_versioning
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

    // ---- 3. ROLLUP (set-based) --------------------------------------------

    /**
     * credential_matches / matches -> gp_identity_credential + gp_identity_exclusion
     * (set-based, from the mirrored src_* transport buffers).
     *
     * Both statements used to end in ON DUPLICATE KEY UPDATE, including
     * identity_id=VALUES(identity_id). Two changes, for two different reasons:
     *
     *   - the upsert becomes a versioned write. Both tables' primary keys now end
     *     in version_no (2026_09_04_000100_add_scd2_versioning), so ON DUPLICATE
     *     KEY no longer matches an existing row at all — and where it still would,
     *     on uq_cred_current, the UPDATE half would overwrite a golden fact in
     *     place. A credential whose status, validity or CAMI currency flag moved is
     *     now superseded; one that came back identical produces nothing, which
     *     matters because sync re-reads every credential of every changed employee
     *     on every run.
     *   - identity_id STOPS BEING REPOINTED HERE. 3a made it onCreate: repointing on
     *     a merge is a GROUPING change, recorded in gp_resolution_log and on the
     *     merged identity's own final version, and versioning it would mint one row
     *     per credential per merge — 397,170 for identity 3 alone.
     *     Engine::applyMerge() owns the repoint, as a bulk UPDATE across all
     *     versions where no unique can collide.
     *
     * link_confidence is in Versioner's attribute list for both tables and is passed
     * by no path, per-row or set-based, so it carries forward. Do not "fix" that by
     * writing a float into it: a DECIMAL(5,4) round-trips as '0.9900' and
     * Versioner::same() would then report a change on every write forever.
     *
     * ONE DUPLICATE-ROW HAZARD the scratch tables introduce, and why it is safe:
     * SetVersionWriter::write() requires exactly one row per natural key. Both
     * builds satisfy that because credential_match_id / match_id is the source's
     * primary key and gp_source_link's uq_source(system_id, source_table,
     * source_id) means the join to l cannot fan out. If a future change makes
     * either fan out, the symptom is a duplicate-key error from uq_cred_current on
     * the insert — loud, immediate, and the reason 3a built that index.
     */
    public function rollup(): void
    {
        $hub = $this->hub();
        $sys = $this->systemId;
        $writer = new SetVersionWriter;

        $exclude = config('golden_profile.credential_search.rollup_exclude_status_codes', []);
        $excludeSql = $exclude
            ? 'AND c.match_summary_status_code NOT IN ('.implode(',', array_map('intval', $exclude)).')'
            : '';

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_rollup_credential');
        $hub->statement(
            "CREATE TEMPORARY TABLE tmp_rollup_credential
                (INDEX idx_key (system_id, credential_match_id))
             ENGINE=InnoDB AS
             SELECT ? AS system_id, c.id AS credential_match_id, l.identity_id,
                 c.registry, c.match_summary_status, c.match_summary_status_code,
                 c.match_is_valid, c.`current` AS source_current, c.date_resolved,
                 'confirmed' AS link_state
             FROM src_credential_match c
             JOIN gp_source_link l ON l.system_id=? AND l.source_table='employees' AND l.source_id=c.employee_id
             WHERE 1=1 $excludeSql",
            [$sys, $sys]
        );
        $writer->write('gp_identity_credential', 'tmp_rollup_credential', [
            'registry', 'match_summary_status', 'match_summary_status_code',
            'match_is_valid', 'source_current', 'date_resolved', 'link_state',
        ]);
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_rollup_credential');

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_rollup_exclusion');
        $hub->statement(
            "CREATE TEMPORARY TABLE tmp_rollup_exclusion
                (INDEX idx_key (system_id, match_id))
             ENGINE=InnoDB AS
             SELECT ? AS system_id, m.id AS match_id, l.identity_id,
                 m.exclusion_record_id, er.exclusion_list_prefix AS registry,
                 m.is_ssn_match, m.is_npi_match, m.is_canonical_name_match,
                 m.is_upin_match, m.is_license_number_match,
                 'candidate' AS link_state
             FROM src_match m
             JOIN gp_source_link l ON l.system_id=? AND l.source_table='employees' AND l.source_id=m.employee_id
             LEFT JOIN src_exclusion_record er ON er.id = m.exclusion_record_id",
            [$sys, $sys]
        );
        $writer->write('gp_identity_exclusion', 'tmp_rollup_exclusion', [
            'exclusion_record_id', 'registry', 'is_ssn_match', 'is_npi_match',
            'is_canonical_name_match', 'is_upin_match', 'is_license_number_match', 'link_state',
        ]);
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_rollup_exclusion');
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
            return Carbon::parse($v)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}

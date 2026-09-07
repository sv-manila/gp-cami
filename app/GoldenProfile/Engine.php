<?php

namespace App\GoldenProfile;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
use App\GoldenProfile\Support\JunkKeyGuard;
use App\GoldenProfile\Support\SsnHashGuard;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates one source through the pipeline: ingest -> resolve -> roll up
 * credentials/exclusions -> materialize profile. Mode 1 (backfill) and
 * Mode 2 (incremental sync by watermark) share the same per-row logic.
 */
class Engine
{
    public const SYSTEM_CODE = 'streamline_local';

    public const SOURCE_TABLE = 'employees';

    private int $systemId;

    private StreamlineLocalConnector $connector;

    private DeterministicResolver $resolver;

    private ProfileMaterializer $materializer;

    private Survivorship $survivorship;

    private SsnHashGuard $ssnGuard;

    private JunkKeyGuard $junkGuard;

    private Versioner $versioner;

    public function __construct()
    {
        $this->systemId = $this->ensureSystem();
        $this->connector = new StreamlineLocalConnector($this->systemId);
        $this->resolver = new DeterministicResolver($this->systemId);
        $this->materializer = new ProfileMaterializer;
        $this->survivorship = new Survivorship;
        $this->ssnGuard = new SsnHashGuard;
        $this->junkGuard = new JunkKeyGuard;
        $this->versioner = new Versioner;
    }

    /** Per affected identity: recompute survivorship winners, then rebuild the profile. */
    private function finalize(array $identityIds): void
    {
        foreach (array_keys($identityIds) as $identityId) {
            $this->survivorship->recompute((int) $identityId);
            $this->materializer->rebuild((int) $identityId);
        }
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
        // insertOrIgnore is atomic — parallel finalize shards constructing this
        // class concurrently won't collide on the system_code unique key.
        $hub->table('gp_source_system')->insertOrIgnore([
            'system_code' => self::SYSTEM_CODE, 'display_name' => 'StreamlineVerify local',
            'reliability_rank' => 50, 'is_active' => 1, 'added_at' => now(),
        ]);

        return (int) $hub->table('gp_source_system')->where('system_code', self::SYSTEM_CODE)->value('system_id');
    }

    /**
     * Mode 1 — full backfill over every employee. Returns count processed.
     *
     * For bulk loads survivorship + profile materialization are deferred: the
     * load phase only builds the graph (ingest -> resolve -> rollups), then a
     * single finalize pass recomputes survivorship and rebuilds every profile.
     * This turns ~30 per-row hub queries (per-row finalize) into one pass over
     * distinct identities — the dominant cost for a bulk load. Deterministic
     * matching is unaffected: createIdentity seeds canonical_* from the first
     * row, so name/dob keys resolve during load. Pass $defer=false to keep the
     * legacy per-chunk finalize.
     *
     * Options (all optional):
     *   fromId (int)   start at this source id (inclusive); null => resume from cursor
     *   toId (int)     stop at this source id (inclusive) — for partitioned parallel runs
     *   chunk (int)    rows per chunk (default 1000); also the resume granularity
     *   segment (str)  namespaces the resume cursor so parallel workers don't collide
     *   defer (bool)   defer survivorship/materialization to one pass (default true)
     *   finalize (bool) run that deferred pass at the end (default true); set false for
     *                  parallel workers and run finalizeAll() once after all finish
     *   progress (callable)         fn(int $count) — load-phase progress
     *   finalizeProgress (callable) fn(int $done, int $total) — finalize-phase progress
     */
    public function backfill(array $opts = []): int
    {
        $fromId = $opts['fromId'] ?? null;
        $toId = $opts['toId'] ?? null;
        $chunk = $opts['chunk'] ?? 1000;
        $segment = $opts['segment'] ?? 'default';
        $defer = $opts['defer'] ?? true;
        $finalize = $opts['finalize'] ?? true;
        $progress = $opts['progress'] ?? null;
        $finalizeProgress = $opts['finalizeProgress'] ?? null;

        $count = 0;
        $maxModified = null;

        // Resume support: pick up just past this segment's last checkpoint.
        // max() so it works whether or not an explicit fromId was given —
        // re-running the identical command continues instead of restarting,
        // while a checkpoint ahead of fromId still wins. Use --restart (which
        // clears the cursor) to force a fresh pass. Idempotent, so re-covering
        // the final in-flight chunk on resume is harmless.
        $cursor = $this->backfillCursor($segment);
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
        $q->chunkById($chunk, function ($rows) use (&$count, &$maxModified, $progress, $defer, $segment) {
            $accountMap = $this->accountMapFor($rows);
            $identityIds = [];
            $empIds = [];
            foreach ($rows as $emp) {
                $stgId = $this->connector->ingest($emp, $accountMap);
                // ingest() returns null when the row was quarantined and never
                // staged, so there is no stg_person_id to resolve and no
                // credential/exclusion rollup to do for it. It HAS still been
                // processed, so the watermark and $count below stay OUTSIDE this
                // branch: skipping the watermark would let a quarantined row
                // that happens to be the newest-modified in the source stall
                // the sync watermark and re-read from there on every run.
                if ($stgId !== null) {
                    $identityIds[$this->resolver->resolve($stgId)] = true;
                    $empIds[] = $emp->id;
                }
                if ($emp->date_modified && $emp->date_modified > $maxModified) {
                    $maxModified = $emp->date_modified;
                }
                $count++;
            }
            $this->rollupCredentials($empIds);
            $this->rollupExclusions($empIds);
            if (! $defer) {
                $this->finalize($identityIds);
            }
            // Checkpoint the load phase (rows are ordered by id -> last = max).
            $this->setBackfillCursor((int) $rows->last()->id, $segment);
            if ($progress) {
                $progress($count);
            }
        }, 'id');

        if ($defer && $finalize) {
            $this->finalizeAll($finalizeProgress);
        }

        if ($maxModified) {
            $this->setWatermark(self::SOURCE_TABLE, $maxModified);
        }

        // Segment completed cleanly — drop its cursor so the next invocation is
        // a fresh pass rather than a no-op resume from the end.
        $this->clearBackfillCursor($segment);

        return $count;
    }

    private const BACKFILL_CURSOR_KEY = 'employees:bf_cursor';

    private function cursorKey(string $segment): string
    {
        return self::BACKFILL_CURSOR_KEY.':'.$segment;
    }

    /** Last source id checkpointed by an in-progress backfill segment, or null. */
    public function backfillCursor(string $segment = 'default'): ?int
    {
        $v = $this->hub()->table('gp_watermark')
            ->where(['system_id' => $this->systemId, 'source_table' => $this->cursorKey($segment)])
            ->value('high_water');

        return $v !== null ? (int) $v : null;
    }

    private function setBackfillCursor(int $id, string $segment = 'default'): void
    {
        $this->hub()->table('gp_watermark')->updateOrInsert(
            ['system_id' => $this->systemId, 'source_table' => $this->cursorKey($segment)],
            ['high_water' => (string) $id, 'updated_at' => now()],
        );
    }

    public function clearBackfillCursor(string $segment = 'default'): void
    {
        $this->hub()->table('gp_watermark')
            ->where(['system_id' => $this->systemId, 'source_table' => $this->cursorKey($segment)])
            ->delete();
    }

    /**
     * Recompute survivorship + rebuild the profile for every identity, chunked.
     *
     * Shardable for parallel finalize: shard s of $shards handles identities
     * where identity_id % shards == s. Run $shards processes with s = 0..N-1,
     * each disjoint, so the materialization pass scales like the load. Default
     * (shard 0 of 1) processes everything.
     */
    public function finalizeAll(?callable $progress = null, int $shard = 0, int $shards = 1): void
    {
        // current = 1 is not an optimisation here, it is what keeps chunkById
        // sound. identity_id stopped being unique in gp_identity when versions
        // arrived, so without the filter the cursor visits an identity once per
        // version — recomputing survivorship and rebuilding the profile N times
        // for one person, and reporting an inflated total. uq_identity_current
        // makes identity_id unique among current rows, so the cursor is valid
        // again.
        // status = 'active' as well as current = 1, for the same reason
        // SetFinalizer::materializeRange() filters both: a merged-away identity
        // keeps a current row now, and recomputing survivorship for it would
        // rebuild the profile applyMerge() just deleted.
        $q = fn () => $this->hub()->table('gp_identity')
            ->where('current', 1)->where('status', 'active')
            ->when($shards > 1, fn ($qq) => $qq->whereRaw('identity_id % ? = ?', [$shards, $shard]));

        $total = (int) $q()->count();
        $done = 0;
        $q()->orderBy('identity_id')
            ->chunkById(500, function ($ids) use (&$done, $progress, $total) {
                foreach ($ids as $row) {
                    $this->survivorship->recompute((int) $row->identity_id);
                    $this->materializer->rebuild((int) $row->identity_id);
                    $done++;
                }
                if ($progress) {
                    $progress($done, $total);
                }
            }, 'identity_id');

        if ($progress) {
            $progress($total, $total);
        }
    }

    /**
     * Set-based whole-hub finalize — same result as finalizeAll but produced
     * with a fixed handful of INSERT…SELECT/UPDATE…JOIN statements instead of
     * ~25 hub round-trips per identity. Use for bulk backfill; keep finalizeAll
     * (or finalize()) for the incremental per-identity path. Requires MySQL 8.
     */
    public function finalizeAllSet(?callable $log = null): void
    {
        (new SetFinalizer)->run($log);
    }

    /**
     * Consolidate identities that share a deterministic key — ssn_hash, npi,
     * upin, dea_number, license (number+state+board), or name+dob. Parallel
     * id-partitioned loading can mint separate identities for the same person
     * across partitions; this pass merges them so the graph matches what a
     * single-threaded load would have produced. Idempotent. Run AFTER all load
     * workers finish and BEFORE finalizeAll (survivors are re-materialized by
     * finalize from their merged source links).
     *
     * Iterates to a fixed point: a merge lets the survivor inherit the loser's
     * keys, which can expose further (transitive) matches on the next pass.
     *
     * Shardable for parallel runs: shard s of $shards handles only key groups
     * whose value hashes to s (CRC32(value) % shards). Run S processes with
     * s = 0..S-1, THEN one serial pass (shards = 1) to converge any transitive
     * merges whose inherited key crossed a shard boundary. Merges are wrapped
     * in a row-locked transaction, so concurrent shards can't corrupt a shared
     * survivor/loser.
     *
     * @return int identities merged away
     */
    public function dedup(?callable $progress = null, int $shard = 0, int $shards = 1): int
    {
        $merged = 0;
        do {
            $round = 0;
            foreach (['ssn_hash', 'npi', 'upin', 'dea_number'] as $col) {
                $round += $this->mergeByColumn($col, $shard, $shards);
            }
            $round += $this->mergeByLicense($shard, $shards);
            $round += $this->mergeByIdentifier($shard, $shards);
            $round += $this->mergeByNameDob($shard, $shards);
            $merged += $round;
            if ($progress) {
                $progress($merged);
            }
        } while ($round > 0);

        return $merged;
    }

    /** Restrict a group query to this shard by hashing the key expression. */
    private function shardFilter($q, string $expr, int $shard, int $shards)
    {
        return $shards > 1 ? $q->whereRaw("CRC32($expr) % ? = ?", [$shards, $shard]) : $q;
    }

    /** Merge active identities sharing a non-null value in $col. */
    private function mergeByColumn(string $col, int $shard = 0, int $shards = 1): int
    {
        // $col is from a fixed internal whitelist — safe to interpolate.
        //
        // current = 1 sits beside status = 'active' in every query below, and both
        // are needed. `current` picks the newest VERSION; `status` says whether the
        // identity is live. A merged-away identity keeps a current row (status
        // 'merged'), which is exactly what stops dedup's fixed-point loop from
        // re-finding it — and a superseded version can carry a key its successor
        // dropped, which without the current filter would make dedup fold two
        // live identities together on evidence that no longer exists.
        $hub = $this->hub();
        $n = 0;
        $q = $hub->table('gp_identity')->whereNotNull($col)
            ->where('current', 1)->where('status', 'active');
        $q = $this->shardFilter($q, $col, $shard, $shards);
        $dupVals = $q->groupBy($col)->havingRaw('COUNT(*) > 1')->pluck($col);
        foreach ($dupVals as $val) {
            // A filler value is not evidence of shared identity. Resolution now
            // refuses to bind on one, but dedup would still fold together any
            // identities that already carry it — so screen here too.
            if ($col === 'ssn_hash' && $this->ssnGuard->isBlocked($val)) {
                continue;
            }
            if ($col === 'npi' && $this->junkGuard->isBlocked('npi', (string) $val)) {
                continue;
            }
            $ids = $hub->table('gp_identity')
                ->where($col, $val)->where('current', 1)->where('status', 'active')
                ->orderBy('identity_id')->pluck('identity_id')->all();
            $survivor = (int) array_shift($ids);
            foreach ($ids as $loser) {
                $n += $this->mergeIdentity($survivor, (int) $loser, $col);
            }
        }

        return $n;
    }

    /** Merge active identities that share a license (number + state + board). */
    private function mergeByLicense(int $shard = 0, int $shards = 1): int
    {
        $hub = $this->hub();
        $n = 0;
        $q = $hub->table('gp_license')
            ->join('gp_identity', 'gp_identity.identity_id', '=', 'gp_license.identity_id')
            ->where('gp_license.current', 1)
            ->where('gp_identity.current', 1)
            ->where('gp_identity.status', 'active')
            ->select('license_number', 'certification_state', 'certification_board');
        $q = $this->shardFilter($q, "CONCAT_WS('|',license_number,certification_state,certification_board)", $shard, $shards);
        $groups = $q->groupBy('license_number', 'certification_state', 'certification_board')
            ->havingRaw('COUNT(DISTINCT gp_identity.identity_id) > 1')->get();
        foreach ($groups as $g) {
            $q = $hub->table('gp_license')
                ->join('gp_identity', 'gp_identity.identity_id', '=', 'gp_license.identity_id')
                ->where('gp_license.current', 1)
                ->where('gp_identity.current', 1)
                ->where('gp_identity.status', 'active')
                ->where('license_number', $g->license_number);
            $q = $g->certification_state === null
                ? $q->whereNull('certification_state') : $q->where('certification_state', $g->certification_state);
            $q = $g->certification_board === null
                ? $q->whereNull('certification_board') : $q->where('certification_board', $g->certification_board);
            $ids = $q->orderBy('gp_identity.identity_id')->distinct()->pluck('gp_identity.identity_id')->all();
            $survivor = (int) array_shift($ids);
            foreach ($ids as $loser) {
                $n += $this->mergeIdentity($survivor, (int) $loser, 'license_registry');
            }
        }

        return $n;
    }

    /** Merge active identities sharing a multi-valued identifier (DEA, MMIS). */
    private function mergeByIdentifier(int $shard = 0, int $shards = 1): int
    {
        $hub = $this->hub();
        $n = 0;
        $q = $hub->table('gp_identity_identifier as gii')
            ->join('gp_identity as gi', 'gi.identity_id', '=', 'gii.identity_id')
            ->where('gii.current', 1)
            ->where('gi.current', 1)
            ->where('gi.status', 'active')
            ->select('gii.id_type', 'gii.id_value', 'gii.state');
        $q = $this->shardFilter($q, "CONCAT_WS('|',gii.id_type,gii.id_value,gii.state)", $shard, $shards);
        // gii.state is part of the GROUP BY (unlike the storage unique key,
        // which deliberately omits it — see the 2026_09_05_000000 migration):
        // two different states' identical MMIS number must never be treated as
        // one match group, or this pass would fold unrelated providers
        // together. Verified: before this change, MMIS-4471/CA and
        // MMIS-4471/TX merged into one identity.
        $groups = $q->groupBy('gii.id_type', 'gii.id_value', 'gii.state')
            ->havingRaw('COUNT(DISTINCT gii.identity_id) > 1')->get();
        foreach ($groups as $g) {
            $sub = $hub->table('gp_identity_identifier as gii')
                ->join('gp_identity as gi', 'gi.identity_id', '=', 'gii.identity_id')
                ->where('gii.current', 1)
                ->where('gi.current', 1)
                ->where('gi.status', 'active')
                ->where('gii.id_type', $g->id_type)->where('gii.id_value', $g->id_value);
            $sub = $g->state === null ? $sub->whereNull('gii.state') : $sub->where('gii.state', $g->state);
            $ids = $sub->orderBy('gii.identity_id')->distinct()->pluck('gii.identity_id')->all();
            $survivor = (int) array_shift($ids);
            foreach ($ids as $loser) {
                $n += $this->mergeIdentity($survivor, (int) $loser, 'identifier');
            }
        }

        return $n;
    }

    /** Merge active identities sharing canonical first + last + dob (resolver's name_dob tier). */
    private function mergeByNameDob(int $shard = 0, int $shards = 1): int
    {
        $hub = $this->hub();
        $n = 0;
        // No LOWER() anywhere: canonical_first/canonical_last are utf8mb4_unicode_ci
        // so grouping and comparing are already case-insensitive, and canonical_dob
        // is a DATE. Wrapping them made the per-group probe below non-sargable —
        // the same defect documented in DeterministicResolver::matchDeterministic()
        // that pinned sync at ~0.03 rows/sec, one probe per duplicate group.
        $q = $hub->table('gp_identity')
            ->where('current', 1)
            ->where('status', 'active')
            ->whereNotNull('canonical_first')->whereNotNull('canonical_last')->whereNotNull('canonical_dob')
            ->select('canonical_first as f', 'canonical_last as l', 'canonical_dob as d');
        // The shard expression KEEPS LOWER(), unlike the probe below. Two reasons,
        // both correctness rather than style:
        //   1. CRC32 hashes raw bytes, but the GROUP BY above is case-insensitive
        //      (utf8mb4_unicode_ci). Hashing the unfolded value would put "Smith"
        //      and "SMITH" — one group — in different shards, so a sharded run
        //      could process a group twice or, worse, split it and merge neither
        //      half completely.
        //   2. It keeps the shard partition byte-identical to previous releases,
        //      so a sharded dedup interrupted before this change and resumed after
        //      it does not silently skip the rows that changed shard.
        // Only the per-group probe needed to become sargable; this expression is
        // evaluated once per row of an aggregate that scans regardless.
        $q = $this->shardFilter($q, "CONCAT_WS('|',LOWER(canonical_last),LOWER(canonical_first),canonical_dob)", $shard, $shards);
        $groups = $q->groupBy('f', 'l', 'd')->havingRaw('COUNT(*) > 1')->get();
        foreach ($groups as $g) {
            $ids = $hub->table('gp_identity')
                ->where('current', 1)
                ->where('status', 'active')
                ->where('canonical_last', $g->l)
                ->where('canonical_first', $g->f)
                ->where('canonical_dob', $g->d)
                ->orderBy('identity_id')->pluck('identity_id')->all();
            $survivor = (int) array_shift($ids);
            foreach ($ids as $loser) {
                $n += $this->mergeIdentity($survivor, (int) $loser, 'name_dob');
            }
        }

        return $n;
    }

    /**
     * Fold $loser into $survivor: repoint all child rows, drop rebuilt-on-
     * finalize artifacts, and delete the loser identity. The survivor inherits
     * the loser's null deterministic keys so later passes can chain matches.
     *
     * Row-locked in a transaction so concurrent dedup shards that happen to
     * touch the same survivor/loser serialize instead of corrupting each other;
     * if another shard already merged one of them away, this is a no-op.
     *
     * @param  string  $matchKey  which deterministic key produced this merge — recorded in the log
     * @return int 1 if a merge happened, 0 otherwise
     */
    private function mergeIdentity(int $survivor, int $loser, string $matchKey): int
    {
        if ($survivor === $loser) {
            return 0;
        }

        return $this->hub()->transaction(function () use ($survivor, $loser, $matchKey) {
            $hub = $this->hub();
            // Lock both rows in a stable order to avoid deadlocks between shards.
            [$lo, $hi] = $survivor < $loser ? [$survivor, $loser] : [$loser, $survivor];
            $hub->table('gp_identity')->whereIn('identity_id', [$lo, $hi])
                ->where('current', 1)
                ->orderBy('identity_id')->lockForUpdate()->get();

            $s = $this->versioner->current('gp_identity', ['identity_id' => $survivor]);
            $l = $this->versioner->current('gp_identity', ['identity_id' => $loser]);

            // A merged loser keeps a current row, so "already merged" is now a
            // status check rather than an absent row.
            if (! $s || ! $l || $s->status !== 'active' || $l->status !== 'active') {
                return 0;
            }
            $this->applyMerge($hub, $s, $l, $survivor, $loser, $matchKey);

            return 1;
        });
    }

    /**
     * The row-moving half of a merge (runs inside mergeIdentity's transaction).
     *
     * WHAT CHANGED UNDER SCD-2
     * ------------------------
     * This method used to end with DELETE FROM gp_identity. Data Flow by CAMI says
     * older rows "preserve a full audit trail", so destroying the losing golden
     * record is the opposite of the rule. The loser now gets a final version —
     * status 'merged', merged_into the survivor, current 1 — because the latest
     * truth about that identity is that it was merged. It stops matching because
     * every tier and every merge query filters status = 'active', not because the
     * row is gone. That is also what keeps dedup()'s fixed-point loop terminating.
     *
     * The merge is also logged. gp_resolution_log has had a 'merge' action in its
     * enum since the first migration and has never been written one; without it,
     * the version trail records THAT an identity was merged but not why or by what
     * key, and the child tables (where identity_id is not part of the natural key,
     * so repointing is a bulk update across all versions) carry no trace at all.
     */
    private function applyMerge($hub, $s, $l, int $survivor, int $loser, string $matchKey): void
    {
        // The survivor inherits the loser's null deterministic keys so later dedup
        // passes can chain matches. That is a change to golden facts, so it is a
        // version rather than an in-place update — and Versioner mints nothing when
        // there is nothing to inherit.
        $upd = [];
        foreach (['ssn_hash', 'npi', 'upin', 'dea_number', 'canonical_dob',
            'canonical_first', 'canonical_last', 'canonical_middle'] as $c) {
            if (empty($s->$c) && ! empty($l->$c)) {
                $upd[$c] = $l->$c;
            }
        }
        if ($upd) {
            $this->versioner->write('gp_identity', ['identity_id' => $survivor], $upd);
        }

        // Repoint children whose natural key does NOT include identity_id. ALL
        // versions move, deliberately: no unique on these tables can collide on
        // identity_id, and leaving the history behind would orphan it from the
        // identity the facts now belong to. The cost is that the identity a
        // credential belonged to before the merge is recoverable only from
        // gp_resolution_log — see docs/SCD2.md, which records that trade.
        foreach (['gp_source_link', 'gp_edge', 'gp_identity_credential', 'gp_identity_exclusion',
            'gp_identity_resolution', 'gp_resolution_log', 'gp_board_action'] as $t) {
            $hub->table($t)->where('identity_id', $loser)->update(['identity_id' => $survivor]);
        }

        // Collision-prone (identity_id is part of the natural key). The current
        // version moves and is renumbered under the survivor's key; a version that
        // would clash with something the survivor already holds is RETIRED rather
        // than deleted, and superseded versions are left attached to the merged
        // identity — repointing those would violate the natural-key unique, whose
        // last column is now version_no. See Versioner::repointForMerge.
        foreach (['gp_license', 'gp_address', 'gp_identity_identifier'] as $t) {
            $this->versioner->repointForMerge($t, $survivor, $loser);
        }

        // Rebuilt from scratch by finalize — just remove the loser's copies.
        foreach (['gp_attribute', 'gp_survivorship_audit', 'gp_identity_profile'] as $t) {
            $hub->table($t)->where('identity_id', $loser)->delete();
        }

        // The loser's final version: where it went, and that this is the last word.
        $this->versioner->write('gp_identity', ['identity_id' => $loser], [
            'status' => 'merged',
            'merged_into' => $survivor,
        ]);

        $hub->table('gp_resolution_log')->insert([
            'action' => 'merge',
            'identity_id' => $survivor,
            'affected_ids' => json_encode(['merged' => [$loser]]),
            'match_key' => $matchKey,
            'reason' => "dedup: identities shared $matchKey",
            'actor' => 'engine',
            'created_at' => now(),
        ]);
    }

    /** Mode 2 — incremental: only employees changed since the watermark. */
    public function sync(int $chunk = 1000, ?callable $progress = null): int
    {
        $water = $this->getWatermark(self::SOURCE_TABLE);
        $count = 0;
        $maxModified = $water;
        $q = $this->src()->table(self::SOURCE_TABLE)->orderBy('id');
        if ($water) {
            $q->where('date_modified', '>', $water);
        }
        $q->chunkById($chunk, function ($rows) use (&$count, &$maxModified, $progress) {
            $accountMap = $this->accountMapFor($rows);
            $identityIds = [];
            $empIds = [];
            foreach ($rows as $emp) {
                $stgId = $this->connector->ingest($emp, $accountMap);
                // ingest() returns null when the row was quarantined and never
                // staged, so there is no stg_person_id to resolve and no
                // credential/exclusion rollup to do for it. It HAS still been
                // processed, so the watermark and $count below stay OUTSIDE this
                // branch: skipping the watermark would let a quarantined row
                // that happens to be the newest-modified in the source stall
                // the sync watermark and re-read from there on every run.
                if ($stgId !== null) {
                    $identityIds[$this->resolver->resolve($stgId)] = true;
                    $empIds[] = $emp->id;
                }
                if ($emp->date_modified && $emp->date_modified > $maxModified) {
                    $maxModified = $emp->date_modified;
                }
                $count++;
            }
            $this->rollupCredentials($empIds);
            $this->rollupExclusions($empIds);
            $this->finalize($identityIds);
            if ($progress) {
                $progress($count);
            }
        }, 'id');

        if ($maxModified && $maxModified !== $water) {
            $this->setWatermark(self::SOURCE_TABLE, $maxModified);
        }

        return $count;
    }

    /**
     * One source query per chunk: map employeelist_id => account_id for every
     * employeelist referenced in this batch of rows. Avoids a per-row source
     * round-trip (the source is a high-latency WAN connection).
     *
     * @return array<int,int|null>
     */
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

        return $this->src()->table('employeelists')
            ->whereIn('id', array_keys($listIds))
            ->pluck('account_id', 'id')
            ->all();
    }

    /**
     * One hub query per chunk: source employee_id => identity_id, so the
     * rollups don't do a per-credential/per-match identity lookup.
     *
     * @return array<int,int>
     */
    private function identityMapFor(array $employeeIds): array
    {
        if (! $employeeIds) {
            return [];
        }

        return $this->hub()->table('gp_source_link')
            ->where('system_id', $this->systemId)
            ->where('source_table', self::SOURCE_TABLE)
            ->whereIn('source_id', $employeeIds)
            ->pluck('identity_id', 'source_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** credential_matches -> gp_identity_credential (confirmed links). */
    private function rollupCredentials(array $employeeIds): void
    {
        if (! $employeeIds) {
            return;
        }
        $excludeCodes = config('golden_profile.credential_search.rollup_exclude_status_codes', []);
        $rows = $this->src()->table('credential_matches')->whereIn('employee_id', $employeeIds)->get();
        $identityMap = $this->identityMapFor($employeeIds);

        $upserts = [];
        $deleteIds = [];
        foreach ($rows as $c) {
            $identityId = $identityMap[(int) $c->employee_id] ?? null;
            if (! $identityId) {
                continue;
            }
            // Pending / Error matches are not part of the golden data — never roll them up.
            if (in_array((int) $c->match_summary_status_code, $excludeCodes, true)) {
                $deleteIds[] = (int) $c->id;

                continue;
            }
            $upserts[] = [
                'system_id' => $this->systemId,
                'credential_match_id' => (int) $c->id,
                'identity_id' => $identityId,
                'registry' => $c->registry,
                'match_summary_status' => $c->match_summary_status,
                'match_summary_status_code' => $c->match_summary_status_code,
                'match_is_valid' => $c->match_is_valid,
                // CAMI's own currency flag, mirrored. Named source_current because
                // `current` is the SCD-2 version flag on this table.
                'source_current' => $c->current,
                'date_resolved' => $this->dt($c->date_resolved),
                'link_state' => 'confirmed',
            ];
        }

        // upsert() became per-row Versioner::write(). A credential whose status,
        // validity or CAMI currency flag moved is superseded rather than
        // overwritten; one that came back identical produces nothing, which matters
        // because sync re-reads every credential of every changed employee on every
        // run.
        //
        // COST, stated plainly: this is one transaction per credential where it
        // used to be one upsert per 500. That is acceptable on the incremental
        // path — sync is already per-row and bounded by the source's WAN latency,
        // not the hub's — and unacceptable on a bulk load, which is why
        // SqlBackfill::rollup() keeps its set-based statement and is guarded off
        // until plan 3b converts it.
        foreach ($upserts as $row) {
            $this->versioner->write(
                'gp_identity_credential',
                ['system_id' => $row['system_id'], 'credential_match_id' => $row['credential_match_id']],
                array_intersect_key($row, array_flip([
                    'registry', 'match_summary_status', 'match_summary_status_code',
                    'match_is_valid', 'source_current', 'date_resolved', 'link_state',
                ])),
                [],
                ['identity_id' => $row['identity_id']],
            );
        }

        // Pending / Error matches are not part of the golden data. They used to be
        // DELETEd; retiring them keeps the trail of a credential that was rolled up
        // and later became ineligible, which is the whole point of the pattern.
        foreach ($deleteIds as $credentialMatchId) {
            $this->versioner->retire('gp_identity_credential', [
                'system_id' => $this->systemId,
                'credential_match_id' => $credentialMatchId,
            ]);
        }
    }

    /** matches (exclusion hits) -> gp_identity_exclusion (candidate links). */
    private function rollupExclusions(array $employeeIds): void
    {
        if (! $employeeIds) {
            return;
        }
        $rows = $this->src()->table('matches')->whereIn('employee_id', $employeeIds)->get();

        // Batch the exclusion_records lookup: one source query for the whole
        // chunk instead of one per match (source is a high-latency WAN link).
        $recordIds = $rows->pluck('exclusion_record_id')->filter()->unique()->all();
        $registryMap = $recordIds
            ? $this->src()->table('exclusion_records')
                ->whereIn('id', $recordIds)
                ->pluck('exclusion_list_prefix', 'id')->all()
            : [];

        $identityMap = $this->identityMapFor($employeeIds);

        $upserts = [];
        foreach ($rows as $m) {
            $identityId = $identityMap[(int) $m->employee_id] ?? null;
            if (! $identityId) {
                continue;
            }
            $registry = $m->exclusion_record_id
                ? ($registryMap[$m->exclusion_record_id] ?? null)
                : null;
            $upserts[] = [
                'system_id' => $this->systemId,
                'match_id' => (int) $m->id,
                'identity_id' => $identityId,
                'exclusion_record_id' => $m->exclusion_record_id,
                'registry' => $registry,
                'is_ssn_match' => $m->is_ssn_match,
                'is_npi_match' => $m->is_npi_match,
                'is_canonical_name_match' => $m->is_canonical_name_match,
                'is_upin_match' => $m->is_upin_match,
                'is_license_number_match' => $m->is_license_number_match,
                'link_state' => 'candidate',
            ];
        }

        // Same conversion, same reasoning, as rollupCredentials above.
        foreach ($upserts as $row) {
            $this->versioner->write(
                'gp_identity_exclusion',
                ['system_id' => $row['system_id'], 'match_id' => $row['match_id']],
                array_intersect_key($row, array_flip([
                    'exclusion_record_id', 'registry', 'is_ssn_match', 'is_npi_match',
                    'is_canonical_name_match', 'is_upin_match', 'is_license_number_match', 'link_state',
                ])),
                [],
                ['identity_id' => $row['identity_id']],
            );
        }
    }

    private function identityForSource(int $sourceId): ?int
    {
        $id = $this->hub()->table('gp_source_link')->where([
            'system_id' => $this->systemId, 'source_table' => self::SOURCE_TABLE, 'source_id' => $sourceId,
        ])->value('identity_id');

        return $id ? (int) $id : null;
    }

    public function rebuildProfile(?int $identityId = null): int
    {
        $ids = $identityId
            ? [$identityId]
            : $this->hub()->table('gp_identity')->where('current', 1)
                ->where('status', 'active')->pluck('identity_id')->all();
        foreach ($ids as $id) {
            $this->materializer->rebuild((int) $id);
        }

        return count($ids);
    }

    private function getWatermark(string $table): ?string
    {
        return $this->hub()->table('gp_watermark')
            ->where(['system_id' => $this->systemId, 'source_table' => $table])->value('high_water');
    }

    private function setWatermark(string $table, string $value): void
    {
        $this->hub()->table('gp_watermark')->updateOrInsert(
            ['system_id' => $this->systemId, 'source_table' => $table],
            ['high_water' => $value, 'updated_at' => now()],
        );
    }

    private function dt(?string $v): ?string
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

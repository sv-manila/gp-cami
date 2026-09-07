<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one implementation of the slowly-changing-dimension (Type 2) write rule
 * from Data Flow by CAMI (DEV page 4099997697):
 *
 *   "Insert a new row with current = 1, and set all preexisting rows to
 *    current = 0."
 *
 * Eight call sites used to update() or updateOrInsert() a versioned table. The
 * rule lives here rather than in each of them because this codebase already has a
 * documented case of two implementations of one rule drifting apart:
 * Survivorship's final tiebreak had to be pinned to link_id ASC to match
 * SetFinalizer's SQL "because a mismatch broke the rebuild-produces-a-byte-
 * identical-profile invariant".
 *
 * THREE CATEGORIES OF COLUMN, AND WHY
 * -----------------------------------
 * A naive versioner compares the whole update payload and mints a version
 * whenever anything differs. That is unusable here. Engine::finalizeAll()
 * recomputes survivorship for EVERY identity, and Survivorship writes
 * last_updated => now() unconditionally, so a whole-payload comparison would add
 * one gp_identity row per identity per rebuild — ~13.38M rows a run. So each table
 * declares:
 *
 *   attributes  the golden facts. A change in any of them mints a version, and
 *               they are what "did anything change?" compares. Absent columns are
 *               carried forward from the previous version, so a partial write
 *               (backfillKeys supplying only an npi) still produces a complete row.
 *   derived     recomputed aggregates — record_count, confidence. Written onto the
 *               CURRENT version in place. record_count is COUNT(*) over
 *               gp_source_link, which already records when each link was made with
 *               better resolution than a version row would; versioning on a
 *               record_count bump would add one identity row per source row
 *               (~13.4M on a backfill) to record something the link table holds.
 *   onCreate    written only when minting version 1, carried forward after.
 *               source_link_id belongs here: it records which source row
 *               ESTABLISHED the fact. As an attribute it would mint a version
 *               every time a second account's employee row re-observed the same
 *               licence — thousands of identical versions on the pile-up
 *               identities (identity 3 folds 12,463 source rows).
 *
 * Any column in none of the four lists is carried forward untouched (identity_uuid
 * is the example). The surrogate primary key, where there is one, is dropped when
 * carrying a row forward so the new version gets its own.
 *
 * ORDERING AND CONCURRENCY
 * ------------------------
 * Flip-old-then-insert-new, inside one transaction, with the current row locked
 * FOR UPDATE. The order is forced by the schema: uq_*_current makes at most one
 * current row per natural key, so inserting first would collide with the row it is
 * about to supersede. The lock serialises parallel writers (backfill workers,
 * dedup shards); if two race past it anyway, the unique index rejects the second —
 * loudly, which is the whole reason that index exists.
 *
 * The flip sets ONLY current = 0. It deliberately does not touch the superseded
 * row's timestamps: date_updated on a version means "when this version was
 * written", and an audit trail whose rows get restamped every time they are
 * superseded has lost the thing it was keeping.
 *
 * `current` IS NOT `alive`. A merged-away identity gets a NEW version with
 * status = 'merged' and current = 1 — the latest truth about it is that it was
 * merged. Callers keep filtering status = 'active' as well.
 */
class Versioner
{
    /**
     * @var array<string, array{key: list<string>, attributes: list<string>,
     *     derived: list<string>, onCreate: list<string>, surrogate: ?string,
     *     created: string, updated: string}>
     */
    public const TABLES = [
        'gp_identity' => [
            'key' => ['identity_id'],
            'attributes' => [
                'canonical_first', 'canonical_middle', 'canonical_last', 'canonical_suffix',
                'canonical_dob', 'ssn_hash', 'npi', 'upin', 'dea_number', 'status', 'merged_into',
            ],
            'derived' => ['record_count', 'confidence'],
            'onCreate' => [],
            // identity_id is both the surrogate and the natural key, so it is
            // carried forward rather than reassigned.
            'surrogate' => null,
            'created' => 'first_seen',
            'updated' => 'last_updated',
        ],
        'gp_license' => [
            'key' => ['identity_id', 'license_number', 'certification_state', 'certification_board'],
            'attributes' => ['license_type', 'license_type_id', 'registry', 'is_verified'],
            'derived' => [],
            'onCreate' => ['source_link_id'],
            'surrogate' => 'license_id',
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
        'gp_address' => [
            'key' => ['identity_id', 'address1', 'city', 'state', 'zip'],
            'attributes' => ['address2', 'is_primary'],
            'derived' => [],
            'onCreate' => ['source_link_id'],
            'surrogate' => 'address_id',
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
        'gp_identity_identifier' => [
            // The key is the whole fact. It still gets versioned: a DEA number
            // being WITHDRAWN is a fact, and the only way to record it is a
            // version with current = 0.
            'key' => ['identity_id', 'id_type', 'id_value'],
            'attributes' => [],
            'derived' => [],
            'onCreate' => ['source_link_id'],
            'surrogate' => 'id',
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
        'gp_identity_credential' => [
            'key' => ['system_id', 'credential_match_id'],
            'attributes' => [
                'registry', 'match_summary_status', 'match_summary_status_code', 'match_is_valid',
                'source_current', 'date_resolved', 'link_state', 'link_confidence',
            ],
            'derived' => [],
            // identity_id is onCreate, not an attribute: repointing on a merge is a
            // GROUPING change, recorded in gp_resolution_log and on the merged
            // identity's own final version. Versioning it would mint one row per
            // credential per merge — 397,170 for identity 3 alone.
            'onCreate' => ['identity_id'],
            'surrogate' => null,
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
        'gp_identity_exclusion' => [
            'key' => ['system_id', 'match_id'],
            'attributes' => [
                'exclusion_record_id', 'registry', 'is_ssn_match', 'is_npi_match',
                'is_canonical_name_match', 'is_upin_match', 'is_license_number_match',
                'link_state', 'link_confidence',
            ],
            'derived' => [],
            'onCreate' => ['identity_id'],
            'surrogate' => null,
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
    ];

    public function __construct(private ?string $connection = null) {}

    public static function isVersioned(string $table): bool
    {
        return isset(self::TABLES[$table]);
    }

    /** @return array{key: list<string>, attributes: list<string>, derived: list<string>, onCreate: list<string>, surrogate: ?string, created: string, updated: string} */
    public static function spec(string $table): array
    {
        return self::TABLES[$table]
            ?? throw new InvalidArgumentException("$table is not a versioned table");
    }

    /** The current version of $key, or null. */
    public function current(string $table, array $key): ?object
    {
        self::spec($table);

        return $this->db()->table($table)->where($key)->where('current', 1)->first();
    }

    /**
     * Insert a new version of $key, or do nothing if no golden fact changed.
     *
     * @param  array<string,mixed>  $key  the natural key (all of spec['key'])
     * @param  array<string,mixed>  $attributes  golden facts; a subset is fine, the rest carries forward
     * @param  array<string,mixed>  $derived  recomputed aggregates, written in place
     * @param  array<string,mixed>  $onCreate  applied only when minting version 1
     * @return array{version_no: int, new_version: bool}
     */
    public function write(string $table, array $key, array $attributes, array $derived = [], array $onCreate = []): array
    {
        $spec = self::spec($table);
        $db = $this->db();

        return $db->transaction(function () use ($db, $table, $key, $attributes, $derived, $onCreate, $spec) {
            // The highest version, current or not. A key whose versions were all
            // retired (a merge collision, see repointForMerge) can be revived, and
            // reviving it must continue the numbering rather than restart it.
            $latest = $db->table($table)->where($key)
                ->orderByDesc('version_no')->lockForUpdate()->first();

            $isCurrent = $latest !== null && (int) $latest->current === 1;

            if ($isCurrent && ! $this->differs($latest, $attributes, $spec['attributes'])) {
                if ($derived !== []) {
                    $db->table($table)->where($key)->where('current', 1)
                        ->update($this->only($derived, $spec['derived']));
                }

                return ['version_no' => (int) $latest->version_no, 'new_version' => false];
            }

            $now = now();
            $version = $latest === null ? 1 : ((int) $latest->version_no + 1);

            if ($isCurrent) {
                // ONLY current. Restamping a superseded row's date_updated would
                // destroy the "when was this version written" the audit trail is for.
                $db->table($table)->where($key)->where('current', 1)->update(['current' => 0]);
            }

            // array_replace, not `+`: `+` keeps the LEFT operand for a duplicate
            // key, so the carried-forward row would win over the incoming values.
            // Later arguments win here, which is the order the categories need —
            // carried forward, then the key, then the golden facts, then derived,
            // then (only for version 1) onCreate, then the bookkeeping.
            $row = array_replace(
                $this->carryForward($latest, $spec),
                $key,
                $this->only($attributes, $spec['attributes']),
                $this->only($derived, $spec['derived']),
                $latest === null ? $this->only($onCreate, $spec['onCreate']) : [],
                [
                    'version_no' => $version,
                    'current' => 1,
                    $spec['created'] => $latest->{$spec['created']} ?? $now,
                    $spec['updated'] => $now,
                ],
            );

            $db->table($table)->insert($row);

            return ['version_no' => $version, 'new_version' => true];
        });
    }

    /**
     * Flip every current row matching $key to current = 0 without inserting a
     * successor. Used when a merge folds a fact into an identity that already has
     * an equivalent one: the loser's chain is preserved, attached to the identity
     * it belonged to, but stops being current.
     *
     * @return int rows retired
     */
    public function retire(string $table, array $key): int
    {
        self::spec($table);

        return (int) $this->db()->table($table)->where($key)->where('current', 1)
            ->update(['current' => 0]);
    }

    /**
     * Move the current version of every natural key held by $loser onto $survivor.
     *
     * Only applies to tables whose natural key CONTAINS identity_id — gp_license,
     * gp_address, gp_identity_identifier. For the others identity_id is not part of
     * the key, so Engine repoints them with a plain bulk UPDATE across all versions
     * and no unique can collide.
     *
     * Superseded versions are deliberately LEFT BEHIND, still pointing at the loser.
     * Repointing them too would violate the natural-key unique (which now ends in
     * version_no): the loser's version 1 and the survivor's version 1 would become
     * the same row. Leaving them attached to the merged-away identity is also the
     * better history — the loser's gp_identity row survives as a version with
     * status = 'merged', so the trail is reachable.
     *
     * @return array{repointed: int, retired: int}
     */
    public function repointForMerge(string $table, int $survivor, int $loser): array
    {
        $spec = self::spec($table);

        if (! in_array('identity_id', $spec['key'], true)) {
            throw new InvalidArgumentException(
                "$table does not key on identity_id; repoint it with a bulk update instead"
            );
        }

        $db = $this->db();
        $others = array_values(array_diff($spec['key'], ['identity_id']));
        $repointed = 0;
        $retired = 0;

        foreach ($db->table($table)->where('identity_id', $loser)->where('current', 1)->get() as $row) {
            $survivorKey = ['identity_id' => $survivor];
            foreach ($others as $column) {
                $survivorKey[$column] = $row->$column;
            }

            $clash = $db->table($table);
            foreach ($survivorKey as $column => $value) {
                $clash = $value === null ? $clash->whereNull($column) : $clash->where($column, $value);
            }

            if ($clash->clone()->where('current', 1)->exists()) {
                $db->table($table)->where($spec['surrogate'], $row->{$spec['surrogate']})
                    ->update(['current' => 0]);
                $retired++;

                continue;
            }

            // Continue the survivor's numbering for this key: it may already hold a
            // retired chain from an earlier merge, and (survivor, key, version_no)
            // is unique.
            $next = 1 + (int) ($clash->clone()->max('version_no') ?? 0);

            $db->table($table)->where($spec['surrogate'], $row->{$spec['surrogate']})->update([
                'identity_id' => $survivor,
                'version_no' => $next,
                $spec['updated'] => now(),
            ]);
            $repointed++;
        }

        return ['repointed' => $repointed, 'retired' => $retired];
    }

    /** True when any declared attribute present in $incoming differs from $row. */
    private function differs(object $row, array $incoming, array $attributes): bool
    {
        foreach ($attributes as $column) {
            if (! array_key_exists($column, $incoming)) {
                continue;                       // absent = carry forward = no change
            }

            if (! $this->same($row->$column ?? null, $incoming[$column])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Value equality as the DATABASE sees it after a round trip.
     *
     * Compared loosely on purpose. A DATE column comes back as '1970-04-02' but is
     * written as '1970-04-02' or a Carbon or a DATETIME string; npi comes back as a
     * string '1234567893' and is written as int 1234567893; is_verified comes back
     * as '0'. Strict comparison would call every one of those a change and mint a
     * version on every single write, which is precisely the runaway this class
     * exists to prevent. Nulls are compared strictly, since NULL and '' are
     * genuinely different here (they are what makes the natural-key uniques
     * NULL-permissive).
     */
    private function same($stored, $incoming): bool
    {
        if ($stored === null || $incoming === null) {
            return $stored === null && $incoming === null;
        }

        $a = $stored instanceof \DateTimeInterface ? $stored->format('Y-m-d H:i:s') : (string) $stored;
        $b = $incoming instanceof \DateTimeInterface ? $incoming->format('Y-m-d H:i:s') : (string) $incoming;

        // A DATE column round-trips as 'Y-m-d' while callers hand over staged
        // values that may carry a time. Compare on the date when both look like
        // timestamps of the same day.
        if (strlen($a) === 10 && strlen($b) >= 10 && str_starts_with($b, $a)) {
            return true;
        }
        if (strlen($b) === 10 && strlen($a) >= 10 && str_starts_with($a, $b)) {
            return true;
        }

        return $a === $b;
    }

    /** Every column of the previous version except its surrogate key and version bookkeeping. */
    private function carryForward(?object $latest, array $spec): array
    {
        if ($latest === null) {
            return [];
        }

        $row = (array) $latest;

        unset($row['current'], $row['version_no'], $row['current_key']);

        if ($spec['surrogate'] !== null) {
            unset($row[$spec['surrogate']]);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function only(array $values, array $allowed): array
    {
        return array_intersect_key($values, array_flip($allowed));
    }

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }
}

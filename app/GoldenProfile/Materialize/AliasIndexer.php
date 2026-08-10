<?php

namespace App\GoldenProfile\Materialize;

use Illuminate\Support\Facades\DB;

/**
 * Keeps gp_identity_alias in step with the aliases JSON on gp_identity_profile.
 *
 * Both rollup paths write that JSON — ProfileMaterializer per identity,
 * SetFinalizer set-based — so both must maintain this table too, or the search
 * index silently drifts from the data it indexes. The logic lives here once rather
 * than being duplicated (and diverging) in each.
 *
 * Derived from the same join the JSON rollup uses
 * (gp_source_link -> stg_person -> stg_person_alias), not by re-parsing the JSON:
 * parsing it back would inherit the formatting quirk that broke the original LIKE
 * predicate, and staging is the actual source of truth.
 *
 * Both name parts are indexed. Only 17 of the 108,527 staged alias rows carry a
 * surname; the rest are entity names in first_name, so a surname-only index would
 * find nothing worth having.
 */
class AliasIndexer
{
    /** Staged alias rows per batch when rebuilding in bulk. */
    private const CHUNK = 20000;

    private function hub()
    {
        return DB::connection(config('golden_profile.connections.hub', 'golden_profile'));
    }

    /**
     * The projection every write path shares: one row per (identity, name, part),
     * blanks excluded. $where is ANDed in to scope it.
     */
    private function insertSql(string $where): string
    {
        return "INSERT INTO gp_identity_alias (alias_name, identity_id, alias_part)
                SELECT DISTINCT TRIM(n.name), n.identity_id, n.part
                FROM (
                    SELECT l.identity_id, a.last_name AS name, 'last' AS part, a.stg_person_id
                    FROM gp_source_link l
                    JOIN stg_person sp
                      ON sp.system_id = l.system_id AND sp.source_table = l.source_table
                     AND sp.source_id = l.source_id
                    JOIN stg_person_alias a ON a.stg_person_id = sp.stg_person_id
                    WHERE a.last_name IS NOT NULL AND TRIM(a.last_name) <> ''
                    UNION ALL
                    SELECT l.identity_id, a.first_name AS name, 'first' AS part, a.stg_person_id
                    FROM gp_source_link l
                    JOIN stg_person sp
                      ON sp.system_id = l.system_id AND sp.source_table = l.source_table
                     AND sp.source_id = l.source_id
                    JOIN stg_person_alias a ON a.stg_person_id = sp.stg_person_id
                    WHERE a.first_name IS NOT NULL AND TRIM(a.first_name) <> ''
                ) n
                WHERE $where
                ON DUPLICATE KEY UPDATE identity_id = VALUES(identity_id)";
    }

    /**
     * Refresh one identity: replace whatever is stored for it.
     *
     * Delete-then-insert rather than upsert, because an alias that disappears from
     * staging has to disappear here too — an upsert would leave the stale row
     * behind and keep returning that identity for a name it no longer has.
     */
    public function refresh(int $identityId): int
    {
        $hub = $this->hub();

        $hub->table('gp_identity_alias')->where('identity_id', $identityId)->delete();

        return (int) $hub->affectingStatement($this->insertSql('n.identity_id = ?'), [$identityId]);
    }

    /**
     * Rebuild a slice of identities, or all of them.
     *
     * Driven by identity_id so it can be called with the same bounds the
     * materialize pass uses. For a full rebuild prefer rebuildFromStaging(), which
     * walks the 108k staged alias rows instead of all 13.38M identities.
     *
     * @return int rows written
     */
    public function rebuildAll(?int $fromId = null, ?int $toId = null): int
    {
        $hub = $this->hub();

        if ($fromId === null && $toId === null) {
            return $this->rebuildFromStaging();
        }

        $from = $fromId ?? 0;
        $to = $toId ?? PHP_INT_MAX;

        $hub->table('gp_identity_alias')->whereBetween('identity_id', [$from, $to])->delete();

        return (int) $hub->affectingStatement(
            $this->insertSql('n.identity_id BETWEEN ? AND ?'),
            [$from, $to],
        );
    }

    /**
     * Full rebuild, walking staging rather than the identity range.
     *
     * Driving from identity_id means ~2,676 passes over 13.38M ids to find ~108k
     * aliases, which is why the first attempt at this crawled. stg_person_alias is
     * the small side, so the whole table can be walked by its own key in a handful
     * of batches.
     *
     * @param  callable|null  $progress  fn(int $upToStgPersonId, int $rowsWritten)
     * @return int rows written
     */
    public function rebuildFromStaging(?callable $progress = null): int
    {
        $hub = $this->hub();

        $hub->table('gp_identity_alias')->truncate();

        $max = (int) $hub->table('stg_person_alias')->max('stg_person_id');
        $written = 0;

        for ($start = 0; $start <= $max; $start += self::CHUNK) {
            $end = $start + self::CHUNK - 1;

            $written += (int) $hub->affectingStatement(
                $this->insertSql('n.stg_person_id BETWEEN ? AND ?'),
                [$start, $end],
            );

            if ($progress) {
                $progress(min($end, $max), $written);
            }
        }

        return $written;
    }

    /**
     * Identity ids whose aliases include this name, in either part.
     * Case-insensitive by column collation — no LOWER(), which is what made the
     * original predicate non-sargable.
     *
     * @return list<int>
     */
    public function identityIdsFor(string $name, int $limit = 2000): array
    {
        return $this->hub()->table('gp_identity_alias')
            ->where('alias_name', trim($name))
            ->limit($limit)
            ->pluck('identity_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}

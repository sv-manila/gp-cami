<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populates date_created / date_updated on the five tables that gained them in
 * 2026_09_04_000100_add_scd2_versioning.
 *
 * WHY THIS IS A COMMAND AND NOT PART OF THE MIGRATION
 * ---------------------------------------------------
 * version_no and current were backfilled by their column DEFAULTs, which is free:
 * an INSTANT ADD COLUMN means every existing row already reads as version 1,
 * current 1 without a single UPDATE. The two timestamps cannot work that way —
 * their correct value is per row and historical — and a single UPDATE over
 * gp_identity_credential (the largest table in the hub) would hold one
 * transaction and one undo log for its whole duration. SetFinalizer already
 * documents that failure mode at MATERIALIZE_CHUNK: a 250k-row chunk's DELETE
 * "ran over 8 minutes and built an undo log big enough that interrupting it was
 * expensive". A migration that does that leaves a failed deploy wedged halfway
 * with no way to resume.
 *
 * So: chunked by primary-key range, each chunk its own autocommitted statement,
 * and the WHERE clause is `date_created IS NULL` — which makes the whole thing
 * resumable by construction. Re-running after an interruption picks up exactly
 * the rows that were missed, and re-running after completion is a no-op.
 *
 * WHERE THE VALUES COME FROM
 * --------------------------
 *   gp_license, gp_address, gp_identity_identifier
 *       gp_source_link.linked_at of the link recorded in source_link_id — the
 *       only record the hub keeps of when the fact entered it.
 *   gp_identity_credential
 *       CAMI's own date_resolved, the closest thing to a creation time on the row.
 *   gp_identity_exclusion
 *       nothing usable; NOW(). An exclusion link carries no date at all until
 *       plan 7 adds excl_date / reinstate_date.
 *
 * date_updated is seeded equal to date_created: nothing has been versioned yet, so
 * every row was last updated when it was created.
 *
 * gp_identity is not listed. It maps the doc's timestamps onto first_seen /
 * last_updated, which are already populated on every row.
 */
class GpVersionBackfill extends Command
{
    protected $signature = 'gp:version-backfill
        {--table= : one of gp_license, gp_address, gp_identity_identifier, gp_identity_credential, gp_identity_exclusion}
        {--chunk=10000 : primary-key ids per statement}
        {--dry-run : report how many rows need backfilling and change nothing}';

    protected $description = 'Populate date_created/date_updated on the SCD-2 versioned tables (chunked, resumable)';

    /**
     * table => [primary key column, the SQL expression for date_created, extra FROM/JOIN].
     * Each entry is a complete recipe so the loop below carries no per-table
     * branching.
     */
    private const RECIPES = [
        'gp_license' => [
            'pk' => 'license_id',
            'join' => 'LEFT JOIN gp_source_link sl ON sl.link_id = t.source_link_id',
            'created' => 'COALESCE(sl.linked_at, NOW())',
        ],
        'gp_address' => [
            'pk' => 'address_id',
            'join' => 'LEFT JOIN gp_source_link sl ON sl.link_id = t.source_link_id',
            'created' => 'COALESCE(sl.linked_at, NOW())',
        ],
        'gp_identity_identifier' => [
            'pk' => 'id',
            'join' => 'LEFT JOIN gp_source_link sl ON sl.link_id = t.source_link_id',
            'created' => 'COALESCE(sl.linked_at, NOW())',
        ],
        'gp_identity_credential' => [
            'pk' => 'credential_match_id',
            'join' => '',
            'created' => 'COALESCE(t.date_resolved, NOW())',
        ],
        'gp_identity_exclusion' => [
            'pk' => 'match_id',
            'join' => '',
            'created' => 'NOW()',
        ],
    ];

    public function handle(): int
    {
        $only = $this->option('table');
        if ($only !== null && ! isset(self::RECIPES[$only])) {
            $this->error("unknown table '$only'; expected one of ".implode(', ', array_keys(self::RECIPES)));

            return self::FAILURE;
        }

        $tables = $only !== null ? [$only] : array_keys(self::RECIPES);
        $chunk = max(1, (int) $this->option('chunk'));

        foreach ($tables as $table) {
            $pending = $this->pending($table);

            if ($this->option('dry-run')) {
                $this->line(sprintf('%-24s %d row(s) need date_created', $table, $pending));

                continue;
            }

            $this->line(sprintf('%-24s %d row(s) pending', $table, $pending));
            $written = $this->backfillTable($table, $chunk);
            $this->info(sprintf('%-24s %d row(s) written', $table, $written));
        }

        return self::SUCCESS;
    }

    /** Rows still missing date_created. */
    public function pending(string $table): int
    {
        return (int) $this->hub()->table($table)->whereNull('date_created')->count();
    }

    /**
     * Backfill one table, chunked by primary-key range. Returns rows written.
     *
     * Ranged rather than LIMITed: a bare `UPDATE … WHERE date_created IS NULL
     * LIMIT n` re-scans from the start of the table on every iteration, so the
     * last chunk of a 100M-row table pays for all of them. A key range touches
     * only its own slice.
     */
    public function backfillTable(string $table, int $chunk): int
    {
        $recipe = self::RECIPES[$table] ?? throw new \InvalidArgumentException("$table has no backfill recipe");
        $pk = $recipe['pk'];
        $hub = $this->hub();

        $bounds = $hub->selectOne("SELECT MIN(`$pk`) lo, MAX(`$pk`) hi FROM `$table`");
        if (! $bounds || $bounds->lo === null) {
            return 0;
        }

        $written = 0;
        for ($lo = (int) $bounds->lo; $lo <= (int) $bounds->hi; $lo += $chunk) {
            $hi = $lo + $chunk;   // exclusive

            $written += (int) $hub->affectingStatement(
                "UPDATE `$table` t {$recipe['join']}
                 SET t.date_created = {$recipe['created']},
                     t.date_updated = {$recipe['created']}
                 WHERE t.date_created IS NULL
                   AND t.`$pk` >= ? AND t.`$pk` < ?",
                [$lo, $hi]
            );
        }

        return $written;
    }

    private function hub()
    {
        return DB::connection(config('golden_profile.connections.hub', 'golden_profile'));
    }
}

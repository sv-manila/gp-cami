<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A scratch carrier for SqlBackfill::residualCreateAndLink().
 *
 * The residual tier mints one identity per still-unlinked staged row and then has
 * to link the two. It cannot do that without carrying stg_person_id out of the
 * INSERT that generated the identity_id: MySQL offers no way to capture a batch's
 * generated auto-increment values into a second table, LAST_INSERT_ID() returns
 * only the first of the batch, and assuming the block is contiguous breaks as soon
 * as another writer interleaves. So the carrier is a column on the inserted row.
 *
 * It used to be gp_identity.merged_into, described in that method as "the unused
 * merged_into column as a temporary stg_person_id carrier so the 1:1 create + link
 * stays fully set-based". 2026_09_04_000100_add_scd2_versioning made merged_into a
 * golden attribute — Versioner compares it, and it records which identity a merged
 * one went to — so the borrow would now write a stg_person_id into a golden field,
 * mint a version recording that, and mint a second version clearing it. Worse, any
 * read of merged_into between the two statements follows a merge pointer to an
 * identity that does not exist.
 *
 * NULLABLE, DEFAULT NULL, at the end of the row, so ADD COLUMN is ALGORITHM=INSTANT
 * (MySQL 8.0.12+) — a metadata change on a 13.4M-row table, no rebuild, no UPDATE
 * pass.
 *
 * DELIBERATELY UNINDEXED. The link INSERT joins stg_person on it and the final clear
 * scans for non-null values, both of which the merged_into version already did
 * unindexed. An index here would be maintained during the single biggest insert in
 * the pipeline, inside the exact window
 * SqlBackfill::withoutIdentityKeyIndexes() exists to keep index-free. The cost is
 * two full scans of gp_identity per residual run, which is the same cost the code
 * has today and is why the residual step is bulk-only.
 *
 * NOT a versioned attribute. It is absent from Versioner::TABLES, and
 * Versioner::carryForward() unsets it explicitly so a run that died between setting
 * and clearing cannot preserve a stale stg_person_id through every future version.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasColumn('gp_identity', 'stg_seed_id')) {
            return;
        }

        DB::connection($this->connection)->statement(
            'ALTER TABLE `gp_identity` ADD COLUMN `stg_seed_id` BIGINT UNSIGNED NULL DEFAULT NULL,
             ALGORITHM=INSTANT'
        );
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('gp_identity', 'stg_seed_id')) {
            return;
        }

        DB::connection($this->connection)->statement(
            'ALTER TABLE `gp_identity` DROP COLUMN `stg_seed_id`'
        );
    }
};

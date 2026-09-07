<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Refuses to let the set-based bulk paths run against a versioned schema they do
 * not understand.
 *
 * SetFinalizer and SqlBackfill are ~1,400 lines of raw MySQL. Plan 3a converted
 * the per-row paths; converting these is plan 3b, because it is not a matter of
 * adding `AND current = 1` to some subqueries:
 *
 *   - SetFinalizer::survivorship() runs NINE separate per-field UPDATEs against
 *     gp_identity. None of them can mint a version without minting up to nine per
 *     identity per run, so the whole half has to be restructured into one
 *     all-fields pass that compares the winning row to the current version and
 *     versions only the identities that actually changed.
 *   - SetFinalizer::materializeRange()'s aggregate subqueries ($lic, $addr, $cred,
 *     $excl, $idt) COUNT and JSON_ARRAYAGG every row for an identity. Unfiltered
 *     they aggregate history: license_count doubles on the first re-observation.
 *   - SqlBackfill::residualCreateAndLink() uses merged_into "as a temporary
 *     stg_person_id carrier so the 1:1 create + link stays fully set-based", then
 *     clears it. merged_into is now a versioned attribute recording where a merged
 *     identity went, so that reuse would write a stg_person_id into a golden field
 *     and mint two versions per identity doing it. It needs its own scratch column.
 *
 * Every one of those failures is SILENT — wrong counts and wrong canonical values,
 * not an exception. On a 13M-row hub, discovering it after the fact means a full
 * reload. So the guard is deliberately loud and deliberately blunt: while the
 * schema carries version columns and this class still exists, the bulk paths do
 * not run.
 *
 * DELETING THIS FILE IS PART OF PLAN 3b. Remove the two assertConverted() calls
 * and this class together with the conversion, in the same commit.
 */
class SetBasedPathGuard
{
    /** True once 2026_09_04_000100_add_scd2_versioning has run. */
    public function schemaIsVersioned(): bool
    {
        return Schema::connection(config('golden_profile.connections.hub', 'golden_profile'))
            ->hasColumn('gp_identity', 'version_no');
    }

    /**
     * @throws RuntimeException when the schema is versioned and $class is not
     */
    public function assertConverted(string $class): void
    {
        if (! $this->schemaIsVersioned()) {
            return;
        }

        $short = class_basename($class);

        throw new RuntimeException(
            "$short has not been converted to SCD-2 and would corrupt a versioned hub: it ".
            'aggregates superseded rows into the profile rollups and overwrites gp_identity in '.
            'place instead of versioning it. Both failures are silent. Convert it (plan 3b) or '.
            'use the per-row path — Engine::backfill() and Engine::finalizeAll() — instead.'
        );
    }
}

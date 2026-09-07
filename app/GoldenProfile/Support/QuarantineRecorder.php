<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a staged-person row (post junk-cleaning) has any usable
 * identity signal at all, and records + alerts on the ones that don't.
 *
 * "Alert" is concrete, not aspirational: a queryable gp_quarantine row for a
 * dashboard, plus a Log::critical() with a fixed, greppable message prefix
 * (GP_QUARANTINE_ALERT) so CloudWatch-style log-based alerting can filter on
 * it — the same mechanism this codebase's existing Log::warning/Log::error
 * calls already rely on, just at critical severity because silently never
 * staging a row is worse than a log line nobody reads.
 */
class QuarantineRecorder
{
    /**
     * @param  array<string,mixed>  $personRow  the array personRow()/stage() would insert
     * @param  list<array<string,mixed>>  $licenses  that row's license child rows
     */
    public function evaluate(array $personRow, array $licenses): ?string
    {
        $hasIdentifyingData = ! empty($personRow['last_name'])
            || ! empty($personRow['first_name'])
            || ! empty($personRow['npi'])
            || ! empty($personRow['ssn_hash'])
            || ! empty($personRow['dea_number'])
            || $licenses !== [];

        return $hasIdentifyingData ? null : 'no_identifying_data';
    }

    public function record(int $systemId, string $sourceTable, int $sourceId, string $reason, array $detail = []): void
    {
        DB::connection(config('golden_profile.connections.hub', 'golden_profile'))
            ->table('gp_quarantine')
            ->updateOrInsert(
                ['system_id' => $systemId, 'source_table' => $sourceTable, 'source_id' => $sourceId],
                ['reason' => $reason, 'detail' => json_encode($detail), 'quarantined_at' => now()]
            );

        Log::critical("GP_QUARANTINE_ALERT: row quarantined ($reason)", [
            'system_id' => $systemId, 'source_table' => $sourceTable, 'source_id' => $sourceId,
        ]);
    }
}

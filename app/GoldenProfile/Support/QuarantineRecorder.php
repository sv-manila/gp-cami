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
     * $identifiers is the row's multi-valued DEA/MMIS identifiers, and it is
     * load-bearing rather than belt-and-braces. $personRow['dea_number'] is
     * hardcoded null by StreamlineLocalConnector::personRow() ("not present in
     * this source"), and config/golden_profile.php says the same of the
     * dea_number tier, so for streamline_local that condition is dead code.
     * The REAL DEA and MMIS values arrive through employee_additional_info and
     * are pivoted by additionalRows(). Without them here, a row whose only
     * identifying data is a DEA or MMIS number would be quarantined and its
     * identifier never staged — precisely the row plan 5 promotes those two
     * into match keys for. $licenses must likewise include the additional-info
     * licences, not just childRows()'s.
     *
     * @param  array<string,mixed>  $personRow  the array personRow()/stage() would insert
     * @param  list<array<string,mixed>>  $licenses  every licence child row, additional-info included
     * @param  list<array<string,mixed>>  $identifiers  every DEA/MMIS identifier child row
     */
    public function evaluate(array $personRow, array $licenses, array $identifiers = []): ?string
    {
        $hasIdentifyingData = ! empty($personRow['last_name'])
            || ! empty($personRow['first_name'])
            || ! empty($personRow['npi'])
            || ! empty($personRow['ssn_hash'])
            || ! empty($personRow['dea_number'])
            || $licenses !== []
            || $identifiers !== [];

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

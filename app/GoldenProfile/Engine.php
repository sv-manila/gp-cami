<?php

namespace App\GoldenProfile;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
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

    public function __construct()
    {
        $this->systemId = $this->ensureSystem();
        $this->connector = new StreamlineLocalConnector($this->systemId);
        $this->resolver = new DeterministicResolver($this->systemId);
        $this->materializer = new ProfileMaterializer;
        $this->survivorship = new Survivorship;
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
        $hub->table('gp_source_system')->updateOrInsert(
            ['system_code' => self::SYSTEM_CODE],
            ['display_name' => 'StreamlineVerify local', 'reliability_rank' => 50, 'is_active' => 1, 'added_at' => now()],
        );

        return (int) $hub->table('gp_source_system')->where('system_code', self::SYSTEM_CODE)->value('system_id');
    }

    /** Mode 1 — full backfill over every employee. Returns count processed. */
    public function backfill(?int $fromId = null, int $chunk = 1000, ?callable $progress = null): int
    {
        $count = 0;
        $maxModified = null;
        $q = $this->src()->table(self::SOURCE_TABLE)->orderBy('id');
        if ($fromId) {
            $q->where('id', '>=', $fromId);
        }
        $q->chunkById($chunk, function ($rows) use (&$count, &$maxModified, $progress) {
            $identityIds = [];
            $empIds = [];
            foreach ($rows as $emp) {
                $stgId = $this->connector->ingest($emp);
                $identityIds[$this->resolver->resolve($stgId)] = true;
                $empIds[] = $emp->id;
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

        if ($maxModified) {
            $this->setWatermark(self::SOURCE_TABLE, $maxModified);
        }

        return $count;
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
            $identityIds = [];
            $empIds = [];
            foreach ($rows as $emp) {
                $stgId = $this->connector->ingest($emp);
                $identityIds[$this->resolver->resolve($stgId)] = true;
                $empIds[] = $emp->id;
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

    /** credential_matches -> gp_identity_credential (confirmed links). */
    private function rollupCredentials(array $employeeIds): void
    {
        if (! $employeeIds) {
            return;
        }
        $excludeCodes = config('golden_profile.credential_search.rollup_exclude_status_codes', []);
        $rows = $this->src()->table('credential_matches')->whereIn('employee_id', $employeeIds)->get();
        foreach ($rows as $c) {
            $identityId = $this->identityForSource((int) $c->employee_id);
            if (! $identityId) {
                continue;
            }
            // Pending / Error matches are not part of the golden data — never roll them up.
            if (in_array((int) $c->match_summary_status_code, $excludeCodes, true)) {
                $this->hub()->table('gp_identity_credential')
                    ->where(['system_id' => $this->systemId, 'credential_match_id' => $c->id])->delete();

                continue;
            }
            $this->hub()->table('gp_identity_credential')->updateOrInsert(
                ['system_id' => $this->systemId, 'credential_match_id' => $c->id],
                [
                    'identity_id' => $identityId,
                    'registry' => $c->registry,
                    'match_summary_status' => $c->match_summary_status,
                    'match_summary_status_code' => $c->match_summary_status_code,
                    'match_is_valid' => $c->match_is_valid,
                    'current' => $c->current,
                    'date_resolved' => $this->dt($c->date_resolved),
                    'link_state' => 'confirmed',
                ],
            );
        }
    }

    /** matches (exclusion hits) -> gp_identity_exclusion (candidate links). */
    private function rollupExclusions(array $employeeIds): void
    {
        if (! $employeeIds) {
            return;
        }
        $rows = $this->src()->table('matches')->whereIn('employee_id', $employeeIds)->get();
        foreach ($rows as $m) {
            $identityId = $this->identityForSource((int) $m->employee_id);
            if (! $identityId) {
                continue;
            }
            $registry = null;
            if ($m->exclusion_record_id) {
                $registry = $this->src()->table('exclusion_records')
                    ->where('id', $m->exclusion_record_id)->value('exclusion_list_prefix');
            }
            $this->hub()->table('gp_identity_exclusion')->updateOrInsert(
                ['system_id' => $this->systemId, 'match_id' => $m->id],
                [
                    'identity_id' => $identityId,
                    'exclusion_record_id' => $m->exclusion_record_id,
                    'registry' => $registry,
                    'is_ssn_match' => $m->is_ssn_match,
                    'is_npi_match' => $m->is_npi_match,
                    'is_canonical_name_match' => $m->is_canonical_name_match,
                    'is_upin_match' => $m->is_upin_match,
                    'is_license_number_match' => $m->is_license_number_match,
                    'link_state' => 'candidate',
                ],
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
            : $this->hub()->table('gp_identity')->pluck('identity_id')->all();
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
            return \Illuminate\Support\Carbon::parse($v)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}

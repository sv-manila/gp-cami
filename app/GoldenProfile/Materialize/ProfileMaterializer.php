<?php

namespace App\GoldenProfile\Materialize;

use Illuminate\Support\Facades\DB;

/**
 * Rebuilds gp_identity_profile — one wide, denormalized row per identity —
 * from the graph. Always rebuildable; never edited directly.
 */
class ProfileMaterializer
{
    private function hub()
    {
        return DB::connection('golden_profile');
    }

    public function rebuild(int $identityId): void
    {
        $hub = $this->hub();
        $identity = $hub->table('gp_identity')->where('identity_id', $identityId)->first();
        if (! $identity) {
            return;
        }

        $links = $hub->table('gp_source_link')->where('identity_id', $identityId)->get();
        $systemCodes = $hub->table('gp_source_system')->pluck('system_code', 'system_id');

        $accounts = $links->pluck('account_id')->filter()->unique()->values();
        $sourceRecords = $links->map(fn ($l) => [
            'system_code' => $systemCodes[$l->system_id] ?? (string) $l->system_id,
            'source_table' => $l->source_table,
            'source_id' => (int) $l->source_id,
            'account_id' => $l->account_id ? (int) $l->account_id : null,
        ])->values();

        $stgIds = $this->stagedPersonIds($links);

        // aliases across all linked staged persons
        $aliases = $stgIds->isEmpty() ? collect() : $hub->table('stg_person_alias')
            ->whereIn('stg_person_id', $stgIds)
            ->get()
            ->map(fn ($a) => ['type' => $a->alias_type, 'first' => $a->first_name, 'last' => $a->last_name])
            ->unique(fn ($a) => $a['type'].'|'.$a['first'].'|'.$a['last'])->values();

        $licenses = $hub->table('gp_license')->where('identity_id', $identityId)->get()
            ->map(fn ($l) => [
                'number' => $l->license_number, 'state' => $l->certification_state,
                'board' => $l->certification_board, 'type' => $l->license_type,
                'registry' => $l->registry, 'verified' => (bool) $l->is_verified,
            ])->values();

        $addresses = $hub->table('gp_address')->where('identity_id', $identityId)->get();
        $primary = $addresses->firstWhere('is_primary', 1) ?? $addresses->first();
        $addressJson = $addresses->map(fn ($a) => [
            'type' => $a->is_primary ? 'primary' : 'alt',
            'address1' => $a->address1, 'address2' => $a->address2,
            'city' => $a->city, 'state' => $a->state, 'zip' => $a->zip,
        ])->values();

        $credentials = $hub->table('gp_identity_credential')->where('identity_id', $identityId)->get()
            ->map(fn ($c) => [
                'credential_match_id' => (int) $c->credential_match_id, 'registry' => $c->registry,
                'status' => $c->match_summary_status, 'status_code' => $c->match_summary_status_code,
                'valid' => (bool) $c->match_is_valid, 'current' => (bool) $c->current,
                'link_state' => $c->link_state,
            ])->values();

        $exclusions = $hub->table('gp_identity_exclusion')->where('identity_id', $identityId)->get()
            ->map(fn ($e) => [
                'match_id' => (int) $e->match_id, 'registry' => $e->registry,
                'is_ssn_match' => (bool) $e->is_ssn_match, 'is_npi_match' => (bool) $e->is_npi_match,
                'is_canonical_name_match' => (bool) $e->is_canonical_name_match,
                'is_license_number_match' => (bool) $e->is_license_number_match,
                'link_state' => $e->link_state,
            ])->values();
        $hasActiveExclusion = $exclusions->contains(fn ($e) => $e['link_state'] !== 'rejected') ? 1 : 0;

        $resolutions = $hub->table('gp_identity_resolution')
            ->where('identity_id', $identityId)->where('is_current', 1)->get()
            ->map(fn ($r) => [
                'domain' => $r->domain, 'target_key' => $r->target_key, 'decision' => $r->decision,
                'resolved_by' => $r->resolved_by, 'resolved_at' => (string) $r->resolved_at,
                'auto_resolvable' => (bool) $r->is_auto_resolvable,
            ])->values();

        // terminated flag = latest staged person's flag
        $terminated = $stgIds->isEmpty() ? null : (int) $hub->table('stg_person')
            ->whereIn('stg_person_id', $stgIds)->orderByDesc('source_modified')->value('terminated');

        $now = now();
        $hub->table('gp_identity_profile')->updateOrInsert(
            ['identity_id' => $identityId],
            [
                'identity_uuid' => $identity->identity_uuid,
                'first_name' => $identity->canonical_first,
                'middle_name' => $identity->canonical_middle,
                'last_name' => $identity->canonical_last,
                'date_of_birth' => $identity->canonical_dob,
                'ssn_hash' => $identity->ssn_hash,
                'ssn_last_four' => $this->ssnLastFour($stgIds),
                'npi' => $identity->npi,
                'upin' => $identity->upin,
                'dea_number' => $identity->dea_number,
                'address1' => $primary->address1 ?? null,
                'city' => $primary->city ?? null,
                'state' => $primary->state ?? null,
                'zip' => $primary->zip ?? null,
                'address_count' => $addresses->count(),
                'addresses' => $addressJson->toJson(),
                'terminated' => $terminated,
                'license_count' => $licenses->count(),
                'licenses' => $licenses->toJson(),
                'confidence' => $identity->confidence,
                'record_count' => $links->count(),
                'account_count' => $accounts->count(),
                'system_count' => $links->pluck('system_id')->unique()->count(),
                'aliases' => $aliases->toJson(),
                'source_records' => $sourceRecords->toJson(),
                'accounts' => $accounts->toJson(),
                'credential_count' => $credentials->count(),
                'credentials' => $credentials->toJson(),
                'exclusion_count' => $exclusions->count(),
                'has_active_exclusion' => $hasActiveExclusion,
                'exclusions' => $exclusions->toJson(),
                'resolution_count' => $resolutions->count(),
                'resolutions' => $resolutions->toJson(),
                'first_seen' => $identity->first_seen,
                'last_updated' => $identity->last_updated,
                'profile_built_at' => $now,
            ],
        );
    }

    private function stagedPersonIds($links)
    {
        if ($links->isEmpty()) {
            return collect();
        }
        $hub = $this->hub();
        $ids = collect();
        foreach ($links as $l) {
            $sid = $hub->table('stg_person')->where([
                'system_id' => $l->system_id, 'source_table' => $l->source_table, 'source_id' => $l->source_id,
            ])->value('stg_person_id');
            if ($sid) {
                $ids->push((int) $sid);
            }
        }

        return $ids->unique()->values();
    }

    private function ssnLastFour($stgIds): ?string
    {
        if ($stgIds->isEmpty()) {
            return null;
        }

        return $this->hub()->table('stg_person')->whereIn('stg_person_id', $stgIds)
            ->whereNotNull('ssn_last_four')->value('ssn_last_four');
    }
}

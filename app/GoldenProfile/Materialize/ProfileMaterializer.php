<?php

namespace App\GoldenProfile\Materialize;

use Illuminate\Support\Facades\DB;

/**
 * Rebuilds gp_identity_profile — one wide, denormalized row per identity —
 * from the graph. Always rebuildable; never edited directly.
 */
class ProfileMaterializer
{
    private AliasIndexer $aliasIndexer;

    public function __construct(?AliasIndexer $aliasIndexer = null)
    {
        $this->aliasIndexer = $aliasIndexer ?? new AliasIndexer;
    }

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    public function rebuild(int $identityId): void
    {
        $hub = $this->hub();
        // The profile is a projection of the CURRENT version of everything. It is
        // itself unversioned (a rebuildable read model whose rows reach 100MB of
        // JSON — see docs/SCD2.md), which makes these filters the only thing
        // keeping it correct. A missing one does not throw: it doubles a count and
        // duplicates a JSON entry.
        $identity = $hub->table('gp_identity')
            ->where('identity_id', $identityId)->where('current', 1)->first();
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

        // The searchable alias index is refreshed alongside the JSON rollup below.
        // Keeping the two writes together is the whole point: identity-search reads
        // gp_identity_alias, so if only the JSON were updated the index would drift
        // and the endpoint would answer from stale aliases.
        $this->aliasIndexer->refresh($identityId);

        // aliases across all linked staged persons
        $aliases = $stgIds->isEmpty() ? collect() : $hub->table('stg_person_alias')
            ->whereIn('stg_person_id', $stgIds)
            ->get()
            ->map(fn ($a) => ['type' => $a->alias_type, 'first' => $a->first_name, 'last' => $a->last_name])
            ->unique(fn ($a) => $a['type'].'|'.$a['first'].'|'.$a['last'])->values();

        $licenses = $hub->table('gp_license')
            ->where('identity_id', $identityId)->where('current', 1)
            ->orderBy('license_id')->get()
            ->map(fn ($l) => [
                'number' => $l->license_number, 'state' => $l->certification_state,
                'board' => $l->certification_board, 'type' => $l->license_type,
                'registry' => $l->registry, 'verified' => (bool) $l->is_verified,
            ])->values();

        $identifiers = $hub->table('gp_identity_identifier')
            ->where('identity_id', $identityId)->where('current', 1)
            ->orderBy('id')->get()
            ->map(fn ($r) => ['type' => $r->id_type, 'value' => $r->id_value])
            ->unique(fn ($r) => $r['type'].'|'.$r['value'])->values();
        // Fall back the profile's dea_number column to a DEA identifier for display.
        // Fall back the profile's dea_number column to a DEA identifier for display.
        //
        // max(), not firstWhere(): SetFinalizer's $idt picks
        // MAX(CASE WHEN id_type='dea' THEN id_value END), so an identity carrying
        // two DEA identifiers would otherwise get a different fallback from each
        // path and break the byte-identical-profile invariant.
        $deaFromIdentifier = $identifiers->where('type', 'dea')->max('value');

        // is_primary first, then lowest address_id — the same order as
        // SetFinalizer's $prim window function. Without the orderBy this read
        // returned rows in whatever order the server chose and firstWhere() could
        // pick a different address than the bulk path did, so the documented
        // byte-identical-profile invariant held by luck rather than by design.
        $addresses = $hub->table('gp_address')
            ->where('identity_id', $identityId)->where('current', 1)
            ->orderBy('address_id')->get();
        $primary = $addresses->firstWhere('is_primary', 1) ?? $addresses->first();
        $addressJson = $addresses->map(fn ($a) => [
            'type' => $a->is_primary ? 'primary' : 'alt',
            'address1' => $a->address1, 'address2' => $a->address2,
            'city' => $a->city, 'state' => $a->state, 'zip' => $a->zip,
        ])->values();

        $credentials = $hub->table('gp_identity_credential')
            ->where('identity_id', $identityId)->where('current', 1)->get()
            ->map(fn ($c) => [
                'credential_match_id' => (int) $c->credential_match_id, 'registry' => $c->registry,
                'status' => $c->match_summary_status, 'status_code' => $c->match_summary_status_code,
                // The JSON key stays `current` (published response shape); the
                // column behind it is source_current — CAMI's flag, not the
                // version flag.
                'valid' => (bool) $c->match_is_valid, 'current' => (bool) $c->source_current,
                'link_state' => $c->link_state,
            ])->values();

        $exclusions = $hub->table('gp_identity_exclusion')
            ->where('identity_id', $identityId)->where('current', 1)->get()
            ->map(fn ($e) => [
                'match_id' => (int) $e->match_id, 'registry' => $e->registry,
                'is_ssn_match' => (bool) $e->is_ssn_match, 'is_npi_match' => (bool) $e->is_npi_match,
                'is_canonical_name_match' => (bool) $e->is_canonical_name_match,
                'is_license_number_match' => (bool) $e->is_license_number_match,
                'link_state' => $e->link_state,
            ])->values();
        $hasActiveExclusion = $exclusions->contains(fn ($e) => $e['link_state'] !== 'rejected') ? 1 : 0;

        $boardActions = $hub->table('gp_board_action')->where('identity_id', $identityId)->get()
            ->map(fn ($b) => [
                'registry' => $b->registry, 'action_type' => $b->action_type,
                'action_date' => (string) $b->action_date, 'resolution_date' => (string) $b->resolution_date,
            ])->values();
        $hasActiveBoardAction = $boardActions->contains(fn ($b) => empty($b['resolution_date'])) ? 1 : 0;

        $resolutions = $hub->table('gp_identity_resolution')
            ->where('identity_id', $identityId)->where('is_current', 1)->get()
            ->map(fn ($r) => [
                'domain' => $r->domain, 'target_key' => $r->target_key, 'decision' => $r->decision,
                'resolved_by' => $r->resolved_by, 'resolved_at' => (string) $r->resolved_at,
                'auto_resolvable' => (bool) $r->is_auto_resolvable,
            ])->values();

        // terminated flag = latest staged person's flag
        // terminated flag = latest staged person's flag. The stg_person_id DESC tail
        // matches SetFinalizer's $term window (source_modified DESC, stg_person_id
        // DESC); without it two rows with the same source_modified could resolve
        // differently on the two paths.
        $terminated = $stgIds->isEmpty() ? null : (int) $hub->table('stg_person')
            ->whereIn('stg_person_id', $stgIds)
            ->orderByDesc('source_modified')->orderByDesc('stg_person_id')
            ->value('terminated');

        $now = now();
        $hub->table('gp_identity_profile')->updateOrInsert(
            ['identity_id' => $identityId],
            [
                'identity_uuid' => $identity->identity_uuid,
                'first_name' => $identity->canonical_first,
                'middle_name' => $identity->canonical_middle,
                'last_name' => $identity->canonical_last,
                'suffix' => $identity->canonical_suffix ?? null,
                'date_of_birth' => $identity->canonical_dob,
                'ssn_hash' => $identity->ssn_hash,
                'ssn_last_four' => $this->ssnLastFour($stgIds),
                'npi' => $identity->npi,
                'upin' => $identity->upin,
                'dea_number' => $identity->dea_number ?: $deaFromIdentifier,
                'identifier_count' => $identifiers->count(),
                'identifiers' => $identifiers->toJson(),
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
                'board_action_count' => $boardActions->count(),
                'has_active_board_action' => $hasActiveBoardAction,
                'board_actions' => $boardActions->toJson(),
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
        // Batch by (system_id, source_table): one query per group with an
        // IN() on source_id, instead of one query per link (N+1).
        $hub = $this->hub();
        $ids = collect();
        foreach ($links->groupBy(fn ($l) => $l->system_id.'|'.$l->source_table) as $group) {
            $first = $group->first();
            $sourceIds = $group->pluck('source_id')->unique()->all();
            $found = $hub->table('stg_person')
                ->where('system_id', $first->system_id)
                ->where('source_table', $first->source_table)
                ->whereIn('source_id', $sourceIds)
                ->pluck('stg_person_id');
            foreach ($found as $sid) {
                $ids->push((int) $sid);
            }
        }

        return $ids->unique()->values();
    }

    /**
     * Lowest stg_person_id with a non-null ssn_last_four — the same pick as
     * SetFinalizer's $ssn4 window (ORDER BY stg_person_id ASC). Without the
     * ordering this returned whichever row the server offered first, so the two
     * paths could disagree on an identity with more than one staged SSN tail.
     *
     * Deleted by plan 2 along with the column.
     */
    private function ssnLastFour($stgIds): ?string
    {
        if ($stgIds->isEmpty()) {
            return null;
        }

        return $this->hub()->table('stg_person')->whereIn('stg_person_id', $stgIds)
            ->whereNotNull('ssn_last_four')
            ->orderBy('stg_person_id')
            ->value('ssn_last_four');
    }
}

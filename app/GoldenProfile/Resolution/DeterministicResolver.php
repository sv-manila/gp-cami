<?php

namespace App\GoldenProfile\Resolution;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pass A — deterministic identity resolution. For a staged person, try exact
 * high-precision keys in confidence order; the first that fires binds the row
 * to that identity. No key hit => a new identity. Idempotent per source row.
 */
class DeterministicResolver
{
    public function __construct(private int $systemId) {}

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /** Resolve one staged person to an identity_id. */
    public function resolve(int $stgPersonId): int
    {
        $hub = $this->hub();
        $p = $hub->table('stg_person')->where('stg_person_id', $stgPersonId)->first();
        $licenses = $hub->table('stg_person_license')->where('stg_person_id', $stgPersonId)->get();

        // Idempotent: an existing link for this source row wins.
        $existing = $hub->table('gp_source_link')->where([
            'system_id' => $this->systemId,
            'source_table' => $p->source_table,
            'source_id' => $p->source_id,
        ])->first();

        if ($existing) {
            $identityId = (int) $existing->identity_id;
            $this->enrich($identityId, $p, $licenses, (int) $existing->link_id);

            return $identityId;
        }

        [$identityId, $key, $conf] = $this->matchDeterministic($p, $licenses);

        if ($identityId === null) {
            $identityId = $this->createIdentity($p);
            $key = 'new';
            $conf = 1.0;
            $method = 'deterministic';
        } else {
            $method = 'deterministic';
            $this->backfillKeys($identityId, $p);
        }

        $linkId = (int) $hub->table('gp_source_link')->insertGetId([
            'identity_id' => $identityId,
            'system_id' => $this->systemId,
            'source_table' => $p->source_table,
            'source_id' => $p->source_id,
            'account_id' => $p->account_id,
            'employeelist_id' => $p->employeelist_id,
            'match_method' => $method,
            'match_key' => $key,
            'match_score' => $conf,
            'linked_at' => now(),
        ]);

        // record count + freshness
        $hub->table('gp_identity')->where('identity_id', $identityId)->update([
            'record_count' => $hub->table('gp_source_link')->where('identity_id', $identityId)->count(),
            'last_updated' => now(),
        ]);

        $this->enrich($identityId, $p, $licenses, $linkId);

        return $identityId;
    }

    /** @return array{0:?int,1:?string,2:?float} [identity_id, match_key, confidence] */
    private function matchDeterministic(object $p, $licenses): array
    {
        $hub = $this->hub();

        if ($p->ssn_hash) {
            $id = $hub->table('gp_identity')->where('ssn_hash', $p->ssn_hash)->where('status', 'active')->value('identity_id');
            if ($id) {
                return [(int) $id, 'ssn_hash', 0.99];
            }
        }
        if ($p->npi) {
            $id = $hub->table('gp_identity')->where('npi', $p->npi)->where('status', 'active')->value('identity_id');
            if ($id) {
                return [(int) $id, 'npi', 0.99];
            }
        }
        if ($p->dea_number) {
            $id = $hub->table('gp_identity')->where('dea_number', $p->dea_number)->where('status', 'active')->value('identity_id');
            if ($id) {
                return [(int) $id, 'dea_number', 0.99];
            }
        }
        if ($p->upin) {
            $id = $hub->table('gp_identity')->where('upin', $p->upin)->where('status', 'active')->value('identity_id');
            if ($id) {
                return [(int) $id, 'upin', 0.99];
            }
        }
        // license_number + certification_state (any of the person's licenses)
        foreach ($licenses as $lic) {
            $q = $hub->table('gp_license as l')
                ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
                ->where('i.status', 'active')
                ->where('l.license_number', $lic->license_number);
            if ($lic->certification_state) {
                $q->where('l.certification_state', $lic->certification_state);
            } else {
                $q->whereNull('l.certification_state');
            }
            $id = $q->value('l.identity_id');
            if ($id) {
                return [(int) $id, 'license_registry', 0.99];
            }
        }
        // name + dob (lower confidence)
        if ($p->last_name && $p->first_name && $p->date_of_birth) {
            $id = $hub->table('gp_identity')
                ->where('status', 'active')
                ->whereRaw('LOWER(canonical_last) = ?', [mb_strtolower($p->last_name)])
                ->whereRaw('LOWER(canonical_first) = ?', [mb_strtolower($p->first_name)])
                ->whereDate('canonical_dob', $p->date_of_birth)
                ->value('identity_id');
            if ($id) {
                return [(int) $id, 'name_dob', 0.95];
            }
        }

        return [null, null, null];
    }

    private function createIdentity(object $p): int
    {
        $now = now();

        return (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => $p->first_name,
            'canonical_middle' => $p->middle_name,
            'canonical_last' => $p->last_name,
            'canonical_dob' => $p->date_of_birth,
            'ssn_hash' => $p->ssn_hash,
            'npi' => $p->npi,
            'upin' => $p->upin,
            'dea_number' => $p->dea_number,
            'confidence' => 1.0,
            'record_count' => 0,
            'status' => 'active',
            'first_seen' => $now,
            'last_updated' => $now,
        ]);
    }

    /** Backfill identity keys that were null when a later row supplies them. */
    private function backfillKeys(int $identityId, object $p): void
    {
        $id = $this->hub()->table('gp_identity')->where('identity_id', $identityId)->first();
        $upd = [];
        foreach (['ssn_hash', 'npi', 'upin', 'dea_number', 'canonical_dob'] as $col) {
            $srcCol = $col === 'canonical_dob' ? 'date_of_birth' : $col;
            if (empty($id->$col) && ! empty($p->$srcCol)) {
                $upd[$col] = $p->$srcCol;
            }
        }
        foreach (['canonical_first' => 'first_name', 'canonical_last' => 'last_name', 'canonical_middle' => 'middle_name'] as $col => $src) {
            if (empty($id->$col) && ! empty($p->$src)) {
                $upd[$col] = $p->$src;
            }
        }
        if ($upd) {
            $this->hub()->table('gp_identity')->where('identity_id', $identityId)->update($upd);
        }
    }

    /** Add licenses + addresses + basic attribute provenance for this source row. */
    private function enrich(int $identityId, object $p, $licenses, int $linkId): void
    {
        $hub = $this->hub();

        foreach ($licenses as $lic) {
            $hub->table('gp_license')->updateOrInsert(
                [
                    'identity_id' => $identityId,
                    'license_number' => $lic->license_number,
                    'certification_state' => $lic->certification_state,
                    'certification_board' => $lic->certification_board,
                ],
                [
                    'license_type' => $lic->license_type,
                    'license_type_id' => $lic->license_type_id,
                    'registry' => $lic->registry,
                    'source_link_id' => $linkId,
                ],
            );
        }

        $addrs = $hub->table('stg_person_address')->where('stg_person_id', $p->stg_person_id)->get();
        foreach ($addrs as $a) {
            $hub->table('gp_address')->updateOrInsert(
                [
                    'identity_id' => $identityId,
                    'address1' => $a->address1,
                    'city' => $a->city,
                    'state' => $a->state,
                    'zip' => $a->zip,
                ],
                [
                    'address2' => $a->address2,
                    'is_primary' => $a->address_type === 'primary' ? 1 : 0,
                    'source_link_id' => $linkId,
                ],
            );
        }
    }
}

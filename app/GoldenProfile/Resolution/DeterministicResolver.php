<?php

namespace App\GoldenProfile\Resolution;

use App\GoldenProfile\Support\JunkKeyGuard;
use App\GoldenProfile\Support\SsnHashGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pass A — deterministic identity resolution. For a staged person, try exact
 * high-precision keys in confidence order; the first that fires binds the row
 * to that identity. No key hit => a new identity. Idempotent per source row.
 */
class DeterministicResolver
{
    private ProbabilisticResolver $probabilistic;

    private SsnHashGuard $ssnGuard;

    private JunkKeyGuard $junkGuard;

    /** Bind confidence per key, from config instead of literals. */
    private array $keyConfidence;

    public function __construct(private int $systemId)
    {
        $this->probabilistic = new ProbabilisticResolver($systemId);
        $this->ssnGuard = new SsnHashGuard;
        $this->junkGuard = new JunkKeyGuard;
        $this->keyConfidence = config('golden_profile.deterministic_keys', []);
    }

    /**
     * Bind confidence for a deterministic key. Previously these were hardcoded
     * literals, which silently ignored config('golden_profile.deterministic_keys')
     * — tuning the config changed nothing.
     */
    private function confidence(string $configKey, float $fallback): float
    {
        return (float) ($this->keyConfidence[$configKey] ?? $fallback);
    }

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
        $identifiers = $hub->table('stg_person_identifier')->where('stg_person_id', $stgPersonId)->get();

        // Idempotent: an existing link for this source row wins.
        $existing = $hub->table('gp_source_link')->where([
            'system_id' => $this->systemId,
            'source_table' => $p->source_table,
            'source_id' => $p->source_id,
        ])->first();

        if ($existing) {
            $identityId = (int) $existing->identity_id;
            // A pinned link is a locked human decision — never re-matched or re-enriched.
            if (! $existing->is_pinned) {
                $this->enrich($identityId, $p, $licenses, (int) $existing->link_id, $identifiers);
            }

            return $identityId;
        }

        [$identityId, $key, $conf] = $this->matchDeterministic($p, $licenses, $identifiers);
        $method = 'deterministic';
        $matchState = 'auto_match';

        if ($identityId === null) {
            // Pass A missed — try Pass B probabilistic.
            [$pid, $score, $state] = $this->probabilistic->match($p, $licenses);
            if ($pid !== null && in_array($state, ['auto_match', 'review'], true)) {
                $identityId = $pid;
                $key = 'probabilistic';
                $conf = $score;
                $method = 'probabilistic';
                $matchState = $state;
                if ($state === 'review') {
                    $this->logReview($identityId, $p, $score);
                } else {
                    $this->backfillKeys($identityId, $p);
                }
            } else {
                $identityId = $this->createIdentity($p);
                $key = 'new';
                $conf = 1.0;
            }
        } else {
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
            'match_state' => $matchState,
            'is_pinned' => 0,
            'linked_at' => now(),
        ]);

        // record_count + freshness are set during finalize (Survivorship),
        // which already loads every linked row — avoids a per-row COUNT+UPDATE.
        $this->enrich($identityId, $p, $licenses, $linkId, $identifiers);

        return $identityId;
    }

    /** @return array{0:?int,1:?string,2:?float} [identity_id, match_key, confidence] */
    private function matchDeterministic(object $p, $licenses, $identifiers = []): array
    {
        $hub = $this->hub();

        // Every tier below adds orderBy('identity_id') before ->value(). Without it
        // the winner among several rows sharing a key is whatever storage order
        // returns, so the same source row could bind to different identities across
        // runs — and dedup()'s own docs acknowledge multiple active identities can
        // share a key before dedup runs. The set-based backfill already pins
        // MIN(identity_id); this makes the per-row path agree with it.
        //
        // ssn_hash is additionally screened for filler values: a shared placeholder
        // SSN would otherwise collapse every person carrying it into one identity
        // at 0.99 confidence with no name or DOB cross-check. See SsnHashGuard.
        if ($p->ssn_hash && ! $this->ssnGuard->isBlocked($p->ssn_hash)) {
            $id = $hub->table('gp_identity')->where('ssn_hash', $p->ssn_hash)->where('status', 'active')
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'ssn_hash', $this->confidence('ssn_hash', 0.99)];
            }
        }
        // Junk-screened the same way ssn_hash is above: a shared filler NPI
        // would otherwise collapse every person carrying it at 0.99 confidence
        // with no name or DOB cross-check. See JunkKeyGuard.
        if ($p->npi && ! $this->junkGuard->isBlocked('npi', (string) $p->npi)) {
            $id = $hub->table('gp_identity')->where('npi', $p->npi)->where('status', 'active')
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'npi', $this->confidence('npi', 0.99)];
            }
        }
        if ($p->dea_number) {
            $id = $hub->table('gp_identity')->where('dea_number', $p->dea_number)->where('status', 'active')
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'dea_number', $this->confidence('dea_number', 0.99)];
            }
        }
        if ($p->upin) {
            $id = $hub->table('gp_identity')->where('upin', $p->upin)->where('status', 'active')
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'upin', $this->confidence('upin', 0.99)];
            }
        }
        // Multi-valued identifiers (DEA, MMIS). Real-time here because each
        // row is resolved sequentially — by the time THIS row is resolved,
        // every earlier row's enrich() call (below) has already written any
        // identifier it carried into gp_identity_identifier, so there is no
        // chicken-and-egg the way there would be for a set-based bulk tier
        // (see this task's docblock, and the existing comment in
        // SqlBackfill::resolveDeterministic() about why license works the
        // same way). MMIS is scoped by state; DEA (federal) is not.
        foreach ($identifiers as $ident) {
            $q = $hub->table('gp_identity_identifier as l')
                ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
                ->where('i.status', 'active')
                ->where('l.id_type', $ident->id_type)
                ->where('l.id_value', $ident->id_value);
            if ($ident->id_type === 'mmis') {
                $ident->state === null ? $q->whereNull('l.state') : $q->where('l.state', $ident->state);
            }
            $id = $q->orderBy('l.identity_id')->value('l.identity_id');
            if ($id) {
                $confKey = $ident->id_type === 'mmis' ? 'mmis+state' : 'dea_multi';

                return [(int) $id, $confKey, $this->confidence($confKey, 0.99)];
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
            $id = $q->orderBy('l.identity_id')->value('l.identity_id');
            if ($id) {
                return [(int) $id, 'license_registry', $this->confidence('license_number+certification_state', 0.99)];
            }
        }
        // name + dob (lower confidence).
        // Plain column comparisons on purpose: the name columns are
        // utf8mb4_unicode_ci (already case-insensitive) and canonical_dob is a
        // DATE, so LOWER()/whereDate() only served to make the predicate
        // non-sargable — idx_name_dob (canonical_last, canonical_first,
        // canonical_dob) was skipped and every probe scanned ~6.5M rows
        // (EXPLAIN: type=ref key=idx_status rows=6475711 vs key=idx_name_dob rows=1),
        // which pinned incremental sync at ~0.03 rows/sec.
        if ($p->last_name && $p->first_name && $p->date_of_birth) {
            $id = $hub->table('gp_identity')
                ->where('status', 'active')
                ->where('canonical_last', $p->last_name)
                ->where('canonical_first', $p->first_name)
                ->where('canonical_dob', $p->date_of_birth)
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'name_dob', $this->confidence('name+dob', 0.95)];
            }
        }

        return [null, null, null];
    }

    /** Mid-confidence probabilistic bind — flag for steward review (reversible). */
    private function logReview(int $identityId, object $p, float $score): void
    {
        $this->hub()->table('gp_resolution_log')->insert([
            'action' => 'relink',
            'identity_id' => $identityId,
            'affected_ids' => json_encode(['source_id' => (int) $p->source_id, 'stg_person_id' => (int) $p->stg_person_id]),
            'match_key' => 'probabilistic',
            'reason' => 'review-band probabilistic match score='.round($score, 4).' — steward confirm/split',
            'actor' => 'engine',
            'created_at' => now(),
        ]);
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
                // Never promote a filler ssn_hash/npi onto an identity that
                // lacks one: it would spread the placeholder across more
                // identities and hand later rows a bogus 0.99 key to match on.
                if ($col === 'ssn_hash' && $this->ssnGuard->isBlocked($p->$srcCol)) {
                    continue;
                }
                if ($col === 'npi' && $this->junkGuard->isBlocked('npi', (string) $p->$srcCol)) {
                    continue;
                }
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
    private function enrich(int $identityId, object $p, $licenses, int $linkId, $identifiers = []): void
    {
        $hub = $this->hub();

        // The per-row path never wrote gp_identity_identifier before this —
        // only SqlBackfill::enrich() did — which is what made the real-time
        // identifier tier above possible: an earlier row's identifiers are on
        // the identity by the time a later row is resolved. state is not part
        // of the unique key, deliberately (see the 2026_09_05_000000
        // migration), so it is updated rather than matched on here.
        foreach ($identifiers as $ident) {
            $hub->table('gp_identity_identifier')->updateOrInsert(
                ['identity_id' => $identityId, 'id_type' => $ident->id_type, 'id_value' => $ident->id_value],
                ['state' => $ident->state, 'source_link_id' => $linkId],
            );
        }

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

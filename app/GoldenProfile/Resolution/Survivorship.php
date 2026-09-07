<?php

namespace App\GoldenProfile\Resolution;

use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Facades\DB;

/**
 * Per-field survivorship (GPP "Building One Trusted Record" + conflict-resolution research).
 * The winning source is chosen per attribute: identity leans verified -> NPI registry ->
 * source -> scrape; recency breaks ties. Winners are written to gp_identity canonical_*,
 * with full provenance in gp_attribute (is_canonical) and gp_survivorship_audit.
 */
class Survivorship
{
    private array $authority;

    private Versioner $versioner;

    public function __construct()
    {
        $this->authority = config('golden_profile.survivorship.field_authority');
        $this->versioner = new Versioner;
    }

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /**
     * identity field <- staged column.
     *
     * Must stay identical, in content AND order, to
     * SetFinalizer::IDENTITY_FIELDS — the two classes are the per-row and
     * set-based halves of the same computation and ProfileHasNoSsnTest asserts
     * they agree. ssn_hash was the last entry; the GPP conformance programme
     * removed it (Delivery Checklist §1), which also stops the hash being copied
     * into gp_attribute and gp_survivorship_audit on every recompute.
     */
    private const IDENTITY_FIELDS = [
        'canonical_first' => 'first_name',
        'canonical_middle' => 'middle_name',
        'canonical_last' => 'last_name',
        'canonical_suffix' => 'name_suffix',
        'canonical_dob' => 'date_of_birth',
        'npi' => 'npi',
        'upin' => 'upin',
        'dea_number' => 'dea_number',
    ];

    public function recompute(int $identityId): void
    {
        $hub = $this->hub();

        // all staged rows linked to this identity, with their source system_code
        $rows = $hub->table('gp_source_link as l')
            ->join('stg_person as sp', function ($j) {
                $j->on('sp.system_id', '=', 'l.system_id')
                    ->on('sp.source_table', '=', 'l.source_table')
                    ->on('sp.source_id', '=', 'l.source_id');
            })
            ->join('gp_source_system as ss', 'ss.system_id', '=', 'l.system_id')
            ->where('l.identity_id', $identityId)
            ->get(['sp.*', 'l.link_id', 'ss.system_code', 'ss.reliability_rank']);

        if ($rows->isEmpty()) {
            return;
        }

        $order = $this->authority['identity'] ?? [];
        $now = now();
        $update = [];
        $attrRows = [];
        $auditRows = [];

        foreach (self::IDENTITY_FIELDS as $canonical => $srcCol) {
            $candidates = $rows->filter(fn ($r) => ! self::isBlank($r->$srcCol))->values();
            if ($candidates->isEmpty()) {
                continue;
            }

            $ranked = $candidates->sort(function ($a, $b) use ($order) {
                $ra = self::authorityRank($order, $a->system_code, $a->reliability_rank);
                $rb = self::authorityRank($order, $b->system_code, $b->reliability_rank);
                if ($ra !== $rb) {
                    return $ra <=> $rb;                 // lower rank = higher authority
                }
                $recency = strcmp((string) $b->source_modified, (string) $a->source_modified); // newer wins
                if ($recency !== 0) {
                    return $recency;
                }

                // Final tiebreak MUST match SetFinalizer's SQL ordering (which ends
                // in link_id ASC). Without it, a full authority+recency tie is
                // broken by whatever order the DB returned rows in, so the
                // incremental path here and the bulk path there could crown
                // different canonical winners for the same identity — which breaks
                // the "rebuild produces a byte-identical profile" invariant.
                return $a->link_id <=> $b->link_id;
            })->values();

            $winner = $ranked->first();
            $value = $winner->$srcCol;
            $update[$canonical] = $value;

            foreach ($candidates as $c) {
                $attrRows[] = [
                    'identity_id' => $identityId,
                    'attr_name' => $canonical,
                    'attr_value' => is_string($c->$srcCol) ? mb_substr($c->$srcCol, 0, 255) : $c->$srcCol,
                    'source_link_id' => $c->link_id,
                    'is_canonical' => $c->link_id === $winner->link_id ? 1 : 0,
                    'observed_at' => $now,
                ];
            }
            $auditRows[] = [
                'identity_id' => $identityId,
                'attribute_name' => $canonical,
                'surviving_value' => is_string($value) ? mb_substr($value, 0, 500) : $value,
                'system_id' => $winner->system_id,
                'source_link_id' => $winner->link_id,
                'rule_applied' => 'authority['.$winner->system_code.'] + recency',
                'decided_at' => $now,
            ];
        }

        // The canonical winners are golden facts, so they are written as a VERSION
        // rather than an update (Data Flow by CAMI: "insert a new row with
        // current = 1, and set all preexisting rows to current = 0").
        //
        // record_count goes in as DERIVED, and last_updated is not passed at all.
        // Both used to be folded into the same in-place update as the canonical
        // fields, and both would defeat versioning if they were treated as facts:
        // finalizeAll() recomputes every identity, so an unconditional
        // last_updated => now() would mint ~13.38M rows a run, and a record_count
        // bump would mint one per source row (~13.4M on a backfill) to record
        // something gp_source_link already holds with better resolution.
        // Versioner owns last_updated: it stamps it only on a version that is
        // actually written, which is what makes it mean "when the golden facts last
        // changed" rather than "when we last looked".
        //
        // $update carries only the fields that had candidates; the rest carry
        // forward from the previous version, which is the same partial-write
        // behaviour the old ->update($update) had.
        $this->versioner->write(
            'gp_identity',
            ['identity_id' => $identityId],
            $update,
            ['record_count' => $rows->count()],
        );

        // rewrite provenance for identity attributes (idempotent per identity).
        // NOT versioned: gp_attribute and gp_survivorship_audit are per-observation
        // provenance — they ARE the history, so they do not have one. See
        // docs/SCD2.md.
        $names = array_keys(self::IDENTITY_FIELDS);
        $hub->table('gp_attribute')->where('identity_id', $identityId)->whereIn('attr_name', $names)->delete();
        $hub->table('gp_survivorship_audit')->where('identity_id', $identityId)->whereIn('attribute_name', $names)->delete();
        foreach (array_chunk($attrRows, 500) as $c) {
            $hub->table('gp_attribute')->insert($c);
        }
        foreach (array_chunk($auditRows, 500) as $c) {
            $hub->table('gp_survivorship_audit')->insert($c);
        }
    }

    private static function authorityRank(array $order, ?string $code, ?int $reliabilityRank): int
    {
        $idx = array_search($code, $order, true);
        if ($idx !== false) {
            return $idx;                       // explicit authority order wins
        }

        // unknown systems ranked after listed ones, best reliability_rank first
        return 100 - (int) ($reliabilityRank ?? 50);
    }

    private static function isBlank($v): bool
    {
        return $v === null || $v === '' || (is_string($v) && trim($v) === '');
    }
}

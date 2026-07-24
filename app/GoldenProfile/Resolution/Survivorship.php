<?php

namespace App\GoldenProfile\Resolution;

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

    public function __construct()
    {
        $this->authority = config('golden_profile.survivorship.field_authority');
    }

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /** identity field <- staged column */
    private const IDENTITY_FIELDS = [
        'canonical_first' => 'first_name',
        'canonical_middle' => 'middle_name',
        'canonical_last' => 'last_name',
        'canonical_suffix' => 'name_suffix',
        'canonical_dob' => 'date_of_birth',
        'npi' => 'npi',
        'upin' => 'upin',
        'dea_number' => 'dea_number',
        'ssn_hash' => 'ssn_hash',
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

            $ranked = $candidates->sort(function ($a, $b) use ($order, $srcCol) {
                $ra = self::authorityRank($order, $a->system_code, $a->reliability_rank);
                $rb = self::authorityRank($order, $b->system_code, $b->reliability_rank);
                if ($ra !== $rb) {
                    return $ra <=> $rb;                 // lower rank = higher authority
                }
                return strcmp((string) $b->source_modified, (string) $a->source_modified); // newer wins
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
                'rule_applied' => 'authority[' . $winner->system_code . '] + recency',
                'decided_at' => $now,
            ];
        }

        // record_count + freshness folded in here (this method already loaded
        // every linked row) so the resolver skips a per-row COUNT+UPDATE.
        $update['record_count'] = $rows->count();
        $update['last_updated'] = $now;
        $hub->table('gp_identity')->where('identity_id', $identityId)->update($update);

        // rewrite provenance for identity attributes (idempotent per identity)
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

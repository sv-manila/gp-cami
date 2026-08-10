<?php

namespace App\GoldenProfile\Resolution;

use App\GoldenProfile\Support\NameMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pass B — probabilistic resolution (GPP three-pass matcher, pass 2/3 rule layer).
 * Runs only when Pass A misses. Blocks on block_key, scores candidate identities
 * with weighted signals, and gates every candidate behind the strict name rule and
 * the hard-no safeguards (conflicting DOB / two different valid NPIs).
 *
 * Returns [identity_id|null, score, match_state]:
 *   >= auto_merge_at  -> [id, score, 'auto_match']   bind to candidate
 *   review band       -> [id, score, 'review']       bind but flag for steward
 *   below floor        -> [null, score, 'no_match']   caller makes a new identity
 */
class ProbabilisticResolver
{
    private array $cfg;

    private static bool $warnedUnreachable = false;

    public function __construct(private int $systemId)
    {
        $this->cfg = config('golden_profile.probabilistic');
        $this->warnIfAutoMergeUnreachable();
    }

    /**
     * score() can only ever add the weights it actually implements. If those sum at
     * or below auto_merge_at, 'auto_match' is unreachable in practice — every Pass B
     * hit lands in the review band at best — which is worth a log line rather than
     * silently behaving as though the configured band were in effect.
     */
    private function warnIfAutoMergeUnreachable(): void
    {
        if (self::$warnedUnreachable) {
            return;
        }
        self::$warnedUnreachable = true;

        $implemented = (array) ($this->cfg['implemented_weights'] ?? []);
        if ($implemented === []) {
            return;
        }

        $reachable = array_sum(array_intersect_key($this->cfg['weights'] ?? [], array_flip($implemented)));
        $threshold = (float) ($this->cfg['auto_merge_at'] ?? 1.0);

        // <=, not <. Equality is the case that actually bites: the implemented
        // weights sum to exactly auto_merge_at (0.92), so auto_match is reachable
        // only on a flawless score across every signal at once. A strict < treated
        // that knife-edge as healthy and logged nothing.
        if (round($reachable, 4) <= $threshold) {
            Log::warning('probabilistic auto_merge is unreachable with the implemented signals', [
                'max_reachable_score' => round($reachable, 4),
                'auto_merge_at' => $threshold,
                'declared_but_unimplemented' => array_values(
                    array_diff(array_keys($this->cfg['weights'] ?? []), $implemented)
                ),
            ]);
        }
    }

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /** @return array{0:?int,1:float,2:string} */
    public function match(object $p, $licenses): array
    {
        if (! $p->block_key) {
            return [null, 0.0, 'no_match'];
        }

        // Oversized blocks carry no evidence. block_key is surname-soundex + DOB,
        // and rows with no DOB collapse into buckets like "D500|____" holding
        // 107,164 people — that is "surname sounds like D-500", not a candidate
        // set. Scoring one costs ~30s per source row and cannot produce a
        // defensible match, so the block_size_cap is enforced here: over the cap,
        // Pass B declines and the caller mints a new identity.
        $cap = (int) ($this->cfg['block_size_cap'] ?? 0);
        if ($cap > 0) {
            $blockSize = (int) $this->hub()->table('stg_person')
                ->where('block_key', $p->block_key)->count();
            if ($blockSize > $cap) {
                return [null, 0.0, 'no_match'];
            }
        }

        $candidateIds = $this->hub()->table('gp_source_link as l')
            ->join('stg_person as sp', function ($j) {
                $j->on('sp.system_id', '=', 'l.system_id')
                    ->on('sp.source_table', '=', 'l.source_table')
                    ->on('sp.source_id', '=', 'l.source_id');
            })
            ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
            ->where('sp.block_key', $p->block_key)
            ->where('i.status', 'active')
            ->where(fn ($q) => $q->where('sp.source_id', '!=', $p->source_id)->orWhere('sp.system_id', '!=', $this->systemId))
            ->distinct()->pluck('l.identity_id');

        $best = null;
        $bestScore = 0.0;

        // Candidates are loaded in batches, not one query each. A block_key like
        // "smith|1992-09-21" can hold thousands of members (the seeded test-data
        // pile-ups run to 12k), and a per-candidate SELECT made every new source
        // row cost that many round-trips — measured at ~13,500 hub queries per
        // row, which pinned gp:sync at ~0.03 rows/sec.
        $identities = collect();
        foreach ($candidateIds->chunk(1000) as $batch) {
            $identities = $identities->merge(
                $this->hub()->table('gp_identity')->whereIn('identity_id', $batch->all())->get()
            );
        }

        foreach ($identities as $identity) {
            $cid = $identity->identity_id;
            if ($this->hardNo($p, $identity)) {
                continue;
            }
            // strict name prerequisite (first+last equal, middle/suffix/dob compatible)
            if (! NameMatcher::compatible($p, $this->asNameObj($identity))) {
                continue;
            }
            $score = $this->score($p, $identity);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int) $cid;
            }
        }

        if ($best === null) {
            return [null, 0.0, 'no_match'];
        }
        if ($bestScore >= $this->cfg['auto_merge_at']) {
            return [$best, $bestScore, 'auto_match'];
        }
        if ($bestScore >= $this->cfg['review_band_floor']) {
            return [$best, $bestScore, 'review'];
        }

        return [null, $bestScore, 'no_match'];
    }

    /** Hard-no safeguards: block merge regardless of similarity. */
    private function hardNo(object $p, object $identity): bool
    {
        $hn = $this->cfg['hard_no'];
        if (! empty($hn['conflicting_dob']) && NameMatcher::dobConflicts($p->date_of_birth, $identity->canonical_dob)) {
            return true;
        }
        if (! empty($hn['two_valid_npis']) && $p->npi && $identity->npi && (int) $p->npi !== (int) $identity->npi) {
            return true;
        }

        return false;
    }

    private function score(object $p, object $identity): float
    {
        $w = $this->cfg['weights'];
        $sum = 0.0;

        // name — Jaro-Winkler over "last first"
        $nameSim = self::jaroWinkler(
            strtolower(trim(($p->last_name ?? '').' '.($p->first_name ?? ''))),
            strtolower(trim(($identity->canonical_last ?? '').' '.($identity->canonical_first ?? '')))
        );
        $sum += $w['name'] * $nameSim;

        // dob
        if ($p->date_of_birth && $identity->canonical_dob) {
            $d2 = substr((string) $identity->canonical_dob, 0, 10);
            if ($p->date_of_birth === $d2) {
                $sum += $w['dob'];
            } elseif (substr($p->date_of_birth, 0, 4) === substr($d2, 0, 4)) {
                $sum += $w['dob'] * 0.5;
            }
        }

        // address round-robin (any staged address vs any gp_address of the identity)
        [$addrHit, $zipHit] = $this->addressOverlap($p, $identity->identity_id);
        if ($addrHit) {
            $sum += $w['address'];
        }
        if ($zipHit) {
            $sum += $w['zip'];
        }

        // shared exclusion registry (compliance signal)
        if ($this->sharesExclusionRegistry($p, $identity->identity_id)) {
            $sum += $w['exclusion_share'];
        }

        return min(1.0, round($sum, 4));
    }

    private function addressOverlap(object $p, int $identityId): array
    {
        $stg = $this->hub()->table('stg_person_address')
            ->where('stg_person_id', $this->stgId($p))->get();
        if ($stg->isEmpty()) {
            return [false, false];
        }
        $gp = $this->hub()->table('gp_address')->where('identity_id', $identityId)->get();
        $addrHit = false;
        $zipHit = false;
        foreach ($stg as $a) {
            foreach ($gp as $b) {
                if ($a->zip && $a->zip === $b->zip) {
                    $zipHit = true;
                    if ($a->address1 && strtolower((string) $a->address1) === strtolower((string) $b->address1)) {
                        $addrHit = true;
                    }
                }
            }
        }

        return [$addrHit, $zipHit];
    }

    private function sharesExclusionRegistry(object $p, int $identityId): bool
    {
        // if this source row's employee has an exclusion registry already on the identity
        $regs = $this->hub()->table('gp_identity_exclusion')->where('identity_id', $identityId)
            ->whereNotNull('registry')->pluck('registry');

        return $regs->isNotEmpty();
    }

    private function stgId(object $p): int
    {
        return (int) ($p->stg_person_id ?? 0);
    }

    private function asNameObj(object $identity): object
    {
        return (object) [
            'first_name' => $identity->canonical_first,
            'last_name' => $identity->canonical_last,
            'middle_name' => $identity->canonical_middle,
            'name_suffix' => $identity->canonical_suffix ?? null,
            'date_of_birth' => $identity->canonical_dob ? substr((string) $identity->canonical_dob, 0, 10) : null,
        ];
    }

    /** Compact Jaro-Winkler similarity (0..1). */
    public static function jaroWinkler(string $s1, string $s2): float
    {
        if ($s1 === $s2) {
            return 1.0;
        }
        $len1 = strlen($s1);
        $len2 = strlen($s2);
        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }
        $matchDist = (int) floor(max($len1, $len2) / 2) - 1;
        $s1m = array_fill(0, $len1, false);
        $s2m = array_fill(0, $len2, false);
        $matches = 0;
        for ($i = 0; $i < $len1; $i++) {
            $start = max(0, $i - $matchDist);
            $end = min($i + $matchDist + 1, $len2);
            for ($j = $start; $j < $end; $j++) {
                if ($s2m[$j] || $s1[$i] !== $s2[$j]) {
                    continue;
                }
                $s1m[$i] = $s2m[$j] = true;
                $matches++;
                break;
            }
        }
        if ($matches === 0) {
            return 0.0;
        }
        $t = 0;
        $k = 0;
        for ($i = 0; $i < $len1; $i++) {
            if (! $s1m[$i]) {
                continue;
            }
            while (! $s2m[$k]) {
                $k++;
            }
            if ($s1[$i] !== $s2[$k]) {
                $t++;
            }
            $k++;
        }
        $t /= 2;
        $jaro = ($matches / $len1 + $matches / $len2 + ($matches - $t) / $matches) / 3;
        // Winkler prefix boost (max 4)
        $prefix = 0;
        for ($i = 0; $i < min(4, $len1, $len2); $i++) {
            if ($s1[$i] === $s2[$i]) {
                $prefix++;
            } else {
                break;
            }
        }

        return $jaro + $prefix * 0.1 * (1 - $jaro);
    }
}

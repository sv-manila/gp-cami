<?php

namespace App\GoldenProfile\Resolution;

use App\GoldenProfile\Support\NameMatcher;
use Illuminate\Support\Facades\DB;

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

    public function __construct(private int $systemId)
    {
        $this->cfg = config('golden_profile.probabilistic');
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
        foreach ($candidateIds as $cid) {
            $identity = $this->hub()->table('gp_identity')->where('identity_id', $cid)->first();
            if (! $identity || $this->hardNo($p, $identity)) {
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

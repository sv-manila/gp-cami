<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\TooManyCredentialLinksException;
use App\GoldenProfile\Support\CredentialSelector;
use App\GoldenProfile\Support\SsnHasher;
use App\Http\Controllers\Controller;
use App\Http\Requests\CredentialSearchRequest;
use App\Models\Gp\GpIdentityProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CredentialSearchController extends Controller
{
    public function __construct(private SsnHasher $ssnHasher) {}

    /**
     * POST /api/v1/credential-search
     * Resolve one person, return the latest qualifying credential match plus any
     * prior resolution. 404 if no identity resolves; 200 with match=null if the
     * person has no qualifying, unexpired credential.
     */
    public function __invoke(CredentialSearchRequest $request): JsonResponse
    {
        // A supplied SSN does two separate jobs, and only one of them needs the
        // shared hash key:
        //
        //   1. narrowing identity resolution, by matching gp_identity_profile
        //      .ssn_hash — impossible without the key;
        //   2. gating credential matches whose own scrape recorded an SSN, which
        //      compares against the value in the payload and needs no key at all.
        //
        // Refusing the whole request when the key is missing would disable (2) as
        // well, and the key is absent in every environment that has not been given
        // GP_SSN_PLAINTEXT_KEY. So (2) still runs, (1) is skipped, and the response
        // says so — the point of the original refusal was that a dropped SSN filter
        // must never be silent, not that it must be fatal.
        $warnings = [];

        if ($request->filled('ssn') && ! $this->ssnHasher->available()) {
            $reason = $this->ssnHasher->unavailableReason() ?? 'unknown';
            Log::warning('credential-search cannot use the ssn for identity resolution', ['reason' => $reason]);

            $warnings[] = [
                'code' => 'ssn_not_used_for_identity_resolution',
                'message' => 'SSN hashing is unavailable on this instance, so the SSN did not narrow '
                    .'which identity was resolved — it was still used to exclude credential matches '
                    .'recorded against a different SSN. Configure GP_SSN_PLAINTEXT_KEY to narrow on it.',
                'reason' => $reason,
            ];
        }

        $identity = $this->resolveIdentity($request);

        if (! $identity) {
            return response()->json([
                'match' => null,
                'prior_resolution' => null,
                'message' => 'No identity resolved for the given inputs.',
                'warnings' => $warnings,
            ], 404);
        }

        $match = $this->latestQualifyingCredential($identity, $request);
        $prior = $this->priorResolution($identity, $request);

        return response()->json([
            'identity' => [
                'identity_id' => (int) $identity->identity_id,
                'identity_uuid' => $identity->identity_uuid,
                'first_name' => $identity->first_name,
                'last_name' => $identity->last_name,
                'ssn_last_four' => $identity->ssn_last_four,
            ],
            'match' => $match,
            'prior_resolution' => $prior,
            'warnings' => $warnings,
        ], 200);
    }

    private function resolveIdentity(CredentialSearchRequest $r): ?GpIdentityProfile
    {
        // Plain column comparisons on purpose — same reasoning as
        // DeterministicResolver::matchDeterministic(). last_name/first_name are
        // utf8mb4_unicode_ci (already case-insensitive) and date_of_birth is a
        // DATE, so LOWER()/whereDate() only made the predicate non-sargable:
        // idx_name_dob(last_name, first_name, date_of_birth) was reduced to a full
        // index scan on EVERY request (EXPLAIN: type=index key=idx_name_dob
        // rows=13661726 vs type=ref rows=1 without the wrapping).
        $q = GpIdentityProfile::query()
            ->where('last_name', $r->input('last_name'))
            ->where('first_name', $r->input('first_name'));

        if ($r->filled('dob')) {
            $q->where('date_of_birth', $r->date('dob')->toDateString());
        }

        // Only when a key exists. candidateHashes() returns [] without one, and
        // whereIn('ssn_hash', []) matches NOTHING — so an unavailable key would
        // turn every SSN-bearing request into a 404 rather than simply not
        // narrowing. __invoke() has already recorded the warning for this case.
        if ($r->filled('ssn') && $this->ssnHasher->available()) {
            $q->whereIn('ssn_hash', $this->ssnHasher->candidateHashes($r->input('ssn')));
        }

        if ($r->filled('license_number')) {
            $ids = DB::connection('golden_profile')->table('gp_license')
                ->where('license_number', $r->input('license_number'))
                ->pluck('identity_id');
            $q->whereIn('identity_id', $ids);
        }

        // Narrow phase-1 select, then hydrate — identical reasoning to
        // IdentitySearchController. Ordering SELECT * here fails outright with
        // "1038 Out of sort memory" for any name whose candidates carry large JSON
        // rollups, which is exactly the common-name case this endpoint exists for.
        //
        // identity_id is the tiebreak: record_count alone leaves ties to arbitrary
        // storage order, so two identical calls could resolve to different people.
        $ranked = $q->clone()->select('identity_id', 'record_count')
            ->orderByDesc('record_count')->orderBy('identity_id')->limit(2)->get();

        if ($ranked->isEmpty()) {
            return null;
        }

        $matches = $ranked->pluck('identity_id')->all();

        if (count($matches) > 1) {
            // Identity ids only — names and DOBs are PII and do not belong in logs.
            Log::warning('credential-search resolved multiple identities; taking lowest identity_id at the top record_count', [
                'registry' => $r->input('registry'),
                'narrowers' => array_keys(array_filter([
                    'dob' => $r->filled('dob'),
                    'ssn' => $r->filled('ssn'),
                    'license_number' => $r->filled('license_number'),
                    'license_type' => $r->filled('license_type'),
                ])),
                'candidates' => $matches,
            ]);
        }

        // Only the columns this endpoint actually uses: the response echoes
        // identity_id/uuid/first_name/last_name/ssn_last_four, and the credential
        // and prior-resolution lookups key off identity_id. Selecting * here would
        // pull the JSON rollups too — identity 3 ("John Smith", 397,170 credential
        // links) carries a 69MB credentials blob and a 38MB exclusions blob, enough
        // to exhaust PHP's memory_limit on the hydrate alone.
        return GpIdentityProfile::query()
            ->select('identity_id', 'identity_uuid', 'first_name', 'last_name', 'ssn_last_four')
            ->find($matches[0]);
    }

    private function latestQualifyingCredential(GpIdentityProfile $identity, CredentialSearchRequest $r): ?array
    {
        $codes = config('golden_profile.credential_search.qualifying_status_codes', []);

        // Two queries, not a cross-database join.
        //
        // The hub and the CAMI source are separate MySQL *servers*, so qualifying
        // credential_matches with the source schema name produced
        // "1049 Unknown database '<src>'" and every credential-search that actually
        // resolved an identity returned a 500. The hub's own src_credential_match
        // mirror can't stand in either — it carries no expiry_date/date_updated/
        // date_created, which is exactly what the expiry filter and ordering need.
        // Streamed in chunks, deliberately. An over-merged identity can carry a
        // huge number of links for one registry — "Smith / John" at NYEMED has
        // 364,570 — and loading them all pulled ~316MB into PHP and then built a
        // 364,570-placeholder whereIn, which MySQL rejects outright ("1390
        // Prepared statement contains too many placeholders") after ~97s. The
        // original SQL never materialised more than one row.
        //
        // A plain LIMIT is NOT a safe substitute: the winner depends on
        // source-side dates the hub does not hold, so the correct row can be
        // anywhere in the set. Instead each chunk is resolved to its own best
        // candidate and folded into a running winner — constant memory, bounded
        // statement size, and the same answer as sorting the whole set.
        $respectExpiry = (bool) config('golden_profile.credential_search.respect_expiry_date');
        $today = now()->toDateString();
        $chunkSize = (int) config('golden_profile.credential_search.link_chunk_size', 1000);
        $maxLinks = (int) config('golden_profile.credential_search.max_links', 10000);

        $linkQuery = DB::connection('golden_profile')->table('gp_identity_credential')
            ->where('identity_id', $identity->identity_id)
            ->where('registry', $r->input('registry'))
            ->whereIn('match_summary_status_code', $codes);

        // Refuse rather than hang. The winner depends on dates held on a different
        // server, so every link has to be looked up there — streaming the 364,439
        // qualifying links on identity 3 takes ~390s (and >600s with bigger
        // batches), far past any request budget. A truncated scan would instead
        // return a confidently wrong credential.
        //
        // An identity with this many links for one registry is not a real person:
        // it is an over-merged record (identity 3 is "John Smith", 12,463 source
        // rows folded together). The honest answer is to say so. Normal identities
        // are nowhere near the cap — hub-wide the average is 5.54 links per
        // identity+registry pair.
        if ($maxLinks > 0) {
            $linkCount = (int) $linkQuery->clone()->count();
            if ($linkCount > $maxLinks) {
                Log::error('credential-search refused: identity has implausibly many credential links', [
                    'identity_id' => (int) $identity->identity_id,
                    'registry' => $r->input('registry'),
                    'link_count' => $linkCount,
                    'max_links' => $maxLinks,
                ]);

                throw new TooManyCredentialLinksException($linkCount, $maxLinks);
            }
        }

        $best = null;
        $sawAny = false;

        // Gate values for CredentialSelector::identityAgrees(). A match whose own
        // scrape recorded an SSN or DOB is only returned when the request names the
        // same one, so these travel with every selection call.
        $reqSsn = $r->filled('ssn') ? (string) $r->input('ssn') : null;
        $reqDob = $r->filled('dob') ? $r->date('dob')->toDateString() : null;

        // Chunked PER SOURCE SYSTEM, not across all of them.
        //
        // chunkById needs a strictly unique cursor column, and
        // gp_identity_credential's primary key is (system_id, credential_match_id)
        // — identity_id is not part of it. So credential_match_id is unique only
        // within one system_id. There is a single system today (verified), which
        // masks the problem entirely, but the moment a second source is ingested a
        // repeated credential_match_id would make the cursor skip rows and quietly
        // return the wrong credential. Fixing the loop is cheaper than relying on
        // that invariant holding forever.
        $systemIds = $linkQuery->clone()->distinct()->pluck('system_id');

        foreach ($systemIds as $systemId) {
            $linkQuery->clone()
                ->where('system_id', $systemId)
                ->orderBy('credential_match_id')
                ->chunkById($chunkSize, function ($links) use (&$best, &$sawAny, $respectExpiry, $today, $reqSsn, $reqDob) {
                    $sawAny = true;

                    $ids = $links->pluck('credential_match_id')->filter()->all();

                    // req_ssn / req_dob are the SSN and DOB the match's own scrape
                    // was run with. Extracted server-side rather than by shipping
                    // `match` here: it is a mediumtext payload and only these two
                    // scalars are needed. JSON_VALID guards the extraction — every
                    // one of 3,000 sampled rows was valid JSON, but a malformed
                    // payload must yield NULL, not fail the whole batch.
                    $dates = $ids === []
                        ? collect()
                        : DB::connection('streamline_local')->table('credential_matches')
                            ->whereIn('id', $ids)
                            ->selectRaw("id, expiry_date, date_updated, date_created,
                                CASE WHEN JSON_VALID(`match`)
                                     THEN JSON_UNQUOTE(JSON_EXTRACT(`match`, '$.request_params.ssn'))
                                END AS req_ssn,
                                CASE WHEN JSON_VALID(`match`)
                                     THEN JSON_UNQUOTE(JSON_EXTRACT(`match`, '$.request_params.date_of_birth'))
                                END AS req_dob")
                            ->get()
                            ->keyBy('id');

                    $withDates = $links->map(function ($link) use ($dates) {
                        $d = $dates[$link->credential_match_id] ?? null;
                        $link->expiry_date = $d->expiry_date ?? null;
                        $link->date_updated = $d->date_updated ?? null;
                        $link->date_created = $d->date_created ?? null;
                        $link->req_ssn = $d->req_ssn ?? null;
                        $link->req_dob = $d->req_dob ?? null;

                        return $link;
                    });

                    // Fold this chunk's winner into the running winner. Valid
                    // because the comparator is a total order, so best-of-bests
                    // equals best-of-all — including across systems, since the fold
                    // carries $best between iterations of the outer loop too.
                    $candidates = collect([
                        $best,
                        CredentialSelector::pick($withDates, $respectExpiry, $today, $reqSsn, $reqDob),
                    ])->filter();

                    // The running winner already passed the gate, so re-checking it
                    // is a no-op — but passing the criteria keeps the two calls
                    // identical, so a future change cannot make the fold apply a
                    // different rule than the per-chunk selection.
                    $best = CredentialSelector::pick($candidates, $respectExpiry, $today, $reqSsn, $reqDob);
                }, 'credential_match_id');
        }

        if (! $sawAny) {
            return null;
        }

        // Filter + ordering equivalence with the original SQL is covered by
        // CredentialSelectorTest, which needs no database.
        $row = $best;

        if (! $row) {
            return null;
        }

        return [
            'credential_match_id' => (int) $row->credential_match_id,
            'registry' => $row->registry,
            'match_summary_status' => $row->match_summary_status,
            'match_summary_status_code' => $row->match_summary_status_code,
            'match_is_valid' => (bool) $row->match_is_valid,
            // Response field name unchanged; the source column was renamed to free
            // `current` for the SCD-2 version flag.
            'current' => (bool) $row->source_current,
            'expiry_date' => $row->expiry_date,
            'date_resolved' => $row->date_resolved,
        ];
    }

    private function priorResolution(GpIdentityProfile $identity, CredentialSearchRequest $r): ?array
    {
        if (! $r->filled('license_number')) {
            return null;
        }
        $targetKey = $r->input('registry').':'.$r->input('license_number');
        if ($r->filled('license_type')) {
            $targetKey .= ':'.$r->input('license_type');
        }

        $row = DB::connection('golden_profile')->table('gp_identity_resolution')
            ->where('identity_id', $identity->identity_id)
            ->where('domain', 'credential')
            ->where('target_key', $targetKey)
            ->where('is_current', 1)
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'decision' => $row->decision,
            'action_type' => $row->action_type,
            'resolved_by' => $row->resolved_by,
            'resolved_at' => (string) $row->resolved_at,
            'auto_resolvable' => (bool) $row->is_auto_resolvable,
        ];
    }
}

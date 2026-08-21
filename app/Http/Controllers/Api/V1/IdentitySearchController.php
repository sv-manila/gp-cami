<?php

namespace App\Http\Controllers\Api\V1;

use App\GoldenProfile\Materialize\AliasIndexer;
use App\Http\Controllers\Controller;
use App\Http\Requests\IdentitySearchRequest;
use App\Http\Resources\IdentityProfileResource;
use App\Models\Gp\GpIdentityProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IdentitySearchController extends Controller
{
    public function __construct(private AliasIndexer $aliasIndexer) {}

    /**
     * POST /api/v1/identity-search
     * Name-only search. Returns every matching identity with all associated
     * data, served from gp_identity_profile. Matches canonical name AND aliases.
     */
    public function __invoke(IdentitySearchRequest $request): JsonResponse
    {
        $last = trim($request->input('last_name'));
        $first = $request->filled('first_name') ? trim($request->input('first_name')) : null;
        $perPage = (int) ($request->input('per_page', 25));

        // Alias hits come from gp_identity_alias, resolved to ids up front.
        //
        // This leg used to be LOWER(aliases) LIKE '%"last":"x"%' over the JSON
        // rollup, which was wrong twice over. It matched nothing at all — MySQL
        // normalises stored JSON with a space after the colon ("last": "Smith"), so
        // the pattern could not match its own row — and a leading-wildcard LIKE on
        // a JSON column cannot use an index, so OR-ing it with the canonical leg
        // forced a full scan of all 13.6M rows for the statement as a whole
        // (type=ALL rows=13661726 whether or not the canonical leg was sargable).
        // That scan, not the canonical name, is what pushed this endpoint past
        // max_execution_time into a 500.
        //
        // Both legs are now index reads, so the OR can no longer poison the plan.
        $aliasIds = $this->aliasIndexer->identityIdsFor($last);

        $q = GpIdentityProfile::query()->where(function ($outer) use ($last, $first, $aliasIds) {
            // Canonical name. Plain comparisons, not LOWER(): the columns are
            // utf8mb4_unicode_ci so LOWER() only made this leg non-sargable
            // (EXPLAIN on this leg alone: type=ref key=idx_name_dob rows=116,
            // versus type=index rows=13661726 with LOWER()).
            $outer->where(function ($w) use ($last, $first) {
                $w->where('last_name', $last);
                if ($first) {
                    $w->where('first_name', $first);
                }
            });

            if ($aliasIds !== []) {
                // Primary-key lookups on the profile table.
                $outer->orWhereIn('identity_id', $aliasIds);
            }
        })
            // identity_id tiebreak: record_count alone leaves equal-ranked rows in
            // arbitrary storage order, which makes pagination unstable — a row can
            // repeat on page 2 or be skipped entirely between requests.
            ->orderByDesc('record_count')->orderBy('identity_id');

        // Two-phase paging. Ordering and paginating SELECT * fails outright on this
        // table — MySQL reports "1038 Out of sort memory, consider increasing
        // server sort buffer size" — because a single gp_identity_profile row can
        // carry >100MB of JSON rollups (identity 3 alone has a 69MB credentials
        // blob) and filesort has to materialise the sort rows. Phase 1 sorts and
        // pages narrow (identity_id, record_count) tuples, which stay inside
        // sort_buffer_size; phase 2 hydrates only the ids on the requested page.
        $page = $q->clone()->select('identity_id', 'record_count')->paginate($perPage);

        $ids = array_map(static fn ($r) => (int) $r->identity_id, $page->items());
        $order = array_flip($ids);

        [$rows, $oversized] = $this->hydrate($ids);

        $rows = $rows->sortBy(static fn ($r) => $order[(int) $r->identity_id] ?? PHP_INT_MAX)->values();

        return response()->json([
            'data' => IdentityProfileResource::collection($rows),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                // Named so a caller can tell "this person has no credentials" apart
                // from "we declined to send them".
                'omitted_fields' => $oversized,
            ],
        ]);
    }

    /**
     * Load the page's rows, leaving behind any JSON rollup too large to hold.
     *
     * A gp_identity_profile row is not bounded: identity 3 ("John Smith", 12,463
     * merged source records) carries a 69MB credentials blob and a 38MB exclusions
     * blob, so hydrating it exhausted PHP's 128MB limit and the request died with a
     * FatalError no handler could turn into a useful response. It only became
     * reachable here once alias search started working, since that identity is
     * found by alias rather than by canonical name.
     *
     * Oversized columns are replaced with NULL and reported in meta.omitted_fields
     * rather than silently dropped — the same approach the dashboard already takes
     * when rendering one of these rows.
     *
     * @return array{0:\Illuminate\Support\Collection,1:array<string,list<string>>}
     */
    private function hydrate(array $ids): array
    {
        if ($ids === []) {
            return [collect(), []];
        }

        $jsonColumns = (array) config('golden_profile.api.json_columns', []);
        $maxBytes = (int) config('golden_profile.api.max_json_bytes', 2097152);
        $table = (new GpIdentityProfile)->getTable();

        // One length probe for the whole page. OCTET_LENGTH reads the stored blob
        // size without materialising it.
        $lengths = collect();
        if ($jsonColumns !== []) {
            $select = implode(', ', array_map(
                fn ($c) => "OCTET_LENGTH(`$c`) AS `$c`",
                $jsonColumns,
            ));
            $lengths = collect(DB::connection('golden_profile')
                ->table($table)
                ->selectRaw("identity_id, $select")
                ->whereIn('identity_id', $ids)
                ->get())
                ->keyBy('identity_id');
        }

        // Group ids by which columns have to be skipped, so a page with nothing
        // oversized (the normal case) still costs a single query.
        $groups = [];
        $omitted = [];
        foreach ($ids as $id) {
            $skip = [];
            foreach ($jsonColumns as $col) {
                if ((int) ($lengths[$id]->$col ?? 0) > $maxBytes) {
                    $skip[] = $col;
                }
            }
            if ($skip !== []) {
                $omitted[(string) $id] = $skip;
            }
            $groups[implode(',', $skip)][] = $id;
        }

        $rows = collect();
        foreach ($groups as $skipKey => $groupIds) {
            $skip = $skipKey === '' ? [] : explode(',', $skipKey);

            $columns = array_values(array_diff(
                Schema::connection('golden_profile')->getColumnListing($table),
                $skip,
            ));

            $rows = $rows->merge(
                GpIdentityProfile::query()->select($columns)->whereIn('identity_id', $groupIds)->get()
            );
        }

        return [$rows, $omitted];
    }
}

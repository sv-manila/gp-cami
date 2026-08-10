<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IdentitySearchRequest;
use App\Http\Resources\IdentityProfileResource;
use App\Models\Gp\GpIdentityProfile;
use Illuminate\Http\JsonResponse;

class IdentitySearchController extends Controller
{
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

        $q = GpIdentityProfile::query()->where(function ($outer) use ($last, $first) {
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
            // Alias hit (maiden names etc.) — JSON stored as [{type,first,last}].
            //
            // KNOWN COST: this leg is a leading-wildcard LIKE over a JSON column,
            // so it cannot use any index, and OR-ing it with the canonical leg
            // forces a full scan of the whole 13.6M-row table for the statement as
            // a whole (verified: fixing the canonical leg alone does not change the
            // plan — type=ALL key=NULL rows=13661726 either way). Only ~108k rows
            // (0.8%) actually carry aliases, so the fix is to index those rather
            // than scan everything; that needs a schema addition and is tracked
            // separately rather than changed silently here.
            $outer->orWhereRaw('LOWER(aliases) LIKE ?', ['%"last":"'.mb_strtolower($last).'"%']);
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

        $rows = $ids === []
            ? collect()
            : GpIdentityProfile::query()->whereIn('identity_id', $ids)->get()
                ->sortBy(static fn ($r) => $order[(int) $r->identity_id] ?? PHP_INT_MAX)
                ->values();

        return response()->json([
            'data' => IdentityProfileResource::collection($rows),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}

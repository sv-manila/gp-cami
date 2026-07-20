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
            // canonical name
            $outer->where(function ($w) use ($last, $first) {
                $w->whereRaw('LOWER(last_name) = ?', [mb_strtolower($last)]);
                if ($first) {
                    $w->whereRaw('LOWER(first_name) = ?', [mb_strtolower($first)]);
                }
            });
            // alias hit (maiden names etc.) — JSON stored as [{type,first,last}]
            $outer->orWhereRaw('LOWER(aliases) LIKE ?', ['%"last":"'.mb_strtolower($last).'"%']);
        })->orderByDesc('record_count');

        $page = $q->paginate($perPage);

        return response()->json([
            'data' => IdentityProfileResource::collection($page->items()),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}

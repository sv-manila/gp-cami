<?php

namespace App\Http\Controllers\Api\V1;

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
        $identity = $this->resolveIdentity($request);

        if (! $identity) {
            return response()->json([
                'match' => null,
                'prior_resolution' => null,
                'message' => 'No identity resolved for the given inputs.',
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
        ], 200);
    }

    private function resolveIdentity(CredentialSearchRequest $r): ?GpIdentityProfile
    {
        $q = GpIdentityProfile::query()
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($r->input('last_name'))])
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($r->input('first_name'))]);

        if ($r->filled('dob')) {
            $q->whereDate('date_of_birth', $r->date('dob'));
        }

        if ($r->filled('ssn')) {
            $hash = $this->ssnHasher->hash($r->input('ssn'));
            if ($hash) {
                $q->where('ssn_hash', $hash);
            }
        }

        if ($r->filled('license_number')) {
            $ids = DB::connection('golden_profile')->table('gp_license')
                ->where('license_number', $r->input('license_number'))
                ->pluck('identity_id');
            $q->whereIn('identity_id', $ids);
        }

        $matches = $q->orderByDesc('record_count')->limit(2)->get();

        if ($matches->count() > 1) {
            Log::warning('credential-search resolved multiple identities; taking best by record_count', [
                'inputs' => $r->only(['registry', 'first_name', 'last_name', 'license_number']),
                'candidates' => $matches->pluck('identity_id')->all(),
            ]);
        }

        return $matches->first();
    }

    private function latestQualifyingCredential(GpIdentityProfile $identity, CredentialSearchRequest $r): ?array
    {
        $codes = config('golden_profile.credential_search.qualifying_status_codes', []);

        $row = DB::connection('golden_profile')->table('gp_identity_credential as gc')
            ->leftJoin(
                DB::connection('streamline_local')->getDatabaseName().'.credential_matches as cm',
                'cm.id', '=', 'gc.credential_match_id'
            )
            ->where('gc.identity_id', $identity->identity_id)
            ->where('gc.registry', $r->input('registry'))
            ->whereIn('gc.match_summary_status_code', $codes)
            ->when(config('golden_profile.credential_search.respect_expiry_date'), function ($q) {
                $q->where(function ($w) {
                    $w->whereNull('cm.expiry_date')->orWhere('cm.expiry_date', '>=', now()->toDateString());
                });
            })
            ->orderByDesc('gc.current')
            ->orderByRaw('COALESCE(cm.date_updated, cm.date_created) DESC')
            ->select('gc.*', 'cm.expiry_date', 'cm.date_updated', 'cm.date_created')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'credential_match_id' => (int) $row->credential_match_id,
            'registry' => $row->registry,
            'match_summary_status' => $row->match_summary_status,
            'match_summary_status_code' => $row->match_summary_status_code,
            'match_is_valid' => (bool) $row->match_is_valid,
            'current' => (bool) $row->current,
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

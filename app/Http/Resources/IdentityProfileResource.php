<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a gp_identity_profile row for the API. Never exposes ssn_hash or
 * encrypted SSN — ssn_last_four only.
 */
class IdentityProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'identity_id' => (int) $this->identity_id,
            'identity_uuid' => $this->identity_uuid,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'date_of_birth' => optional($this->date_of_birth)->toDateString(),
            'ssn_last_four' => $this->ssn_last_four,
            'npi' => $this->npi ? (int) $this->npi : null,
            'upin' => $this->upin,
            'dea_number' => $this->dea_number,
            'primary_address' => array_filter([
                'address1' => $this->address1, 'city' => $this->city,
                'state' => $this->state, 'zip' => $this->zip,
            ]),
            'terminated' => (bool) $this->terminated,
            'confidence' => (float) $this->confidence,
            'record_count' => (int) $this->record_count,
            'account_count' => (int) $this->account_count,
            'system_count' => (int) $this->system_count,
            'aliases' => $this->aliases ?? [],
            'licenses' => $this->licenses ?? [],
            'addresses' => $this->addresses ?? [],
            'accounts' => $this->accounts ?? [],
            'source_records' => $this->source_records ?? [],
            'credentials' => $this->credentials ?? [],
            'exclusions' => $this->exclusions ?? [],
            'has_active_exclusion' => (bool) $this->has_active_exclusion,
            'resolutions' => $this->resolutions ?? [],
            'last_updated' => optional($this->last_updated)->toIso8601String(),
        ];
    }
}

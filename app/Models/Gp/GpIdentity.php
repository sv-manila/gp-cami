<?php

namespace App\Models\Gp;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GpIdentity extends GpModel
{
    protected $table = 'gp_identity';

    protected $primaryKey = 'identity_id';

    protected $guarded = [];

    protected $casts = [
        'canonical_dob' => 'date',
        'confidence' => 'decimal:4',
        'first_seen' => 'datetime',
        'last_updated' => 'datetime',
    ];

    public function profile(): HasOne
    {
        return $this->hasOne(GpIdentityProfile::class, 'identity_id', 'identity_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(GpSourceLink::class, 'identity_id', 'identity_id');
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(GpLicense::class, 'identity_id', 'identity_id');
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(GpIdentityResolution::class, 'identity_id', 'identity_id');
    }
}

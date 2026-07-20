<?php

namespace App\Models\Gp;

class GpIdentityProfile extends GpModel
{
    protected $table = 'gp_identity_profile';

    protected $primaryKey = 'identity_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
        'addresses' => 'array',
        'licenses' => 'array',
        'aliases' => 'array',
        'source_records' => 'array',
        'accounts' => 'array',
        'credentials' => 'array',
        'exclusions' => 'array',
        'resolutions' => 'array',
        'confidence' => 'decimal:4',
        'first_seen' => 'datetime',
        'last_updated' => 'datetime',
        'profile_built_at' => 'datetime',
    ];
}

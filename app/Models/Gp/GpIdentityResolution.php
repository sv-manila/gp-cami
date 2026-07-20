<?php

namespace App\Models\Gp;

class GpIdentityResolution extends GpModel
{
    protected $table = 'gp_identity_resolution';

    protected $primaryKey = 'resolution_id';

    protected $guarded = [];

    protected $casts = [
        'resolution_metadata' => 'array',
        'resolved_at' => 'datetime',
        'is_auto_resolvable' => 'boolean',
        'is_current' => 'boolean',
    ];
}

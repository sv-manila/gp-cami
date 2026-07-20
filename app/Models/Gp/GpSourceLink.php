<?php

namespace App\Models\Gp;

class GpSourceLink extends GpModel
{
    protected $table = 'gp_source_link';

    protected $primaryKey = 'link_id';

    protected $guarded = [];

    protected $casts = [
        'match_score' => 'decimal:4',
        'linked_at' => 'datetime',
    ];
}

<?php

namespace App\Models\Gp;

class GpLicense extends GpModel
{
    protected $table = 'gp_license';

    protected $primaryKey = 'license_id';

    protected $guarded = [];
}

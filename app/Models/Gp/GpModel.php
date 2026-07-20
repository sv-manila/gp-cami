<?php

namespace App\Models\Gp;

use Illuminate\Database\Eloquent\Model;

/** Base for all golden_profile hub models. */
abstract class GpModel extends Model
{
    protected $connection = 'golden_profile';

    public $timestamps = false;
}

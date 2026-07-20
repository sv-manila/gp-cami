<?php

namespace App\Models\Src;

/** streamline_local.employees — read-only source row. */
class Employee extends ReadOnlyModel
{
    protected $table = 'employees';

    public const NOT_TERMINATED = 0;
}

<?php

namespace App\Models\Gp;

/** Append-only disciplinary action. Never overwritten (GPP spec). */
class GpBoardAction extends GpModel
{
    protected $table = 'gp_board_action';

    protected $primaryKey = 'action_id';

    protected $guarded = [];

    protected $casts = [
        'action_date' => 'date',
        'resolution_date' => 'date',
        'ingested_at' => 'datetime',
    ];
}

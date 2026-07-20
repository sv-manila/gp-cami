<?php

namespace App\Models\Src;

use Illuminate\Database\Eloquent\Model;

/**
 * Base for streamline_local source models. Bound to the SELECT-only source
 * connection and hard-blocked from writing — the hub never mutates a source.
 */
abstract class ReadOnlyModel extends Model
{
    protected $connection = 'streamline_local';

    public $timestamps = false;

    protected static function boot(): void
    {
        parent::boot();

        $block = static function (): void {
            throw new \RuntimeException('streamline_local is read-only: writes are forbidden by design (INV: no source mutation).');
        };

        static::creating($block);
        static::updating($block);
        static::deleting($block);
        static::saving($block);
    }
}

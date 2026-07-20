<?php

namespace App\Console\Commands;

use App\GoldenProfile\Engine;
use Illuminate\Console\Command;

class GpRebuildProfile extends Command
{
    protected $signature = 'gp:rebuild-profile {--identity=}';

    protected $description = 'Re-materialize gp_identity_profile for one identity or all.';

    public function handle(): int
    {
        $engine = new Engine;
        $n = $engine->rebuildProfile($this->option('identity') ? (int) $this->option('identity') : null);
        $this->info("Rebuilt $n profile(s).");

        return self::SUCCESS;
    }
}

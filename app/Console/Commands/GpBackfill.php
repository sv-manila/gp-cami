<?php

namespace App\Console\Commands;

use App\GoldenProfile\Engine;
use Illuminate\Console\Command;

class GpBackfill extends Command
{
    protected $signature = 'gp:backfill {system=streamline_local} {--from-id=} {--chunk=1000}';

    protected $description = 'Mode 1: full backfill — resolve every source record into golden identities.';

    public function handle(): int
    {
        $engine = new Engine;
        $this->info('Backfilling '.$this->argument('system').'...');
        $n = $engine->backfill(
            $this->option('from-id') ? (int) $this->option('from-id') : null,
            (int) $this->option('chunk'),
            fn ($c) => $this->output->write("\r  processed: $c"),
        );
        $this->newLine();
        $this->info("Done. $n source rows processed.");

        return self::SUCCESS;
    }
}

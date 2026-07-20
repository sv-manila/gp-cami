<?php

namespace App\Console\Commands;

use App\GoldenProfile\Engine;
use Illuminate\Console\Command;

class GpSync extends Command
{
    protected $signature = 'gp:sync {system=streamline_local} {--chunk=1000}';

    protected $description = 'Mode 2: incremental sync — resolve only rows changed since the watermark.';

    public function handle(): int
    {
        $engine = new Engine;
        $this->info('Syncing '.$this->argument('system').'...');
        $n = $engine->sync(
            (int) $this->option('chunk'),
            fn ($c) => $this->output->write("\r  processed: $c"),
        );
        $this->newLine();
        $this->info("Done. $n changed rows processed.");

        return self::SUCCESS;
    }
}

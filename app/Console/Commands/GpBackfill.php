<?php

namespace App\Console\Commands;

use App\GoldenProfile\SqlBackfill;
use Illuminate\Console\Command;

class GpBackfill extends Command
{
    protected $signature = 'gp:backfill
        {system=streamline_local}
        {--from-id= : Start at this source id (inclusive)}
        {--to-id= : Stop at this source id (inclusive) — for a bounded / sanity run}
        {--chunk=5000 : Staging read/insert batch size}';

    protected $description = 'Mode 1: full backfill — stage, resolve, enrich, dedup and finalize every source record into golden identities.';

    public function handle(): int
    {
        $engine = new SqlBackfill;
        $this->info('Backfilling '.$this->argument('system').' (stage → resolve → enrich → dedup → finalize)...');

        $result = $engine->run(
            [
                'fromId' => $this->option('from-id') !== null ? (int) $this->option('from-id') : null,
                'toId' => $this->option('to-id') !== null ? (int) $this->option('to-id') : null,
                'chunk' => (int) $this->option('chunk'),
            ],
            fn ($phase, $detail) => $this->line("  [$phase] $detail"),
        );

        $this->info("Done. staged={$result['staged']} identities={$result['identities']} links={$result['links']}");

        return self::SUCCESS;
    }
}

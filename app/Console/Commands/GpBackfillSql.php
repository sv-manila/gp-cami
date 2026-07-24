<?php

namespace App\Console\Commands;

use App\GoldenProfile\SqlBackfill;
use Illuminate\Console\Command;

class GpBackfillSql extends Command
{
    protected $signature = 'gp:backfill-sql
        {system=streamline_local}
        {--from-id= : Start at this source id (inclusive)}
        {--to-id= : Stop at this source id (inclusive)}
        {--chunk=5000 : Staging read/insert batch size}';

    protected $description = 'Mode 1 (bulk): set-based backfill — stage, resolve, rollup, finalize in a handful of SQL statements.';

    public function handle(): int
    {
        $engine = new SqlBackfill;
        $this->info('Set-based backfill starting...');
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

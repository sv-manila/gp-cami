<?php

namespace App\Console\Commands;

use App\GoldenProfile\Engine;
use Illuminate\Console\Command;

class GpDedup extends Command
{
    protected $signature = 'gp:dedup
        {system=streamline_local}
        {--shard=0 : This shard index (0-based)}
        {--shards=1 : Total shards — key groups are hash-partitioned across them}';

    protected $description = 'Merge identities that share a deterministic key. Run after parallel load workers finish, before finalize.';

    public function handle(): int
    {
        $engine = new Engine;
        $shard = (int) $this->option('shard');
        $shards = max(1, (int) $this->option('shards'));
        $this->info("Deduplicating identities (ssn/npi/upin/dea/license/name+dob) shard $shard/$shards...");
        $n = $engine->dedup(fn ($m) => $this->output->write("\r  merged: $m"), $shard, $shards);
        $this->newLine();
        $this->info("Done. $n identities merged.");

        return self::SUCCESS;
    }
}

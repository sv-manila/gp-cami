<?php

namespace App\Console\Commands;

use App\GoldenProfile\Engine;
use Illuminate\Console\Command;

class GpBackfill extends Command
{
    protected $signature = 'gp:backfill
        {system=streamline_local}
        {--from-id= : Start at this source id (inclusive)}
        {--to-id= : Stop at this source id (inclusive) — for partitioned parallel runs}
        {--chunk=1000 : Rows per chunk; also the resume checkpoint granularity}
        {--segment=default : Namespaces the resume cursor so parallel workers do not collide}
        {--no-defer : Materialize per chunk instead of one deferred pass}
        {--no-finalize : Skip the deferred finalize pass (parallel workers; run --finalize-only after)}
        {--finalize-only : Run only the survivorship + profile materialization pass, then exit}
        {--shard=0 : With --finalize-only, this shard index (0-based)}
        {--shards=1 : With --finalize-only, total number of finalize shards}
        {--restart : Ignore this segment’s saved cursor and start from the beginning}';

    protected $description = 'Mode 1: full backfill — resolve every source record into golden identities.';

    public function handle(): int
    {
        $engine = new Engine;

        // Finalize-only: materialize identities (run after parallel workers +
        // dedup). Shardable — run N processes with --shards=N --shard=0..N-1.
        if ($this->option('finalize-only')) {
            $shard = (int) $this->option('shard');
            $shards = max(1, (int) $this->option('shards'));
            $this->info("Finalizing (survivorship + profile materialization) shard $shard/$shards...");
            $engine->finalizeAll(
                fn ($d, $t = 0) => $this->output->write("\r  materialized: $d".($t ? " / $t" : '')),
                $shard,
                $shards,
            );
            $this->newLine();
            $this->info('Done.');

            return self::SUCCESS;
        }

        $segment = (string) $this->option('segment');
        $defer = ! $this->option('no-defer');
        $finalize = ! $this->option('no-finalize');
        $explicitFrom = $this->option('from-id') !== null ? (int) $this->option('from-id') : null;
        $toId = $this->option('to-id') !== null ? (int) $this->option('to-id') : null;

        if ($this->option('restart')) {
            $engine->clearBackfillCursor($segment);
        }

        if (! $this->option('restart')) {
            $cursor = $engine->backfillCursor($segment);
            if ($cursor !== null && ($explicitFrom === null || $cursor + 1 > $explicitFrom)) {
                $this->info("Resuming segment [$segment] from source id > $cursor.");
            }
        }

        $range = ($explicitFrom !== null || $toId !== null)
            ? ' ids '.($explicitFrom ?? 'start').'..'.($toId ?? 'end')
            : '';
        $this->info('Backfilling '.$this->argument('system').$range
            .($defer ? ' (deferred materialization)' : '')
            .(! $finalize ? ' [load only]' : '').'...');

        $n = $engine->backfill([
            'fromId' => $explicitFrom,
            'toId' => $toId,
            'chunk' => (int) $this->option('chunk'),
            'segment' => $segment,
            'defer' => $defer,
            'finalize' => $finalize,
            'progress' => fn ($c) => $this->output->write("\r  loaded: $c"),
            'finalizeProgress' => fn ($d, $t = 0) => $this->output->write("\r  materialized: $d".($t ? " / $t" : '')),
        ]);

        $this->newLine();
        $this->info("Done. $n source rows processed this run.");
        if (! $finalize) {
            $this->comment('Load-only run: run `php artisan gp:backfill --finalize-only` after all workers complete.');
        }

        return self::SUCCESS;
    }
}

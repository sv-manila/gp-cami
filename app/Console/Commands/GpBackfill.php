<?php

namespace App\Console\Commands;

use App\GoldenProfile\Engine;
use App\GoldenProfile\SqlBackfill;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class GpBackfill extends Command
{
    protected $signature = 'gp:backfill
        {system=streamline_local}
        {--from-id= : Start at this source id (inclusive)}
        {--to-id= : Stop at this source id (inclusive) — for a bounded / sanity run}
        {--chunk=5000 : Staging read/insert batch size}
        {--workers=16 : Parallel degree for the staging and finalize phases}
        {--stage-only : Internal: run only the stage phase for the given range}
        {--finalize-shard= : Internal: run only the finalize phase for this shard}
        {--shards= : Internal: total shards for --finalize-shard}';

    protected $description = 'Mode 1: full backfill — stage, resolve, enrich, dedup and finalize every source record into golden identities.';

    public function handle(): int
    {
        // --- internal sub-worker modes (spawned by the orchestrator) ---
        if ($this->option('stage-only')) {
            (new SqlBackfill)->stage($this->intOpt('from-id'), $this->intOpt('to-id'), (int) $this->option('chunk'));

            return self::SUCCESS;
        }
        if ($this->option('finalize-shard') !== null) {
            (new Engine)->finalizeAll(null, (int) $this->option('finalize-shard'), (int) $this->option('shards'));

            return self::SUCCESS;
        }

        // --- orchestrator ---
        $chunk = (int) $this->option('chunk');
        $workers = max(1, min(32, (int) $this->option('workers')));
        [$from, $to] = $this->resolveRange();

        $this->info("Backfilling {$this->argument('system')} ids $from..$to — $workers-way stage/finalize");

        // #2: parallel staging over disjoint id partitions.
        $span = $to - $from + 1;
        $stride = intdiv($span + $workers - 1, $workers);
        $stageCmds = [];
        for ($i = 0; $i < $workers; $i++) {
            $f = $from + $i * $stride;
            $t = min($f + $stride - 1, $to);
            if ($f > $to) {
                break;
            }
            $stageCmds[] = "--stage-only --from-id=$f --to-id=$t --chunk=$chunk";
        }
        $this->line('  [stage] '.count($stageCmds).' parallel partitions...');
        $this->runParallel($stageCmds);

        // Transform once in this process (bulk set-based, not parallel).
        (new SqlBackfill)->transform(fn ($p, $d) => $this->line("  [$p] $d"));

        // #5: sharded finalize.
        $this->line("  [finalize] $workers parallel shards...");
        $finCmds = [];
        for ($i = 0; $i < $workers; $i++) {
            $finCmds[] = "--finalize-shard=$i --shards=$workers";
        }
        $this->runParallel($finCmds);

        $c = (new SqlBackfill)->counts();
        $this->info("Done. identities={$c['identities']} links={$c['links']}");

        return self::SUCCESS;
    }

    private function intOpt(string $name): ?int
    {
        return $this->option($name) !== null ? (int) $this->option($name) : null;
    }

    /** Full id range, honoring --from-id/--to-id, else the source min/max. */
    private function resolveRange(): array
    {
        $from = $this->intOpt('from-id');
        $to = $this->intOpt('to-id');
        if ($from === null || $to === null) {
            $src = \Illuminate\Support\Facades\DB::connection('streamline_local')->table('employees');
            $from ??= (int) $src->min('id');
            $to ??= (int) $src->max('id');
        }

        return [$from, $to];
    }

    /** Run `gp:backfill <args>` sub-processes concurrently and wait for all. */
    private function runParallel(array $argSets): void
    {
        $procs = [];
        foreach ($argSets as $args) {
            $p = Process::fromShellCommandline(
                PHP_BINARY.' artisan gp:backfill '.$args,
                base_path(),
                null,
                null,
                null   // no timeout
            );
            $p->start();
            $procs[] = $p;
        }
        foreach ($procs as $p) {
            $p->wait();
            if (! $p->isSuccessful()) {
                $this->warn('  worker failed: '.trim($p->getErrorOutput() ?: $p->getOutput()));
            }
        }
    }
}

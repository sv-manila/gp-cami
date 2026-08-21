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
        {--chunk=2000 : Staging read/insert batch size}
        {--workers=16 : Parallel degree for the staging and finalize phases}
        {--restart : Clear staging checkpoints and stage from the beginning}
        {--legacy-finalize : Use the per-identity sharded finalize instead of the set-based one}
        {--stage-only : Internal: run only the stage phase for the given range}
        {--segment= : Internal: staging checkpoint key for this stripe}
        {--finalize-shard= : Internal: run only the finalize phase for this shard}
        {--shards= : Internal: total shards for --finalize-shard}';

    protected $description = 'Mode 1: full backfill — stage, resolve, enrich, dedup and finalize every source record into golden identities.';

    public function handle(): int
    {
        // --- internal sub-worker modes (spawned by the orchestrator) ---
        if ($this->option('stage-only')) {
            (new SqlBackfill)->stage(
                $this->intOpt('from-id'), $this->intOpt('to-id'), (int) $this->option('chunk'),
                null, (string) ($this->option('segment') ?? 'default'),
            );

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

        if ($this->option('restart')) {
            (new SqlBackfill)->clearStageCursors();
            $this->line('  [restart] cleared staging checkpoints');
        }

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
            $stageCmds[] = "--stage-only --segment=$i --from-id=$f --to-id=$t --chunk=$chunk";
        }
        $this->line('  [stage] '.count($stageCmds).' parallel partitions (resumable)...');
        if (! $this->runParallel($stageCmds)) {
            $this->error('Staging did not finish (a worker failed/died). Nothing transformed — '
                .'re-run `php artisan gp:backfill` to resume staging from the checkpoints, then it will transform.');

            return self::FAILURE;
        }

        // Transform once in this process (bulk set-based, not parallel).
        (new SqlBackfill)->transform(fn ($p, $d) => $this->line("  [$p] $d"));

        // Finalize: set-based (default) — one pass of big statements; or the
        // legacy per-identity sharded path (--legacy-finalize).
        if ($this->option('legacy-finalize')) {
            $this->line("  [finalize] $workers parallel shards (per-identity)...");
            $finCmds = [];
            for ($i = 0; $i < $workers; $i++) {
                $finCmds[] = "--finalize-shard=$i --shards=$workers";
            }
            $this->runParallel($finCmds);
        } else {
            (new Engine)->finalizeAllSet(fn ($p, $d) => $this->line("  [$p] $d"));
        }

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

    /**
     * Run `gp:backfill <args>` sub-processes concurrently and wait for all.
     * Returns false if any worker exited non-zero — the caller must NOT proceed
     * to a phase that assumes the workers completed (e.g. transform after stage).
     */
    private function runParallel(array $argSets): bool
    {
        $procs = [];
        foreach ($argSets as $args) {
            // -d memory_limit=512M: staging chunks build sizable in-memory arrays
            // (persons + children + additional_info); the 128M CLI default OOMs.
            $p = Process::fromShellCommandline(
                PHP_BINARY.' -d memory_limit=512M artisan gp:backfill '.$args,
                base_path(),
                null,
                null,
                null   // no timeout
            );
            $p->start();
            $procs[] = $p;
        }
        $ok = true;
        foreach ($procs as $p) {
            $p->wait();
            if (! $p->isSuccessful()) {
                $ok = false;
                $this->warn('  worker failed: '.trim($p->getErrorOutput() ?: $p->getOutput()));
            }
        }

        return $ok;
    }
}

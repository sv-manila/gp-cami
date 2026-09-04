<?php

namespace App\Console\Commands;

use App\GoldenProfile\Eval\EvalRunner;
use App\GoldenProfile\Eval\EvalSet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GpEval extends Command
{
    protected $signature = 'gp:eval
        {--set=tests/eval/identity-pairs.json : path to the labeled set}
        {--min-precision=0.99 : fail below this precision}
        {--min-recall=0.80 : fail below this recall}
        {--allow-false-merges=0 : fail above this many false merges}';

    protected $description = 'Scratch-only: score the resolver against a labeled evaluation set. '
        .'Runs inside a transaction that is always rolled back, and refuses to run '
        .'anywhere but a database whose name starts with gp_ and contains test.';

    public function handle(): int
    {
        $set = EvalSet::load(base_path((string) $this->option('set')));

        // Same connection the resolver writes to — see EvalRunner's docblock.
        $hub = DB::connection('golden_profile');
        $database = $hub->getDatabaseName();

        // Same rule as HubTestCase::guardAgainstTheRealHub(). This command writes
        // synthetic people and identities into whatever GP_DB_DATABASE points at
        // and, before the fix below, never cleaned up — pointed at a freshly
        // provisioned (and therefore empty) production hub, it would pass the
        // emptiness check and inject fixture data straight into prod. Checking
        // the schema name first, before any table is even queried, closes that.
        if (! str_starts_with($database, 'gp_') || ! str_contains($database, 'test')) {
            $this->error(
                "refusing to run against '$database': gp:eval only runs against a scratch ".
                "schema whose name starts with 'gp_' and contains 'test' (e.g. gp_cami_test)."
            );

            return self::FAILURE;
        }

        if ($hub->table('stg_person')->exists() || $hub->table('gp_identity')->exists()) {
            $this->error("refusing to run: '$database' already holds staged people or identities.");
            $this->line('Point GP_DB_DATABASE at an empty scratch schema and migrate it first.');

            return self::FAILURE;
        }

        $hub->beginTransaction();

        try {
            $systemId = (int) $hub->table('gp_source_system')->insertGetId([
                'system_code' => 'eval-'.uniqid(),
                'display_name' => 'eval harness',
                'reliability_rank' => 50,
                'is_active' => 1,
                'added_at' => now(),
            ]);

            $report = (new EvalRunner($systemId))->run($set)['report'];
        } finally {
            // Leave no residue: every insert this command makes — the source
            // system row and everything EvalRunner stages/resolves — rolls back
            // here, so a second run never trips the emptiness guard above.
            $hub->rollBack();
        }

        $this->table(['metric', 'value'], [
            ['true pairs', $report['true_pairs']],
            ['predicted pairs', $report['predicted_pairs']],
            ['true positives', $report['true_positives']],
            ['false merges', $report['false_merges']],
            ['false splits', $report['false_splits']],
            ['precision', number_format($report['precision'], 4)],
            ['recall', number_format($report['recall'], 4)],
            ['f1', number_format($report['f1'], 4)],
        ]);

        $failures = [];
        if ($report['false_merges'] > (int) $this->option('allow-false-merges')) {
            $failures[] = "false merges {$report['false_merges']} exceeds the allowance";
        }
        if ($report['precision'] < (float) $this->option('min-precision')) {
            $failures[] = 'precision below floor';
        }
        if ($report['recall'] < (float) $this->option('min-recall')) {
            $failures[] = 'recall below floor';
        }

        foreach ($failures as $f) {
            $this->error($f);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}

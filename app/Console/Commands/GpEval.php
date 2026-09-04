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

    protected $description = 'Score the resolver against a labeled evaluation set';

    public function handle(): int
    {
        $set = EvalSet::load(base_path((string) $this->option('set')));

        // Same connection the resolver writes to — see EvalRunner's docblock.
        $hub = DB::connection('golden_profile');
        $database = $hub->getDatabaseName();

        if ($hub->table('stg_person')->exists() || $hub->table('gp_identity')->exists()) {
            $this->error("refusing to run: '$database' already holds staged people or identities.");
            $this->line('Point GP_DB_DATABASE at an empty scratch schema and migrate it first.');

            return self::FAILURE;
        }

        $systemId = (int) $hub->table('gp_source_system')->insertGetId([
            'system_code' => 'eval-'.uniqid(),
            'display_name' => 'eval harness',
            'reliability_rank' => 50,
            'is_active' => 1,
            'added_at' => now(),
        ]);

        $report = (new EvalRunner($systemId))->run($set)['report'];

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

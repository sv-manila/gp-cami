<?php

namespace App\Console\Commands;

use App\GoldenProfile\Materialize\AliasIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * (Re)build gp_identity_alias, the surname index identity-search matches aliases
 * against.
 *
 * Normally maintained in step with the aliases JSON by ProfileMaterializer and
 * SetFinalizer, so this is for the initial build and for repairing drift — it
 * needs no re-resolution and rewrites nothing but this one table.
 */
class GpRebuildAliases extends Command
{
    protected $signature = 'gp:rebuild-aliases
        {--identity= : Rebuild one identity only}
        {--from= : Start identity_id}
        {--to= : End identity_id}
        {--verify : Report coverage against the aliases JSON instead of rebuilding}';

    protected $description = 'Rebuild the alias surname index used by identity-search';

    public function handle(AliasIndexer $indexer): int
    {
        if ($this->option('verify')) {
            return $this->verify();
        }

        if ($identity = $this->option('identity')) {
            $rows = $indexer->refresh((int) $identity);
            $this->info("identity $identity: $rows alias row(s)");

            return self::SUCCESS;
        }

        $from = $this->option('from') !== null ? (int) $this->option('from') : null;
        $to = $this->option('to') !== null ? (int) $this->option('to') : null;

        $t0 = microtime(true);

        if ($from !== null || $to !== null) {
            $written = $indexer->rebuildAll($from, $to);
        } else {
            // Full rebuild walks staging (108k rows), not the 13.38M identity range.
            $written = $indexer->rebuildFromStaging(function (int $upTo, int $rows) {
                $this->line("  staged alias rows up to $upTo — ".number_format($rows).' written');
            });
        }

        $this->info(sprintf('done: %s alias row(s) in %ss', number_format($written), round(microtime(true) - $t0, 1)));

        return self::SUCCESS;
    }

    /**
     * Coverage check: every identity whose JSON rollup carries a non-null alias
     * surname should have at least one row here. Drift means a materialize ran
     * without the indexer, which is the failure this table is most exposed to.
     */
    private function verify(): int
    {
        $hub = DB::connection(config('golden_profile.connections.hub', 'golden_profile'));

        $indexed = (int) $hub->table('gp_identity_alias')->distinct()->count('identity_id');

        $expected = (int) $hub->selectOne(
            "SELECT COUNT(*) c FROM gp_identity_profile
              WHERE JSON_LENGTH(aliases) > 0
                AND JSON_SEARCH(aliases, 'one', '%', NULL, '\$[*].last') IS NOT NULL"
        )->c;

        $this->table(
            ['metric', 'value'],
            [
                ['identities in gp_identity_alias', number_format($indexed)],
                ['identities whose JSON has a surname alias', number_format($expected)],
                ['difference', number_format($expected - $indexed)],
            ],
        );

        if ($expected - $indexed !== 0) {
            $this->warn('Index and JSON disagree — run without --verify to rebuild.');

            return self::FAILURE;
        }

        $this->info('Alias index matches the profile rollup.');

        return self::SUCCESS;
    }
}

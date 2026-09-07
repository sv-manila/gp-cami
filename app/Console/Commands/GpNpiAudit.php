<?php

namespace App\Console\Commands;

use App\GoldenProfile\Support\NpiValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only measurement, not a fix. NPI validation lands in
 * StreamlineLocalConnector::personRow() (see this plan's Task 3), which
 * screens NPIs on every future ingest — but it says nothing about identities
 * a PRIOR load already bound on an npi that would fail this check today.
 * Rejecting one of those retroactively would split that identity; whether that
 * is rare or common can only be answered by running this against the real
 * hub, which nobody building this plan has access to (see this plan's
 * Self-review). This command is that measurement, ready for whoever does.
 */
class GpNpiAudit extends Command
{
    protected $signature = 'gp:npi-audit';

    protected $description = 'Read-only: count active identities whose npi is not Luhn-valid '
        .'under the 80840-prefixed NPI check digit, and how many source links are bound via the '
        .'npi tier onto one of them. Run against a real hub to size the retroactive-validation risk.';

    public function handle(): int
    {
        $hub = DB::connection('golden_profile');

        // current = 1 as well as status = 'active'. Without it this counts every
        // VERSION of every identity, so an identity re-versioned three times is
        // reported three times and the retroactive-split exposure this command
        // exists to size comes out inflated. Added when plan 3a versioned
        // gp_identity — the command predates versioning.
        $withNpi = $hub->table('gp_identity')
            ->where('current', 1)->where('status', 'active')->whereNotNull('npi')
            ->select('identity_id', 'npi')->get();

        $invalid = $withNpi->filter(fn ($r) => ! NpiValidator::isValid((string) $r->npi));

        $this->info("Scanned {$withNpi->count()} active identities with a non-null npi.");
        $this->info("{$invalid->count()} fail the Luhn+80840 check digit and would lose npi as ".
            'a bind key if validation were enforced retroactively.');

        if ($invalid->isNotEmpty()) {
            $linkCount = $hub->table('gp_source_link')
                ->where('match_key', 'npi')
                ->whereIn('identity_id', $invalid->pluck('identity_id'))
                ->count();
            $this->info("$linkCount source link(s) are currently bound via the npi tier onto one ".
                'of those identities — that many rows are the retroactive-split exposure.');

            $this->table(['identity_id', 'npi'], $invalid->take(50)->map(fn ($r) => [$r->identity_id, $r->npi])->all());
            if ($invalid->count() > 50) {
                $this->line('... '.($invalid->count() - 50).' more not shown.');
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\GoldenProfile\Eval;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\SqlBackfill;
use Illuminate\Support\Facades\DB;

/**
 * Stages an EvalSet into the hub, resolves every record, and reads back the
 * clustering the engine produced. The hub must already be migrated and carry a
 * gp_source_system row for $systemId.
 *
 * The connection is hardcoded to 'golden_profile' on purpose. It must be the
 * same connection DeterministicResolver::hub() uses — that method hardcodes it
 * too, and does NOT read golden_profile.connections.hub. Reading the config key
 * here would let a caller stage into a scratch database while the resolver wrote
 * identities into the real hub. Point the CONNECTION at a scratch schema
 * (GP_DB_DATABASE), never a different connection name.
 *
 * TWO MODES, because the ladder is implemented twice. run() drives the per-row
 * resolver (gp:sync's path); runSetBased() drives SqlBackfill (gp:backfill's path).
 * They must score the eval set identically — that is the cheapest parity check the
 * programme has, and EvalGateBothPathsTest asserts it. If they ever diverge, the
 * gate becomes the second line of defence behind SetBasedParityTest rather than the
 * first sign of trouble.
 */
class EvalRunner
{
    public function __construct(private int $systemId) {}

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /**
     * Stage every record with its licences and identifiers. Returns
     * ref => stg_person_id.
     *
     * source_id is crc32($ref), which is DETERMINISTIC — the same fixture staged
     * twice produces the same source ids, which is what lets a parity test run both
     * ladders over identical input and compare clusters by ref.
     *
     * @return array<string,int>
     */
    public function stage(EvalSet $set): array
    {
        $stgByRef = [];

        foreach ($set->records() as $r) {
            $ref = $r['ref'];

            $stgByRef[$ref] = (int) $this->hub()->table('stg_person')->insertGetId([
                'system_id' => $this->systemId,
                'source_table' => 'employees',
                'source_id' => crc32($ref),
                'account_id' => 1,
                'employeelist_id' => 1,
                'first_name' => $r['first_name'] ?? null,
                'middle_name' => $r['middle_name'] ?? null,
                'last_name' => $r['last_name'] ?? null,
                'name_suffix' => null,
                'date_of_birth' => $r['date_of_birth'] ?? null,
                'ssn_hash' => $r['ssn_hash'] ?? null,
                'ssn_last_four' => null,
                'npi' => $r['npi'] ?? null,
                'upin' => $r['upin'] ?? null,
                'dea_number' => $r['dea_number'] ?? null,
                'address1' => $r['address1'] ?? null,
                'city' => $r['city'] ?? null,
                'state' => $r['state'] ?? null,
                'zip' => $r['zip'] ?? null,
                'terminated' => 0,
                'source_modified' => now()->toDateTimeString(),
                'ingested_at' => now(),
                'block_key' => $this->blockKey($r['last_name'] ?? null, $r['date_of_birth'] ?? null),
            ]);

            foreach ($set->licenses($ref) as $lic) {
                $this->hub()->table('stg_person_license')->insert([
                    'stg_person_id' => $stgByRef[$ref],
                    'license_number' => $lic['license_number'],
                    'certification_state' => $lic['certification_state'] ?? null,
                    'certification_board' => null,
                    'license_type' => null,
                    'license_type_id' => null,
                    'registry' => null,
                    'is_primary' => 1,
                ]);
            }

            // Identifiers, so the fixture can exercise the dea_multi and
            // mmis+state tiers plan 5 added. state is absent on DEA rows in the
            // fixture JSON (DEA is federal), hence the ?? null.
            foreach ($set->identifiers($ref) as $ident) {
                $this->hub()->table('stg_person_identifier')->insert([
                    'stg_person_id' => $stgByRef[$ref],
                    'id_type' => $ident['id_type'],
                    'id_value' => $ident['id_value'],
                    'state' => $ident['state'] ?? null,
                ]);
            }
        }

        return $stgByRef;
    }

    /**
     * PER-ROW path: DeterministicResolver::resolve() once per staged row. Six
     * deterministic tiers plus Pass B, with enrich() inline, which is why no
     * separate enrich or dedup step appears here.
     *
     * @return array{clusters: list<list<string>>, report: array<string,mixed>}
     */
    public function run(EvalSet $set): array
    {
        $stgByRef = $this->stage($set);
        $resolver = new DeterministicResolver($this->systemId);

        $byIdentity = [];
        foreach ($stgByRef as $ref => $stgId) {
            $byIdentity[$resolver->resolve($stgId)][] = $ref;
        }

        $clusters = array_values($byIdentity);

        return [
            'clusters' => $clusters,
            'report' => MatchScorer::score($clusters, $set->truthClusters()),
        ];
    }

    /**
     * SET-BASED path: SqlBackfill's tiers, then enrich(), then Engine::dedup().
     *
     * All three, and none of them optional. The set-based ladder has no licence
     * tier, no identifier tier and no Pass B — resolveDeterministic()'s own comment
     * records why: "license resolution is handled after enrich(), by dedup's
     * mergeByLicense — it needs gp_license populated, which enrich() does", and
     * "the probabilistic Pass B is intentionally skipped here". So enrich() and
     * dedup() are not extras bolted on for the test; they are where the per-row
     * path's licence and identifier tiers live, and omitting them would compare a
     * four-tier ladder against a six-tier one.
     *
     * $this->systemId must be SqlBackfill's own (SYSTEM_CODE = 'streamline_local'),
     * not a uniqid-suffixed test system: resolveDeterministic() filters
     * stg_person on its own systemId, and Survivorship's authority rank is looked
     * up by system_code.
     *
     * @return array{clusters: list<list<string>>, report: array<string,mixed>}
     */
    public function runSetBased(EvalSet $set): array
    {
        $stgByRef = $this->stage($set);

        $backfill = new SqlBackfill;
        $backfill->indexStaging();
        $backfill->resolveDeterministic();
        $backfill->enrich();
        (new Engine)->dedup();

        // gp_source_link is repointed onto the survivor by a merge, so grouping the
        // links by identity_id gives the post-dedup clustering directly.
        $refBySource = [];
        foreach (array_keys($stgByRef) as $ref) {
            $refBySource[crc32($ref)] = $ref;
        }

        $byIdentity = [];
        foreach ($this->hub()->table('gp_source_link')
            ->where('system_id', $this->systemId)
            ->orderBy('link_id')->get(['identity_id', 'source_id']) as $link) {
            $ref = $refBySource[(int) $link->source_id] ?? null;
            if ($ref !== null) {
                $byIdentity[(int) $link->identity_id][] = $ref;
            }
        }

        $clusters = array_values($byIdentity);

        return [
            'clusters' => $clusters,
            'report' => MatchScorer::score($clusters, $set->truthClusters()),
        ];
    }

    /** Same rule as StreamlineLocalConnector::blockKey(). */
    private function blockKey(?string $last, ?string $dob): ?string
    {
        $last = $last ? trim($last) : null;

        if (! $last) {
            return null;
        }

        return soundex($last).'|'.($dob ? substr((string) $dob, 0, 4) : '____');
    }
}

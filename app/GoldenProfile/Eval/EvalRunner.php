<?php

namespace App\GoldenProfile\Eval;

use App\GoldenProfile\Resolution\DeterministicResolver;
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
 */
class EvalRunner
{
    public function __construct(private int $systemId) {}

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /**
     * @return array{clusters: list<list<string>>, report: array<string,mixed>}
     */
    public function run(EvalSet $set): array
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
        }

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

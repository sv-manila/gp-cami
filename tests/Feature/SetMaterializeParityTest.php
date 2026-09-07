<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Materialize\SetFinalizer;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * The profile is a projection of the CURRENT version of everything. It is itself
 * unversioned — a rebuildable read model whose rows reach 100MB of JSON, so
 * versioning it would multiply 100MB rows to record nothing the versioned tables
 * do not already hold — which makes these read filters the only thing keeping it
 * correct. A missing one does not throw: it doubles a count and duplicates a JSON
 * entry.
 *
 * The acceptance test is the invariant the repo already documents: a set-based
 * materialize and ProfileMaterializer::rebuild() produce the same row. The
 * comparison is byte-exact for scalars and multiset-exact for the JSON aggregates,
 * because MySQL 8 has no ORDER BY inside JSON_ARRAYAGG and neither path's element
 * order is pinned. See docs/SCD2.md.
 */
class SetMaterializeParityTest extends HubTestCase
{
    /** Columns neither path can be expected to match: identity-scoped or clock-scoped. */
    private const VOLATILE = ['identity_uuid', 'first_seen', 'last_updated', 'profile_built_at'];

    /** Columns holding a JSON array whose element ORDER is not pinned by either path. */
    private const JSON_ARRAYS = [
        'identifiers', 'addresses', 'licenses', 'aliases', 'source_records',
        'accounts', 'credentials', 'exclusions', 'board_actions', 'resolutions',
    ];

    private int $identityId;

    private int $linkId;

    protected function setUp(): void
    {
        parent::setUp();

        $stg = $this->stagePerson(['npi' => 1234567893, 'terminated' => 0]);
        $source = $this->hub()->table('stg_person')->where('stg_person_id', $stg)->value('source_id');

        $this->identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'npi' => 1234567893, 'confidence' => 1.0, 'record_count' => 1, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->linkId = (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $this->identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => $source,
            'account_id' => 1, 'employeelist_id' => 1, 'match_method' => 'deterministic',
            'match_key' => 'npi', 'match_score' => 0.99, 'linked_at' => now(),
        ]);
    }

    /**
     * One current version and one superseded version of every child fact. If a read
     * filter is missing, the count doubles and the JSON carries the stale value.
     */
    private function seedTwoVersionsOfEverything(): void
    {
        $hub = $this->hub();

        foreach ([['RN', 1, 0], ['LPN', 2, 1]] as [$type, $version, $current]) {
            $hub->table('gp_license')->insert([
                'identity_id' => $this->identityId, 'license_number' => 'L-77',
                'certification_state' => 'CA', 'certification_board' => 'BRN',
                'license_type' => $type, 'license_type_id' => null, 'registry' => 'CA-BRN',
                'is_verified' => 0, 'source_link_id' => $this->linkId,
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
        }

        foreach ([['Apt 1', 1, 0], ['Apt 2', 2, 1]] as [$address2, $version, $current]) {
            $hub->table('gp_address')->insert([
                'identity_id' => $this->identityId, 'address1' => '1 Main St',
                'address2' => $address2, 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701',
                'is_primary' => 1, 'source_link_id' => $this->linkId,
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
        }

        foreach ([[1, 0], [2, 1]] as [$version, $current]) {
            $hub->table('gp_identity_identifier')->insert([
                'identity_id' => $this->identityId, 'id_type' => 'dea', 'id_value' => 'BX1234563',
                'source_link_id' => $this->linkId,
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
            $hub->table('gp_identity_credential')->insert([
                'identity_id' => $this->identityId, 'credential_match_id' => 501,
                'system_id' => $this->systemId, 'registry' => 'CA-BRN',
                'match_summary_status' => 'Verified', 'match_summary_status_code' => 1,
                'match_is_valid' => 1, 'source_current' => 1, 'date_resolved' => null,
                'link_state' => 'confirmed', 'link_confidence' => null,
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
            $hub->table('gp_identity_exclusion')->insert([
                'identity_id' => $this->identityId, 'match_id' => 601,
                'system_id' => $this->systemId, 'exclusion_record_id' => null, 'registry' => 'LEIE',
                'is_ssn_match' => 0, 'is_npi_match' => 1, 'is_canonical_name_match' => 1,
                'is_upin_match' => 0, 'is_license_number_match' => 0,
                'link_state' => 'candidate', 'link_confidence' => null,
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
        }
    }

    /** The profile row, volatile columns dropped and JSON arrays canonicalised. */
    private function snapshot(): array
    {
        $row = (array) $this->hub()->table('gp_identity_profile')
            ->where('identity_id', $this->identityId)->first();

        foreach (self::VOLATILE as $column) {
            unset($row[$column]);
        }

        foreach (self::JSON_ARRAYS as $column) {
            $decoded = json_decode((string) ($row[$column] ?? ''), true) ?? [];
            $encoded = array_map(fn ($e) => json_encode($e), $decoded);
            sort($encoded);
            $row[$column] = $encoded;
        }

        return $row;
    }

    public function test_the_aggregates_count_current_rows_only(): void
    {
        $this->seedTwoVersionsOfEverything();

        (new SetFinalizer)->materialize();

        $row = $this->hub()->table('gp_identity_profile')
            ->where('identity_id', $this->identityId)->first();

        $this->assertSame(1, (int) $row->license_count, 'licenses aggregated a superseded version');
        $this->assertSame(1, (int) $row->address_count, 'addresses aggregated a superseded version');
        $this->assertSame(1, (int) $row->identifier_count, 'identifiers aggregated a superseded version');
        $this->assertSame(1, (int) $row->credential_count, 'credentials aggregated a superseded version');
        $this->assertSame(1, (int) $row->exclusion_count, 'exclusions aggregated a superseded version');
    }

    public function test_the_json_carries_the_current_version_and_not_the_old_one(): void
    {
        $this->seedTwoVersionsOfEverything();

        (new SetFinalizer)->materialize();

        $row = $this->hub()->table('gp_identity_profile')
            ->where('identity_id', $this->identityId)->first();

        // Decoded, not substring-matched. The plan's version asserted the JSON does
        // not contain 'RN"', which is also a substring of the board code "BRN" —
        // so it could never pass on this fixture regardless of the filters. Reading
        // the values is both precise and immune to the two paths' different JSON
        // rendering (MySQL emits '"type": "LPN"', PHP emits '"type":"LPN"').
        $licenses = json_decode((string) $row->licenses, true);
        $addresses = json_decode((string) $row->addresses, true);

        $this->assertSame(['LPN'], array_column($licenses, 'type'),
            'the superseded licence type must not be aggregated');
        $this->assertSame(['Apt 2'], array_column($addresses, 'address2'),
            'the superseded address must not be aggregated');
    }

    public function test_the_primary_address_scalars_come_from_the_current_version(): void
    {
        // $prim is a window function over gp_address, so it needs the filter in its
        // OWN source, not just in the outer query. Unfiltered, ORDER BY is_primary
        // DESC, address_id ASC picks the OLDEST version, which is the superseded one.
        $this->seedTwoVersionsOfEverything();

        (new SetFinalizer)->materialize();

        $row = $this->hub()->table('gp_identity_profile')
            ->where('identity_id', $this->identityId)->first();

        $this->assertSame('1 Main St', $row->address1);
        $this->assertSame('62701', $row->zip);
        $this->assertStringContainsString('Apt 2', $row->addresses);
    }

    public function test_the_two_materialize_paths_agree(): void
    {
        // The documented invariant. It is why Survivorship's final tiebreak is
        // pinned to link_id ASC to match SetFinalizer's SQL.
        $this->seedTwoVersionsOfEverything();

        (new SetFinalizer)->materialize();
        $setBased = $this->snapshot();

        $this->hub()->table('gp_identity_profile')->where('identity_id', $this->identityId)->delete();
        (new ProfileMaterializer)->rebuild($this->identityId);
        $perRow = $this->snapshot();

        $this->assertSame(
            $setBased, $perRow,
            'the set-based materialize and ProfileMaterializer::rebuild() disagree'
        );
    }
}

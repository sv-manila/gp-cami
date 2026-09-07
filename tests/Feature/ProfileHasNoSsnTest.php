<?php

namespace Tests\Feature;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
use ReflectionClass;
use Tests\Support\HubTestCase;

/**
 * The pipeline must not carry an SSN-derived value past staging, and the two
 * finalize paths must agree about which fields exist at all.
 *
 * On ssn_last_four specifically: it is not the SSN, and the Delivery Checklist
 * forbids only the SSN — but CAMI is the system of record for
 * employees.social_security_num AND the only caller of these endpoints, so the hub
 * read an SSN-derived value out of CAMI's own database, stored it, and handed it
 * back. That is PII duplicated across a trust boundary for no information the
 * caller lacked.
 *
 * The plan gives a second reason that no longer applies and is worth recording as
 * closed rather than repeating: it says ProfileMaterializer picked the last four
 * with no ORDER BY while SetFinalizer used ROW_NUMBER() ordered by stg_person_id,
 * so the two finalizers could disagree on one identity. That was true when the
 * plan was written; plan 3b pinned ProfileMaterializer to orderBy('stg_person_id')
 * as one of four order-dependent picks, so the divergence was already gone before
 * this task ran. The field goes on the compliance argument alone.
 *
 * The index-parity half of the plan's Step 1 is NOT here: IdentityKeyIndexParityTest
 * already compares SqlBackfill's and SetFinalizer's IDENTITY_KEY_INDEXES against the
 * definition the migration writes, which is strictly stronger than comparing the two
 * to each other. It also still expects idx_ssn, deliberately — see the comment on
 * that constant. The index goes with the column, in Task 7.
 */
class ProfileHasNoSsnTest extends HubTestCase
{
    public function test_the_two_finalizers_declare_identical_identity_fields(): void
    {
        // Documented as "the same map as Survivorship" and never enforced. Both are
        // edited by this task; a one-sided edit is the drift that broke the
        // byte-identical-profile invariant once already.
        $perRow = (new ReflectionClass(Survivorship::class))
            ->getReflectionConstant('IDENTITY_FIELDS')->getValue();
        $setBased = (new ReflectionClass(SetFinalizer::class))
            ->getReflectionConstant('IDENTITY_FIELDS')->getValue();

        $this->assertSame($perRow, $setBased,
            'Survivorship and SetFinalizer must survive exactly the same fields, in the same order');
        $this->assertArrayNotHasKey('ssn_hash', $perRow);

        // Not vacuous: the map must still carry the fields survivorship exists to
        // pick. An empty or truncated map would satisfy both assertions above.
        foreach (['canonical_first', 'canonical_last', 'canonical_dob', 'npi', 'upin', 'dea_number'] as $field) {
            $this->assertArrayHasKey($field, $perRow, "survivorship stopped picking $field");
        }
    }

    public function test_a_rebuilt_profile_carries_no_ssn_value(): void
    {
        // The columns still exist at this point in the plan — the migration is
        // Task 7 — so this proves the WRITERS stopped, which is what has to be true
        // before the columns can safely go.
        $stg = $this->stagePerson([
            'first_name' => 'Grace', 'last_name' => 'Adeyemi', 'date_of_birth' => '1979-05-14',
            'ssn_hash' => hash('sha512', 'profile-has-no-ssn-test'), 'ssn_last_four' => '4321',
        ]);

        $identityId = (new DeterministicResolver($this->systemId))->resolve($stg);
        (new Survivorship)->recompute($identityId);
        (new ProfileMaterializer)->rebuild($identityId);

        $profile = $this->hub()->table('gp_identity_profile')
            ->where('identity_id', $identityId)->first();

        $this->assertNotNull($profile, 'the profile was not materialized at all');
        $this->assertSame('Adeyemi', $profile->last_name, 'the profile is materializing the wrong row');
        $this->assertNull($profile->ssn_hash, 'the materializer still writes ssn_hash');
        $this->assertNull($profile->ssn_last_four, 'the materializer still writes ssn_last_four');

        // gp_identity itself must not have been given the hash either. This is the
        // assertion that catches survivorship putting back what Task 2 stopped
        // createIdentity() from writing.
        $this->assertNull(
            $this->hub()->table('gp_identity')->where('identity_id', $identityId)->value('ssn_hash'),
            'resolution or survivorship still writes gp_identity.ssn_hash',
        );

        // And the staged row still HAS both values — otherwise the four assertions
        // above would hold for an identity that never saw an SSN at all.
        $staged = $this->hub()->table('stg_person')->where('stg_person_id', $stg)->first();
        $this->assertNotNull($staged->ssn_hash, 'the fixture never staged an ssn_hash');
        $this->assertSame('4321', $staged->ssn_last_four, 'the fixture never staged an ssn_last_four');
    }

    public function test_survivorship_records_no_ssn_provenance(): void
    {
        // Survivorship writes every candidate value to gp_attribute and the winner
        // to gp_survivorship_audit. An ssn_hash left in IDENTITY_FIELDS would keep
        // copying the hash into two more tables, neither of which the migration
        // touches — a quiet second store of the thing being removed.
        $stg = $this->stagePerson([
            'first_name' => 'Grace', 'last_name' => 'Adeyemi', 'date_of_birth' => '1979-05-14',
            'ssn_hash' => hash('sha512', 'profile-has-no-ssn-provenance'),
        ]);

        $identityId = (new DeterministicResolver($this->systemId))->resolve($stg);
        (new Survivorship)->recompute($identityId);

        $this->assertSame(0, $this->hub()->table('gp_attribute')
            ->where('identity_id', $identityId)->where('attr_name', 'ssn_hash')->count());
        $this->assertSame(0, $this->hub()->table('gp_survivorship_audit')
            ->where('identity_id', $identityId)->where('attribute_name', 'ssn_hash')->count());

        // Non-vacuity: recompute() must have written provenance for the fields it
        // does still survive. Zero rows overall would satisfy both assertions.
        $this->assertGreaterThan(0, $this->hub()->table('gp_attribute')
            ->where('identity_id', $identityId)->where('attr_name', 'canonical_last')->count(),
            'survivorship recorded no provenance at all');
    }

    public function test_the_connector_does_not_read_ssn_from_the_source(): void
    {
        // The compliance boundary. personRow() is a pure mapping (no DB write) and
        // takes the source row as an object, so it can be called with a stub. After
        // this task gp-cami never reads an SSN-derived column out of
        // streamline_local at all — which is what "never store SSN" requires, and
        // is stronger than merely not persisting it.
        //
        // npi is 1234567893 and not the plan's 0: plan 5 added NpiValidator, which
        // screens the value against the NPPES Luhn-over-80840 check digit, and a
        // mapping that silently dropped a valid npi would go unnoticed with 0.
        $emp = (object) [
            'id' => 501, 'employeelist_id' => null, 'first_name' => 'Grace',
            'middle_name' => null, 'last_name' => 'Adeyemi', 'date_of_birth' => '1979-05-14',
            'ssn_hash' => hash('sha512', 'connector-must-not-read-this'),
            'ssn_last_four' => '4321', 'npi' => 1234567893, 'upin' => null,
            'address1' => null, 'city' => null, 'state' => null, 'zip' => null,
            'terminated' => 0, 'date_modified' => '2026-08-01 00:00:00',
        ];

        $row = (new StreamlineLocalConnector($this->systemId))->personRow($emp, []);

        $this->assertArrayNotHasKey('ssn_hash', $row);
        $this->assertArrayNotHasKey('ssn_last_four', $row);
        $this->assertSame('Adeyemi', $row['last_name'], 'the mapping itself broke');
        $this->assertSame(1234567893, (int) $row['npi'], 'the mapping stopped carrying npi');
    }
}

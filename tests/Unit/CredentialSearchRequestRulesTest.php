<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\CredentialSearchController;
use App\Http\Requests\CredentialSearchRequest;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use ReflectionClass;
use Tests\TestCase;

/**
 * The credential-search request contract was confirmed with CAMI on 2026-07-20
 * (PROJECT_PLAN §7) and the GPP conformance programme narrows it. `ssn` survives,
 * because it does two unrelated jobs and only one of them touched stored data:
 *
 *   1. narrowing identity resolution via gp_identity_profile.ssn_hash — removed
 *      with the column;
 *   2. gating credential matches whose own scrape recorded an SSN, which compares
 *      against credential_matches.match->request_params.ssn read live from the
 *      source and needs no key, no hash and no hub write.
 *
 * Job 2 is why the field stays: dropping it would return one person's credential
 * for a request about another, with no compliance benefit at all since the value
 * is never persisted and never logged.
 *
 * A bare last-four is now accepted so CAMI can send less. CredentialSelector
 * already compares at whatever precision the two sides share, so this is a
 * validation change only.
 */
class CredentialSearchRequestRulesTest extends TestCase
{
    private function validate(array $payload): ValidatorContract
    {
        $request = new CredentialSearchRequest;

        return Validator::make($payload, $request->rules(), $request->messages());
    }

    private function base(array $extra = []): array
    {
        return array_merge([
            'registry' => 'CA-BRN', 'first_name' => 'Grace', 'last_name' => 'Adeyemi',
        ], $extra);
    }

    public function test_the_ssn_field_is_still_accepted(): void
    {
        // It gates credential matches. Removing it would be a precision regression
        // dressed up as a compliance win.
        foreach (['123456789', '123-45-6789', '123 45 6789'] as $shape) {
            $this->assertFalse($this->validate($this->base(['ssn' => $shape]))->fails(), $shape);
        }
    }

    public function test_a_bare_last_four_is_accepted(): void
    {
        // Lets CAMI stop putting a whole SSN on the wire. The gate still works:
        // CredentialSelector::ssnParts() reports a partial value as known-by-last4
        // and identityAgrees() compares at the shared precision.
        $this->assertFalse($this->validate($this->base(['ssn' => '6789']))->fails());
    }

    public function test_a_malformed_ssn_is_still_rejected(): void
    {
        // The shape check exists because a typo used to become a non-matching hash
        // instead of an error the caller could see. It still matters: a typo now
        // silently withholds every SSN-bearing credential match instead.
        foreach (['12345', '12345678', '1234567890', 'abcdefghi', '123-4-56789'] as $bad) {
            $v = $this->validate($this->base(['ssn' => $bad]));
            $this->assertTrue($v->fails(), $bad);
            $this->assertSame(
                'ssn must be 9 digits (optionally separated as 123-45-6789) or the last 4 digits.',
                $v->errors()->first('ssn'),
                $bad,
            );
        }
    }

    public function test_ssn_remains_optional(): void
    {
        $this->assertFalse($this->validate($this->base())->fails());
    }

    public function test_the_controller_takes_no_ssn_hasher(): void
    {
        // SsnHasher is deleted in the next task and this injection is the only
        // thing standing in the way. Asserted structurally rather than by
        // instantiating the controller, which would need the container.
        $ctor = (new ReflectionClass(CredentialSearchController::class))->getConstructor();

        $this->assertTrue($ctor === null || $ctor->getNumberOfParameters() === 0,
            'the controller still depends on a hasher');
    }

    public function test_the_controller_no_longer_narrows_or_echoes_on_ssn(): void
    {
        // Beyond the constructor: the ssn_hash narrower and the ssn_last_four echo
        // are both inside __invoke/resolveIdentity, so a controller that merely
        // stopped taking the hasher by injection could still resolve one from the
        // container. A source scan is exact for both.
        $source = file_get_contents(app_path('Http/Controllers/Api/V1/CredentialSearchController.php'));

        $this->assertStringNotContainsString('ssn_last_four', $source,
            'the response still echoes ssn_last_four');
        $this->assertStringNotContainsString("whereIn('ssn_hash'", $source,
            'resolveIdentity still narrows on ssn_hash');
        $this->assertStringNotContainsString('SsnHasher', $source);

        // Non-vacuous: the ssn must still reach the credential gate.
        $this->assertStringContainsString("\$reqSsn = \$r->filled('ssn')", $source,
            'the ssn stopped gating credential matches — that is job 2 and it must survive');
    }

    public function test_the_identity_profile_resource_emits_no_ssn_field(): void
    {
        // The resource is a flat array literal, so a source scan is exact here and
        // needs neither a database row nor a request. It never emitted ssn_hash;
        // ssn_last_four goes with this plan.
        $source = file_get_contents(app_path('Http/Resources/IdentityProfileResource.php'));

        $this->assertStringNotContainsString("'ssn_last_four'", $source);
        $this->assertStringNotContainsString("'ssn_hash'", $source);

        // Non-vacuous: the resource must still shape the rest of the profile.
        $this->assertStringContainsString("'identity_uuid'", $source);
        $this->assertStringContainsString("'npi'", $source);
    }
}

<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * config('golden_profile.deterministic_keys') existed but was never read —
 * DeterministicResolver hardcoded 0.99/0.95 literals, so tuning the config
 * changed nothing. These tests pin the config as the single source of truth and
 * keep the two lists from drifting apart again.
 */
class DeterministicKeyConfigTest extends TestCase
{
    public function test_every_key_tier_has_a_configured_confidence(): void
    {
        $keys = config('golden_profile.deterministic_keys');

        foreach (['npi', 'dea_number', 'upin', 'dea_multi', 'mmis+state', 'license_number+certification_state', 'name+dob'] as $tier) {
            $this->assertArrayHasKey($tier, $keys, "tier $tier has no configured confidence");
            $this->assertGreaterThan(0.0, $keys[$tier]);
            $this->assertLessThanOrEqual(1.0, $keys[$tier]);
        }
    }

    public function test_ssn_hash_is_not_a_configured_tier(): void
    {
        // The GPP conformance programme removed the ssn_hash tier because the
        // Delivery Checklist §1 forbids the hub storing an SSN. A confidence left
        // in this map would be inert — the resolver has no such tier to score —
        // but it would read as though the capability still existed, which is
        // exactly the kind of drift this file was written to stop.
        $this->assertArrayNotHasKey('ssn_hash', config('golden_profile.deterministic_keys'));
    }

    public function test_name_dob_ranks_below_the_hard_identifier_tiers(): void
    {
        $keys = config('golden_profile.deterministic_keys');

        // A shared common name plus a shared birthday is weaker evidence than a
        // shared NPI, DEA, UPIN or MMIS; if that ordering inverts, the tier order
        // is wrong. ssn_hash headed this list until the GPP conformance programme
        // removed the tier.
        foreach (['npi', 'dea_number', 'upin', 'dea_multi', 'mmis+state'] as $strong) {
            $this->assertLessThan($keys[$strong], $keys['name+dob']);
        }
    }

    public function test_resolver_reads_config_rather_than_literals(): void
    {
        $source = file_get_contents(app_path('GoldenProfile/Resolution/DeterministicResolver.php'));

        // The confidence() helper must be how tiers get their score. Bare 0.99 /
        // 0.95 literals in the match tiers are the regression this guards against.
        $this->assertStringContainsString('golden_profile.deterministic_keys', $source);
        // Six call sites for seven configured tiers: dea_multi and mmis+state
        // share one, via $confKey in the identifier tier. Was 7 before the GPP
        // conformance programme removed ssn_hash.
        $this->assertSame(
            6,
            preg_match_all('/\$this->confidence\(/', $source),
            'the 5 remaining single-key tiers plus the multi-valued identifier tier '
            .'(dea_multi/mmis+state, which shares one call site via $confKey) should be 6',
        );
    }

    public function test_every_deterministic_tier_orders_before_taking_a_value(): void
    {
        // Strip comments first — prose that mentions ->value() is not a call site.
        $source = implode("\n", array_filter(
            array_map('trim', file(app_path('GoldenProfile/Resolution/DeterministicResolver.php'))),
            fn ($line) => ! str_starts_with($line, '//') && ! str_starts_with($line, '*') && ! str_starts_with($line, '/*'),
        ));

        // ->value() with no ORDER BY takes whatever storage order returns, so the
        // same source row could bind to a different identity across runs. Every
        // ->value() in the match path must be preceded by an identity_id ordering.
        preg_match_all('/->value\(/', $source, $values);
        preg_match_all('/->orderBy\(\'l?\.?identity_id\'\)->value\(/', $source, $ordered);

        $this->assertNotEmpty($values[0], 'no ->value() call sites found — did the file move?');

        $this->assertSame(
            count($values[0]),
            count($ordered[0]),
            'every ->value() in the deterministic match path needs a deterministic ORDER BY',
        );
    }
}

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

        foreach (['ssn_hash', 'npi', 'dea_number', 'upin', 'license_number+certification_state', 'name+dob'] as $tier) {
            $this->assertArrayHasKey($tier, $keys, "tier $tier has no configured confidence");
            $this->assertGreaterThan(0.0, $keys[$tier]);
            $this->assertLessThanOrEqual(1.0, $keys[$tier]);
        }
    }

    public function test_name_dob_ranks_below_the_hard_identifier_tiers(): void
    {
        $keys = config('golden_profile.deterministic_keys');

        // A shared common name plus a shared birthday is weaker evidence than a
        // shared SSN or NPI; if that ordering inverts, the tier order is wrong.
        foreach (['ssn_hash', 'npi', 'dea_number', 'upin'] as $strong) {
            $this->assertLessThan($keys[$strong], $keys['name+dob']);
        }
    }

    public function test_resolver_reads_config_rather_than_literals(): void
    {
        $source = file_get_contents(app_path('GoldenProfile/Resolution/DeterministicResolver.php'));

        // The confidence() helper must be how tiers get their score. Bare 0.99 /
        // 0.95 literals in the match tiers are the regression this guards against.
        $this->assertStringContainsString('golden_profile.deterministic_keys', $source);
        $this->assertSame(
            6,
            preg_match_all('/\$this->confidence\(/', $source),
            'each of the 6 deterministic tiers should take its confidence from config',
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

    public function test_ssn_placeholder_guard_is_configured(): void
    {
        $placeholders = config('golden_profile.ssn.placeholder_plaintexts');

        $this->assertIsArray($placeholders);
        $this->assertNotEmpty($placeholders, 'an empty filler list disables the exact half of the guard');
        $this->assertContains('000000000', $placeholders);
        $this->assertGreaterThanOrEqual(1, (int) config('golden_profile.ssn.max_identities_per_hash'));
    }
}

<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\SsnHashGuard;
use App\GoldenProfile\Support\SsnHasher;
use Tests\TestCase;

/**
 * The highest-severity resolution defect: ssn_hash is a 0.99-confidence exact key
 * with no name or DOB cross-check, so every person carrying a filler SSN
 * (000-00-0000, 123-45-6789, …) hashed identically and collapsed into ONE
 * identity. These tests cover the guard's key-independent behaviour; the
 * cardinality half needs the hub and is exercised by the integration checks.
 */
class SsnHashGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('golden_profile.ssn.plaintext_key', 'test-key');
    }

    public function test_configured_placeholder_ssns_are_blocked(): void
    {
        $guard = new SsnHashGuard(new SsnHasher);

        // Blocked purely from the placeholder list — no DB round trip, because
        // decide() checks placeholders before it ever counts cardinality.
        foreach (['000000000', '123456789', '999999999'] as $filler) {
            $hash = hash('sha512', $filler.'test-key');
            $this->assertTrue($guard->isBlocked($hash), "filler $filler should be blocked");
        }
    }

    public function test_dashed_placeholder_form_is_blocked_too(): void
    {
        $guard = new SsnHashGuard(new SsnHasher);

        $this->assertTrue($guard->isBlocked(hash('sha512', '123-45-6789test-key')));
    }

    public function test_null_and_empty_hashes_are_not_blocked(): void
    {
        $guard = new SsnHashGuard(new SsnHasher);

        // A missing SSN is simply no evidence; it must not be treated as filler
        // (that would be a behaviour change in the tier's fall-through).
        $this->assertFalse($guard->isBlocked(null));
        $this->assertFalse($guard->isBlocked(''));
    }

    public function test_placeholder_hashes_are_empty_without_a_key(): void
    {
        config()->set('golden_profile.ssn.plaintext_key', null);

        // With no key the exact list cannot be computed. That is expected — the
        // cardinality guard is the one that must still work keyless.
        $hasher = $this->createMock(SsnHasher::class);
        $hasher->method('candidateHashes')->willReturn([]);

        $this->assertSame([], (new SsnHashGuard($hasher))->placeholderHashes());
    }

    public function test_cap_is_at_least_one(): void
    {
        config()->set('golden_profile.ssn.max_identities_per_hash', 0);

        // A cap of 0 would block every hash including real ones, silently
        // disabling the whole ssn_hash tier.
        $this->assertGreaterThanOrEqual(1, (new SsnHashGuard(new SsnHasher))->maxIdentitiesPerHash());
    }

    public function test_exclusion_sql_is_constant_size(): void
    {
        $sql = (new SsnHashGuard(new SsnHasher))->exclusionSql('s.`ssn_hash`');

        // Anti-join, not a NOT IN list — the fragment must not grow with the
        // number of blocked hashes or the tier statements become unbounded.
        $this->assertStringContainsString('gp_ssn_hash_blocklist', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }
}

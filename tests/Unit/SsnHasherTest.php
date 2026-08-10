<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\SsnHasher;
use Tests\TestCase;

/**
 * SsnHasher used to read config('golden_profile.ssn.plaintext_key'), a key that
 * was never declared, so the "explicitly configured key" branch was dead in every
 * environment and hash() silently returned null. CredentialSearchController then
 * dropped the SSN filter and answered 200 with a possibly-different person's data.
 * These tests pin down both halves: the config path resolves, and an unavailable
 * key is reportable rather than silent.
 */
class SsnHasherTest extends TestCase
{
    private function withKey(string $key): SsnHasher
    {
        config()->set('golden_profile.ssn.plaintext_key', $key);

        return new SsnHasher;
    }

    public function test_configured_plaintext_key_is_actually_read(): void
    {
        $hasher = $this->withKey('test-key');

        $this->assertTrue($hasher->available());
        $this->assertNull($hasher->unavailableReason());
        $this->assertSame(hash('sha512', '123456789test-key'), $hasher->hash('123456789'));
    }

    public function test_candidate_hashes_cover_dashed_and_bare_formats(): void
    {
        $hasher = $this->withKey('k');

        $bare = hash('sha512', '123456789k');
        $dashed = hash('sha512', '123-45-6789k');

        // The source column is not format-normalised, so a caller sending either
        // shape must still match a row stored in the other.
        $this->assertEqualsCanonicalizing([$bare, $dashed], $hasher->candidateHashes('123456789'));
        $this->assertEqualsCanonicalizing([$dashed, $bare], $hasher->candidateHashes('123-45-6789'));
    }

    public function test_candidate_hashes_are_deduplicated(): void
    {
        $hashes = $this->withKey('k')->candidateHashes('123456789');

        $this->assertSame($hashes, array_values(array_unique($hashes)));
    }

    public function test_non_nine_digit_input_does_not_fabricate_a_dashed_form(): void
    {
        $hashes = $this->withKey('k')->candidateHashes('12345');

        $this->assertSame([hash('sha512', '12345k')], $hashes);
    }

    public function test_unavailable_key_is_reported_not_silently_swallowed(): void
    {
        config()->set('golden_profile.ssn.plaintext_key', null);

        // No configured key and no reachable source registry (phpunit.xml points
        // the source connection at a dead port), so this must resolve to
        // "unavailable" with a stated reason rather than a null nobody notices.
        $hasher = new SsnHasher;

        try {
            $available = $hasher->available();
        } catch (\Throwable $e) {
            // A connection failure is itself an acceptable loud failure.
            $this->assertNotEmpty($e->getMessage());

            return;
        }

        $this->assertFalse($available);
        $this->assertNotNull($hasher->unavailableReason());
        $this->assertSame([], $hasher->candidateHashes('123456789'));
    }
}

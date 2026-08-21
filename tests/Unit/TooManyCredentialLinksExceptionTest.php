<?php

namespace Tests\Unit;

use App\Exceptions\TooManyCredentialLinksException;
use Tests\TestCase;

/**
 * The over-merge refusal must stay a distinct, machine-readable 409 rather than
 * degrading into a generic 500 — a caller needs to tell "this identity is broken"
 * apart from "the service is broken", and must never mistake it for "no credential
 * found" (which is a legitimate 200/404 answer).
 */
class TooManyCredentialLinksExceptionTest extends TestCase
{
    public function test_renders_409_with_machine_readable_error_code(): void
    {
        $response = (new TooManyCredentialLinksException(364570, 10000))->render();

        $this->assertSame(409, $response->getStatusCode());

        $body = $response->getData(true);
        $this->assertSame('too_many_credential_links', $body['error']);
        $this->assertSame(364570, $body['link_count']);
        $this->assertSame(10000, $body['max_links']);
    }

    public function test_does_not_look_like_a_no_match_response(): void
    {
        $body = (new TooManyCredentialLinksException(364570, 10000))->render()->getData(true);

        // A 'match' => null body is the legitimate "person has no qualifying
        // credential" answer. This is a refusal, not that.
        $this->assertArrayNotHasKey('match', $body);
        $this->assertNotSame(200, (new TooManyCredentialLinksException(1, 1))->render()->getStatusCode());
    }

    public function test_message_carries_both_numbers_for_the_log(): void
    {
        $e = new TooManyCredentialLinksException(364570, 10000);

        $this->assertStringContainsString('364570', $e->getMessage());
        $this->assertStringContainsString('10000', $e->getMessage());
        $this->assertSame(364570, $e->linkCount);
        $this->assertSame(10000, $e->maxLinks);
    }

    public function test_message_does_not_assert_a_cause_it_cannot_know(): void
    {
        $body = (new TooManyCredentialLinksException(16000, 10000))->render()->getData(true);

        // A high link count usually means an over-merge, but identity 226159 has
        // 16,000 links with record_count=1 — one real person. Telling an operator
        // to split that identity would be wrong, so the message must hedge.
        $this->assertStringNotContainsString('needs splitting', $body['message']);
        $this->assertStringContainsString('usually', $body['message']);
        $this->assertStringContainsString('review', $body['message']);
    }

    public function test_guard_is_configured_with_a_sane_cap(): void
    {
        $max = (int) config('golden_profile.credential_search.max_links');
        $chunk = (int) config('golden_profile.credential_search.link_chunk_size');

        // Hub-wide average is 5.54 links per identity+registry pair, so the cap
        // must sit far above normal traffic or real lookups start failing.
        $this->assertGreaterThan(1000, $max, 'cap this low would reject legitimate identities');
        $this->assertGreaterThan(0, $chunk);
        // Each chunk becomes a whereIn on the source; MySQL caps a statement at
        // 65,535 placeholders.
        $this->assertLessThan(65535, $chunk);
    }
}

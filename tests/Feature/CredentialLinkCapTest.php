<?php

namespace Tests\Feature;

use App\Exceptions\TooManyCredentialLinksException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Tests\TestCase;

/**
 * The unit test covers the exception's own shape. What was missing is proof that
 * the exception actually reaches the client as a 409 through the real exception
 * handler — a `render()` method only takes effect if the framework is allowed to
 * call it, and a stray `report`/`render` misconfiguration would silently turn this
 * into a 500 with no test noticing.
 *
 * Deliberately does not touch the hub: phpunit.xml points the hub connection at a
 * dead port, so this asserts the handler contract rather than re-testing the query.
 */
class CredentialLinkCapTest extends TestCase
{
    public function test_exception_is_rendered_as_409_by_the_exception_handler(): void
    {
        $handler = app(ExceptionHandler::class);

        $response = $handler->render(
            request()->instance(),
            new TooManyCredentialLinksException(364570, 10000),
        );

        $this->assertSame(409, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertSame('too_many_credential_links', $body['error'] ?? null);
        $this->assertSame(364570, $body['link_count'] ?? null);
    }

    public function test_409_is_distinguishable_from_every_other_outcome_of_this_endpoint(): void
    {
        // credential-search has three distinct outcomes and a caller has to be able
        // to tell them apart: 200 (resolved, match possibly null), 404 (no identity
        // resolved), 409 (link cap). If any two collide, an integration cannot
        // react correctly.
        //
        // A 503 used to be listed here, from when a missing SSN hash key refused
        // the whole request. That became a 200-with-warning and then, with the SSN
        // removal, nothing at all — there is no key to be missing. 503 is kept in
        // the exclusion list anyway: it is the status a proxy or a downed source
        // connection produces, and 409 must not be confused with it either.
        $capStatus = (new TooManyCredentialLinksException(1, 1))->render()->getStatusCode();

        $this->assertNotContains($capStatus, [200, 404, 503]);
        $this->assertSame(409, $capStatus);
    }

    public function test_cap_leaves_headroom_under_the_php_time_limit(): void
    {
        // Measured: the worst identity the cap ADMITS (id 59, 9,358 links) resolves
        // in ~9.6s, against a 30s max_execution_time. If the cap were raised past
        // roughly 30,000 that headroom disappears and admitted requests start
        // dying mid-flight instead of being refused cleanly.
        $max = (int) config('golden_profile.credential_search.max_links');

        $this->assertLessThanOrEqual(30000, $max,
            'above ~30k links an admitted request cannot finish inside max_execution_time');
    }
}

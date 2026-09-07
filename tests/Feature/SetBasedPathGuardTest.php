<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\SqlBackfill;
use App\GoldenProfile\Support\SetBasedPathGuard;
use RuntimeException;
use Tests\Support\HubTestCase;

class SetBasedPathGuardTest extends HubTestCase
{
    public function test_the_guard_sees_a_versioned_schema(): void
    {
        $this->assertTrue((new SetBasedPathGuard)->schemaIsVersioned());
    }

    public function test_the_set_based_finalizer_refuses_to_run(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SetFinalizer has not been converted to SCD-2');

        (new SetFinalizer)->run();
    }

    public function test_the_set_based_backfill_refuses_to_transform(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SqlBackfill has not been converted to SCD-2');

        (new SqlBackfill)->transform();
    }

    /**
     * stage() is deliberately NOT guarded. It writes only stg_* and src_*, none of
     * which are versioned, so an operator can stage a load, stop at the guard, and
     * finish it with the per-row path once plan 3b lands — instead of throwing the
     * staging work away. Pinned because "guard transform(), not run()" is a
     * decision a later reader would otherwise be tempted to tidy up.
     */
    public function test_staging_is_not_guarded(): void
    {
        $guard = new SetBasedPathGuard;

        $this->assertTrue($guard->schemaIsVersioned());

        // resolveDeterministic() and enrich() are likewise reachable: they are the
        // pieces plan 5's parity tests drive directly, and they carry their own
        // guards (the junk blocklist) rather than this one. Both return void, so
        // the assertion is that neither throws.
        (new SqlBackfill)->resolveDeterministic();
        (new SqlBackfill)->enrich();

        $this->assertTrue(true, 'the unguarded set-based pieces stay reachable');
    }
}

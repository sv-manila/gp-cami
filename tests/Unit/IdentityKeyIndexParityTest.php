<?php

namespace Tests\Unit;

use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\SqlBackfill;
use ReflectionClass;
use Tests\TestCase;

/**
 * SqlBackfill and SetFinalizer each hold their own copy of gp_identity's five key
 * index definitions, and each DROPS AND REBUILDS them around a bulk operation. If
 * either copy drifts from the migration, the next bulk run silently recreates the
 * pre-SCD-2 single-column indexes — and then every deterministic tier probe reads
 * the whole version history and filters in the server. Nothing errors. The symptom
 * is the one DeterministicResolver's docblock already measured: type=ref
 * key=idx_status rows=6475711 instead of key=idx_name_dob rows=1, which pinned
 * sync at ~0.03 rows/sec.
 *
 * This is not a hypothetical drift. It happened during plan 3a Task 3: the schema
 * assertion passed when its own file ran alone and failed in the full suite,
 * because a Feature test had run SetFinalizer in between and put the pre-SCD-2
 * definitions back.
 *
 * No database: this compares the two constants to each other and to the definition
 * the migration writes.
 */
class IdentityKeyIndexParityTest extends TestCase
{
    /** Exactly what 2026_09_04_000100_add_scd2_versioning leaves on gp_identity. */
    private const EXPECTED = [
        'idx_ssn' => 'ssn_hash, `current`',
        'idx_npi' => 'npi, `current`',
        'idx_upin' => 'upin, `current`',
        'idx_dea' => 'dea_number, `current`',
        'idx_name_dob' => 'canonical_last, canonical_first, canonical_dob, `current`',
    ];

    public function test_sql_backfill_agrees_with_the_migration(): void
    {
        $this->assertSame(self::EXPECTED, $this->constantOf(SqlBackfill::class));
    }

    public function test_set_finalizer_agrees_with_the_migration(): void
    {
        $this->assertSame(self::EXPECTED, $this->constantOf(SetFinalizer::class));
    }

    public function test_the_two_copies_agree_with_each_other(): void
    {
        $this->assertSame(
            $this->constantOf(SqlBackfill::class),
            $this->constantOf(SetFinalizer::class),
            'the two IDENTITY_KEY_INDEXES copies have drifted'
        );
    }

    private function constantOf(string $class): array
    {
        return (new ReflectionClass($class))->getConstant('IDENTITY_KEY_INDEXES');
    }
}

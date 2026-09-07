<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\JunkKeyGuard;
use App\GoldenProfile\Support\SsnHasher;
use App\GoldenProfile\Support\SsnHashGuard;
use Tests\TestCase;

/**
 * A removal is only done when it cannot quietly come back. These assertions are
 * cheap and they fail loudly the first time someone reintroduces SSN support by
 * copying an old file back in or restoring a config block from git history.
 *
 * Structural, not behavioural, on purpose: there is no behaviour left to test.
 * The Delivery Checklist §1 requirement ("stream internal verified data via CDC,
 * never store SSN") is a statement about what the codebase does NOT contain, so
 * that is what is asserted.
 */
class NoSsnSupportRemainsTest extends TestCase
{
    public function test_the_ssn_support_classes_are_gone(): void
    {
        $this->assertFalse(class_exists(SsnHasher::class));
        $this->assertFalse(class_exists(SsnHashGuard::class));
        $this->assertFileDoesNotExist(app_path('GoldenProfile/Support/SsnHasher.php'));
        $this->assertFileDoesNotExist(app_path('GoldenProfile/Support/SsnHashGuard.php'));

        // Non-vacuous: the sibling guard plan 5 added must still be there, so a
        // deleted Support directory could not satisfy the four assertions above.
        $this->assertTrue(class_exists(JunkKeyGuard::class));
    }

    public function test_the_ssn_config_block_is_gone(): void
    {
        // The block held the plaintext key paths, the placeholder-SSN list and the
        // cardinality cap. All three only meant something to the guard.
        $this->assertNull(config('golden_profile.ssn'));

        // Non-vacuous: the config file must still load. A typo that made
        // config('golden_profile') itself null would pass the assertion above.
        $this->assertIsArray(config('golden_profile.deterministic_keys'));
    }

    /**
     * The classes that read or write stg_person / gp_identity /
     * gp_identity_profile. A leftover column reference in any of them would break
     * at runtime the moment the migration drops the columns — and it would break
     * inside a 13M-row backfill rather than in CI.
     *
     * EvalRunner is deliberately absent and is added by the migration's own
     * commit: it legitimately still stages ssn_hash while the column exists, so
     * listing it here early would just make the build red for a task.
     *
     * @return list<string>
     */
    private function pipelineFiles(): array
    {
        return [
            'GoldenProfile/Resolution/DeterministicResolver.php',
            'GoldenProfile/Resolution/Survivorship.php',
            'GoldenProfile/Materialize/SetFinalizer.php',
            'GoldenProfile/Materialize/ProfileMaterializer.php',
            'GoldenProfile/Connectors/StreamlineLocalConnector.php',
            'GoldenProfile/SqlBackfill.php',
            'GoldenProfile/Engine.php',
            'Http/Controllers/Api/V1/CredentialSearchController.php',
            'Http/Resources/IdentityProfileResource.php',
        ];
    }

    /**
     * The ONE reference to the column that is still legitimate, and the only line
     * the scan below is allowed to ignore.
     *
     * SqlBackfill and SetFinalizer each drop EVERY gp_identity key index around
     * their bulk writes and re-ADD only what their IDENTITY_KEY_INDEXES constant
     * lists. The migration still creates idx_ssn, so a constant that omitted it
     * would have the bulk path silently revert the migration — the defect
     * IdentityKeyIndexParityTest was written for. The index therefore stays until
     * the column goes, in the migration's own commit, and this exemption is
     * deleted in that same commit.
     */
    private const KEY_INDEX_EXEMPTION = "'idx_ssn' => 'ssn_hash, `current`',";

    /** Source with comments and the key-index exemption removed. */
    private function scannableCode(string $file): string
    {
        // Comments are stripped: several of these files explain WHY the columns are
        // absent, and that prose is the point — it must not be what trips the scan.
        $code = implode("\n", array_filter(
            array_map('trim', file(app_path($file))),
            fn ($line) => ! str_starts_with($line, '//')
                && ! str_starts_with($line, '*')
                && ! str_starts_with($line, '/*'),
        ));

        return str_replace(self::KEY_INDEX_EXEMPTION, '', $code);
    }

    public function test_no_class_in_the_pipeline_still_carries_an_ssn_column(): void
    {
        foreach ($this->pipelineFiles() as $file) {
            $this->assertFileExists(app_path($file), "$file moved — this guard stopped guarding it");

            $code = $this->scannableCode($file);

            $this->assertStringNotContainsString('ssn_hash', $code, "$file still references ssn_hash");
            $this->assertStringNotContainsString('ssn_last_four', $code, "$file still references ssn_last_four");
        }
    }

    public function test_the_key_index_exemption_is_live_and_narrow(): void
    {
        // An exemption nobody checks is a hole. Two things have to hold:
        //
        //   1. it still MATCHES both files. Once the migration drops the column
        //      the exemption stops matching, and this test is what says so —
        //      forcing the constant above to be deleted rather than left as
        //      standing permission to name the column.
        //   2. it is exactly one line per file, so it cannot mask a second
        //      reference that happens to sit near it.
        foreach (['GoldenProfile/SqlBackfill.php', 'GoldenProfile/Materialize/SetFinalizer.php'] as $file) {
            $raw = file_get_contents(app_path($file));

            $this->assertSame(
                1, substr_count($raw, self::KEY_INDEX_EXEMPTION),
                "$file should carry the idx_ssn definition exactly once — if the column has been ".
                'dropped, delete KEY_INDEX_EXEMPTION instead of widening it'
            );
        }
    }

    public function test_the_ssn_column_stripper_does_not_ignore_real_code(): void
    {
        // The comment stripper is the one thing in this file that could make the
        // scan vacuous: a bug that discarded every line would pass it for any file
        // at all. So run the stripper WITHOUT the exemption over a file that does
        // legitimately name the column, and assert it is still caught.
        $code = implode("\n", array_filter(
            array_map('trim', file(app_path('GoldenProfile/SqlBackfill.php'))),
            fn ($line) => ! str_starts_with($line, '//')
                && ! str_starts_with($line, '*')
                && ! str_starts_with($line, '/*'),
        ));

        $this->assertStringContainsString('idx_ssn', $code,
            'the comment stripper is discarding real code — every scan above is vacuous');
    }

    public function test_no_gp_ssn_environment_variable_is_documented(): void
    {
        // .env.example is the contract for a new developer's environment. A
        // GP_SSN_* line there would have them chase a key nothing reads, and — in
        // a public repo — invite someone to paste a real one in.
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringNotContainsString('GP_SSN_', $example);
        $this->assertStringContainsString('GP_DB_', $example, 'the file is not .env.example');
    }
}

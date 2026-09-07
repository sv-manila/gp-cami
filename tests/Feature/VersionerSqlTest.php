<?php

namespace Tests\Feature;

use App\GoldenProfile\Support\Versioner;
use App\GoldenProfile\Support\VersionerSql;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * Versioner::same() decides how many versions the PER-ROW path mints.
 * VersionerSql::same() decides how many the SET-BASED path mints. If they disagree
 * on one pair of values, the two paths produce different version counts from
 * identical input and the parity proof in plan 3b Task 8 fails — with a symptom
 * (a count is off by one) a long way from the cause.
 *
 * So they are compared directly, pair by pair, against the same MySQL server the
 * real statements run on. This is a differential test: it asserts nothing about
 * what the answer SHOULD be, only that the two implementations agree.
 */
class VersionerSqlTest extends HubTestCase
{
    /**
     * [stored, incoming, why this pair is interesting].
     *
     * Every one of these is a shape Versioner::same()'s docblock names or a shape
     * the loose branches make reachable.
     */
    private function pairs(): array
    {
        return [
            ['Smith', 'Smith', 'identical strings'],
            ['Smith', 'Smyth', 'different strings'],
            ['SMITH', 'Smith', 'case only - ci collation says equal, PHP === says different'],
            ['Smith', 'Smith ', 'trailing space'],
            [null, null, 'both null - same() is TRUE only here'],
            [null, 'Smith', 'stored null, incoming set'],
            ['Smith', null, 'stored set, incoming null'],
            ['', '', 'both empty'],
            ['', null, 'empty vs null - genuinely different, and what makes the uniques NULL-permissive'],
            ['1234567893', '1234567893', 'npi as two strings'],
            ['0', '0', 'tinyint round trip'],
            ['0', '', 'zero vs empty'],
            ['1970-04-02', '1970-04-02', 'date as date'],
            ['1970-04-02', '1970-04-02 00:00:00', 'DATE vs DATETIME - the prefix rule, forwards'],
            ['1970-04-02 00:00:00', '1970-04-02', 'the prefix rule, backwards'],
            ['1970-04-02', '1970-04-03 00:00:00', 'prefix rule must NOT fire on a different day'],
            ['1970-04-0', '1970-04-0X', 'nine bytes then a divergence - neither side is length 10'],
            ['abcdefghij', 'abcdefghijkl', 'ten bytes that are not a date - the rule is length-based'],
        ];
    }

    public function test_the_sql_and_the_php_agree_on_every_pair(): void
    {
        $sql = 'SELECT '.VersionerSql::same('@a', '@b').' AS r';

        foreach ($this->pairs() as [$stored, $incoming, $why]) {
            $this->hub()->statement('SET @a = ?', [$stored]);
            $this->hub()->statement('SET @b = ?', [$incoming]);

            $this->assertSame(
                Versioner::same($stored, $incoming),
                (bool) $this->hub()->selectOne($sql)->r,
                "VersionerSql::same() disagrees with Versioner::same() on [$why]"
            );
        }
    }

    public function test_the_two_agree_across_real_column_types(): void
    {
        // The user-variable test above compares the expression's LOGIC with both
        // sides typed as strings. This one compares its TYPE RENDERING: a DATE
        // column against a VARCHAR scratch column, and a BIGINT against a VARCHAR,
        // which is exactly the shape SetFinalizer::survivorship() produces when it
        // pivots winners into a VARCHAR(500) scratch table.
        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02', 'npi' => 1234567893,
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->hub()->statement('DROP TEMPORARY TABLE IF EXISTS tmp_same_probe');
        $this->hub()->statement('CREATE TEMPORARY TABLE tmp_same_probe (
            identity_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            canonical_dob VARCHAR(500) NULL,
            npi VARCHAR(500) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->hub()->table('tmp_same_probe')->insert([
            'identity_id' => $identityId, 'canonical_dob' => '1970-04-02', 'npi' => '1234567893',
        ]);

        $row = $this->hub()->selectOne('
            SELECT '.VersionerSql::same('i.`canonical_dob`', 'p.`canonical_dob`').' AS dob,
                   '.VersionerSql::same('i.`npi`', 'p.`npi`').' AS npi
            FROM gp_identity i JOIN tmp_same_probe p ON p.identity_id = i.identity_id');

        $stored = $this->hub()->table('gp_identity')->where('identity_id', $identityId)->first();

        $this->assertSame(Versioner::same($stored->canonical_dob, '1970-04-02'), (bool) $row->dob);
        $this->assertSame(Versioner::same($stored->npi, '1234567893'), (bool) $row->npi);
        $this->assertTrue((bool) $row->dob, 'a DATE against its own string form must be "same"');
        $this->assertTrue((bool) $row->npi, 'a BIGINT against its own digits must be "same"');

        $this->hub()->statement('DROP TEMPORARY TABLE IF EXISTS tmp_same_probe');
    }

    public function test_absent_and_null_are_different_things(): void
    {
        // Versioner::differs() SKIPS a column absent from $incoming and COMPARES a
        // column present with a NULL value. differsOnPresent models absence as a
        // NULL incoming expression; differsOnAll compares NULLs. Confusing the two
        // is silent, so the two renderings are asserted to be different SQL.
        $onAll = VersionerSql::differsOnAll('i', ['canonical_last' => 'NULL']);
        $onPresent = VersionerSql::differsOnPresent('i', ['canonical_last' => 'NULL']);

        $this->hub()->table('gp_identity')->insert([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_last' => 'Smith', 'confidence' => 1.0, 'record_count' => 0,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);

        $row = $this->hub()->selectOne("SELECT ($onAll) a, ($onPresent) p FROM gp_identity i LIMIT 1");

        $this->assertSame(1, (int) $row->a, 'a present NULL against a set value IS a change');
        $this->assertSame(0, (int) $row->p, 'an ABSENT column is never a change');
    }

    public function test_an_empty_attribute_list_is_never_a_change(): void
    {
        // gp_identity_identifier declares NO attributes: its key is the whole fact.
        // So an existing current row must never be superseded by a re-observation,
        // and the predicate has to be a literal FALSE rather than an empty string
        // that would break the enclosing WHERE.
        $this->assertSame('FALSE', VersionerSql::differsOnAll('x', []));
        $this->assertSame('FALSE', VersionerSql::differsOnPresent('x', []));
    }

    public function test_key_joins_treat_two_nulls_as_equal(): void
    {
        // Versioner::current() does ->where($key), and Laravel turns a null value
        // under '=' into IS NULL, so the per-row path matches a NULL key part. A
        // set-based join with '=' would not, and gp_license would gain a duplicate
        // row per run for every licence with a NULL certification_state.
        $sql = VersionerSql::keysEqual('a', 'b', ['x', 'y']);

        $this->assertSame('a.`x` <=> b.`x` AND a.`y` <=> b.`y`', $sql);
    }
}

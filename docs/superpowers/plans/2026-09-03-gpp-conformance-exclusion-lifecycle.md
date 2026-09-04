# Exclusion Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `gp_identity_exclusion` the two lifecycle-facing fields gp-cami can honestly compute
today — `matched_on` and `match_score` — and stop discarding the raw per-registry exclusion record,
without fabricating the typed `excl_date`/`reinstate_date`/`is_active` columns the design docs want,
because the source data needed to populate those correctly does not exist in a form gp-cami can trust.

**Architecture:** Plan 3 gives `gp_identity_exclusion` **versioning**: an SCD-2 audit trail of when
the hub's *belief* about a match changed (a re-screen flips `is_npi_match`, a new version is minted,
the old one survives with `current = 0`). That is not the same thing as a **lifecycle**: the calendar
history of the *real-world fact* — when a person was placed on an exclusion list, and if ever, when
they were reinstated. A version bump only says the hub's knowledge moved; it says nothing about
when the exclusion itself started or ended. This plan investigated whether gp-cami's only source
(`streamline_local`) carries that calendar history and found a split answer: `matches` (the per-
employee hit) carries none, but `exclusion_records.match` — a raw per-registry JSON blob gp-cami
currently mirrors as only two columns (`id`, `exclusion_list_prefix`) and throws the rest away — does
contain genuine date-shaped fields, in a different, undocumented shape for every one of the ~20
registries sampled, with at least one demonstrably corrupted sentinel value and one field
(`date_deleted`) whose meaning contradicts itself between two closely related registries. Normalizing
that into typed dates without a domain expert to verify each registry's format risks the exact
failure this programme calls unacceptable: a fabricated reinstatement turning a still-excluded
provider into a false "not excluded." So this plan does the honest subset — compute `matched_on` and
`match_score` from signals gp-cami already trusts (the five match-quality flags), and mirror the raw
record so a human (or a future, verified normalization pass) can read it — and documents the rest as
deliberately deferred, not stubbed. Reinstatement stays a steward decision recorded via `link_state`
(plan 6's `gp_identity_exclusion` writer layer), not a fact this plan claims to compute.

**Tech Stack:** PHP ^8.3 (8.4.12 local), Laravel 13.20, PHPUnit 12.5, Laravel Pint, MySQL 8.

## Global Constraints

- Branch off the latest `feat/eval-harness` (or `master` once that has merged) as
  `feat/<slug>`. Do not push to `master`.
- `composer install` needs no credentials — every dependency is public packagist. `vendor/` already
  exists in the working checkout.
- **The test harness is MySQL, never SQLite.** The schema reuses index names across tables
  (`idx_identity` is on eight of them); MySQL scopes index names per table, SQLite per database, so
  `migrate` dies at the second `gp_*` table. Extend `Tests\Support\HubTestCase`.
- Tests must never touch the real hub. `phpunit.xml` points `GP_DB_*`/`SRC_DB_*` at `127.0.0.1:1`
  deliberately — do not remove or repoint those lines. `HubTestCase` reads a separate
  `GP_TEST_DB_*` set and skips when unset.
- `HubTestCase::setUp()` runs `migrate:fresh`, which DROPS EVERY TABLE in the target schema. Its
  guard requires a database name starting with `gp_` and containing `test`. Do not weaken it. The
  same MySQL server hosts `streamline_local`, `streamline_test`, `streamline_integration` and
  `admin_dash_sb`.
- Never edit a migration that has already run against the shared hub. New behaviour = new migration,
  prefixed `2026_09_*`.
- Style: `vendor/bin/pint --dirty` before every commit — never bare `vendor/bin/pint`, which
  reformats the whole repo. 4-space indent, plain PHP (no `declare(strict_types=1)`), explanatory
  docblocks that state the reason for a decision, not just the rule.
- Tests use `public function test_snake_case(): void`. There are NO PHPUnit attributes anywhere in
  `tests/`; do not introduce `#[Test]`.
- CI runs `vendor/bin/pint --test` and `vendor/bin/phpunit --fail-on-skipped`. A skipped test fails
  the build by design — the database-backed tests must actually run.
- Commit messages: `type(scope): imperative summary`.
- `sv-manila/gp-cami` is a PUBLIC repository. Never commit a key, token, credential or real
  person's data. The eval fixture is synthetic and must stay synthetic.
- **This plan's own addition:** `gp_identity_exclusion` mirrors real government exclusion-list data
  about real named people once it runs against a real hub. That is normal application data, not
  something committed to git — but every fixture value THIS plan adds to the repo (tests, sample
  JSON in docs) must be synthetic and obviously so, the same rule the eval fixture already follows.

---

## Programme context — this is plan 7 of 8

| # | Plan | Depends on | Status |
|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — | DONE, merged into this branch |
| 2 | SSN removal | 1 | written |
| 3 | SCD-2 versioning | 1 | written, not executed |
| 4 | Individual vs entity | 1, 3 | written |
| 5 | Match keys & data quality | 1 | written |
| 6 | Steward writer layer | 1, 3 | in progress (parallel) |
| **7** | **Exclusion lifecycle** | **1, 3** | **this document** |
| 8 | Incremental profiling | 1, 5 | written |

This plan sits after plan 3 because every line it touches on `gp_identity_exclusion` — the migration,
the `Versioner` spec, the write path in `Engine::rollupExclusions()`, the read path in
`ProfileMaterializer::rebuild()` — only makes sense against the versioned shape plan 3 leaves behind
(a `version_no`/`current` pair, a single-current unique constraint, and `Versioner::write()` as the
one place that flips old and inserts new). It is independent of plan 6, which is being authored in
parallel and owns a different axis of change on the same table: `link_state` transitions
(`candidate` → `confirmed`/`rejected`) driven by a human steward reviewing whether a match is
correct. This plan does not specify those transitions and does not touch `link_state`'s write path —
see the boundary note in Task 4.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_08_000000_add_exclusion_lifecycle_fields.php` | Adds `matched_on`, `match_score`, `source_record` to `gp_identity_exclusion` (create) |
| `app/GoldenProfile/Support/ExclusionMatchClassifier.php` | Pure mapping from the five match-quality flags to `matched_on`/`match_score` (create) |
| `app/GoldenProfile/Support/Versioner.php` | Registers the three new columns as versioned attributes on `gp_identity_exclusion` (modify) |
| `app/GoldenProfile/Engine.php` | `rollupExclusions()` computes and persists the three new fields via `Versioner::write()` (modify) |
| `app/GoldenProfile/Materialize/ProfileMaterializer.php` | `rebuild()` exposes `matched_on`/`match_score` on each exclusion entry of the profile (modify) |
| `docs/EXCLUSION_LIFECYCLE.md` | The investigation, the decisions, what is deferred and why, the human follow-up path (create) |
| `docs/EVALUATION.md` | Records that the eval gate did not move (modify) |
| `tests/Feature/ExclusionLifecycleSchemaTest.php` | Pins the three new columns and their nullability (create) |
| `tests/Unit/ExclusionMatchClassifierTest.php` | Pins the priority order and score formatting (create) |
| `tests/Unit/ExclusionLifecycleVersionerSpecTest.php` | Pins the three columns as versioned attributes (create) |
| `tests/Feature/ExclusionLifecycleVersioningTest.php` | Proves idempotent resync and never-delete at the `Versioner` layer (create) |
| `tests/Feature/ExclusionLifecycleProfileTest.php` | Proves the profile reads only the current version (create) |

---

## Task 1: The migration — three nullable columns, nothing else

**Files:**
- Create: `database/migrations/2026_09_08_000000_add_exclusion_lifecycle_fields.php`
- Test: `tests/Feature/ExclusionLifecycleSchemaTest.php`

**Interfaces:**
- Produces: `gp_identity_exclusion.matched_on` (`VARCHAR(24) NULL`), `.match_score`
  (`DECIMAL(5,4) NULL`), `.source_record` (`MEDIUMTEXT NULL`).
- Consumed by: `Versioner` (Task 3), `Engine::rollupExclusions()` (Task 4),
  `ProfileMaterializer::rebuild()` (Task 5).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ExclusionLifecycleSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * Pins the three columns this plan adds to gp_identity_exclusion, on top of the
 * version_no/current/date_created/date_updated columns plan 3's SCD-2 migration
 * already put there. All three must be nullable: matched_on and match_score are
 * null whenever none of the five match-quality flags is true, and source_record
 * is null for any exclusion_record_id gp-cami cannot look up (or, before this
 * plan runs against real data, simply not backfilled yet).
 */
class ExclusionLifecycleSchemaTest extends HubTestCase
{
    public function test_the_three_lifecycle_columns_exist(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        $this->assertTrue($schema->hasColumn('gp_identity_exclusion', 'matched_on'));
        $this->assertTrue($schema->hasColumn('gp_identity_exclusion', 'match_score'));
        $this->assertTrue($schema->hasColumn('gp_identity_exclusion', 'source_record'));
    }

    public function test_the_columns_are_nullable(): void
    {
        $id = $this->seedIdentity();

        $this->hub()->table('gp_identity_exclusion')->insert([
            'system_id' => $this->systemId, 'match_id' => 1, 'identity_id' => $id,
            'link_state' => 'candidate',
        ]);

        $row = $this->hub()->table('gp_identity_exclusion')->where('match_id', 1)->first();

        $this->assertNull($row->matched_on);
        $this->assertNull($row->match_score);
        $this->assertNull($row->source_record);
    }

    private function seedIdentity(): int
    {
        return (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_last' => 'Smith', 'confidence' => 1.0, 'record_count' => 0,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ExclusionLifecycleSchemaTest.php`

Expected: FAIL — `Unknown column 'matched_on' in 'field list'` (or the schema assertion fails first,
depending on PHPUnit's evaluation order; either way the column does not exist yet).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_08_000000_add_exclusion_lifecycle_fields.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three columns on gp_identity_exclusion, added on top of plan 3's SCD-2
 * migration (version_no, current, date_created, date_updated):
 *
 *   matched_on     which of the five match-quality flags (is_ssn_match,
 *                  is_npi_match, is_canonical_name_match, is_upin_match,
 *                  is_license_number_match) is the strongest signal behind this
 *                  match. App\GoldenProfile\Support\ExclusionMatchClassifier
 *                  computes it.
 *   match_score    how many of the five flags corroborate the match, 0.0-1.0,
 *                  same classifier.
 *   source_record  the raw per-registry JSON streamline_local.exclusion_records
 *                  .match already carries for this hit's exclusion_record_id,
 *                  mirrored here instead of discarded.
 *
 * WHAT THIS MIGRATION DELIBERATELY DOES NOT ADD, AND WHY
 * --------------------------------------------------------
 * "Data Model (What We Store)" also wants excl_type, excl_date, reinstate_date,
 * waiver_date, waiver_state, and a stored is_active flag on gold.exclusion. None
 * of those are added here. Verified live against streamline_local on the shared
 * test VM (192.168.56.22) on 2026-09-04: the raw record DOES carry date-shaped
 * fields, but the field names and their meaning differ in every registry —
 * prefix 'oig' has excldate/wvrstate, 'sam' has
 * Active_Date/Termination_Date/Record_Status, 'wv2' has
 * exclusion_date/reinstatement_date, 'pa1' has BeginDate/EndDate/Status, 'ca1'
 * has date_of_suspension/active_period, and a dozen state-board prefixes
 * (ilelba, ncmbba, vaelba, camb2ba, cambba, flelba, iaphba, txmbba, txbdeba,
 * utllba) have no date field at all, only a free-text `details` blob. Some
 * values are corrupted sentinels (the literal string "AREALNULL") or ambiguous
 * across near-identical prefixes: sam2's date_deleted is the SAME timestamp on
 * every sampled row, which reads like an import-housekeeping stamp rather than
 * a per-record reinstatement date, but nobody in this environment has
 * documentation for what streamline_local's scraper actually populates it
 * with. Guessing wrong here is not cosmetic: a fabricated reinstate_date turns
 * a still-excluded provider into a false "not excluded" on the API, which
 * "Building One Trusted Record" and this programme's own authoring brief both
 * name as the direction that must never happen. See docs/EXCLUSION_LIFECYCLE.md
 * for the full investigation, the exact queries run, and what a human with
 * registry documentation would need to do to finish this safely, registry by
 * registry.
 *
 * source_record is the honest middle ground: it stops throwing the raw record
 * away — so a steward reviewing a match (or a future, verified normalization
 * pass) can read it — without gp-cami claiming to understand it.
 *
 * Guarded with hasColumn() checks and ALGORITHM=INSTANT, the same pattern
 * plan 3's SCD-2 migration uses: these are plain nullable columns appended at
 * the end of the row, which MySQL 8.0.12+ can add as a metadata-only change.
 * gp_identity_exclusion is nowhere near gp_identity's ~13.4M rows, but there is
 * no reason to take a table lock when INSTANT is available for free.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    private const COLUMNS = [
        'matched_on' => 'VARCHAR(24) NULL',
        'match_score' => 'DECIMAL(5,4) NULL',
        'source_record' => 'MEDIUMTEXT NULL',
    ];

    public function up(): void
    {
        $conn = DB::connection($this->connection);
        $schema = Schema::connection($this->connection);

        foreach (self::COLUMNS as $column => $definition) {
            if (! $schema->hasColumn('gp_identity_exclusion', $column)) {
                $conn->statement(
                    "ALTER TABLE `gp_identity_exclusion` ADD COLUMN `$column` $definition, ALGORITHM=INSTANT"
                );
            }
        }
    }

    public function down(): void
    {
        $conn = DB::connection($this->connection);
        $schema = Schema::connection($this->connection);

        foreach (array_keys(self::COLUMNS) as $column) {
            if ($schema->hasColumn('gp_identity_exclusion', $column)) {
                $conn->statement("ALTER TABLE `gp_identity_exclusion` DROP COLUMN `$column`");
            }
        }
    }
};
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ExclusionLifecycleSchemaTest.php`
Expected: PASS, 2 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions — every pre-existing test still green; this migration adds nullable
columns nothing yet writes to.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_09_08_000000_add_exclusion_lifecycle_fields.php \
        tests/Feature/ExclusionLifecycleSchemaTest.php
git commit -m "feat(exclusion): add matched_on, match_score and source_record columns"
```

---

## Task 2: `ExclusionMatchClassifier` — the honest subset of `matched_on`/`match_score`

**Files:**
- Create: `app/GoldenProfile/Support/ExclusionMatchClassifier.php`
- Test: `tests/Unit/ExclusionMatchClassifierTest.php`

**Interfaces:**
- Produces: `ExclusionMatchClassifier::matchedOn(array $flags): ?string`,
  `ExclusionMatchClassifier::matchScore(array $flags): string` (formatted to 4 decimal places).
- Consumed by: `Engine::rollupExclusions()` (Task 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ExclusionMatchClassifierTest.php`:

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\ExclusionMatchClassifier;
use Tests\TestCase;

class ExclusionMatchClassifierTest extends TestCase
{
    private ExclusionMatchClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ExclusionMatchClassifier;
    }

    public function test_npi_outranks_every_other_signal(): void
    {
        $flags = [
            'is_npi_match' => 1, 'is_ssn_match' => 1, 'is_license_number_match' => 1,
            'is_canonical_name_match' => 1, 'is_upin_match' => 1,
        ];

        $this->assertSame('npi', $this->classifier->matchedOn($flags));
    }

    public function test_ssn_outranks_license_and_name(): void
    {
        $flags = [
            'is_npi_match' => 0, 'is_ssn_match' => 1, 'is_license_number_match' => 1,
            'is_canonical_name_match' => 1, 'is_upin_match' => 1,
        ];

        $this->assertSame('ssn', $this->classifier->matchedOn($flags));
    }

    public function test_name_only_match(): void
    {
        $flags = [
            'is_npi_match' => null, 'is_ssn_match' => 0, 'is_license_number_match' => 0,
            'is_canonical_name_match' => 1, 'is_upin_match' => 0,
        ];

        $this->assertSame('name', $this->classifier->matchedOn($flags));
    }

    public function test_no_flags_true_returns_null(): void
    {
        $flags = [
            'is_npi_match' => 0, 'is_ssn_match' => null, 'is_license_number_match' => 0,
            'is_canonical_name_match' => 0, 'is_upin_match' => 0,
        ];

        $this->assertNull($this->classifier->matchedOn($flags));
    }

    public function test_missing_keys_are_treated_as_not_matched(): void
    {
        $this->assertNull($this->classifier->matchedOn([]));
        $this->assertSame('0.0000', $this->classifier->matchScore([]));
    }

    public function test_match_score_counts_corroborating_signals(): void
    {
        $flags = [
            'is_npi_match' => 1, 'is_ssn_match' => 0, 'is_license_number_match' => 1,
            'is_canonical_name_match' => 0, 'is_upin_match' => 0,
        ];

        // 2 of 5 signals -> 0.4000, formatted to exactly 4 decimals so it
        // round-trips through the DECIMAL(5,4) column byte for byte.
        $this->assertSame('0.4000', $this->classifier->matchScore($flags));
    }

    public function test_match_score_is_always_four_decimal_places(): void
    {
        $flags = [
            'is_npi_match' => 1, 'is_ssn_match' => 1, 'is_license_number_match' => 1,
            'is_canonical_name_match' => 1, 'is_upin_match' => 1,
        ];

        $this->assertSame('1.0000', $this->classifier->matchScore($flags));
        $this->assertMatchesRegularExpression('/^\d\.\d{4}$/', $this->classifier->matchScore($flags));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ExclusionMatchClassifierTest.php`

Expected: FAIL — `Class "App\GoldenProfile\Support\ExclusionMatchClassifier" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/GoldenProfile/Support/ExclusionMatchClassifier.php`:

```php
<?php

namespace App\GoldenProfile\Support;

/**
 * Maps CAMI's five boolean exclusion-match flags (is_ssn_match, is_npi_match,
 * is_canonical_name_match, is_upin_match, is_license_number_match — mirrored
 * verbatim by Engine::rollupExclusions() from streamline_local.matches) onto
 * the two match-quality fields "Data Flow by CAMI" wants on every
 * exclusion_matches row: which signal produced the match, and how strong it is.
 *
 * WHY THIS DOES NOT USE THE DOC'S npi|name_dob|name_addr ENUM VERBATIM
 * ---------------------------------------------------------------------
 * That enum assumes CAMI correlates a name match with a DOB or an address. It
 * does not, for exclusions: streamline_local.matches carries no dob-match or
 * address-match flag at all (verified against its live schema, 2026-09-04) —
 * only the five booleans below, plus is_diminutive_name_match/is_aka_name_match
 * /is_npi_mismatch, which Engine::rollupExclusions() has never mirrored and
 * this plan does not start mirroring (a fourth, separate expansion of what
 * gp-cami pulls from `matches`, out of scope here). Forcing
 * is_canonical_name_match into 'name_dob' would assert a DOB corroboration
 * gp-cami never actually checked — exactly the kind of fabricated certainty
 * that turns a plausible-looking match into a false "not excluded" once a
 * steward trusts the label. So this returns the signal gp-cami genuinely has
 * ('npi', 'ssn', 'license_number', 'name', 'upin'), not the doc's
 * closest-sounding category.
 *
 * PRIORITY ORDER, AND WHY
 * -----------------------
 * npi and ssn are the two identifiers CAMI's own matcher treats as
 * near-unique-per-person; license_number is state-scoped but still a specific
 * credential; canonical_name alone is the weakest of the five (no diminutive or
 * aka correlation is mirrored, so it is a literal string match only); upin is a
 * Medicare identifier phased out in 2007 and, on the sampled data, the rarest
 * flag ever set. This order is a judgment call, not a measurement — unlike the
 * resolver's tier weights (config/golden_profile.php), which are pinned against
 * the eval set by ProbabilisticScoringTest, no equivalent ground truth exists
 * for exclusion-match strength. Revisit it if that ever changes.
 */
class ExclusionMatchClassifier
{
    /** Priority order, most to least specific. Flag column => matched_on label. */
    private const PRIORITY = [
        'is_npi_match' => 'npi',
        'is_ssn_match' => 'ssn',
        'is_license_number_match' => 'license_number',
        'is_canonical_name_match' => 'name',
        'is_upin_match' => 'upin',
    ];

    /**
     * The single strongest signal behind this match, or null when none of the
     * five flags is true.
     *
     * @param  array<string,mixed>  $flags  keyed by the five is_*_match columns;
     *                                      a missing key is treated as not-matched
     */
    public function matchedOn(array $flags): ?string
    {
        foreach (self::PRIORITY as $column => $label) {
            if (! empty($flags[$column])) {
                return $label;
            }
        }

        return null;
    }

    /**
     * How many of the five signals corroborate this match, as a fraction
     * (0.0000-1.0000). Deliberately a plain count, not a weighted score: nobody
     * in this environment can defend a specific weight for "npi is worth 0.5 and
     * name is worth 0.15" the way ProbabilisticResolver's identity-tier weights
     * are defended by the eval gate (docs/EVALUATION.md). A count is the most
     * this plan can honestly assert — more matched signals is more
     * corroboration, full stop.
     *
     * Returns a STRING formatted to exactly 4 decimal places, matching the
     * gp_identity_exclusion.match_score column's DECIMAL(5,4) round-trip byte
     * for byte. Versioner::write() compares the incoming value against the
     * stored one as a string (Versioner::same()); a bare float 0.6 stringifies
     * to "0.6" while the column reads back "0.6000", which same() would call a
     * change on every single resync — minting a version every time gp:sync
     * touches an exclusion whose flags never moved. This is the same class of
     * trap Versioner's own docblock names for DATE columns; DECIMAL needs the
     * same discipline, and ExclusionLifecycleVersioningTest pins it.
     */
    public function matchScore(array $flags): string
    {
        $matched = 0;
        foreach (self::PRIORITY as $column => $label) {
            if (! empty($flags[$column])) {
                $matched++;
            }
        }

        return number_format($matched / count(self::PRIORITY), 4, '.', '');
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ExclusionMatchClassifierTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Support/ExclusionMatchClassifier.php tests/Unit/ExclusionMatchClassifierTest.php
git commit -m "feat(exclusion): add ExclusionMatchClassifier for matched_on/match_score"
```

---

## Task 3: Register the three columns in `Versioner`

Plan 3's `Versioner::TABLES['gp_identity_exclusion']` already lists `link_state` and the five match
flags as versioned attributes (its Task 5). This task adds the three new columns to that same list so
a change in any of them mints a version through the one place the SCD-2 write rule lives, exactly
like every other attribute on this table.

**Files:**
- Modify: `app/GoldenProfile/Support/Versioner.php` — the `gp_identity_exclusion` entry of the
  `TABLES` const (see note below on why this is identified by content, not a line range)
- Test: `tests/Unit/ExclusionLifecycleVersionerSpecTest.php`

**Interfaces:**
- Consumes: `Versioner::spec(string $table): array` (plan 3, Task 5).
- Produces: nothing new — extends an existing array.

> **Why no line range.** `Versioner.php` does not exist yet in this checkout; plan 3 is written but
> not executed. The class this task modifies is the one plan 3's Task 5 creates, quoted here
> verbatim. If plan 3 lands with different formatting, find the block by its content
> (`'gp_identity_exclusion' => [` inside `Versioner::TABLES`) rather than by line number.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ExclusionLifecycleVersionerSpecTest.php`:

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\Versioner;
use Tests\TestCase;

/**
 * Plan 3's VersionerSpecTest pins the register as of the SCD-2 migration. This
 * file pins the three columns THIS plan adds to it. Versioner::TABLES is a
 * single source of truth, so both test files check the same array from
 * different angles without editing each other.
 */
class ExclusionLifecycleVersionerSpecTest extends TestCase
{
    public function test_the_lifecycle_columns_are_versioned_attributes(): void
    {
        $attributes = Versioner::spec('gp_identity_exclusion')['attributes'];

        foreach (['matched_on', 'match_score', 'source_record'] as $column) {
            $this->assertContains($column, $attributes, "$column must mint a version when it changes");
        }
    }

    public function test_the_lifecycle_columns_are_not_the_key_derived_or_oncreate(): void
    {
        $spec = Versioner::spec('gp_identity_exclusion');

        foreach (['matched_on', 'match_score', 'source_record'] as $column) {
            $this->assertNotContains($column, $spec['key']);
            $this->assertNotContains($column, $spec['onCreate']);
            $this->assertNotContains($column, $spec['derived']);
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ExclusionLifecycleVersionerSpecTest.php`

Expected: FAIL — `Failed asserting that an array contains 'matched_on'`.

- [ ] **Step 3: Add the three columns to the spec**

In `app/GoldenProfile/Support/Versioner.php`, find the `gp_identity_exclusion` entry of
`Versioner::TABLES` (plan 3, Task 5):

```php
        'gp_identity_exclusion' => [
            'key' => ['system_id', 'match_id'],
            'attributes' => [
                'exclusion_record_id', 'registry', 'is_ssn_match', 'is_npi_match',
                'is_canonical_name_match', 'is_upin_match', 'is_license_number_match',
                'link_state', 'link_confidence',
            ],
            'derived' => [],
            'onCreate' => ['identity_id'],
            'surrogate' => null,
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
```

Replace it with:

```php
        'gp_identity_exclusion' => [
            'key' => ['system_id', 'match_id'],
            'attributes' => [
                'exclusion_record_id', 'registry', 'is_ssn_match', 'is_npi_match',
                'is_canonical_name_match', 'is_upin_match', 'is_license_number_match',
                'link_state', 'link_confidence',
                // Plan 7 (exclusion lifecycle): the classifier's output, plus the
                // raw per-registry record. All three are re-observable golden
                // facts — a re-screen can change which signal matched, and the
                // source registry can amend its own record later (e.g. add a
                // reinstatement note) — so each belongs in `attributes`, not
                // `onCreate`: a later change must mint a version, not be frozen
                // at whatever the first sync happened to see.
                'matched_on', 'match_score', 'source_record',
            ],
            'derived' => [],
            'onCreate' => ['identity_id'],
            'surrogate' => null,
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ExclusionLifecycleVersionerSpecTest.php`
Expected: PASS, 2 tests.

Run: `vendor/bin/phpunit tests/Unit/VersionerSpecTest.php`
Expected: PASS, unchanged — plan 3's own spec test does not enumerate individual attribute names for
`gp_identity_exclusion`, so adding three more does not touch its assertions.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Support/Versioner.php tests/Unit/ExclusionLifecycleVersionerSpecTest.php
git commit -m "feat(exclusion): version matched_on, match_score and source_record"
```

---

## Task 4: `Engine::rollupExclusions()` — compute and persist the three fields

**Boundary with plan 6:** this task changes what `rollupExclusions()` writes into the *attributes*
plan 3 already versions (`matched_on`, `match_score`, `source_record`, alongside the five match
flags). It does not touch `link_state` and does not add a write path for it — `link_state`'s
`candidate` → `confirmed`/`rejected` transition is a human steward decision and belongs to plan 6's
writer layer. `rollupExclusions()` keeps writing `'link_state' => 'candidate'` for a brand-new match
exactly as it does today (an `onCreate`-adjacent value plan 3's Task 8 already versions as an
attribute); this task adds three more attributes alongside it and leaves that value alone.

**Also confirms design question 2 ("never deleted").** Neither today's code nor plan 3's converted
version of `rollupExclusions()` ever deletes a `gp_identity_exclusion` row — unlike
`rollupCredentials()`, which deletes/retires rows whose `match_summary_status_code` lands on the
exclude list, `rollupExclusions()` has no equivalent filter and only ever upserts (today) or
`Versioner::write()`s (after plan 3). So "never deleted" already holds for this table, vacuously: a
match that stops appearing in a resync is not deleted, but it is also not flagged inactive — it is
simply not touched again, which is the "never transitions" landmine the authoring brief names and
plan 6 owns. This plan investigated whether a "no longer reported by the source" signal could safely
drive an automated inactive flag and found no reliable trigger to build it from:
`streamline_local.matches` has no soft-delete or superseded column, and nothing in this environment
can confirm whether CAMI ever removes a match row at all (its `check_created_id` column suggests each
screening event mints its own permanent match rows rather than updating or removing prior ones). Google:
if the source never removes a row, "vanished from source" would never fire and would be dead code;
if it sometimes does, this plan has no reliable way to distinguish "removed because reinstated" from
"removed because it was a data-entry error." A `match_actions` table does exist in `streamline_local`
and did look promising, but its `action_type` values (`confirm`, `resolve`) and mismatch-flag columns
(`is_dob_mismatch`, `is_ssn_mismatch`, ...) are exactly plan 6's `link_state`/steward-resolution
domain — whether this hit correctly identifies the employee — not exclusion-lifecycle data. See
`docs/EXCLUSION_LIFECYCLE.md` for the full investigation.

**Files:**
- Modify: `app/GoldenProfile/Engine.php` — `rollupExclusions()` (plan 3, Task 8, Step 5)
- Test: `tests/Feature/ExclusionLifecycleVersioningTest.php`

**Interfaces:**
- Consumes: `ExclusionMatchClassifier::matchedOn()`/`matchScore()` (Task 2), `Versioner::write()`
  (plan 3, Task 5, extended by Task 3 above).
- Produces: no new public signature — `rollupExclusions(array $employeeIds): void` is unchanged.

> **Why the test does not call `Engine::rollupExclusions()` directly.** `Engine::src()` hardcodes
> `DB::connection('streamline_local')`, and `phpunit.xml` deliberately points that connection at a
> dead socket (`127.0.0.1:1`) so no test can silently read the real source — see
> `PLAN-AUTHORING-BRIEF.md` §3 and `HubTestCase`'s own docblock. No existing test in this repo calls
> `Engine::backfill()` or `Engine::sync()` for exactly this reason; `rollupCredentials()` and
> `rollupExclusions()` have never had feature-test coverage of their own wiring, only of what they
> write. This task does not close that pre-existing gap (a real fix would mean making `src()`
> injectable, a larger refactor this plan's scope does not call for) — it keeps the new LOGIC in the
> fully unit-tested `ExclusionMatchClassifier` (Task 2) and proves the SCHEMA + `Versioner` side
> (idempotent resync, never-delete, the DECIMAL round-trip) with the exact payload
> `rollupExclusions()` builds. See Self-review's known risks.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ExclusionLifecycleVersioningTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Support\ExclusionMatchClassifier;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class ExclusionLifecycleVersioningTest extends HubTestCase
{
    private Versioner $versioner;

    private ExclusionMatchClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versioner = new Versioner;
        $this->classifier = new ExclusionMatchClassifier;
    }

    public function test_resyncing_an_unchanged_match_mints_no_version(): void
    {
        // The exact bug a bare float would cause: DECIMAL(5,4) round-trips as
        // "0.4000" but PHP's 2/5 stringifies to "0.4" — Versioner::same() would
        // call that a change on every single gp:sync pass. matchScore() returns
        // an already-formatted string for this reason; this is what would catch
        // a regression if that formatting were ever dropped.
        $id = $this->seedIdentity();
        $flags = ['is_npi_match' => 1, 'is_ssn_match' => 0, 'is_license_number_match' => 1,
            'is_canonical_name_match' => 0, 'is_upin_match' => 0];

        $first = $this->write($id, 501, $flags, 'raw json v1');
        $second = $this->write($id, 501, $flags, 'raw json v1');

        $this->assertTrue($first['new_version']);
        $this->assertFalse($second['new_version'], 'an unchanged resync must not mint a version');
        $this->assertSame(1, $this->versionCount(501));
    }

    public function test_a_changed_flag_mints_a_new_version_and_keeps_the_old_one(): void
    {
        $id = $this->seedIdentity();
        $before = ['is_npi_match' => 0, 'is_ssn_match' => 0, 'is_license_number_match' => 0,
            'is_canonical_name_match' => 1, 'is_upin_match' => 0];
        $after = ['is_npi_match' => 1, 'is_ssn_match' => 0, 'is_license_number_match' => 0,
            'is_canonical_name_match' => 1, 'is_upin_match' => 0];

        $this->write($id, 502, $before, 'raw json v1');
        $result = $this->write($id, 502, $after, 'raw json v1');

        $this->assertTrue($result['new_version']);
        $this->assertSame(2, $this->versionCount(502));

        $rows = $this->hub()->table('gp_identity_exclusion')->where('match_id', 502)
            ->orderBy('version_no')->get();

        // Never deleted: both the pre- and post-re-screen classification survive.
        $this->assertSame('name', $rows[0]->matched_on);
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('npi', $rows[1]->matched_on);
        $this->assertSame(1, (int) $rows[1]->current);
    }

    public function test_source_record_is_versioned_when_the_registry_updates_it(): void
    {
        $id = $this->seedIdentity();
        $flags = ['is_npi_match' => 1, 'is_ssn_match' => 0, 'is_license_number_match' => 0,
            'is_canonical_name_match' => 0, 'is_upin_match' => 0];

        $this->write($id, 503, $flags, '{"excldate":"2010-01-01"}');
        $result = $this->write($id, 503, $flags, '{"excldate":"2010-01-01","date_deleted":"2026-01-01"}');

        $this->assertTrue(
            $result['new_version'],
            'the raw record changing (e.g. the source adds a date_deleted) is itself worth versioning'
        );
        $this->assertSame(
            '{"excldate":"2010-01-01","date_deleted":"2026-01-01"}',
            $this->versioner->current('gp_identity_exclusion', [
                'system_id' => $this->systemId, 'match_id' => 503,
            ])->source_record
        );
    }

    /** Exactly the payload Engine::rollupExclusions() builds for one `matches` row. */
    private function write(int $identityId, int $matchId, array $flags, ?string $rawRecord): array
    {
        return $this->versioner->write(
            'gp_identity_exclusion',
            ['system_id' => $this->systemId, 'match_id' => $matchId],
            array_merge($flags, [
                'exclusion_record_id' => 9000,
                'registry' => 'oig',
                'link_state' => 'candidate',
                'matched_on' => $this->classifier->matchedOn($flags),
                'match_score' => $this->classifier->matchScore($flags),
                'source_record' => $rawRecord,
            ]),
            [],
            ['identity_id' => $identityId],
        );
    }

    private function seedIdentity(): int
    {
        return (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_last' => 'Smith', 'confidence' => 1.0, 'record_count' => 0,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);
    }

    private function versionCount(int $matchId): int
    {
        return (int) $this->hub()->table('gp_identity_exclusion')->where('match_id', $matchId)->count();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ExclusionLifecycleVersioningTest.php`

Expected: FAIL — `Unknown column 'matched_on'` if Task 1/3 were skipped; if this task is run in order,
it passes immediately because it exercises `Versioner` directly rather than the not-yet-modified
`Engine::rollupExclusions()`. Run it anyway to confirm the fixture and connection setup are correct
before moving on — this is the proof that Task 1 and Task 3 actually took effect.

- [ ] **Step 3: Update `rollupExclusions()`**

In `app/GoldenProfile/Engine.php`, add the import:

```php
use App\GoldenProfile\Support\ExclusionMatchClassifier;
```

Replace `rollupExclusions()` (plan 3, Task 8, Step 5 — the version that calls `Versioner::write()`)
with:

```php
    /** matches (exclusion hits) -> gp_identity_exclusion (candidate links). */
    private function rollupExclusions(array $employeeIds): void
    {
        if (! $employeeIds) {
            return;
        }
        $rows = $this->src()->table('matches')->whereIn('employee_id', $employeeIds)->get();

        // Batch the exclusion_records lookup: one source query for the whole
        // chunk instead of one per match (source is a high-latency WAN link).
        // `match` (the raw per-registry record) rides along for source_record —
        // see docs/EXCLUSION_LIFECYCLE.md for why it is mirrored raw rather than
        // parsed into typed dates.
        $recordIds = $rows->pluck('exclusion_record_id')->filter()->unique()->all();
        $registryRows = $recordIds
            ? $this->src()->table('exclusion_records')
                ->whereIn('id', $recordIds)
                ->get(['id', 'exclusion_list_prefix', 'match'])
            : collect();
        $registryMap = $registryRows->pluck('exclusion_list_prefix', 'id')->all();
        $rawRecordMap = $registryRows->pluck('match', 'id')->all();

        $identityMap = $this->identityMapFor($employeeIds);
        $classifier = new ExclusionMatchClassifier;

        foreach ($rows as $m) {
            $identityId = $identityMap[(int) $m->employee_id] ?? null;
            if (! $identityId) {
                continue;
            }
            $registry = $m->exclusion_record_id
                ? ($registryMap[$m->exclusion_record_id] ?? null)
                : null;
            $flags = [
                'is_ssn_match' => $m->is_ssn_match,
                'is_npi_match' => $m->is_npi_match,
                'is_canonical_name_match' => $m->is_canonical_name_match,
                'is_upin_match' => $m->is_upin_match,
                'is_license_number_match' => $m->is_license_number_match,
            ];

            $this->versioner->write(
                'gp_identity_exclusion',
                ['system_id' => $this->systemId, 'match_id' => (int) $m->id],
                array_merge($flags, [
                    'exclusion_record_id' => $m->exclusion_record_id,
                    'registry' => $registry,
                    'link_state' => 'candidate',
                    'matched_on' => $classifier->matchedOn($flags),
                    'match_score' => $classifier->matchScore($flags),
                    'source_record' => $m->exclusion_record_id
                        ? ($rawRecordMap[$m->exclusion_record_id] ?? null)
                        : null,
                ]),
                [],
                ['identity_id' => $identityId],
            );
        }
    }
```

This drops the `$upserts`-then-`foreach` two-pass shape plan 3 left behind in favour of one pass that
writes as it goes — the classifier needs each row's own flags, not the batch, so nothing was gained by
building an intermediate array first. `$this->versioner` is already a constructor property as of plan
3's Task 8; nothing about the constructor changes here.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ExclusionLifecycleVersioningTest.php`
Expected: PASS, 3 tests (unchanged from Step 2 — this step is the regression check that the rewritten
method still compiles and nothing else broke).

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Engine.php tests/Feature/ExclusionLifecycleVersioningTest.php
git commit -m "feat(exclusion): compute matched_on/match_score/source_record in rollupExclusions"
```

---

## Task 5: `ProfileMaterializer` — expose `matched_on`/`match_score` on the profile

**Sizing decision — `source_record` is deliberately left out of the profile.** `gp_identity_profile`
is a wide, denormalized row rebuilt whole on every finalize; `IdentitySearchController`'s own
docblock already measures a 38MB `exclusions` blob on the worst pile-up identity (12,463 linked source
rows). `matched_on` (a short string) and `match_score` (a decimal) add a few bytes per entry.
`source_record` is a raw per-registry JSON blob that can run to several hundred bytes each (the
sampled SAM records are ~600 bytes); duplicating that across every exclusion entry of a pile-up
identity would make an already-documented sizing problem worse for no reader that needs it inline. It
stays queryable directly on `gp_identity_exclusion` (one row per match, no duplication) for a steward
or a future normalization pass — `IdentityProfileResource` never needs to change to pick this up,
since it already forwards whatever `ProfileMaterializer` puts in the `exclusions` array verbatim.

**Files:**
- Modify: `app/GoldenProfile/Materialize/ProfileMaterializer.php` — the `$exclusions` mapping in
  `rebuild()` (plan 3, Task 7 adds the `->where('current', 1)` filter this task builds on)
- Test: `tests/Feature/ExclusionLifecycleProfileTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: no signature change — `rebuild(int $identityId): void` is unchanged; the `exclusions`
  JSON array gains two keys per entry.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ExclusionLifecycleProfileTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\ProfileMaterializer;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class ExclusionLifecycleProfileTest extends HubTestCase
{
    public function test_the_profile_carries_matched_on_and_match_score_for_the_current_version_only(): void
    {
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        // A superseded version of the same match — must not leak into the profile.
        $this->hub()->table('gp_identity_exclusion')->insert([
            'system_id' => $this->systemId, 'match_id' => 700, 'identity_id' => $id,
            'link_state' => 'candidate', 'matched_on' => 'name', 'match_score' => '0.2000',
            'version_no' => 1, 'current' => 0, 'date_created' => now(), 'date_updated' => now(),
        ]);
        $this->hub()->table('gp_identity_exclusion')->insert([
            'system_id' => $this->systemId, 'match_id' => 700, 'identity_id' => $id,
            'link_state' => 'candidate', 'matched_on' => 'npi', 'match_score' => '0.6000',
            'version_no' => 2, 'current' => 1, 'date_created' => now(), 'date_updated' => now(),
        ]);

        (new ProfileMaterializer)->rebuild($id);

        $exclusions = json_decode(
            $this->hub()->table('gp_identity_profile')->where('identity_id', $id)->value('exclusions'),
            true
        );

        $this->assertCount(1, $exclusions, 'only the current version of match_id 700, not both');
        $this->assertSame('npi', $exclusions[0]['matched_on']);
        $this->assertSame(0.6, $exclusions[0]['match_score']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ExclusionLifecycleProfileTest.php`

Expected: FAIL — `Undefined array key "matched_on"`.

- [ ] **Step 3: Update the `$exclusions` mapping**

In `app/GoldenProfile/Materialize/ProfileMaterializer.php`, find (plan 3, Task 7):

```php
        $exclusions = $hub->table('gp_identity_exclusion')
            ->where('identity_id', $identityId)->where('current', 1)->get()
            ->map(fn ($e) => [
                'match_id' => (int) $e->match_id, 'registry' => $e->registry,
                'is_ssn_match' => (bool) $e->is_ssn_match, 'is_npi_match' => (bool) $e->is_npi_match,
                'is_canonical_name_match' => (bool) $e->is_canonical_name_match,
                'is_license_number_match' => (bool) $e->is_license_number_match,
                'link_state' => $e->link_state,
            ])->values();
```

Replace it with:

```php
        $exclusions = $hub->table('gp_identity_exclusion')
            ->where('identity_id', $identityId)->where('current', 1)->get()
            ->map(fn ($e) => [
                'match_id' => (int) $e->match_id, 'registry' => $e->registry,
                'is_ssn_match' => (bool) $e->is_ssn_match, 'is_npi_match' => (bool) $e->is_npi_match,
                'is_canonical_name_match' => (bool) $e->is_canonical_name_match,
                'is_license_number_match' => (bool) $e->is_license_number_match,
                'link_state' => $e->link_state,
                // Plan 7: which signal matched and how strongly. source_record
                // is deliberately NOT included here — see this task's sizing
                // decision above; it stays on gp_identity_exclusion only.
                'matched_on' => $e->matched_on,
                'match_score' => $e->match_score !== null ? (float) $e->match_score : null,
            ])->values();
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ExclusionLifecycleProfileTest.php`
Expected: PASS, 1 test.

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Materialize/ProfileMaterializer.php tests/Feature/ExclusionLifecycleProfileTest.php
git commit -m "feat(exclusion): expose matched_on/match_score on the profile's exclusions"
```

---

## Task 6: `docs/EXCLUSION_LIFECYCLE.md` — the register

Plan 3 committed its versioned-table register as `docs/SCD2.md` "so the next plan author does not
have to re-derive it." This task does the same for the lifecycle investigation: what was checked,
what it found, what was deliberately not built, and the exact path for a human who later gets registry
documentation to finish it.

**Files:**
- Create: `docs/EXCLUSION_LIFECYCLE.md`

**Interfaces:** none — documentation only.

- [ ] **Step 1: Write the register**

Create `docs/EXCLUSION_LIFECYCLE.md`:

```markdown
# Exclusion lifecycle — investigation and decisions

Written by plan 7 of the GPP conformance programme. Read this before touching
`gp_identity_exclusion`'s lifecycle-facing columns, or before deciding whether
`excl_date`/`reinstate_date`/`waiver_date`/`waiver_state`/`is_active` can finally
be built.

## Versioning is not lifecycle

Plan 3 gives `gp_identity_exclusion` SCD-2 versioning: a new row, `current = 1`,
whenever the hub's *belief* about a match changes. That records when gp-cami's
knowledge moved. It says nothing about the calendar history of the underlying
exclusion itself — when the person was excluded, and whether they were ever
reinstated. Conflating the two means an implementer builds a version history and
believes they have a lifecycle. This plan builds the honest subset of the
second thing and defers the rest.

## What was checked, and what it found

Investigated live against `streamline_local` on the shared test VM
(`192.168.56.22`, read-only), 2026-09-04.

**`exclusion_records`** (84 rows in this dataset): `id`, `exclusion_list_prefix`,
`match` (mediumtext — a raw JSON blob), `hash`, `date_created`, `date_modified`.
`match`'s shape is different for every prefix. Sampled:

| Prefix | Shape (abridged) |
|---|---|
| `oig` (LEIE) | `excldate`, `wvrstate`, `date_deleted` (sampled value: the literal string `"AREALNULL"`) |
| `sam` | `Active_Date`, `Termination_Date`, `Record_Status` |
| `sam2` | Same as `sam` plus `date_deleted` — every sampled row carries the SAME `date_deleted` timestamp, which reads like an import-housekeeping stamp, not a per-record fact |
| `wv2` | `exclusion_date`, `reinstatement_date`, `reinstatement_reason` (all three genuinely present, empty string when not applicable) |
| `pa1` | `BeginDate`, `EndDate`, `Status` (`"Terminated"` observed; `EndDate` null in every sample) |
| `ca1` | `date_of_suspension`, `active_period` (string values like `"indefinitely effective"`, not a date) |
| `ilelba`, `ncmbba`, `vaelba`, `camb2ba`, `cambba`, `flelba`, `iaphba`, `txmbba`, `txbdeba`, `utllba` | No date field at all — `registry_name`, `license_number`, `details` (free text) |

**`matches`** (387 rows): `is_ssn_match`, `is_npi_match`, `is_canonical_name_match`,
`is_diminutive_name_match`, `is_aka_name_match`, `is_npi_mismatch`,
`is_upin_match`, `is_license_number_match`, plus `metadata` (json, per-attribute
match detail). No date-of-exclusion or reinstatement field. No soft-delete or
"superseded" column — nothing distinguishes a still-valid hit from one a
re-screen would no longer produce, and nothing in this environment confirms
whether CAMI ever removes a `matches` row at all.

**`match_actions`** (29 rows): looked like it might be lifecycle data —
`action_type`, `status`, `resolved_via`, `resolution_source_data`, plus mismatch
flags (`is_dob_mismatch`, `is_ssn_mismatch`, `is_first_name_mismatch`, ...). It
is not: the only `action_type` values present are `confirm` and `resolve`, and
the mismatch flags are about whether the match correctly identifies the
employee. This is **plan 6's** domain — the steward decision that drives
`gp_identity_exclusion.link_state` — not exclusion lifecycle. Do not repurpose it
here.

## Decision: mirror the honest subset, defer the rest

Built (this plan):

- `matched_on` / `match_score` — computed from the five flags gp-cami already
  mirrors and trusts. See `App\GoldenProfile\Support\ExclusionMatchClassifier`.
- `source_record` — the raw `exclusion_records.match` JSON, mirrored verbatim
  onto `gp_identity_exclusion.source_record` instead of discarded. Not parsed.
  Not typed. A steward reviewing a match, or a future normalization pass, can
  read it; gp-cami does not claim to understand it.

Not built, and not stubbed with a NULL column nothing will ever populate:

- `excl_type`, `excl_date`, `reinstate_date`, `waiver_date`, `waiver_state` — the
  per-registry field names and semantics above are not consistent or
  documented enough to map safely. A wrong mapping is not cosmetic: it can turn
  a still-excluded provider into a false "not excluded," which "Building One
  Trusted Record" and this programme's authoring brief both name as the
  direction that must never happen.
- A stored `is_active` flag driven by dates. Same reason — there is no
  trustworthy date to derive it from. `has_active_exclusion` on
  `gp_identity_profile` is unchanged by this plan; it continues to mean "has an
  exclusion match no steward has rejected" (`link_state <> 'rejected'`), which
  is the only reinstatement signal gp-cami has — a human, not a computed date.
  It is currently inert only because `link_state` never transitions yet
  (plan 6's job); once plan 6 wires transitions, this same formula starts
  reflecting steward judgment with no code change needed here.
- An automated "vanished from source" inactive flag. Investigated and rejected:
  `matches` has no soft-delete column and no evidence a row is ever removed: a
  reinstatement mechanism built on an unconfirmed trigger is worse than none,
  because a silent no-op invites the assumption that reinstatement handling
  exists when it does not.

## Survivorship: `field_authority.exclusion` is structurally, not just
## practically, unwired

`config/golden_profile.php`'s `survivorship.field_authority.exclusion` lists
`['leie', 'sam', 'state_exclusion', 'streamline_local']` — four competing
*source systems* to rank when a field needs one winner, the same shape as
`field_authority.identity`/`license`/`address`. But `Survivorship::recompute()`
ranks by `gp_source_system.reliability_rank`, and gp-cami has exactly ONE
source system row (`streamline_local`; see `Engine::SYSTEM_CODE`). LEIE, SAM
and state exclusion lists are not separate source systems in this
architecture — they are values of `exclusion_records.exclusion_list_prefix`
inside that one system's mirrored data. Wiring `field_authority.exclusion` into
`Survivorship::recompute()` the way the other three fields work is not merely
undone; the four-way distinction it names does not correspond to anything
`Survivorship` can rank today. Making it real would mean either registering
each registry as a pseudo source system, or writing an exclusion-specific
ranking function keyed on `exclusion_list_prefix` instead of `system_id` — a
design decision, not a bug fix, and out of scope for this plan.

In any case, the doc's own tiebreak rule for exclusion status — "use the
earliest exclusion date; keep all of them on record" — needs a trustworthy
`excl_date` to rank by, which the section above establishes does not exist yet.
"Keep all of them on record" already holds: gp-cami never collapses exclusion
matches to one row per identity. The remaining half of that rule (which one is
*primary*) is blocked on the same date-normalization gap, not on
`Survivorship` wiring.

## For whoever gets registry documentation next

1. Get the field definitions for each `exclusion_list_prefix` from whoever owns
   the CAMI scraper that populates `exclusion_records.match` (its per-registry
   ingestion code, not gp-cami, decides these shapes).
2. Re-run the sampling query below against a larger sample (84 rows is not
   enough to be confident no registry's format has a second, undocumented
   shape) and confirm `date_deleted`'s meaning does not vary within a prefix as
   well as across prefixes.
3. Build a per-registry mapping (config-driven, like
   `survivorship.field_authority`, not a giant `if` chain) from raw field name
   to `excl_date`/`reinstate_date`/`waiver_date`, and leave any
   unmapped/unrecognized prefix's typed columns NULL rather than guessing.
4. Add those typed columns to `Versioner::TABLES['gp_identity_exclusion']`
   exactly as this plan added `matched_on`/`match_score`/`source_record` — same
   pattern, same test shapes.
5. Only then does `is_active` become derivable from real dates, and only then
   does the `field_authority.exclusion` tiebreak ("earliest exclusion date")
   become buildable.

Sampling query used for this investigation (read-only; run against a
`streamline_local` replica, never the production hub):

\```sql
SELECT id, exclusion_list_prefix, `match`
FROM exclusion_records
WHERE exclusion_list_prefix = ?
ORDER BY id
LIMIT 5;
\```

## Note for plan 3b (`SqlBackfill` set-based conversion)

`SqlBackfill::rollup()`'s exclusion `INSERT ... SELECT` is guarded off by
`SetBasedPathGuard` (plan 3, Task 10) pending plan 3b and is untouched by this
plan — its column list still lacks `matched_on`/`match_score`/`source_record`.
Whoever converts it needs to reproduce `ExclusionMatchClassifier`'s logic as SQL
`CASE`/`COUNT` expressions (the same dual-path discipline plan 3 applied to
`SsnHashGuard::exclusionSql()`) and, if `src_exclusion_record`'s three-column
mirror (`id`, `exclusion_list_prefix`) is still in use by then, extend it with
a `match_data` column so the set-based join has the raw record to copy from —
this plan did not extend that mirror because nothing reads it today.
```

- [ ] **Step 2: Commit**

```bash
git add docs/EXCLUSION_LIFECYCLE.md
git commit -m "docs(exclusion): record the lifecycle investigation and what is deferred"
```

---

## Task 7: Verify the eval gate did not move, then close out

This plan changes what is stored on `gp_identity_exclusion` and what the profile exposes. It does not
touch `DeterministicResolver`, `ProbabilisticResolver`'s weights, or any blocking/tiering logic.
`ProbabilisticResolver::sharesExclusionRegistry()` reads `registry` (unchanged by this plan) and, as
of plan 3's Task 9, filters `current = 1` — nothing here alters what that query returns. So the eval
gate is expected to be **completely unaffected**: no re-baseline, no new fixture records, no ratchet
change. This task proves that rather than assuming it.

**Files:**
- Modify: `docs/EVALUATION.md`

**Interfaces:** none.

- [ ] **Step 1: Run the full suite**

Run: `vendor/bin/phpunit`

Expected: PASS, 0 skipped. Test count is whatever the suite reported immediately before Task 1 of
this plan, plus 15 (2 + 7 + 2 + 3 + 1 across the five new test files this plan adds). If plan 3 landed
exactly as written, that baseline is 137, so PASS, 152 tests, 0 skipped — but the authoritative number
is whatever `vendor/bin/phpunit` actually reported on this branch before this plan started, not this
figure.

- [ ] **Step 2: Run the eval gate and confirm it did not move**

Run: `php artisan gp:eval`

Expected: precision 1.0000, recall 1.0000, f1 1.0000, false_merges 0, false_splits 0, true_pairs 9,
records 17, clusters 10 — identical to the "Achieved" table in `docs/EVALUATION.md`. If any figure
differs, stop: something in this plan touched a matching read path it should not have (the most
likely culprit would be an accidental change to `sharesExclusionRegistry()` or to `registry`'s
meaning) — find it and fix it before continuing. Do not edit `EvalGateTest`'s assertions.

- [ ] **Step 3: Record the non-movement**

Append to `docs/EVALUATION.md`, after the SCD-2 section plan 3's Task 10 adds (or after "Achieved" if
plan 3's section is not present):

```markdown
## Exclusion lifecycle (plan 7) — the gate did not move

| Metric | Before | After |
|---|---|---|
| Precision | 1.0000 | 1.0000 |
| Recall | 1.0000 | 1.0000 |
| F1 | 1.0000 | 1.0000 |
| False merges | 0 | 0 |
| False splits | 0 | 0 |
| True pairs | 9 | 9 |

Plan 7 adds `matched_on`, `match_score` and `source_record` to `gp_identity_exclusion` and changes
what `gp_identity_profile.exclusions` exposes per entry. It does not touch any resolver, tier, weight
or blocking rule, and `ProbabilisticResolver::sharesExclusionRegistry()`'s query (`registry IS NOT
NULL`, `current = 1` as of plan 3) is unchanged. See `docs/EXCLUSION_LIFECYCLE.md` for what this plan
built and, more importantly, what it deliberately did not.
```

- [ ] **Step 4: Style check**

Run: `vendor/bin/pint --test`
Expected: PASS, no style issues.

- [ ] **Step 5: Commit**

```bash
git add docs/EVALUATION.md
git commit -m "docs(eval): record that the exclusion lifecycle plan did not move the gate"
```

---

## Self-review

**Spec coverage.**

| Requirement | Where |
|---|---|
| Distinguish versioning from lifecycle | Architecture section; restated in Task 4's design-question-2 note and `docs/EXCLUSION_LIFECYCLE.md` |
| Where does lifecycle data come from | `docs/EXCLUSION_LIFECYCLE.md`'s investigation table, built from a live read of `streamline_local` on 2026-09-04; Task 1's migration docblock carries the same finding |
| Never deleted vs. what the code does today | Task 4's boundary note: neither today's code nor plan 3's version deletes exclusion rows; the real gap is "never transitions," which is plan 6's `link_state`, not this plan's to build |
| `matched_on`/`match_score` | Task 2 (`ExclusionMatchClassifier`), honestly narrowed from the doc's `npi\|name_dob\|name_addr` enum with the reason stated in its own docblock |
| Survivorship for exclusion status | `docs/EXCLUSION_LIFECYCLE.md`'s "structurally, not just practically, unwired" section — no code change, because there is nothing in `Survivorship` to wire it to yet |
| Reinstatement semantics | `docs/EXCLUSION_LIFECYCLE.md`: `is_active`/`has_active_exclusion` stay steward-driven (`link_state`, plan 6), not date-driven; no false "not excluded" is introduced because nothing here claims to compute activity from dates |
| The eval gate | Task 7: no matching change, verified rather than assumed, recorded in `docs/EVALUATION.md` |
| Public repo / synthetic data | Every test fixture uses the existing "Robert/Bob Smith" convention; `docs/EXCLUSION_LIFECYCLE.md`'s sampled JSON is quoted from real (but non-sensitive-looking, already-public-registry-style) test-VM data for illustration of *shape*, not identity — no name, DOB, or address value from the live sample is reproduced verbatim in the doc, only field-name lists and one representative status string |

**Placeholders.** None. Every task changes real, runnable code or ships a real migration; the
deliberately-deferred columns are documented as deferred, not stubbed with dead code.

**Type consistency.** `ExclusionMatchClassifier::matchedOn(array): ?string` and
`matchScore(array): string` are the only new signatures, and both are used identically in Task 4
(`Engine::rollupExclusions()`) and in Task 4's test's `write()` helper. `Versioner::write()`'s
signature is unchanged — this plan only adds entries to one table's `attributes` array, which was
always plan 3's designed extension point. `ProfileMaterializer::rebuild(int): void` and
`Engine::rollupExclusions(array): void` keep their existing signatures.

**Verified against the repo.** `gp_identity_exclusion`'s current columns (`2026_07_20_140000`, lines
113-129) were read directly. `Engine::rollupExclusions()` and `Engine::rollupCredentials()`'s current
(pre-plan-3) bodies were read directly (lines 599-701) to confirm neither deletes exclusion rows.
`ProfileMaterializer::rebuild()`'s `$exclusions` mapping and `$hasActiveExclusion` formula
(`link_state !== 'rejected'`) were read directly, and `SetFinalizer::materializeRange()`'s `$excl`
subquery was confirmed to compute the identical formula (`MAX(link_state <> 'rejected')`), which is
why this plan does not need to touch `Survivorship`'s "the two must agree" concern for that formula.
`ProbabilisticResolver::sharesExclusionRegistry()` was read directly (it does not filter `current`
pre-plan-3; plan 3's Task 9 adds that filter, unrelated to this plan). `config/golden_profile.php`'s
`survivorship.field_authority.exclusion` and `Survivorship::recompute()`'s `IDENTITY_FIELDS`-only
scope were both read directly. `streamline_local.exclusion_records`, `.matches`, and `.match_actions`
were queried live and read-only against `192.168.56.22` on 2026-09-04 (columns via `SHOW COLUMNS`,
shapes via sampled rows); the exact prefixes and field lists in `docs/EXCLUSION_LIFECYCLE.md` are
quoted from that session.

**Known risks carried into execution.**

1. **`Engine::rollupExclusions()`'s wiring is not feature-tested end to end, and this plan does not
   fix that.** `Engine::src()` hardcodes `DB::connection('streamline_local')`, which `phpunit.xml`
   deliberately breaks for every test in this suite. This is a pre-existing condition —
   `rollupCredentials()` has the same gap and no test in the repository calls `Engine::backfill()` or
   `Engine::sync()` — not something this plan introduces, but it does mean the actual `foreach`/
   `array_merge` wiring in Task 4's replacement method is proven only by code review and by the fact
   that it calls fully-tested primitives (`ExclusionMatchClassifier`, `Versioner::write()`) with the
   same shape the test's `write()` helper uses. A real fix (making `src()` injectable) is a larger
   refactor this plan's scope does not call for; flagging it here rather than claiming coverage that
   does not exist.
2. **The `matched_on` priority order is a judgment call, not a measurement**, unlike every other
   ranked weight in this codebase (the resolver's tier weights are pinned by the eval gate). If a
   future plan gets access to real-world exclusion-matching outcomes, revisit
   `ExclusionMatchClassifier::PRIORITY` against them rather than trusting this plan's ordering
   indefinitely.
3. **`docs/EXCLUSION_LIFECYCLE.md`'s registry survey is 84 `exclusion_records` rows on one test VM.**
   It is enough to prove the format is heterogeneous and ambiguous — which is all this plan needed to
   decide not to build typed dates — but it is not enough to be a complete catalogue of every
   registry format CAMI has ever ingested. A larger, production-representative sample is listed as
   step 2 of the human follow-up path for exactly this reason.
4. **If plan 6 lands first** and changes `rollupExclusions()`'s shape (for example, moving `link_state`
   assignment logic around, or renaming the method), Task 4's "find the block by content" instruction
   in Task 3 applies here too: locate the `Versioner::write()` call for `gp_identity_exclusion` by its
   natural key (`system_id`, `match_id`) rather than assuming the surrounding code is byte-identical
   to what this plan quotes.

**Recommended split:** not needed. Seven tasks, each independently testable, none over the 2-5 minute
step budget. The scope legitimately shrank once the source investigation (Task-adjacent, done before
writing this plan) showed the typed lifecycle columns cannot be built honestly yet — the alternative
would have been padding this plan with unverifiable per-registry parsing code, which the authoring
brief's own precedent (plan 5's NPI validator, deferring "follow the trail if an NPI was replaced")
argues against.

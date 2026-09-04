# Match Keys & Data Quality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Validate NPI with a real check-digit, strip junk/placeholder values out of the matching
pipeline before they can bind two strangers together, quarantine rows that carry no usable
identity signal at all instead of silently minting noise identities, and promote the DEA/MMIS
identifiers gp-cami already stages into real match keys — all measured against the eval gate so
recall only ever goes up and precision never cracks.

**Architecture:** Three independent hardening layers, built bottom-up so each is provably correct
before the next depends on it. (1) `NpiValidator` implements the NPPES Luhn-with-`80840` check
digit as a pure, DB-free class; `StreamlineLocalConnector` becomes the single choke point that
enforces it, which means both the per-row engine and the set-based bulk backfill inherit the fix
for free — they already share this one connector method. (2) `JunkKeyGuard` generalizes
`SsnHashGuard`'s cardinality trick (a value shared by implausibly many distinct people is filler,
key or no key) into a config-driven, column-parameterized guard, wired into every site that
currently trusts an NPI blindly — `DeterministicResolver`, `SqlBackfill`, and `Engine::dedup()`.
It is written from scratch, not by extending `SsnHashGuard`, because plan 2 deletes that class
outright. (3) A new `gp_quarantine` table plus `QuarantineRecorder` gives rows with zero
identifying signal, after junk-cleaning, somewhere to go other than silent staging — in both the
per-row and bulk ingestion paths. On top of that, DEA and (state, MMIS) get promoted from
after-the-fact `dedup()` consolidation to genuine match keys, following the license tier's
existing precedent for how a per-row resolver and a set-based backfill can legitimately reach the
same end state by different mechanisms.

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

---

## Programme context — this is plan 5 of 8

| # | Plan | Depends on | Status |
|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — | **DONE, merged into this branch** |
| 2 | SSN removal | 1 | to write |
| 3 | SCD-2 versioning | 1 | to write |
| 4 | Individual vs entity | 1, 3 | to write |
| **5** | **Match keys & data quality** | **1** | **this document** |
| 6 | Steward writer layer | 1, 3 | to write |
| 7 | Exclusion lifecycle | 1, 3 | to write |
| 8 | Incremental profiling | 1, 5 | to write |

This plan depends only on plan 1 (the eval harness), not on plans 2/3/4 — it does not touch SSN
storage, SCD-2 versioning, or the individual/entity split, so it can be built and merged in any
order relative to those. Plan 8 (incremental profiling) depends on this one because the identifier
tier this plan adds changes what a "changed row" needs to re-resolve. Two things this plan builds
on purpose do NOT touch `SsnHashGuard`: plan 2 deletes that class entirely, and `JunkKeyGuard`
(Task 4) is written as the general mechanism that class's cardinality idea should have been from
the start, so the two plans never conflict regardless of merge order.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/GoldenProfile/Support/NpiValidator.php` *(create)* | Luhn-with-`80840` NPI check-digit validation, pure/no DB |
| `tests/Unit/NpiValidatorTest.php` *(create)* | Algorithm correctness against known-shape vectors |
| `tests/eval/identity-pairs.json` *(modify)* | Two NPI values corrected to be check-digit valid; new MMIS/DEA records |
| `tests/Unit/EvalFixtureNpiValidityTest.php` *(create)* | Pins every fixture NPI as Luhn-valid, forever |
| `app/GoldenProfile/Connectors/StreamlineLocalConnector.php` *(modify)* | NPI validation + name-junk screening at ingestion; identifier `state`; per-row identifier staging |
| `app/Console/Commands/GpNpiAudit.php` *(create)* | Read-only: quantifies the real hub's Luhn-invalid NPIs, for a human to run |
| `tests/Unit/StreamlineLocalConnectorTest.php` *(create)* | Pure tests of `personRow()`/`childRows()`/`additionalRows()` — no DB |
| `app/GoldenProfile/Support/JunkKeyGuard.php` *(create)* | General placeholder + cardinality guard, parameterized by column |
| `tests/Unit/JunkKeyGuardTest.php` *(create)* | Placeholder + cardinality behaviour |
| `app/GoldenProfile/Resolution/DeterministicResolver.php` *(modify)* | NPI junk guard; new identifier tier (DEA/MMIS+state); identifier enrich |
| `app/GoldenProfile/SqlBackfill.php` *(modify)* | NPI junk guard in `tierCreate`/`tierLink`; `state` through `enrich()`; quarantine gate in `stage()` |
| `app/GoldenProfile/Engine.php` *(modify)* | NPI junk guard in `mergeByColumn`; state-scoped `mergeByIdentifier`; quarantine null-handling in `backfill()`/`sync()` |
| `config/golden_profile.php` *(modify)* | `junk` config block; two new `deterministic_keys` entries |
| `tests/Unit/DeterministicKeyConfigTest.php` *(modify)* | Extended for the two new tiers |
| `database/migrations/2026_09_04_000000_add_state_to_identifiers.php` *(create)* | Nullable `state` on `stg_person_identifier` / `gp_identity_identifier` |
| `database/migrations/2026_09_04_000001_create_gp_quarantine.php` *(create)* | Quarantine table |
| `app/GoldenProfile/Support/QuarantineRecorder.php` *(create)* | Decides + records "no identifying data" rows |
| `tests/Feature/QuarantineGateTest.php` *(create)* | Both ingestion paths quarantine the same shape of row |
| `tests/Feature/IdentifierTierParityTest.php` *(create)* | Per-row real-time bind vs. bulk dedup-time consolidation converge to one identity |
| `docs/EVALUATION.md` *(modify)* | Record the measured before/after gate numbers |

---

## Task 1: NPI check-digit validation (Luhn + the `80840` prefix)

**Files:**
- Create: `app/GoldenProfile/Support/NpiValidator.php`
- Test: `tests/Unit/NpiValidatorTest.php`

**Interfaces:**
- Produces: `NpiValidator::isValid(mixed $npi): bool` — later tasks call this as the single source
  of truth for "is this a real NPI shape."

The GPP wiki's "How Record Matching Works" says NPI validation must "check that it's a valid
10-digit number." The only screen gp-cami has today is `$npi = (int) ($emp->npi ?? 0); $npi > 0`
in `StreamlineLocalConnector::personRow()` — any 10-digit-shaped number, or any garbage that casts
to a positive int, is accepted and immediately used as a 0.99-confidence deterministic bind key
(`DeterministicResolver::matchDeterministic()`) and as a hard-no signal
(`ProbabilisticResolver::hardNo()`'s `two_valid_npis` rule, which is misnamed today since nothing
validates the "valid" part).

The real NPPES rule: an NPI is valid when its 10th digit is the Luhn check digit of the 15-digit
string formed by prepending the constant `80840` (the ISO/IEC 7812 issuer-identification prefix
CMS registered for the US NPI system) to the NPI's own 10 digits. Luhn validation on that
15-digit string: starting from the rightmost digit (the NPI's own check digit, which is never
itself doubled), move left doubling every second digit, folding any doubled value over 9 by
subtracting 9; the total must be a multiple of 10.

This was hand-verified against the canonical NPPES example (`1234567893`, published as the
worked Luhn example in the NPI rule) before writing the class, using a standalone script — the
same math this class implements, run outside the codebase, confirms `1234567893` validates and a
one-digit change to its check digit does not. That external check is what caught the fixture bug
fixed in Task 2.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\NpiValidator;
use Tests\TestCase;

/**
 * Luhn-with-80840 is the NPPES check-digit rule: prepend the constant issuer
 * prefix 80840 to the 10 NPI digits and Luhn-validate the resulting 15-digit
 * string. 1234567893 is the worked example in the NPI final rule and is the
 * standard "this algorithm is implemented correctly" vector; every other
 * vector here was derived from it by hand-computing a fresh check digit for a
 * chosen 9-digit prefix, not guessed.
 */
class NpiValidatorTest extends TestCase
{
    public function test_the_canonical_nppes_example_is_valid(): void
    {
        $this->assertTrue(NpiValidator::isValid('1234567893'));
    }

    public function test_known_valid_vectors(): void
    {
        // 1999999992 and 1987654328/1112223338 are the (corrected, see Task 2)
        // NPIs used by the eval fixture; 1509876540 is independent of the
        // fixture, included so this test does not merely echo the fixture back.
        foreach (['1999999992', '1987654328', '1112223338', '1509876540'] as $npi) {
            $this->assertTrue(NpiValidator::isValid($npi), "$npi should be valid");
        }
    }

    public function test_a_single_digit_check_digit_change_is_rejected(): void
    {
        // Same 9 leading digits as a known-valid vector, wrong check digit.
        $this->assertFalse(NpiValidator::isValid('1509876541'));
    }

    /**
     * These are the values the eval fixture used to carry (see Task 2's docblock)
     * before this class existed to check them. Recorded here as a permanent
     * regression vector: the fixture must never drift back to using an
     * unvalidated NPI as if it were a real one.
     */
    public function test_the_original_broken_fixture_values_are_rejected(): void
    {
        $this->assertFalse(NpiValidator::isValid('1987654327'));
        $this->assertFalse(NpiValidator::isValid('1112223339'));
    }

    public function test_all_same_digit_numbers_are_rejected(): void
    {
        // Not because of a special-case rule — the fixed, non-zero 80840 prefix
        // makes an all-same-digit payload fail the checksum on its own. Pinned
        // here so nobody "simplifies" the algorithm in a way that reintroduces
        // this as an accepted value.
        foreach (['0000000000', '1111111111', '9999999999'] as $npi) {
            $this->assertFalse(NpiValidator::isValid($npi), "$npi should be invalid");
        }
    }

    public function test_wrong_length_is_rejected(): void
    {
        $this->assertFalse(NpiValidator::isValid('123456789'));
        $this->assertFalse(NpiValidator::isValid('12345678901'));
    }

    public function test_non_numeric_is_rejected(): void
    {
        $this->assertFalse(NpiValidator::isValid('123456789A'));
        $this->assertFalse(NpiValidator::isValid(null));
        $this->assertFalse(NpiValidator::isValid(''));
    }

    public function test_accepts_int_input_the_way_stg_person_npi_is_typed(): void
    {
        // stg_person.npi is unsignedBigInteger; callers will pass an int.
        $this->assertTrue(NpiValidator::isValid(1234567893));
        $this->assertFalse(NpiValidator::isValid(1987654327));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/NpiValidatorTest.php`
Expected: FAIL with `Class "App\GoldenProfile\Support\NpiValidator" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\GoldenProfile\Support;

/**
 * NPI check-digit validation (CMS/NPPES rule, "How Record Matching Works"):
 * a valid NPI is a 10-digit number whose 10th digit is the Luhn check digit
 * of the 15-digit string formed by prepending the constant "80840" — the
 * ISO/IEC 7812 issuer-identification prefix CMS registered for the US
 * National Provider Identifier — to the 10 NPI digits.
 *
 * This validates FORMAT only: that the number is internally consistent, not
 * that it is actually assigned, active, or belongs to the person carrying it.
 * Confirming that would need an NPPES registry lookup, and NPPES ingestion is
 * out of scope for gp-cami (PROJECT_PLAN.md §8 rejects external government-feed
 * ingestion). See this plan's Self-review for why the "follow the trail if an
 * NPI was replaced" requirement is deferred rather than half-built here.
 *
 * Luhn alone does not reject an all-same-digit number in general (doubling a
 * repeated digit produces a repeated checksum contribution regardless of
 * value), but the fixed, non-zero "80840" prefix breaks that symmetry for
 * this specific 15-digit construction — 0000000000, 1111111111 and
 * 9999999999 all fail this check on their own, verified in
 * NpiValidatorTest::test_all_same_digit_numbers_are_rejected(). No separate
 * "looks like all zeros" rule is needed for NPI. A Luhn-VALID but still
 * fabricated NPI (e.g. one value reused as filler across many unrelated
 * people) is a different problem — that is what JunkKeyGuard's cardinality
 * check catches, independent of format.
 */
class NpiValidator
{
    /** NPPES/HIPAA constant issuer-id prefix for the US National Provider Identifier. */
    private const PREFIX = '80840';

    public static function isValid(mixed $npi): bool
    {
        $npi = is_int($npi) ? (string) $npi : $npi;
        if (! is_string($npi) || ! preg_match('/^\d{10}$/', $npi)) {
            return false;
        }

        return self::luhnValid(self::PREFIX.$npi);
    }

    /**
     * Standard Luhn checksum: from the rightmost digit (the digit under test —
     * here the NPI's own check digit, so it is never itself doubled), moving
     * left, double every SECOND digit. Fold any doubled value over 9 by
     * subtracting 9 (equivalent to summing that value's own two digits). Valid
     * iff the total across all digits is a multiple of 10.
     */
    private static function luhnValid(string $digits): bool
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $digits[$len - 1 - $i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/NpiValidatorTest.php`
Expected: PASS (9 tests)

- [ ] **Step 5: Commit**
```bash
git add app/GoldenProfile/Support/NpiValidator.php tests/Unit/NpiValidatorTest.php
git commit -m "feat(gp): add NPPES Luhn-with-80840 NPI check-digit validator"
```

---

## Task 2: Fix the eval fixture's own invalid NPIs, and pin them going forward

**Files:**
- Modify: `tests/eval/identity-pairs.json`
- Create: `tests/Unit/EvalFixtureNpiValidityTest.php`

**Interfaces:**
- Consumes: `NpiValidator::isValid()` from Task 1.

The authoring brief for this plan claimed the eval fixture's four NPIs (`1234567893`,
`1987654327`, `1999999992`, `1112223339`) were "check-digit-valid" and asked this plan to verify
that rather than trust it. Running `NpiValidator` (built in Task 1, but the same math was checked
independently with a standalone script before that class was written) against all four found that
**two of the four are not actually Luhn-valid**:

| Record | NPI | Luhn-with-80840 |
|---|---|---|
| `smith-a` / `smith-b` | `1234567893` | **valid** (the canonical NPPES example) |
| `smith-other` | `1987654327` | **invalid** |
| `garcia-a` | `1999999992` | **valid** |
| `chain-a` | `1112223339` | **invalid** |

This is not currently a scoring bug: `smith-other`'s NPI is never shared with another record (it
exists specifically to prove a same-name-different-person case stays split), and `chain-a`'s NPI
is likewise unique to it in the fixture — its actual truth-cluster merge with `chain-b`/`chain-c`
happens through the shared license, not the NPI. So today, with no NPI validation wired in, the
gate passes at 1.0/1.0/1.0 regardless of whether these two values are real. But the moment Task 3
makes NPI validation load-bearing, an invalid NPI silently drops out of matching — and if that had
happened to be the ONLY key holding a true pair together, this fixture would have gone from
proving a merge to silently no longer testing it, with the gate still green. Since `sv-manila/gp-cami`
is public and this fixture is the only thing standing between "eval gate passes" and "eval gate
means something," fix the data now rather than let a future task quietly stop testing what it
claims to test.

Replacement values keep the same first 9 digits (so they are visibly "the same person's NPI, just
check-digit-corrected" in a diff) with a recomputed valid check digit: `1987654327` → `1987654328`,
`1112223339` → `1112223338`. Neither collides with any other NPI already in the fixture.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Eval\EvalSet;
use App\GoldenProfile\Support\NpiValidator;
use Tests\TestCase;

/**
 * The eval fixture is the only thing standing between "the gate passes" and
 * "the gate means something." An NPI in the fixture that is not actually
 * Luhn-valid is invisible today (nothing checks it) and becomes a silent
 * de-fanging of whatever case it was meant to exercise the moment NPI
 * validation goes live in matching (Task 3) — the value just stops
 * contributing to any bind and the gate never says so. This test makes that
 * class of drift loud instead of silent, permanently.
 */
class EvalFixtureNpiValidityTest extends TestCase
{
    public function test_every_fixture_npi_is_luhn_valid(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));

        foreach ($set->records() as $r) {
            if (! empty($r['npi'])) {
                $this->assertTrue(
                    NpiValidator::isValid((string) $r['npi']),
                    "fixture record '{$r['ref']}' has npi {$r['npi']}, which is not Luhn-valid ".
                    'under the 80840-prefixed NPI check digit — see NpiValidator'
                );
            }
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/EvalFixtureNpiValidityTest.php`
Expected: FAIL — `smith-other has npi 1987654327 ... chain-a has npi 1112223339 ...` (2 failures,
or 1 depending on assertion-per-loop reporting; either way it fails)

- [ ] **Step 3: Fix the fixture**

In `tests/eval/identity-pairs.json`, change:
```json
    { "ref": "smith-other", "first_name": "Robert", "last_name": "Smith", "date_of_birth": "1991-12-18", "npi": 1987654327 },
```
to:
```json
    { "ref": "smith-other", "first_name": "Robert", "last_name": "Smith", "date_of_birth": "1991-12-18", "npi": 1987654328 },
```
and change:
```json
    { "ref": "chain-a", "first_name": "Peter", "last_name": "Nguyen", "date_of_birth": "1968-07-21", "npi": 1112223339 },
```
to:
```json
    { "ref": "chain-a", "first_name": "Peter", "last_name": "Nguyen", "date_of_birth": "1968-07-21", "npi": 1112223338 },
```

Also update the top-level `"notes"` string: after the existing sentence about the ssn-* records,
append: `"smith-other's and chain-a's NPIs were originally 1987654327 / 1112223339, which are NOT Luhn-valid under the 80840-prefixed NPI check digit despite looking like plausible 10-digit NPIs — corrected to 1987654328 / 1112223338 (same 9 leading digits, recomputed check digit) once NPI validation was added in plan 5. See EvalFixtureNpiValidityTest, which pins this."`

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/EvalFixtureNpiValidityTest.php`
Expected: PASS

- [ ] **Step 5: Re-run the full eval gate — confirm the fix was neutral**

Run: `vendor/bin/phpunit --filter=EvalGateTest`
Expected: PASS, numbers unchanged from the plan-1 baseline — precision 1.0000, recall 1.0000,
f1 1.0000, true_pairs 9, false_merges 0, false_splits 0. Neither corrected record shares its NPI
with another record, so this edit cannot change any current merge decision; this run proves that
rather than assumes it.

- [ ] **Step 6: Commit**
```bash
git add tests/eval/identity-pairs.json tests/Unit/EvalFixtureNpiValidityTest.php
git commit -m "fix(eval): correct two non-Luhn-valid NPIs in the eval fixture"
```

---

## Task 3: Reject invalid NPIs at ingestion, and add a real-hub measurement tool

**Files:**
- Modify: `app/GoldenProfile/Connectors/StreamlineLocalConnector.php:44-80` (`personRow()`)
- Create: `app/Console/Commands/GpNpiAudit.php`
- Create: `tests/Unit/StreamlineLocalConnectorTest.php`

**Interfaces:**
- Consumes: `NpiValidator::isValid()` (Task 1).
- Produces: `stg_person.npi` is now null unless the source value is a 10-digit, Luhn-valid NPI —
  every later task and both resolvers see this as a fact about staging, not something they
  re-check.

`personRow()` is the ONE place that maps a `streamline_local.employees` row into the canonical
staging shape, and both ingestion paths call it — `StreamlineLocalConnector::ingest()` (per-row,
used by `Engine::backfill()`/`sync()`) and `SqlBackfill::stage()` (bulk, used by `gp:backfill`).
Fixing it here means neither resolver, and neither the per-row nor the set-based path, needs its
own separate NPI-format check — this is the parity the brief asks for, achieved by not duplicating
the check at all rather than by keeping two copies in sync.

**Risk this task must not hand-wave:** rejecting a currently-accepted NPI can *split* an identity
that today merges two source rows on that NPI. This cannot be evaluated against the eval fixture —
Task 2 confirmed neither corrected fixture NPI is shared, so the fixture cannot exercise this risk
either way. It can only be measured against the real hub, which nobody in this environment can
reach (Environment fact: "No production hub access"). `gp:npi-audit`, below, is written now as the
concrete, runnable, read-only command a human runs later — not a promise to "check this at some
point."

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use Tests\TestCase;

/**
 * personRow()/childRows()/additionalRows() take a plain object and return an
 * array — no DB access, unlike ingest() (which needs src()+hub() and is
 * therefore untestable in isolation; SRC_DB_HOST is deliberately a dead
 * socket in phpunit.xml, and nothing in tests/ has ever called ingest()
 * directly for that reason). These tests exercise the connector at the one
 * boundary that IS testable without a live streamline_local.
 */
class StreamlineLocalConnectorTest extends TestCase
{
    private function connector(): StreamlineLocalConnector
    {
        return new StreamlineLocalConnector(1);
    }

    private function employee(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 1,
            'employeelist_id' => null,
            'first_name' => 'Robert',
            'middle_name' => null,
            'last_name' => 'Smith',
            'date_of_birth' => '1970-04-02',
            'ssn_hash' => null,
            'ssn_last_four' => null,
            'npi' => null,
            'upin' => null,
            'address1' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'terminated' => 0,
            'date_modified' => null,
        ], $overrides);
    }

    public function test_a_luhn_valid_npi_is_kept(): void
    {
        $row = $this->connector()->personRow($this->employee(['npi' => 1234567893]));

        $this->assertSame(1234567893, $row['npi']);
    }

    public function test_a_luhn_invalid_npi_is_nulled(): void
    {
        // Same shape as a real NPI (10 digits, plausible), just not check-digit valid.
        $row = $this->connector()->personRow($this->employee(['npi' => 1987654327]));

        $this->assertNull($row['npi']);
    }

    public function test_zero_and_null_npi_stay_null(): void
    {
        $this->assertNull($this->connector()->personRow($this->employee(['npi' => 0]))['npi']);
        $this->assertNull($this->connector()->personRow($this->employee(['npi' => null]))['npi']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/StreamlineLocalConnectorTest.php --filter=test_a_luhn_invalid_npi_is_nulled`
Expected: FAIL — `Failed asserting that 1987654327 matches expected null`

- [ ] **Step 3: Write the implementation**

In `app/GoldenProfile/Connectors/StreamlineLocalConnector.php`, add the import at the top:

```php
use App\GoldenProfile\Support\NpiValidator;
```

Replace lines 54 and 68 (the `$npi` computation and its use in the returned array):

```php
        $npi = (int) ($emp->npi ?? 0);
```

with:

```php
        // Format-valid means "10 digits with a correct NPPES check digit" — see
        // NpiValidator. A value that fails this is nulled here, not just skipped
        // by a caller, so every downstream consumer (both resolvers, both
        // ingestion paths, since they all share this one method) sees the same
        // fact: stg_person.npi is either a validated NPI or nothing. Rejecting a
        // value that a PRIOR load accepted can split an identity that currently
        // merges on it — see gp:npi-audit for measuring that against a real hub,
        // since the eval fixture cannot exercise this (Task 2's fix confirmed
        // neither corrected NPI is shared between records).
        $npi = (int) ($emp->npi ?? 0);
        $npiValid = $npi > 0 && NpiValidator::isValid((string) $npi);
```

and change line 68 from:

```php
            'npi' => $npi > 0 ? $npi : null,
```

to:

```php
            'npi' => $npiValid ? $npi : null,
```

Now create `app/Console/Commands/GpNpiAudit.php`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Support\NpiValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only measurement, not a fix. NPI validation lands in
 * StreamlineLocalConnector::personRow() (see this plan's Task 3), which
 * screens NPIs on every future ingest — but it says nothing about identities
 * a PRIOR load already bound on an npi that would fail this check today.
 * Rejecting one of those retroactively would split that identity; whether that
 * is rare or common can only be answered by running this against the real
 * hub, which nobody building this plan has access to (see this plan's
 * Self-review). This command is that measurement, ready for whoever does.
 */
class GpNpiAudit extends Command
{
    protected $signature = 'gp:npi-audit';

    protected $description = 'Read-only: count active identities whose npi is not Luhn-valid '
        .'under the 80840-prefixed NPI check digit, and how many source links are bound via the '
        .'npi tier onto one of them. Run against a real hub to size the retroactive-validation risk.';

    public function handle(): int
    {
        $hub = DB::connection('golden_profile');

        $withNpi = $hub->table('gp_identity')->where('status', 'active')->whereNotNull('npi')
            ->select('identity_id', 'npi')->get();

        $invalid = $withNpi->filter(fn ($r) => ! NpiValidator::isValid((string) $r->npi));

        $this->info("Scanned {$withNpi->count()} active identities with a non-null npi.");
        $this->info("{$invalid->count()} fail the Luhn+80840 check digit and would lose npi as ".
            'a bind key if validation were enforced retroactively.');

        if ($invalid->isNotEmpty()) {
            $linkCount = $hub->table('gp_source_link')
                ->where('match_key', 'npi')
                ->whereIn('identity_id', $invalid->pluck('identity_id'))
                ->count();
            $this->info("$linkCount source link(s) are currently bound via the npi tier onto one ".
                'of those identities — that many rows are the retroactive-split exposure.');

            $this->table(['identity_id', 'npi'], $invalid->take(50)->map(fn ($r) => [$r->identity_id, $r->npi])->all());
            if ($invalid->count() > 50) {
                $this->line('... '.($invalid->count() - 50).' more not shown.');
            }
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/StreamlineLocalConnectorTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Re-run the eval gate**

Run: `vendor/bin/phpunit --filter=EvalGateTest`
Expected: PASS, unchanged from Task 2 — precision 1.0000, recall 1.0000, f1 1.0000, true_pairs 9,
0 false merges, 0 false splits. `EvalRunner` stages fixture records directly into `stg_person`
(it does not go through `personRow()`), so this task cannot change the gate's own inputs; this run
proves the connector change did not regress anything reachable through the normal ingestion path
either, since `gp:eval`'s harness and `EvalRunner` share the same `DeterministicResolver`.

- [ ] **Step 6: Commit**
```bash
git add app/GoldenProfile/Connectors/StreamlineLocalConnector.php app/Console/Commands/GpNpiAudit.php tests/Unit/StreamlineLocalConnectorTest.php
git commit -m "feat(gp): reject non-Luhn-valid NPIs at ingestion; add gp:npi-audit"
```

---

## Task 4: `JunkKeyGuard` — a general placeholder + cardinality guard

**Files:**
- Create: `app/GoldenProfile/Support/JunkKeyGuard.php`
- Test: `tests/Unit/JunkKeyGuardTest.php`

**Interfaces:**
- Produces: `JunkKeyGuard::isBlocked(string $column, ?string $value): bool`,
  `JunkKeyGuard::buildBlocklistTable(string $column): int`, `JunkKeyGuard::exclusionSql(string
  $column, string $sqlColumnRef): string` — Task 5 wires all three into every NPI match site.

`SsnHashGuard::decide()` has the right idea, generalized wrong: a value shared by an implausible
number of distinct people is filler evidence regardless of whether anyone can name the specific
placeholder, and that check needs no decryption key to run. The class itself is SSN-specific and,
per this plan's authoring brief, plan 2 deletes it entirely along with the rest of SSN handling —
so this is not an extension of `SsnHashGuard`, it is a fresh, independent class that generalizes
the one idea worth keeping: parameterize by column instead of hardcoding `ssn_hash`/`stg_person`,
and a shared blocklist table keyed by `(column_name, value)` instead of one table per column.

This plan wires it for exactly one column — `npi` — because that is the concrete example the
Delivery Checklist names ("all-zero NPI"). Task 1 already showed Luhn rejects the literal
all-same-digit case; the risk this guard actually covers is different and more realistic: any
Luhn-*valid* NPI reused as filler across unrelated people (a data-entry shortcut, a QA fixture
value accidentally shipped in real data, or a canonical published example — `1234567893` itself is
exactly the kind of value that gets copy-pasted as a placeholder precisely because it is the most
famous "valid-looking" NPI there is). No plaintext denylist can anticipate that; cardinality can,
without needing to know in advance which value is bad. License numbers and addresses are named as
candidates for the same treatment in "Recommendations & Open Risks," but the Delivery Checklist's
own worked examples are NPI and a name placeholder (Task 6) — extending this to license/address is
a same-shaped, low-risk follow-up left for later rather than folded in here (see Self-review).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\JunkKeyGuard;
use Tests\Support\HubTestCase;

/**
 * Generalizes SsnHashGuard's cardinality idea (a value carried by implausibly
 * many distinct people cannot be one person's identifier) to any column,
 * config-driven, with no dependency on SsnHashGuard/SsnHasher — plan 2 deletes
 * those files. The placeholder half needs no DB; the cardinality half needs a
 * real hub, hence HubTestCase.
 */
class JunkKeyGuardTest extends HubTestCase
{
    public function test_configured_placeholder_is_blocked_with_no_db_round_trip(): void
    {
        config()->set('golden_profile.junk.placeholders.npi', ['1234567893']);

        // No stg_person rows staged at all — if this needed the cardinality
        // query it would find 0 people and NOT block, so a true pass here
        // proves the placeholder list is checked first.
        $this->assertTrue((new JunkKeyGuard)->isBlocked('npi', '1234567893'));
    }

    public function test_null_and_empty_values_are_never_blocked(): void
    {
        $guard = new JunkKeyGuard;

        $this->assertFalse($guard->isBlocked('npi', null));
        $this->assertFalse($guard->isBlocked('npi', ''));
    }

    public function test_a_value_under_the_cardinality_cap_is_not_blocked(): void
    {
        config()->set('golden_profile.junk.max_identities_per_value.npi', 3);

        $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Ann', 'last_name' => 'Lee']);
        $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Bob', 'last_name' => 'Diaz']);

        $this->assertFalse((new JunkKeyGuard)->isBlocked('npi', '1234567893'));
    }

    public function test_a_value_over_the_cardinality_cap_is_blocked(): void
    {
        config()->set('golden_profile.junk.max_identities_per_value.npi', 3);

        foreach (range(1, 4) as $i) {
            $this->stagePerson(['npi' => 1234567893, 'first_name' => "Person$i", 'last_name' => 'Distinct']);
        }

        $this->assertTrue((new JunkKeyGuard)->isBlocked('npi', '1234567893'));
    }

    public function test_cap_is_at_least_one(): void
    {
        config()->set('golden_profile.junk.max_identities_per_value.npi', 0);

        // A cap of 0 would block every value including real ones, silently
        // disabling the whole tier — same defensive floor as SsnHashGuard's.
        $this->assertGreaterThanOrEqual(1, (new JunkKeyGuard)->maxIdentitiesPerValue('npi'));
    }

    public function test_exclusion_sql_is_constant_size_and_scoped_to_the_column(): void
    {
        $sql = (new JunkKeyGuard)->exclusionSql('npi', 's.`npi`');

        $this->assertStringContainsString('gp_junk_value_blocklist', $sql);
        $this->assertStringContainsString("column_name = 'npi'", $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function test_build_blocklist_table_captures_both_reasons(): void
    {
        config()->set('golden_profile.junk.placeholders.npi', ['1111111111']);
        config()->set('golden_profile.junk.max_identities_per_value.npi', 3);

        foreach (range(1, 4) as $i) {
            $this->stagePerson(['npi' => 9999999990 + $i, 'first_name' => "P$i", 'last_name' => 'X']);
        }
        // Same npi shared by all 4 distinct people above (overwrite so they collide).
        $this->hub()->table('stg_person')->update(['npi' => 1999999992]);

        $blocked = (new JunkKeyGuard)->buildBlocklistTable('npi');

        $this->assertSame(2, $blocked); // 1 cardinality (1999999992) + 1 placeholder (1111111111)
        $this->assertDatabaseHas('gp_junk_value_blocklist', [
            'column_name' => 'npi', 'value' => '1999999992', 'reason' => 'cardinality',
        ]);
        $this->assertDatabaseHas('gp_junk_value_blocklist', [
            'column_name' => 'npi', 'value' => '1111111111', 'reason' => 'placeholder',
        ]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/JunkKeyGuardTest.php`
Expected: FAIL — `Class "App\GoldenProfile\Support\JunkKeyGuard" not found`

- [ ] **Step 3: Write the implementation**

Add to `config/golden_profile.php`, right after the `deterministic_keys` block (after line 33):

```php
    /*
    | Junk / placeholder value screening — Delivery Checklist §2/§3 and
    | "Recommendations & Open Risks" P1 ("kill mega-blocks at the source").
    | A few high-frequency junk values are what make matching blow up at
    | scale; this generalizes SsnHashGuard's cardinality idea (a value shared
    | by implausibly many distinct people cannot be one person's identifier)
    | to any column, config-driven. Wired for 'npi' only in this plan (Task 5)
    | — 'placeholders' starts empty because, unlike SSN's filler list, nobody
    | has run gp:npi-audit-style measurement against a real hub yet to know
    | which specific NPI values are reused as filler here. Populate it the
    | same way SSN's was populated: from measurement, not a guess. license and
    | address values are good next columns for the same treatment (see this
    | plan's Self-review) but are not wired in yet.
    */
    'junk' => [
        'placeholders' => [
            'npi' => [],
        ],
        'max_identities_per_value' => [
            'npi' => 3,
        ],
    ],
```

Now create `app/GoldenProfile/Support/JunkKeyGuard.php`:

```php
<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;

/**
 * General placeholder + cardinality guard, parameterized by column. Generalizes
 * SsnHashGuard's cardinality idea rather than extending that class: plan 2
 * deletes SsnHashGuard/SsnHasher entirely (SSN removal), so anything meant to
 * outlive that change cannot depend on them.
 *
 * Two independent checks, same as SSN's:
 *  1. Known placeholders (config golden_profile.junk.placeholders.$column) —
 *     exact, but only catches values someone has already identified.
 *  2. Cardinality: a value carried by more than
 *     golden_profile.junk.max_identities_per_value.$column *distinct people*
 *     — distinct (last_name, first_name, date_of_birth) triples in staging —
 *     cannot be one person's identifier. Needs no prior knowledge and catches
 *     junk nobody has listed, including a value that is individually
 *     well-formed (a real NPI's worth of digits, a Luhn-valid check digit)
 *     but reused as filler.
 *
 * Deliberately counts distinct PEOPLE in stg_person, not identities in
 * gp_identity: the deterministic tiers mint one identity per distinct value
 * and link every row carrying it, so a filler value ends up on exactly one
 * identity — the damage is invisible from the identity side (see
 * SsnHashGuard's original docblock for the same reasoning, which is why this
 * class keeps it).
 */
class JunkKeyGuard
{
    /** @var array<string,bool> */
    private array $decisions = [];

    public function maxIdentitiesPerValue(string $column): int
    {
        return max(1, (int) config("golden_profile.junk.max_identities_per_value.$column", 3));
    }

    /** @return list<string> */
    public function placeholders(string $column): array
    {
        return array_map('strval', (array) config("golden_profile.junk.placeholders.$column", []));
    }

    /** True when this value must NOT be used as a deterministic identity key. */
    public function isBlocked(string $column, ?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $key = $column.'|'.$value;

        return $this->decisions[$key] ??= $this->decide($column, $value);
    }

    private function decide(string $column, string $value): bool
    {
        if (in_array($value, $this->placeholders($column), true)) {
            return true;
        }

        $cap = $this->maxIdentitiesPerValue($column);

        // $column is always a fixed, code-controlled string (never user input;
        // see callers in DeterministicResolver/SqlBackfill/Engine) — safe to
        // interpolate, same rule Engine::mergeByColumn already documents for
        // its own $col interpolation.
        $distinctPeople = $this->hub()
            ->table('stg_person')
            ->where($column, $value)
            ->distinct()
            ->limit($cap + 1)
            ->pluck(DB::raw("CONCAT_WS('|', last_name, first_name, date_of_birth)"))
            ->count();

        return $distinctPeople > $cap;
    }

    private function hub()
    {
        return DB::connection(config('golden_profile.connections.hub', 'golden_profile'));
    }

    /**
     * Materialise the blocklist for one column into gp_junk_value_blocklist so
     * the set-based backfill can anti-join against it instead of threading a
     * NOT IN list through every statement. Rebuilt from scratch for THIS
     * column only — other columns' rows are untouched.
     *
     * @return int number of blocked values for this column
     */
    public function buildBlocklistTable(string $column): int
    {
        $hub = $this->hub();
        $cap = $this->maxIdentitiesPerValue($column);

        $hub->statement('CREATE TABLE IF NOT EXISTS gp_junk_value_blocklist (
            column_name VARCHAR(64) NOT NULL,
            value VARCHAR(255) NOT NULL,
            reason VARCHAR(32) NOT NULL,
            distinct_people INT NOT NULL DEFAULT 0,
            PRIMARY KEY (column_name, value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $hub->table('gp_junk_value_blocklist')->where('column_name', $column)->delete();

        $hub->statement(
            "INSERT INTO gp_junk_value_blocklist (column_name, value, reason, distinct_people)
             SELECT ?, v, 'cardinality', people FROM (
                 SELECT `$column` v,
                        COUNT(DISTINCT CONCAT_WS('|', last_name, first_name, date_of_birth)) people
                 FROM stg_person
                 WHERE `$column` IS NOT NULL AND `$column` <> ''
                 GROUP BY `$column`
             ) g WHERE g.people > ?",
            [$column, $cap]
        );

        foreach (array_chunk($this->placeholders($column), 200) as $chunk) {
            $hub->table('gp_junk_value_blocklist')->insertOrIgnore(array_map(
                fn ($v) => ['column_name' => $column, 'value' => $v, 'reason' => 'placeholder', 'distinct_people' => 0],
                $chunk
            ));
        }

        return (int) $hub->table('gp_junk_value_blocklist')->where('column_name', $column)->count();
    }

    /**
     * SQL fragment excluding blocked values for one column, for the set-based
     * backfill tiers. Anti-joins gp_junk_value_blocklist (see
     * buildBlocklistTable) so the fragment stays constant-size regardless of
     * how many values are blocked.
     */
    public function exclusionSql(string $column, string $sqlColumnRef): string
    {
        return " AND NOT EXISTS (SELECT 1 FROM gp_junk_value_blocklist b ".
            "WHERE b.column_name = '$column' AND b.value = $sqlColumnRef)";
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/JunkKeyGuardTest.php`
Expected: PASS (7 tests) — requires `GP_TEST_DB_*` set; skips cleanly otherwise.

- [ ] **Step 5: Commit**
```bash
git add app/GoldenProfile/Support/JunkKeyGuard.php tests/Unit/JunkKeyGuardTest.php config/golden_profile.php
git commit -m "feat(gp): add JunkKeyGuard, a general placeholder+cardinality guard"
```

---

## Task 5: Wire the NPI junk guard into every match-time site

**Files:**
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php:1-28,119-160,236-261`
- Modify: `app/GoldenProfile/SqlBackfill.php:25-48,350-379,447-500`
- Modify: `app/GoldenProfile/Engine.php:1-45,296-322`
- Test: `tests/Feature/JunkNpiParityTest.php` *(create)*

**Interfaces:**
- Consumes: `JunkKeyGuard` (Task 4).

`ssn_hash` is guarded in four places today: `DeterministicResolver::matchDeterministic()` (real-time
bind), `DeterministicResolver::backfillKeys()` (real-time backfill), `SqlBackfill::tierCreate()`/
`tierLink()` (bulk resolve), and `Engine::mergeByColumn()` (dedup-time cleanup). NPI needs the
same four, using `JunkKeyGuard` instead — a junk NPI value must not bind a new row (matchDeterministic),
must not get promoted onto an identity that lacks one (backfillKeys), must not seed or extend a
bulk tier (SqlBackfill), and must not fold two identities together after the fact (mergeByColumn).
Missing any one of the four would leave a hole precisely where the SSN precedent shows one matters.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\SqlBackfill;
use Tests\Support\HubTestCase;

/**
 * A junk NPI (here: one carried by more distinct people than
 * golden_profile.junk.max_identities_per_value.npi allows) must not bind
 * anyone in ANY of the four places NPI is used as a key. This is the NPI
 * analogue of the ssn_hash guard's existing coverage, proving the four sites
 * agree rather than trusting that wiring the same thing four times was done
 * consistently.
 */
class JunkNpiParityTest extends HubTestCase
{
    private function seedJunkNpi(): void
    {
        config()->set('golden_profile.junk.max_identities_per_value.npi', 2);
        // 3 distinct people, over the cap of 2.
        foreach (['Ann', 'Bob', 'Cal'] as $first) {
            $this->stagePerson(['npi' => 1234567893, 'first_name' => $first, 'last_name' => 'Distinct'.$first]);
        }
    }

    public function test_deterministic_resolver_does_not_bind_on_a_junk_npi(): void
    {
        $this->seedJunkNpi();
        $extraId = $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Dee', 'last_name' => 'Fourth']);

        $resolver = new DeterministicResolver($this->systemId);
        // Resolve everyone; each of the 4 should land on its OWN identity —
        // none should bind together purely via the junk npi.
        $ids = [];
        foreach ($this->hub()->table('stg_person')->where('npi', 1234567893)->pluck('stg_person_id') as $id) {
            $ids[] = $resolver->resolve($id);
        }

        $this->assertSame(4, count(array_unique($ids)), 'a junk npi must not bind unrelated people');
    }

    public function test_sql_backfill_tier_does_not_bind_on_a_junk_npi(): void
    {
        $this->seedJunkNpi();

        (new SqlBackfill)->resolveDeterministic();

        $identityCount = $this->hub()->table('gp_identity')->where('npi', 1234567893)->count();
        $this->assertSame(3, $identityCount, 'each of the 3 junk-npi people should get their own identity');
    }

    public function test_dedup_does_not_merge_identities_sharing_a_junk_npi(): void
    {
        $this->seedJunkNpi();
        (new SqlBackfill)->resolveDeterministic();

        $merged = (new Engine)->dedup();

        $this->assertSame(0, $merged, 'dedup must not fold the 3 junk-npi identities together');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/JunkNpiParityTest.php`
Expected: FAIL on all three — the guard is not wired in yet, so all three (or four, in the SQL
tier case) collapse onto one identity/bind together.

- [ ] **Step 3: Wire the guard into `DeterministicResolver`**

Add the import and property (after line 5, and after line 18):
```php
use App\GoldenProfile\Support\JunkKeyGuard;
```
```php
    private JunkKeyGuard $junkGuard;
```
In the constructor (line 26), after `$this->ssnGuard = new SsnHashGuard;`, add:
```php
        $this->junkGuard = new JunkKeyGuard;
```

In `matchDeterministic()`, change the npi branch (lines 140-146) from:
```php
        if ($p->npi) {
            $id = $hub->table('gp_identity')->where('npi', $p->npi)->where('status', 'active')
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'npi', $this->confidence('npi', 0.99)];
            }
        }
```
to:
```php
        // Junk-screened the same way ssn_hash is above: a shared filler NPI
        // would otherwise collapse every person carrying it at 0.99 confidence
        // with no name or DOB cross-check. See JunkKeyGuard.
        if ($p->npi && ! $this->junkGuard->isBlocked('npi', (string) $p->npi)) {
            $id = $hub->table('gp_identity')->where('npi', $p->npi)->where('status', 'active')
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'npi', $this->confidence('npi', 0.99)];
            }
        }
```

In `backfillKeys()` (lines 241-252), the `foreach` already special-cases `ssn_hash`; extend the
same `if` to cover `npi` too:
```php
        foreach (['ssn_hash', 'npi', 'upin', 'dea_number', 'canonical_dob'] as $col) {
            $srcCol = $col === 'canonical_dob' ? 'date_of_birth' : $col;
            if (empty($id->$col) && ! empty($p->$srcCol)) {
                // Never promote a filler ssn_hash/npi onto an identity that
                // lacks one: it would spread the placeholder and hand later
                // rows a bogus 0.99 key to match on.
                if ($col === 'ssn_hash' && $this->ssnGuard->isBlocked($p->$srcCol)) {
                    continue;
                }
                if ($col === 'npi' && $this->junkGuard->isBlocked('npi', (string) $p->$srcCol)) {
                    continue;
                }
                $upd[$col] = $p->$srcCol;
            }
        }
```

- [ ] **Step 4: Wire the guard into `SqlBackfill`**

Add the import, property, and constructor line:
```php
use App\GoldenProfile\Support\JunkKeyGuard;
```
```php
    private JunkKeyGuard $junkGuard;
```
In the constructor, after `$this->ssnGuard = new SsnHashGuard;`:
```php
        $this->junkGuard = new JunkKeyGuard;
```

In `resolveDeterministic()` (lines 350-359), after the ssn_hash blocklist build, add the npi one:
```php
        $blocked = $this->ssnGuard->buildBlocklistTable();
        $log('resolve', "ssn_hash blocklist: $blocked filler hash(es) excluded");

        $npiBlocked = $this->junkGuard->buildBlocklistTable('npi');
        $log('resolve', "npi junk blocklist: $npiBlocked value(s) excluded");
```

In `tierCreate()` (line 453) and `tierLink()` (line 483), extend the `$guard` assignment from a
single `ssn_hash`-only ternary to cover `npi` too:
```php
        $guard = match ($col) {
            'ssn_hash' => $this->ssnGuard->exclusionSql('s.`ssn_hash`'),
            'npi' => $this->junkGuard->exclusionSql('npi', 's.`npi`'),
            default => '',
        };
```
(Replace the existing one-line ternary in both methods with this identical `match` block.)

- [ ] **Step 5: Wire the guard into `Engine::mergeByColumn()`**

Add the import, property, and constructor line (mirroring `$ssnGuard` at lines 10, 35, 44):
```php
use App\GoldenProfile\Support\JunkKeyGuard;
```
```php
    private JunkKeyGuard $junkGuard;
```
```php
        $this->junkGuard = new JunkKeyGuard;
```

In `mergeByColumn()` (lines 305-311), extend the existing ssn_hash-only check:
```php
        foreach ($dupVals as $val) {
            // A filler value is not evidence of shared identity. Resolution now
            // refuses to bind on one, but dedup would still fold together any
            // identities that already carry it — so screen here too.
            if ($col === 'ssn_hash' && $this->ssnGuard->isBlocked($val)) {
                continue;
            }
            if ($col === 'npi' && $this->junkGuard->isBlocked('npi', (string) $val)) {
                continue;
            }
            $ids = $hub->table('gp_identity')
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/JunkNpiParityTest.php`
Expected: PASS (3 tests)

- [ ] **Step 7: Re-run the eval gate**

Run: `vendor/bin/phpunit --filter=EvalGateTest`
Expected: PASS, unchanged — precision 1.0000, recall 1.0000, f1 1.0000, true_pairs 9, 0 false
merges, 0 false splits. No fixture NPI is shared by more than 2 people (the cap default is 3), so
this is a genuine no-op against the current fixture; the mechanism is now armed rather than
exercised by it.

- [ ] **Step 8: Commit**
```bash
git add app/GoldenProfile/Resolution/DeterministicResolver.php app/GoldenProfile/SqlBackfill.php app/GoldenProfile/Engine.php tests/Feature/JunkNpiParityTest.php
git commit -m "feat(gp): guard the npi tier against junk values in all four match sites"
```

---

## Task 6: Screen "INFORMATION NOT AVAILABLE"-style junk name values at ingestion

**Files:**
- Modify: `app/GoldenProfile/Connectors/StreamlineLocalConnector.php:44-80,131-193`
- Modify: `tests/Unit/StreamlineLocalConnectorTest.php` (add tests)

**Interfaces:**
- Produces: name fields (`first_name`/`middle_name`/`last_name` on `personRow()`, and alias name
  fields from `childRows()`) never carry a configured junk placeholder string — everything
  downstream (block keys, name+dob tier, Pass B's name score) sees `null` instead.

The Delivery Checklist's own worked example for junk keys is `"INFORMATION NOT AVAILABLE"` — a
name-field placeholder some source systems write instead of leaving the field empty. Unlike NPI
junk, this needs no cardinality check: the string itself is the signal, and it can be screened
statelessly (no DB) at the same point `clean()` already trims and nulls empty strings. This stays
narrowly scoped to *name* fields, not `clean()` itself, so address/city values are unaffected — a
value like "UNKNOWN" might be a legitimate placeholder in a name field and equally legitimate
literal data in another column; broadening this beyond names is a judgment call this plan is not
making today (see Self-review).

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/StreamlineLocalConnectorTest.php`:

```php
    public function test_a_junk_name_placeholder_is_nulled(): void
    {
        $row = $this->connector()->personRow($this->employee(['last_name' => 'INFORMATION NOT AVAILABLE']));

        $this->assertNull($row['last_name']);
    }

    public function test_junk_name_screening_is_case_insensitive(): void
    {
        $row = $this->connector()->personRow($this->employee(['first_name' => 'information not available']));

        $this->assertNull($row['first_name']);
    }

    public function test_a_real_name_is_kept(): void
    {
        $row = $this->connector()->personRow($this->employee(['last_name' => 'Smith']));

        $this->assertSame('Smith', $row['last_name']);
    }

    public function test_junk_screening_applies_to_business_aliases_too(): void
    {
        $emp = $this->employee(['business' => 'UNKNOWN']);
        $rows = $this->connector()->childRows($emp);

        $this->assertSame([], $rows['aliases']);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/StreamlineLocalConnectorTest.php --filter=test_a_junk_name_placeholder_is_nulled`
Expected: FAIL — `Failed asserting that 'INFORMATION NOT AVAILABLE' matches expected null`

- [ ] **Step 3: Write the implementation**

Add to `config/golden_profile.php`, inside the `'junk'` block added in Task 4:

```php
        'name_placeholders' => [
            'INFORMATION NOT AVAILABLE', 'NOT AVAILABLE', 'UNKNOWN', 'N/A', 'NONE',
        ],
```

(so the full `'junk'` block now reads `placeholders`, `max_identities_per_value`, and
`name_placeholders`.)

In `StreamlineLocalConnector.php`, add a new private method right after `clean()` (which sits
just above `blockKey()` — insert before line 255's `blockKey`):

```php
    /**
     * clean() trims and nulls empty strings for every text column; this is the
     * narrower, name-specific half of junk screening (Delivery Checklist: "all-
     * zero NPI, 'INFORMATION NOT AVAILABLE'"). Kept separate from clean() on
     * purpose — a value like "UNKNOWN" is unambiguous junk in a name field but
     * not necessarily in every other column, so this is applied only where
     * this plan has confirmed it belongs: first/middle/last name and name-
     * shaped alias fields.
     */
    private function cleanName(?string $v): ?string
    {
        $v = $this->clean($v);
        if ($v === null) {
            return null;
        }
        $placeholders = array_map('strtoupper', (array) config('golden_profile.junk.name_placeholders', []));

        return in_array(strtoupper($v), $placeholders, true) ? null : $v;
    }
```

In `personRow()`, change lines 62-64 from:
```php
            'first_name' => $this->clean($emp->first_name),
            'middle_name' => $this->clean($emp->middle_name),
            'last_name' => $this->clean($emp->last_name),
```
to:
```php
            'first_name' => $this->cleanName($emp->first_name),
            'middle_name' => $this->cleanName($emp->middle_name),
            'last_name' => $this->cleanName($emp->last_name),
```

In `childRows()`'s `$addAlias` closure (around line 135), change:
```php
        $addAlias = function ($type, $first, $last) use (&$aliases) {
            $first = $this->clean($first);
            $last = $this->clean($last);
```
to:
```php
        $addAlias = function ($type, $first, $last) use (&$aliases) {
            $first = $this->cleanName($first);
            $last = $this->cleanName($last);
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/StreamlineLocalConnectorTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**
```bash
git add app/GoldenProfile/Connectors/StreamlineLocalConnector.php config/golden_profile.php tests/Unit/StreamlineLocalConnectorTest.php
git commit -m "feat(gp): screen INFORMATION-NOT-AVAILABLE-style junk name values at ingestion"
```

---

## Task 7: Quarantine rows with no identifying data at all, in both ingestion paths

**Files:**
- Create: `database/migrations/2026_09_04_000001_create_gp_quarantine.php`
- Create: `app/GoldenProfile/Support/QuarantineRecorder.php`
- Modify: `app/GoldenProfile/Connectors/StreamlineLocalConnector.php:82-124` (`ingest()`, `rebuildChildren()`)
- Modify: `app/GoldenProfile/SqlBackfill.php:152-244` (`stage()`)
- Modify: `app/GoldenProfile/Engine.php:139,529` (`backfill()`, `sync()` — `ingest()` return handling)
- Test: `tests/Feature/QuarantineGateTest.php`

**Interfaces:**
- Produces: `StreamlineLocalConnector::ingest()` now returns `?int` (was `int`) — `null` means the
  row was quarantined and never staged. Both call sites in `Engine.php` are updated in this task.

The Delivery Checklist calls for "quality gates — per-source schema/format checks plus shared
rules (valid / complete / unique / consistent / fresh) with quarantine + alert," and today gp-cami
has four scattered `Log::` calls and a runtime table (`gp_ssn_hash_blocklist`) created ad hoc by
code rather than a migration. This task adds one real table and wires one concrete, narrow rule
into it: a row that, after junk-cleaning (Tasks 3 and 6), has no name, no valid NPI, no SSN hash,
no DEA number, and no license at all carries nothing a resolver can act on — it is not "a person
with thin data," it is unmatchable noise, and today it silently becomes its own residual identity
forever. "Alert" is made concrete rather than aspirational: a `gp_quarantine` row a dashboard can
query, plus a `Log::critical()` call with a fixed, greppable prefix
(`GP_QUARANTINE_ALERT`) so log-based alerting (CloudWatch or otherwise) can filter on it — the
same mechanism this codebase already uses for its existing `Log::warning`/`Log::error` calls, just
at `critical` because data silently never reaching the hub is worse than a log line nobody reads.

This narrow "no identifying data whatsoever" rule is deliberate, not a stand-in for the full
valid/complete/unique/consistent/fresh gate the checklist describes — see Self-review for what
that fuller gate would need and why it is not attempted here.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use App\GoldenProfile\SqlBackfill;
use Tests\Support\HubTestCase;

/**
 * A row with nothing to resolve on — no name, no npi, no ssn, no dea, no
 * license, all after junk-cleaning — must be quarantined rather than silently
 * staged into its own meaningless residual identity, in BOTH ingestion paths.
 */
class QuarantineGateTest extends HubTestCase
{
    private function emptyEmployee(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 9001,
            'employeelist_id' => null,
            'first_name' => null,
            'middle_name' => null,
            'last_name' => null,
            'date_of_birth' => null,
            'ssn_hash' => null,
            'ssn_last_four' => null,
            'npi' => null,
            'upin' => null,
            'address1' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'terminated' => 0,
            'date_modified' => null,
        ], $overrides);
    }

    public function test_a_row_with_nothing_identifying_is_quarantined_not_staged(): void
    {
        $connector = new StreamlineLocalConnector($this->systemId);

        $stgId = $connector->ingest($this->emptyEmployee());

        $this->assertNull($stgId);
        $this->assertDatabaseHas('gp_quarantine', [
            'system_id' => $this->systemId, 'source_table' => 'employees',
            'source_id' => 9001, 'reason' => 'no_identifying_data',
        ]);
        $this->assertSame(0, $this->hub()->table('stg_person')
            ->where(['system_id' => $this->systemId, 'source_id' => 9001])->count());
    }

    public function test_a_row_with_only_a_last_name_is_not_quarantined(): void
    {
        $connector = new StreamlineLocalConnector($this->systemId);

        $stgId = $connector->ingest($this->emptyEmployee(['id' => 9002, 'last_name' => 'Okafor']));

        $this->assertNotNull($stgId);
        $this->assertSame(0, $this->hub()->table('gp_quarantine')->where('source_id', 9002)->count());
    }

    public function test_sql_backfill_stage_quarantines_the_same_shape_of_row(): void
    {
        // Stage directly against a fabricated stg_person row shaped like the
        // connector would have produced for an empty employee, using the same
        // predicate SqlBackfill::stage() applies — see Step 3's wiring.
        $backfill = new SqlBackfill;
        $reflection = new \ReflectionClass($backfill);
        $method = $reflection->getMethod('shouldQuarantine');
        $method->setAccessible(true);

        $emptyRow = ['first_name' => null, 'last_name' => null, 'npi' => null,
            'ssn_hash' => null, 'dea_number' => null];

        $this->assertTrue($method->invoke($backfill, $emptyRow, []));
        $this->assertFalse($method->invoke($backfill, $emptyRow + ['last_name' => 'Okafor'], []));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/QuarantineGateTest.php`
Expected: FAIL — `gp_quarantine` table does not exist yet.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_04_000001_create_gp_quarantine.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quality-gate quarantine (Delivery Checklist §2/§3: "quarantine + alert").
 * A row that fails a data-quality gate is recorded here instead of the four
 * ad-hoc Log:: calls (or the runtime-created gp_ssn_hash_blocklist table)
 * gp-cami used before this. One row per offending source record, upserted —
 * re-ingesting the same still-bad row updates quarantined_at rather than
 * accumulating duplicates.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        Schema::connection($this->connection)->create('gp_quarantine', function (Blueprint $t) {
            $t->bigIncrements('quarantine_id');
            $t->unsignedSmallInteger('system_id');
            $t->string('source_table', 64);
            $t->unsignedBigInteger('source_id');
            $t->string('reason', 64);
            $t->json('detail')->nullable();
            $t->dateTime('quarantined_at');
            $t->dateTime('resolved_at')->nullable();
            $t->unique(['system_id', 'source_table', 'source_id'], 'uq_quarantine_source');
            $t->index('reason', 'idx_reason');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('gp_quarantine');
    }
};
```

- [ ] **Step 4: Write `QuarantineRecorder`**

Create `app/GoldenProfile/Support/QuarantineRecorder.php`:

```php
<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a staged-person row (post junk-cleaning) has any usable
 * identity signal at all, and records + alerts on the ones that don't.
 *
 * "Alert" is concrete, not aspirational: a queryable gp_quarantine row for a
 * dashboard, plus a Log::critical() with a fixed, greppable message prefix
 * (GP_QUARANTINE_ALERT) so CloudWatch-style log-based alerting can filter on
 * it — the same mechanism this codebase's existing Log::warning/Log::error
 * calls already rely on, just at critical severity because silently never
 * staging a row is worse than a log line nobody reads.
 */
class QuarantineRecorder
{
    /**
     * @param  array<string,mixed>  $personRow  the array personRow()/stage() would insert
     * @param  list<array<string,mixed>>  $licenses  that row's license child rows
     */
    public function evaluate(array $personRow, array $licenses): ?string
    {
        $hasIdentifyingData = ! empty($personRow['last_name'])
            || ! empty($personRow['first_name'])
            || ! empty($personRow['npi'])
            || ! empty($personRow['ssn_hash'])
            || ! empty($personRow['dea_number'])
            || $licenses !== [];

        return $hasIdentifyingData ? null : 'no_identifying_data';
    }

    public function record(int $systemId, string $sourceTable, int $sourceId, string $reason, array $detail = []): void
    {
        DB::connection(config('golden_profile.connections.hub', 'golden_profile'))
            ->table('gp_quarantine')
            ->updateOrInsert(
                ['system_id' => $systemId, 'source_table' => $sourceTable, 'source_id' => $sourceId],
                ['reason' => $reason, 'detail' => json_encode($detail), 'quarantined_at' => now()]
            );

        Log::critical("GP_QUARANTINE_ALERT: row quarantined ($reason)", [
            'system_id' => $systemId, 'source_table' => $sourceTable, 'source_id' => $sourceId,
        ]);
    }
}
```

- [ ] **Step 5: Wire into `StreamlineLocalConnector::ingest()`**

Change the method signature and body (lines 82-101):

```php
    public function ingest(object $emp, ?array $accountMap = null): ?int
    {
        $row = $this->personRow($emp, $accountMap);
        $children = $this->childRows($emp);

        $reason = (new QuarantineRecorder)->evaluate($row, $children['licenses']);
        if ($reason !== null) {
            (new QuarantineRecorder)->record($this->systemId, self::SOURCE_TABLE, (int) $emp->id, $reason);

            return null;
        }

        // Select-first instead of updateOrInsert: on a fresh load the common
        // path is a brand-new row, and knowing it's new lets us skip the three
        // child-table deletes (nothing to delete) and the id re-select.
        $key = ['system_id' => $this->systemId, 'source_table' => self::SOURCE_TABLE, 'source_id' => $emp->id];
        $stgId = (int) $this->hub()->table('stg_person')->where($key)->value('stg_person_id');
        $isNew = $stgId === 0;

        if ($isNew) {
            $stgId = (int) $this->hub()->table('stg_person')->insertGetId($row);
        } else {
            $this->hub()->table('stg_person')->where($key)->update($row);
        }

        $this->rebuildChildren($stgId, $emp, $isNew, $children);

        return $stgId;
    }
```

Add the import at the top:
```php
use App\GoldenProfile\Support\QuarantineRecorder;
```

Update `rebuildChildren()`'s signature to accept the already-computed `$children` (avoids
recomputing `childRows($emp)` a second time) — change line 105 from:
```php
    private function rebuildChildren(int $stgId, object $emp, bool $isNew = false): void
    {
        $hub = $this->hub();
        // A freshly inserted staged person has no children yet — skip the
        // three (empty) deletes that dominate the fresh-load per-row cost.
        if (! $isNew) {
            $hub->table('stg_person_alias')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_address')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_license')->where('stg_person_id', $stgId)->delete();
        }

        $c = $this->childRows($emp);
        foreach (['stg_person_alias' => 'aliases', 'stg_person_address' => 'addresses', 'stg_person_license' => 'licenses'] as $table => $bucket) {
```
to:
```php
    private function rebuildChildren(int $stgId, object $emp, bool $isNew = false, ?array $children = null): void
    {
        $hub = $this->hub();
        // A freshly inserted staged person has no children yet — skip the
        // three (empty) deletes that dominate the fresh-load per-row cost.
        if (! $isNew) {
            $hub->table('stg_person_alias')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_address')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_license')->where('stg_person_id', $stgId)->delete();
        }

        $c = $children ?? $this->childRows($emp);
        foreach (['stg_person_alias' => 'aliases', 'stg_person_address' => 'addresses', 'stg_person_license' => 'licenses'] as $table => $bucket) {
```
(This keeps `rebuildChildren($stgId, $emp, $isNew)` — the 3-argument call — working unchanged for
any other caller; Task 8 will add a 5th "identifiers" bucket to this same loop.)

- [ ] **Step 6: Wire the same predicate into `SqlBackfill::stage()`**

Add the import and property (mirroring `ssnGuard`):
```php
use App\GoldenProfile\Support\QuarantineRecorder;
```
```php
    private QuarantineRecorder $quarantine;
```
In the constructor, after `$this->ssnGuard = new SsnHashGuard;`:
```php
        $this->quarantine = new QuarantineRecorder;
```

Add a private helper (used by both `stage()` below and the reflection-based test in Step 1):
```php
    /**
     * Same rule QuarantineRecorder::evaluate() applies per-row — duplicated as
     * a plain array/array check (no object hydration) because stage() already
     * has $persons/$licenses as arrays at exactly the point this needs to run,
     * and going through QuarantineRecorder's instance method there would mean
     * constructing one row-shaped array just to pass it to another. Kept
     * behaviourally identical on purpose; QuarantineRecorder::evaluate()'s own
     * five-condition check is the source of truth this mirrors.
     */
    private function shouldQuarantine(array $personRow, array $licenses): bool
    {
        return $this->quarantine->evaluate($personRow, $licenses) !== null;
    }
```

In `stage()`'s chunk closure, the `$persons` array is built at lines 170-173:
```php
            $persons = [];
            foreach ($rows as $emp) {
                $persons[] = $this->connector->personRow($emp, $accountMap);
            }
```
Change this to also compute each row's licenses (needed for the same predicate) and split
offending rows out before the bulk insert:

```php
            $persons = [];
            $quarantined = [];
            foreach ($rows as $emp) {
                $row = $this->connector->personRow($emp, $accountMap);
                $rowLicenses = $this->connector->childRows($emp)['licenses'];
                if ($this->shouldQuarantine($row, $rowLicenses)) {
                    $quarantined[] = $emp->id;
                    $this->quarantine->record($this->systemId, self::SOURCE_TABLE, (int) $emp->id, 'no_identifying_data');

                    continue;
                }
                $persons[] = $row;
            }
```

And change the `$rows` used for the rest of the chunk (children staging, mirroring) to skip
quarantined ids — after the `insertOrIgnore` transaction (which now only inserts `$persons`,
already excluding quarantined rows), change the `foreach ($rows as $emp)` loop building aliases/
addresses/licenses/identifiers (lines 194-222) to skip them:
```php
            foreach ($rows as $emp) {
                if (in_array($emp->id, $quarantined, true)) {
                    continue;
                }
                $sid = $ids[$emp->id] ?? null;
```
(only the opening of that loop changes; the body is unchanged.)

- [ ] **Step 7: Handle the new `?int` return in `Engine.php`**

In `Engine::backfill()` (around line 139) and `Engine::sync()` (around line 529), both currently:
```php
                $stgId = $this->connector->ingest($emp, $accountMap);
                $identityIds[$this->resolver->resolve($stgId)] = true;
```
Change both occurrences to:
```php
                $stgId = $this->connector->ingest($emp, $accountMap);
                if ($stgId === null) {
                    continue; // quarantined — nothing to resolve
                }
                $identityIds[$this->resolver->resolve($stgId)] = true;
```

- [ ] **Step 8: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/QuarantineGateTest.php`
Expected: PASS (3 tests)

- [ ] **Step 9: Re-run the eval gate**

Run: `vendor/bin/phpunit --filter=EvalGateTest`
Expected: PASS, unchanged — every fixture record carries at least a last name, so nothing in the
fixture is quarantinable; this proves the gate is a no-op against current fixture data, not that
it works (Step 8's dedicated test proves that).

- [ ] **Step 10: Commit**
```bash
git add database/migrations/2026_09_04_000001_create_gp_quarantine.php app/GoldenProfile/Support/QuarantineRecorder.php app/GoldenProfile/Connectors/StreamlineLocalConnector.php app/GoldenProfile/SqlBackfill.php app/GoldenProfile/Engine.php tests/Feature/QuarantineGateTest.php
git commit -m "feat(gp): quarantine rows with no identifying data, in both ingestion paths"
```

---

## Task 8: Carry `state` through the DEA/MMIS identifier pipeline; fix the per-row staging gap

**Files:**
- Create: `database/migrations/2026_09_04_000000_add_state_to_identifiers.php`
- Modify: `app/GoldenProfile/Connectors/StreamlineLocalConnector.php:82-124,197-253`
- Modify: `app/GoldenProfile/SqlBackfill.php:187-221`
- Test: `tests/Unit/StreamlineLocalConnectorTest.php` (add tests)

**Interfaces:**
- Produces: `additionalRows(iterable $aiRows, ?string $state = null): array` (signature change —
  both call sites updated in this task) — MMIS identifiers now carry the person's state; DEA stays
  national (`state` always null, since DEA registration is federal, not state-issued).

Two findings from reading the connector and `Engine.php` closely, both load-bearing for Task 9:

1. **The per-row path never stages identifiers at all.** `StreamlineLocalConnector::ingest()`
   (used by `Engine::backfill()`/`sync()`) calls `rebuildChildren()`, which stages aliases,
   addresses, and licenses — but never fetches `employee_additional_info` or calls
   `additionalRows()`. Only `SqlBackfill::stage()` (the bulk path) does that today. Promoting
   DEA/MMIS to a real-time resolver tier (Task 9) on the per-row path is pointless while the
   per-row path never populates `stg_person_identifier` in the first place — this must be fixed
   first.
2. **`(state, medicaid id)` and `(state, provider#)` collapse onto one field in this data
   source.** The GPP wiki's two pages use different names for what streamline_local actually
   supplies: `employee_additional_info`'s `mmis_number` is a Medicaid provider identifier, and
   MMIS numbers are inherently state-scoped (the same provider can hold a different MMIS number
   per state Medicaid program) — so "(state, medicaid id)" is exactly "state + mmis_number."
   `"(state, provider#)"` as a *distinct* field does not exist anywhere in streamline_local:
   grepping the client codebase shows `provider_number` is a field on state exclusion-registry
   source tables (e.g. `nyomig_records`), used by CAMI's own exclusion-matching, and it is never
   mirrored into gp-cami at all (`SqlBackfill::mirrorSource()`'s `src_exclusion_record` carries
   only `id` and `exclusion_list_prefix`). Per plan 1's own precedent for reconciling the two doc
   sets ("these plans conform to the semantics... rather than renaming 17 tables"), this plan
   treats MMIS+state as satisfying both spec keys. See Self-review for the honest statement that
   `(state, provider#)` as literally described has no data source in scope.

`stg_person_identifier`/`gp_identity_identifier` gain a nullable `state` column — informational on
the *storage* row (not part of either table's uniqueness), used as the *matching* predicate in
Task 9. This split matters: if `state` were added to `gp_identity_identifier`'s unique key,
MySQL's NULL-is-distinct behaviour would silently stop de-duplicating DEA rows (which always carry
`state = NULL`), since two NULLs never violate a unique constraint. Keeping the existing
`(identity_id, id_type, id_value)` key and using `state` only in the match `WHERE` clause avoids
that trap while still letting Task 9 correctly refuse to merge two different states' identical
MMIS number.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/StreamlineLocalConnectorTest.php`:

```php
    public function test_additional_rows_attaches_state_to_mmis_but_not_dea(): void
    {
        $rows = [
            (object) ['name' => 'mmis_number', 'value' => 'MMIS-4471'],
            (object) ['name' => 'dea_number', 'value' => 'AH1234563'],
        ];

        $extra = $this->connector()->additionalRows($rows, 'CA');

        $byType = collect($extra['identifiers'])->keyBy('id_type');
        $this->assertSame('CA', $byType['mmis']['state']);
        $this->assertArrayNotHasKey('state', $byType['dea']);
    }

    public function test_additional_rows_with_no_state_leaves_mmis_state_null(): void
    {
        $rows = [(object) ['name' => 'mmis_number', 'value' => 'MMIS-4471']];

        $extra = $this->connector()->additionalRows($rows);

        $this->assertNull($extra['identifiers'][0]['state']);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/StreamlineLocalConnectorTest.php --filter=test_additional_rows_attaches_state_to_mmis_but_not_dea`
Expected: FAIL — `additionalRows()` does not accept a second argument / the returned identifier
array has no `state` key.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_04_000000_add_state_to_identifiers.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MMIS numbers are state-scoped (the same provider can hold a different MMIS
 * number per state Medicaid program); DEA numbers are federal and never carry
 * a state. This column is informational on the storage row, not part of
 * either table's uniqueness — see this plan's Task 8 for why adding it to
 * gp_identity_identifier's unique key would silently break DEA de-duplication
 * (NULL is never equal to NULL in a MySQL unique constraint). The MATCHING
 * predicate that uses this column lives in DeterministicResolver and
 * Engine::mergeByIdentifier (Task 9), not in a constraint here.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        $s->table('stg_person_identifier', function (Blueprint $t) {
            $t->string('state', 65)->nullable()->after('id_value');
        });
        $s->table('gp_identity_identifier', function (Blueprint $t) {
            $t->string('state', 65)->nullable()->after('id_value');
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        $s->table('stg_person_identifier', fn (Blueprint $t) => $t->dropColumn('state'));
        $s->table('gp_identity_identifier', fn (Blueprint $t) => $t->dropColumn('state'));
    }
};
```

- [ ] **Step 4: Update `additionalRows()`**

In `StreamlineLocalConnector.php`, change the signature and MMIS branch (lines 197-220):

```php
    public function additionalRows(iterable $aiRows, ?string $state = null): array
    {
        $state = $this->clean($state);
        $v = [];
        foreach ($aiRows as $r) {
            $name = is_array($r) ? ($r['name'] ?? null) : ($r->name ?? null);
            $val = is_array($r) ? ($r['value'] ?? null) : ($r->value ?? null);
            $val = $this->clean($val);
            if ($name !== null && $val !== null) {
                $v[$name] = $val;
            }
        }

        $identifiers = [];
        // DEA (match key, federal — never state-scoped).
        foreach (['dea_number', 'alt_dea_number', 'alt_license_dea_number_2', 'alt_license_dea_number_3',
            'alt_license_dea_number_4', 'alt_license_dea_number_5', 'alt_license_dea_number_6'] as $k) {
            if (! empty($v[$k])) {
                $identifiers[] = ['id_type' => 'dea', 'id_value' => $v[$k]];
            }
        }
        // MMIS (match key, state-scoped — see this plan's Task 8 for why
        // "(state, medicaid id)" and "(state, provider#)" both resolve to this
        // one field in streamline_local).
        if (! empty($v['mmis_number'])) {
            $identifiers[] = ['id_type' => 'mmis', 'id_value' => $v['mmis_number'], 'state' => $state];
        }
```

(the rest of the method — licenses, aliases, return — is unchanged)

Update the docblock above `additionalRows()` (lines ~183-196) to mention the new parameter — add
a line: `` `$state` is the employee's own state (personRow()'s already-cleaned value), attached to
MMIS identifiers only. ``

- [ ] **Step 5: Fix the per-row staging gap — `rebuildChildren()` now also stages identifiers**

Building on Task 7's `rebuildChildren($stgId, $emp, $isNew, $children)` signature, extend it to
also fetch and stage identifiers. Change the method body:

```php
    private function rebuildChildren(int $stgId, object $emp, bool $isNew = false, ?array $children = null): void
    {
        $hub = $this->hub();
        // A freshly inserted staged person has no children yet — skip the
        // four (empty) deletes that dominate the fresh-load per-row cost.
        if (! $isNew) {
            $hub->table('stg_person_alias')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_address')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_license')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_identifier')->where('stg_person_id', $stgId)->delete();
        }

        $c = $children ?? $this->childRows($emp);

        // employee_additional_info (DEA/MMIS + extra licenses/aliases) — the
        // set-based backfill (SqlBackfill::stage()) has always fetched this
        // per chunk; the per-row path never did, so DEA/MMIS identifiers were
        // silently invisible to gp:sync/Engine::backfill() until this fix.
        $aiRows = $this->src()->table('employee_additional_info')
            ->where('employee_id', $emp->id)->where('value', '<>', '')
            ->get(['name', 'value']);
        $extra = $this->additionalRows($aiRows, $emp->state ?? null);
        $c['aliases'] = array_merge($c['aliases'], $extra['aliases']);
        $c['licenses'] = array_merge($c['licenses'], $extra['licenses']);
        $c['identifiers'] = $extra['identifiers'];

        foreach (['stg_person_alias' => 'aliases', 'stg_person_address' => 'addresses',
            'stg_person_license' => 'licenses', 'stg_person_identifier' => 'identifiers'] as $table => $bucket) {
            if ($c[$bucket] ?? null) {
                $hub->table($table)->insert(array_map(
                    fn ($r) => $r + ['stg_person_id' => $stgId], $c[$bucket]
                ));
            }
        }
    }
```

This one extra `employee_additional_info` query per ingested row mirrors exactly what
`SqlBackfill::stage()` already does per chunk (just per-row instead of batched) — `ingest()` is
not called from any hot bulk path (that is `SqlBackfill::stage()`'s job), only from `gp:sync`'s
incremental, already-slow-by-design per-row loop, so the added round trip does not change its
performance character.

Note on test coverage: like the rest of `ingest()`, this new query goes through `src()`, which
`phpunit.xml` deliberately points at a dead socket (`SRC_DB_HOST=127.0.0.1:1`) so no test can
reach a real `streamline_local`. `ingest()`/`rebuildChildren()` have never had direct test
coverage for this reason — Step 1's tests exercise the DB-free `additionalRows()` logic this
change relies on; the wiring itself is verified by code review and by Task 9's
`IdentifierTierParityTest`, which stages identifier rows the way this method now would and checks
what the resolver does with them.

- [ ] **Step 6: Update `SqlBackfill`'s `additionalRows()` call site to pass state**

In `SqlBackfill.php`, line 211:
```php
                    $extra = $this->connector->additionalRows($aiByEmp[$emp->id]);
```
change to:
```php
                    $extra = $this->connector->additionalRows($aiByEmp[$emp->id], $emp->state ?? null);
```

- [ ] **Step 7: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/StreamlineLocalConnectorTest.php`
Expected: PASS (9 tests)

- [ ] **Step 8: Commit**
```bash
git add database/migrations/2026_09_04_000000_add_state_to_identifiers.php app/GoldenProfile/Connectors/StreamlineLocalConnector.php app/GoldenProfile/SqlBackfill.php tests/Unit/StreamlineLocalConnectorTest.php
git commit -m "feat(gp): carry state through DEA/MMIS identifiers; stage identifiers per-row too"
```

---

## Task 9: Promote DEA and (state, MMIS) to real match keys

**Files:**
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php:44-116,236-303`
- Modify: `app/GoldenProfile/SqlBackfill.php:411-423`
- Modify: `app/GoldenProfile/Engine.php:355-380`
- Modify: `config/golden_profile.php:26-33`
- Modify: `tests/Unit/DeterministicKeyConfigTest.php`
- Test: `tests/Feature/IdentifierTierParityTest.php`

**Interfaces:**
- Produces: two new `deterministic_keys` config entries (`dea_multi`, `mmis+state`); a new
  real-time tier in `DeterministicResolver::matchDeterministic()`; `gp_identity_identifier` rows
  written by the per-row `enrich()` (previously only the bulk path wrote them);
  `Engine::mergeByIdentifier()` now state-scoped.

`gp_identity_identifier` (DEA, MMIS) is already staged and already merges identities that share a
value — but only *after the fact*, via `Engine::mergeByIdentifier()` during `dedup()`, which is
called only from `SqlBackfill::transform()`. It is not a Pass A tier, so two source rows sharing a
DEA/MMIS number that reach `DeterministicResolver::resolve()` (the per-row path — `gp:sync`) never
bind to each other in real time; they mint two identities that stay split until someone runs a
bulk `dedup()` pass.

**Why this plan does NOT give `SqlBackfill` a matching create-and-link tier for identifiers.**
`resolveDeterministic()`'s own comment already explains why license is handled this way instead of
as a tier: *"license resolution is handled after enrich(), by dedup's mergeByLicense — it needs
gp_license populated, which enrich() does. Doing it as a resolve tier would chicken-and-egg (a
fresh license identity has no linked row to match against yet)."* The identical problem applies to
`gp_identity_identifier`: it is populated by `enrich()`, which runs *after*
`resolveDeterministic()` in `transform()`'s sequence (stage → resolve → enrich → dedup → rollup),
so a set-based identifier tier at resolve time would have nothing to join against. This plan
follows the existing, deliberate precedent rather than inventing a second, inconsistent design:
DEA/MMIS join license in being real-time bind keys on the **per-row** path (where each row is
processed sequentially, so an earlier row's `enrich()` has already run by the time a later row is
resolved — no chicken-and-egg) and dedup-time consolidation keys on the **bulk** path. Both paths
converge to the same end state — two rows sharing a DEA number end up on one identity either way —
by different, equally legitimate mechanisms; `IdentifierTierParityTest` proves the convergence,
not that the mechanisms are identical.

What DOES change in `SqlBackfill` and `Engine` for this task: `SqlBackfill::enrich()`'s existing
`INSERT INTO gp_identity_identifier` statement and `Engine::mergeByIdentifier()`'s grouping both
need the new `state` column threaded through, or MMIS-4471/CA and MMIS-4471/TX would incorrectly
merge — this is the parity work this task actually owns.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/IdentifierTierParityTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\SqlBackfill;
use Tests\Support\HubTestCase;

class IdentifierTierParityTest extends HubTestCase
{
    private function stageIdentifier(int $stgPersonId, string $type, string $value, ?string $state = null): void
    {
        $this->hub()->table('stg_person_identifier')->insert([
            'stg_person_id' => $stgPersonId, 'id_type' => $type, 'id_value' => $value, 'state' => $state,
        ]);
    }

    public function test_per_row_resolver_binds_two_rows_sharing_state_scoped_mmis_in_real_time(): void
    {
        $a = $this->stagePerson(['first_name' => 'Linda', 'last_name' => 'Park', 'date_of_birth' => '1985-02-20']);
        $this->stageIdentifier($a, 'mmis', 'MMIS-4471', 'CA');
        $b = $this->stagePerson(['first_name' => 'Lynda', 'last_name' => 'Park', 'date_of_birth' => null]);
        $this->stageIdentifier($b, 'mmis', 'MMIS-4471', 'CA');

        $resolver = new DeterministicResolver($this->systemId);
        $idA = $resolver->resolve($a);
        $idB = $resolver->resolve($b);

        $this->assertSame($idA, $idB, 'same mmis + same state must bind in real time');
    }

    public function test_per_row_resolver_does_not_bind_the_same_mmis_across_different_states(): void
    {
        $a = $this->stagePerson(['first_name' => 'Linda', 'last_name' => 'Park', 'date_of_birth' => '1985-02-20']);
        $this->stageIdentifier($a, 'mmis', 'MMIS-4471', 'CA');
        $b = $this->stagePerson(['first_name' => 'Linda', 'last_name' => 'Park', 'date_of_birth' => '1991-08-02']);
        $this->stageIdentifier($b, 'mmis', 'MMIS-4471', 'TX');

        $resolver = new DeterministicResolver($this->systemId);
        $idA = $resolver->resolve($a);
        $idB = $resolver->resolve($b);

        $this->assertNotSame($idA, $idB, 'the same mmis number in a different state must not bind');
    }

    public function test_per_row_resolver_binds_shared_dea_with_no_state_at_all(): void
    {
        $a = $this->stagePerson(['first_name' => 'Omar', 'last_name' => 'Haddad', 'date_of_birth' => '1977-09-09']);
        $this->stageIdentifier($a, 'dea', 'AH1234563');
        $b = $this->stagePerson(['first_name' => 'Omar', 'last_name' => 'Haddad', 'date_of_birth' => null]);
        $this->stageIdentifier($b, 'dea', 'AH1234563');

        $resolver = new DeterministicResolver($this->systemId);
        $this->assertSame($resolver->resolve($a), $resolver->resolve($b));
    }

    public function test_bulk_path_converges_to_one_identity_via_enrich_plus_dedup(): void
    {
        $a = $this->stagePerson(['first_name' => 'Omar', 'last_name' => 'Haddad', 'date_of_birth' => '1977-09-09']);
        $this->stageIdentifier($a, 'dea', 'AH1234563');
        $b = $this->stagePerson(['first_name' => 'Omar', 'last_name' => 'Haddad', 'date_of_birth' => null]);
        $this->stageIdentifier($b, 'dea', 'AH1234563');

        // Simulate what SqlBackfill::transform() does, minus staging (already
        // staged above): resolve (no identifier tier fires here because these
        // two rows are unrelated by any OTHER key, so each mints its own
        // identity) -> enrich (populates gp_identity_identifier) -> dedup.
        $backfill = new SqlBackfill;
        $backfill->resolveDeterministic();
        $this->assertSame(2, $this->hub()->table('gp_identity')->where('status', 'active')->count(),
            'sanity: without the identifier tier the bulk resolve step alone must NOT merge these');

        $backfill->enrich();
        $merged = (new Engine)->dedup();

        $this->assertGreaterThan(0, $merged);
        $this->assertSame(1, $this->hub()->table('gp_identity')->where('status', 'active')->count());
    }

    public function test_bulk_path_does_not_converge_across_different_states(): void
    {
        $a = $this->stagePerson(['first_name' => 'Linda', 'last_name' => 'Park', 'date_of_birth' => '1985-02-20']);
        $this->stageIdentifier($a, 'mmis', 'MMIS-4471', 'CA');
        $b = $this->stagePerson(['first_name' => 'Linda', 'last_name' => 'Park', 'date_of_birth' => '1991-08-02']);
        $this->stageIdentifier($b, 'mmis', 'MMIS-4471', 'TX');

        $backfill = new SqlBackfill;
        $backfill->resolveDeterministic();
        $backfill->enrich();
        (new Engine)->dedup();

        $this->assertSame(2, $this->hub()->table('gp_identity')->where('status', 'active')->count(),
            'different states sharing an mmis number must not converge even after dedup');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/IdentifierTierParityTest.php`
Expected: FAIL on the three `DeterministicResolver`-based tests (no identifier tier exists yet);
PASS on the two bulk-path tests only by coincidence of the state-scoping assertion (since
`mergeByIdentifier` doesn't scope by state yet, `test_bulk_path_does_not_converge_across_different_states`
should also currently FAIL — both CA/TX rows merge today).

- [ ] **Step 3: Add the two new config entries**

In `config/golden_profile.php`, change the `deterministic_keys` block (lines 26-33):

```php
    'deterministic_keys' => [
        'ssn_hash' => 0.99,
        'npi' => 0.99,
        // Vestigial for this source: stg_person.dea_number is always null from
        // StreamlineLocalConnector (DEA is multi-valued in this source, staged
        // into stg_person_identifier instead — see dea_multi below). Left
        // configured rather than removed since gp_identity.dea_number and this
        // tier are harmless dead code, not a bug to fix in this plan.
        'dea_number' => 0.99,
        'upin' => 0.99,
        // Multi-valued identifiers from gp_identity_identifier (Task 9). DEA is
        // federal (never state-scoped); MMIS is inherently state-scoped — see
        // the identifier tier in DeterministicResolver::matchDeterministic().
        'dea_multi' => 0.99,
        'mmis+state' => 0.99,
        'license_number+certification_state' => 0.99,
        'name+dob' => 0.95,
    ],
```

- [ ] **Step 4: Add the real-time identifier tier to `DeterministicResolver`**

In `resolve()` (line 50), fetch identifiers alongside licenses:
```php
        $licenses = $hub->table('stg_person_license')->where('stg_person_id', $stgPersonId)->get();
        $identifiers = $hub->table('stg_person_identifier')->where('stg_person_id', $stgPersonId)->get();
```

Update every call this method makes that currently passes only `$licenses` to also pass
`$identifiers`: line 63 (`$this->enrich($identityId, $p, $licenses, (int) $existing->link_id);`),
line 69 (`[$identityId, $key, $conf] = $this->matchDeterministic($p, $licenses);`), and line 113
(`$this->enrich($identityId, $p, $licenses, $linkId);`) — each becomes:
```php
                $this->enrich($identityId, $p, $licenses, (int) $existing->link_id, $identifiers);
```
```php
        [$identityId, $key, $conf] = $this->matchDeterministic($p, $licenses, $identifiers);
```
```php
        $this->enrich($identityId, $p, $licenses, $linkId, $identifiers);
```

Update `matchDeterministic()`'s signature (line 119) and add the new tier right after the `upin`
branch (after line 160, before the license `foreach` at line 161):
```php
    private function matchDeterministic(object $p, $licenses, $identifiers = []): array
    {
```
```php
        // Multi-valued identifiers (DEA, MMIS). Real-time here because each
        // row is resolved sequentially — by the time THIS row is resolved,
        // every earlier row's enrich() call (below) has already written any
        // identifier it carried into gp_identity_identifier, so there is no
        // chicken-and-egg the way there would be for a set-based bulk tier
        // (see this task's docblock, and the existing comment in
        // SqlBackfill::resolveDeterministic() about why license works the
        // same way). MMIS is scoped by state; DEA (federal) is not.
        foreach ($identifiers as $ident) {
            $q = $hub->table('gp_identity_identifier as l')
                ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
                ->where('i.status', 'active')
                ->where('l.id_type', $ident->id_type)
                ->where('l.id_value', $ident->id_value);
            if ($ident->id_type === 'mmis') {
                $ident->state === null ? $q->whereNull('l.state') : $q->where('l.state', $ident->state);
            }
            $id = $q->orderBy('l.identity_id')->value('l.identity_id');
            if ($id) {
                $confKey = $ident->id_type === 'mmis' ? 'mmis+state' : 'dea_multi';

                return [(int) $id, $confKey, $this->confidence($confKey, 0.99)];
            }
        }
```

Update `enrich()`'s signature (line 264) and add an identifier upsert loop, so the per-row path
starts populating `gp_identity_identifier` (today only `SqlBackfill::enrich()` does):
```php
    private function enrich(int $identityId, object $p, $licenses, int $linkId, $identifiers = []): void
    {
        $hub = $this->hub();

        foreach ($identifiers as $ident) {
            $hub->table('gp_identity_identifier')->updateOrInsert(
                ['identity_id' => $identityId, 'id_type' => $ident->id_type, 'id_value' => $ident->id_value],
                ['state' => $ident->state, 'source_link_id' => $linkId],
            );
        }

        foreach ($licenses as $lic) {
```
(the rest of `enrich()` is unchanged — just the new loop inserted before the existing license
loop)

- [ ] **Step 5: Thread `state` through `SqlBackfill::enrich()`**

In `SqlBackfill.php`'s `enrich()` method, the `gp_identity_identifier` INSERT (lines 413-422):
```php
        $this->hub()->statement(
            'INSERT INTO gp_identity_identifier (identity_id, id_type, id_value, source_link_id)
             SELECT l.identity_id, spi.id_type, spi.id_value, MIN(l.link_id)
             FROM stg_person_identifier spi
             JOIN stg_person sp ON sp.stg_person_id = spi.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spi.id_type, spi.id_value
             ON DUPLICATE KEY UPDATE source_link_id=VALUES(source_link_id)',
            []
        );
```
change to:
```php
        // state is not part of the unique key (identity_id, id_type, id_value)
        // — see this plan's Task 8 for why adding it there would break DEA
        // de-duplication. MAX(spi.state) picks a single deterministic value
        // when more than one staged row disagrees, same pattern already used
        // for MAX(spl.license_type) above.
        $this->hub()->statement(
            'INSERT INTO gp_identity_identifier (identity_id, id_type, id_value, state, source_link_id)
             SELECT l.identity_id, spi.id_type, spi.id_value, MAX(spi.state), MIN(l.link_id)
             FROM stg_person_identifier spi
             JOIN stg_person sp ON sp.stg_person_id = spi.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spi.id_type, spi.id_value
             ON DUPLICATE KEY UPDATE state=VALUES(state), source_link_id=VALUES(source_link_id)',
            []
        );
```

- [ ] **Step 6: State-scope `Engine::mergeByIdentifier()`**

Replace the method (lines 356-380):
```php
    /** Merge active identities sharing a multi-valued identifier (DEA, MMIS+state). */
    private function mergeByIdentifier(int $shard = 0, int $shards = 1): int
    {
        $hub = $this->hub();
        $n = 0;
        $q = $hub->table('gp_identity_identifier as gii')
            ->join('gp_identity as gi', 'gi.identity_id', '=', 'gii.identity_id')
            ->where('gi.status', 'active')
            ->select('gii.id_type', 'gii.id_value', 'gii.state');
        $q = $this->shardFilter($q, "CONCAT_WS('|',gii.id_type,gii.id_value,gii.state)", $shard, $shards);
        // gii.state is part of the GROUP BY (unlike the storage unique key,
        // which deliberately omits it — see Task 8): two different states'
        // identical MMIS number must never be treated as one match group, or
        // this pass would fold unrelated providers together.
        $groups = $q->groupBy('gii.id_type', 'gii.id_value', 'gii.state')
            ->havingRaw('COUNT(DISTINCT gii.identity_id) > 1')->get();
        foreach ($groups as $g) {
            $sub = $hub->table('gp_identity_identifier as gii')
                ->join('gp_identity as gi', 'gi.identity_id', '=', 'gii.identity_id')
                ->where('gi.status', 'active')
                ->where('gii.id_type', $g->id_type)->where('gii.id_value', $g->id_value);
            $sub = $g->state === null ? $sub->whereNull('gii.state') : $sub->where('gii.state', $g->state);
            $ids = $sub->orderBy('gii.identity_id')->distinct()->pluck('gii.identity_id')->all();
            $survivor = (int) array_shift($ids);
            foreach ($ids as $loser) {
                $n += $this->mergeIdentity($survivor, (int) $loser);
            }
        }

        return $n;
    }
```

- [ ] **Step 7: Update `DeterministicKeyConfigTest` for the two new tiers**

In `test_every_key_tier_has_a_configured_confidence()`, extend the tier list:
```php
        foreach (['ssn_hash', 'npi', 'dea_number', 'upin', 'dea_multi', 'mmis+state', 'license_number+certification_state', 'name+dob'] as $tier) {
```

In `test_name_dob_ranks_below_the_hard_identifier_tiers()`, extend the "strong" list:
```php
        foreach (['ssn_hash', 'npi', 'dea_number', 'upin', 'dea_multi', 'mmis+state'] as $strong) {
```

In `test_resolver_reads_config_rather_than_literals()`, the identifier tier adds exactly ONE new
`$this->confidence(` call site (it covers both `dea_multi` and `mmis+state` through the dynamic
`$confKey` variable) — update:
```php
        $this->assertSame(
            7,
            preg_match_all('/\$this->confidence\(/', $source),
            'the 6 original tiers plus the new multi-valued identifier tier (dea_multi/mmis+state) should be 7',
        );
```

`test_every_deterministic_tier_orders_before_taking_a_value()` needs no change — it is a generic
regex over `->orderBy('...identity_id')->value(` pairs, and Step 4's new query already ends in
`->orderBy('l.identity_id')->value('l.identity_id')`, matching the existing pattern the test
already accepts (the license tier uses the identical `l.identity_id` alias convention).

- [ ] **Step 8: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/IdentifierTierParityTest.php tests/Unit/DeterministicKeyConfigTest.php`
Expected: PASS (5 + 5 tests)

- [ ] **Step 9: Re-run the full suite and the eval gate**

Run: `vendor/bin/phpunit`
Expected: PASS, 0 skipped (with `GP_TEST_DB_*` configured).

Run: `vendor/bin/phpunit --filter=EvalGateTest`
Expected: PASS, unchanged — precision 1.0000, recall 1.0000, f1 1.0000, true_pairs 9, 0 false
merges, 0 false splits. The current fixture has no DEA/MMIS identifiers at all, so this tier
cannot fire against it yet; Task 10 adds fixture coverage that actually exercises it.

- [ ] **Step 10: Commit**
```bash
git add app/GoldenProfile/Resolution/DeterministicResolver.php app/GoldenProfile/SqlBackfill.php app/GoldenProfile/Engine.php config/golden_profile.php tests/Unit/DeterministicKeyConfigTest.php tests/Feature/IdentifierTierParityTest.php
git commit -m "feat(gp): promote DEA and state-scoped MMIS to real match keys"
```

---

## Task 10: Eval fixture coverage for the new keys, re-baseline, and close out open decisions

**Files:**
- Modify: `tests/eval/identity-pairs.json`
- Modify: `app/GoldenProfile/Eval/EvalSet.php`
- Modify: `app/GoldenProfile/Eval/EvalRunner.php`
- Modify: `tests/Feature/EvalGateTest.php`
- Modify: `docs/EVALUATION.md`

**Interfaces:**
- Produces: `EvalSet::identifiers(string $ref): array` (new method, mirrors `licenses()`).

The eval gate cannot exercise a key it has no fixture data for. This task adds the plumbing
(`EvalSet`/`EvalRunner` currently only know how to stage licenses alongside a person, not
identifiers) and four new fixture people: one true-merge pair via MMIS+state, one same-MMIS-
different-state control proving the state guard actually holds inside the harness (not just in
Task 9's own unit tests), one true-merge pair via DEA, and one same-name-different-DEA control —
following this fixture's own established pattern of pairing every merge case with a "looks similar,
is not the same person" foil (see `smith-other`, `garcia-other`, `kowalski-other`).

- [ ] **Step 1: Extend `EvalSet` and `EvalRunner` to stage identifiers**

In `app/GoldenProfile/Eval/EvalSet.php`, add a method mirroring `licenses()` (after that method):
```php
    /** @return list<array{id_type:string,id_value:string,state:?string}> */
    public function identifiers(string $ref): array
    {
        foreach ($this->records as $r) {
            if ($r['ref'] === $ref) {
                return $r['identifiers'] ?? [];
            }
        }

        return [];
    }
```

In `app/GoldenProfile/Eval/EvalRunner.php`, after the existing license-staging loop inside
`run()`'s `foreach ($set->records() as $r)` block, add:
```php
            foreach ($set->identifiers($ref) as $ident) {
                $this->hub()->table('stg_person_identifier')->insert([
                    'stg_person_id' => $stgByRef[$ref],
                    'id_type' => $ident['id_type'],
                    'id_value' => $ident['id_value'],
                    'state' => $ident['state'] ?? null,
                ]);
            }
```

- [ ] **Step 2: Write the failing test — extend `EvalGateTest`'s expectations**

`EvalGateTest` asserts fixed floors (`true_pairs >= 9`) and ratchets (`false_splits === 0`,
`recall === 1.0`) — `true_pairs` needs to rise to reflect the two new merge pairs this task adds.
Update the assertion in `tests/Feature/EvalGateTest.php` from:
```php
        $this->assertGreaterThanOrEqual(9, $report['true_pairs']);
```
to:
```php
        // 9 original true pairs (plan 1's baseline) + 1 mmis+state pair
        // (mmis-a/mmis-b) + 1 dea pair (dea-a/dea-b) = 11.
        $this->assertGreaterThanOrEqual(11, $report['true_pairs']);
```

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=EvalGateTest`
Expected: FAIL — `true_pairs` is still 9 (no new fixture records yet).

- [ ] **Step 4: Add the fixture records**

In `tests/eval/identity-pairs.json`, add to the `"records"` array (after the `ssn-b` record, before
the closing `]`):
```json
    { "ref": "mmis-a", "first_name": "Linda", "last_name": "Park", "date_of_birth": "1985-02-20", "npi": null,
      "identifiers": [{ "id_type": "mmis", "id_value": "MMIS-4471", "state": "CA" }] },
    { "ref": "mmis-b", "first_name": "Lynda", "last_name": "Park", "date_of_birth": null, "npi": null,
      "identifiers": [{ "id_type": "mmis", "id_value": "MMIS-4471", "state": "CA" }] },
    { "ref": "mmis-other-state", "first_name": "Linda", "last_name": "Park", "date_of_birth": "1991-08-02", "npi": null,
      "identifiers": [{ "id_type": "mmis", "id_value": "MMIS-4471", "state": "TX" }] },

    { "ref": "dea-a", "first_name": "Omar", "last_name": "Haddad", "date_of_birth": "1977-09-09", "npi": null,
      "identifiers": [{ "id_type": "dea", "id_value": "AH1234563" }] },
    { "ref": "dea-b", "first_name": "Omar", "last_name": "Haddad", "date_of_birth": null, "npi": null,
      "identifiers": [{ "id_type": "dea", "id_value": "AH1234563" }] },
    { "ref": "dea-other", "first_name": "Omar", "last_name": "Haddad", "date_of_birth": "2001-05-05", "npi": null,
      "identifiers": [{ "id_type": "dea", "id_value": "AH9998887" }] }
```
And add to the `"truth"` array:
```json
    ["mmis-a", "mmis-b"],
    ["mmis-other-state"],
    ["dea-a", "dea-b"],
    ["dea-other"]
```
All six new records are synthetic (`sv-manila/gp-cami` is public — same convention the fixture
already follows for its existing names/DOBs/license numbers).

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit --filter=EvalGateTest`
Expected: PASS — `true_pairs` = 11, `false_merges` = 0, `false_splits` = 0, precision 1.0000,
recall 1.0000, f1 1.0000. If `mmis-other-state` or `dea-other` end up merged with their pair (a
false merge) or `mmis-a`/`mmis-b`/`dea-a`/`dea-b` fail to merge (a false split), this fails loudly
here rather than passing silently — that is the point of adding them.

- [ ] **Step 6: Record the measured numbers**

In `docs/EVALUATION.md`, in the baseline table (still all `PENDING` per the authoring brief since
it needs production hub access nobody in this environment has), add a row for this plan's local
eval-set measurement, distinct from the still-pending real-hub baseline:

```markdown
| Plan 5 (match keys & data quality) — eval fixture, local | precision 1.0000 · recall 1.0000 · f1 1.0000 · true_pairs 11 · false_merges 0 · false_splits 0 | measured locally via `gp:eval`; NOT a production-hub measurement — see gp:npi-audit (Task 3) for the one number in this plan that DOES need a real hub |
```

- [ ] **Step 7: Commit**
```bash
git add app/GoldenProfile/Eval/EvalSet.php app/GoldenProfile/Eval/EvalRunner.php tests/Feature/EvalGateTest.php tests/eval/identity-pairs.json docs/EVALUATION.md
git commit -m "test(eval): add mmis+state and dea fixture coverage, raise true_pairs ratchet to 11"
```

---

## Self-review

**Spec coverage against this plan's four numbered sections:**

1. **NPI validation** — done (Tasks 1-3): real Luhn-with-`80840` check digit, wired at the single
   connector choke point both ingestion paths share.
   **NPI-replacement trail — DEFERRED, not built.** The checklist asks to "follow the trail if a
   provider's old NPI was replaced with a new one." That trail is NPPES's own data (a deactivated
   NPI record pointing at its replacement); `streamline_local` has no such feed, and
   `PROJECT_PLAN.md` §8 already rejected external government-feed ingestion for this hub. There is
   no honest way to build even a stub of this inside gp-cami's current data sources — a
   `replaced_by_npi` column with nothing to ever populate it would be worse than no column at all
   (it would look implemented and never be). This is named here explicitly rather than
   half-attempted.
2. **Junk/placeholder keys** — done for NPI (all-same-digit is already rejected by Luhn itself,
   verified; cardinality-based junk is `JunkKeyGuard`, wired in Task 5) and for name placeholders
   (Task 6, exactly the checklist's own worked example). **Deferred:** license number and address
   junk-guarding. `JunkKeyGuard` is written generically (parameterized by column) specifically so
   this is a same-shaped follow-up — `buildBlocklistTable('license_number')` and a call site in
   the license tier — not a redesign, but it is not wired in this plan because the checklist's own
   examples are NPI and a name string, not license/address, and this plan is already at its
   task-count ceiling.
3. **Quarantine + alerting** — a real table and a real, narrow rule (Task 7): no identifying data
   at all, in both ingestion paths, with a concrete alert mechanism (DB row + a `Log::critical`
   with a fixed greppable prefix). **What this is NOT:** the checklist's full "valid / complete /
   unique / consistent / fresh" gate per source. This plan implements roughly the "complete" axis
   only (has *something* to resolve on). "Valid"/"consistent" would mean format-checking every
   field against its own rule (which this plan does do for NPI specifically, but not generally);
   "unique" would mean detecting duplicate source rows within one system, which gp-cami's
   `uq_source` constraint on `stg_person` already partially covers structurally; "fresh" has no
   obvious definition here (streamline_local rows do not expire) and was not attempted rather than
   faked. A per-source input-contract document (also called for in the checklist) is not written —
   this plan is a code plan, not a documentation plan, and the closest code equivalent (a schema
   check on the source shape) is out of scope.
4. **Missing match keys** — NPI is already tier 2 (unchanged). `(state, license)` is already tier
   5 (unchanged). MMIS is promoted to a real match key as `(state, mmis)`, which this plan treats
   as satisfying BOTH the wiki's `"(state, medicaid id)"` and `"(state, provider#)"` labels for the
   one field streamline_local actually has (see Task 8's reasoning) — **this is a deliberate,
   documented judgment call, not a discovery of a separate provider# field.** Grepping the client
   codebase found `provider_number` used only on state exclusion-registry source tables (e.g.
   `nyomig_records`) for CAMI's own exclusion matching, never mirrored into gp-cami at all; if a
   future source genuinely supplies a distinct provider-number identifier, the same `id_type`
   mechanism this plan builds accepts it (`id_type = 'provider_number'`) with zero further schema
   change. DEA is promoted to a real match key (Task 9). **`(state, provider#)` as a literally
   separate field: DEFERRED — no data source in scope, not a code gap.**

**Proposed split — this plan does not attempt Pass B blocking widening.** Name+state blocking and
the phonetic-name/state/zip block are explicitly out of scope here, and should become their own
plan (call it **5b**, following the authoring brief's own suggested boundary). Reasons this is a
split rather than a squeeze:

- It is a genuine schema/architecture change, not a wiring change: `stg_person.block_key` is a
  single column with one soundex+dob-year value; adding legs means a `stg_person_block_key` child
  table (person, block_type, block_key) and rewriting `ProbabilisticResolver::match()`'s candidate
  query to union across legs. That is comparable in size to this entire plan's Tasks 4-9 combined.
- It directly interacts with the `block_size_cap` silent-split landmine this plan was told not to
  make worse without naming: today, an oversized single-leg block causes `no_match` and a silent
  `auto_match`-stamped new identity (the config comment's claim that oversized blocks are "flagged
  for steward" is false — that mechanism does not exist; fixing the flagging is plan 6's job).
  Multiple independent legs, each capped separately, would likely *reduce* how often that fires (a
  person oversized on one leg might still resolve through another), but "likely reduce" is not a
  number anyone has measured, and shipping it inside this already-large plan without its own
  eval-gate baseline pass would be exactly the kind of unmeasured claim this programme's Task 5
  brief warns against. It deserves its own plan, its own fixture cases sized to prove the
  cap-interaction claim, and its own baseline.
- This plan is already at 10 tasks — the authoring brief's stated ceiling before "propose a split
  rather than writing an unexecutable document."

**Placeholder scan:** no task says "add appropriate error handling," "similar to Task N," or
"write tests for the above." Every step that changes code shows the code inline, including the
full Luhn implementation, the full `JunkKeyGuard` class, and the full SQL for every modified
statement.

**Type consistency:** `StreamlineLocalConnector::ingest()`'s return type changes from `int` to
`?int` in Task 7 — both call sites (`Engine::backfill()` and `Engine::sync()`) are updated in the
same task, and `Task 7`'s own test suite exercises the `null` path directly.
`additionalRows()`'s signature changes from one to two parameters in Task 8 — both call sites
(`StreamlineLocalConnector::rebuildChildren()` and `SqlBackfill::stage()`) are updated in the same
task. `matchDeterministic()`/`enrich()` gain a third/fifth parameter in Task 9 with a default
(`= []`), so no other caller breaks.

**Known risks carried into execution:**

- **The retroactive-NPI-split risk (Task 3) is unmeasured against the real hub by construction of
  this environment**, not by omission — `gp:npi-audit` is real, runnable code, not a promise. The
  eval fixture cannot substitute for this measurement (Task 2 confirmed neither corrected fixture
  NPI is shared between records, so the fixture structurally cannot exercise a retroactive split).
  Whoever runs this against a real hub should do so before turning on any retroactive
  NPI-revalidation of already-linked rows — this plan's Task 3 only changes what happens to *new*
  ingests, not existing `gp_identity.npi` values, precisely to avoid silently splitting anything on
  merge.
- **`ingest()`/`rebuildChildren()` remain untestable in isolation** (Task 8's Step 5 is explicit
  about this) because `phpunit.xml` deliberately points the source connection at a dead socket.
  This is a pre-existing property of the codebase (the method had zero test coverage before this
  plan too), not a new gap introduced here — but it does mean the per-row identifier-staging fix is
  verified by code review plus `IdentifierTierParityTest`'s staging-shaped tests, not by a direct
  test of `ingest()` itself.
- **`JunkKeyGuard`'s NPI placeholder list ships empty** (`golden_profile.junk.placeholders.npi =
  []`), deliberately — populating it with a guess would be exactly the kind of unmeasured claim
  this plan otherwise avoids. `gp:npi-audit` (Task 3) is a reasonable starting point for measuring
  which specific values, if any, are reused as filler in the real hub; that measurement should feed
  this config once someone has the access to run it.
- **The `gp_identity_identifier` state-scoping fix in Task 9 changes what `Engine::mergeByIdentifier()`
  merges** for the real hub's existing DEA/MMIS data (state was previously ignored entirely, so any
  same-MMIS-different-state pair that already merged in a prior `dedup()` run will not un-merge
  itself — `gp_identity.status = 'merged'` is not reversed by this change, only future merges are
  scoped correctly). This is the same class of "cannot retroactively fix without a real hub" limit
  as the NPI risk above, and is named here rather than silently left for someone to discover.

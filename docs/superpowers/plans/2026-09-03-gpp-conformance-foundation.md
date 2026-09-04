# GPP Conformance — Foundation: Measurable Match Quality

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give gp-cami a labeled evaluation set, a precision/recall/F1 scorer that counts false splits separately from false merges, a database-backed resolver test harness, and CI that runs all of it — so every later conformance change can be proved not to have broken matching.

**Architecture:** A new `tests/Support/HubTestCase.php` points the `golden_profile` connection at a dedicated **MySQL** scratch schema, migrates it once per run, and rolls each test back in a transaction — which lets `DeterministicResolver::resolve()` be exercised end to end for the first time. A labeled fixture file (`tests/eval/identity-pairs.json`) declares records plus the ground-truth clusters they belong to; `App\GoldenProfile\Eval\MatchScorer` converts predicted vs. true clusters into pairwise counts. A `gp:eval` artisan command runs the same scorer against any hub so it can be used locally, and a GitHub Actions workflow with a MySQL service container gates the repo on it.

**Tech Stack:** PHP ^8.3 (8.4.12 locally and in CI), Laravel 13.20, PHPUnit 12.5, Laravel Pint, MySQL 8.

## Global Constraints

- The repo has **no `vendor/` directory** as checked out. Every task begins from a working `composer install`.
- **`composer install` needs no credentials.** As originally written this plan said it did — `streamlineverify/security` was a private dependency. It was used for exactly one constant, and commit `e64f73d` replaced it by replicating CAMI's own key resolution inline. gp-cami now depends only on public packagist packages (Laravel, Sanctum, Tinker, Predis), so CI needs no `COMPOSER_AUTH` secret. See Amendments.
- **The harness is MySQL, not SQLite, and this is not negotiable.** The schema reuses index names across tables — `idx_identity` appears on `gp_source_link:68`, `gp_edge:81`, `gp_attribute:110`, `gp_identity_credential:128`, `gp_identity_exclusion:148`, `gp_address:176`, `gp_license:192` and `gp_board_action`; `idx_ssn`, `idx_npi`, `idx_dea`, `idx_name_dob`, `idx_zip`, `uq_action` and `idx_type_value` are each duplicated too. MySQL scopes index names per table; **SQLite scopes them per database**, so `migrate` dies at the second `idx_identity` with `index idx_identity already exists`. Renaming twenty-plus indexes on a live 13M-row hub to suit a test harness is the wrong trade.
- Tests must never touch the real hub. `phpunit.xml` points `GP_DB_HOST`/`SRC_DB_HOST` at `127.0.0.1:1` deliberately; **do not remove or repoint those lines**. `HubTestCase` builds its connection from a *separate* `GP_TEST_DB_*` set and **skips** the test when they are absent, so a machine without a test MySQL gets skips rather than accidental writes.
- `SqlBackfill` is raw MySQL and could now be tested, but is out of scope here — this plan covers the per-row path. Set-based parity is Plan 8.
- Style: `vendor/bin/pint --dirty` before every commit. Existing files use 4-space indent, plain PHP (no `declare(strict_types=1)`), and heavy explanatory docblocks — match that voice.
- Tests use `public function test_snake_case(): void`. There are **no** PHPUnit attributes anywhere in `tests/`; do not introduce `#[Test]`.
- Commit messages follow the existing convention: `type(scope): imperative summary`.
- Branch off latest `master` (`17d383d` at time of writing) as `feat/eval-harness`. Do not push to `master`.

---

## Programme context — this is plan 1 of 8

The scope decision is: **conformance inside the Laravel/MySQL hub** (no lakehouse re-platform), and **code changes to match the docs** on the two documented contradictions. That is eight independent subsystems; each gets its own plan so each lands as working, testable software.

| # | Plan | Scope | Depends on |
|---|---|---|---|
| **1** | **Foundation — measurable match quality** *(this document)* | Eval set, scorer, resolver test harness, CI | — |
| 2 | SSN removal | Delete stored SSN, `ssn_hash`, the Pass A SSN tier, `SsnHasher`, `SsnHashGuard`, the `ssn` API param. Docs: Delivery Checklist §1 "never store SSN"; the GPP Data Model has no SSN column | 1 |
| 3 | SCD-2 versioning | `current tinyint(1)` + versioned inserts on the identity, name, address, license and match tables per Data Flow by CAMI | 1 |
| 4 | Individual vs entity | `entity_type`, entity keys (UPIN/TIN/EIN), entity name history, entity survivorship | 1, 3 |
| 5 | Match keys & data quality | NPI check-digit, NPI-replacement trail, junk/placeholder dictionary, quarantine + alerting, MMIS as a resolve-time tier, `(state, provider#)`, name+state and zip blocking | 1 |
| 6 | Steward writer layer | Resolution ingest from `match_actions`/`credential_match_actions`, board-action ingest, `link_state` transitions, `status_severity`, `internal_verified_decay_days`, oversized-block review flagging | 1, 3 |
| 7 | Exclusion lifecycle | `excl_date`, `reinstate_date`, `waiver_date`, `is_active`; never-delete semantics | 1, 3 |
| 8 | Incremental profiling | Profile signature, scheduled full re-profile, set-based/per-row parity | 1, 5 |

**Why this one is first:** it is the wiki's own P0 — *"make match quality measurable… almost every other item below gets easier once this exists."* Plans 2 and 3 both remove or restructure load-bearing matching machinery (the strongest deterministic key; every write path). Doing either without a regression net would be reckless.

**Note on doc naming:** the two doc sets disagree on table names — the GPP Data Model says `golden_provider` / `provider_xref`, Data Flow by CAMI says `individuals` / `entities`. Literal conformance to both is impossible. These plans conform to the **semantics** (SCD-2, entity split, resolution reuse, provenance) and keep the existing `gp_*` names. Raise this at the next design review rather than renaming 17 tables.

---

## File Structure

| File | Responsibility |
|---|---|
| `tests/Support/HubTestCase.php` *(create)* | Base test case: MySQL scratch hub, migrate-once, per-test rollback, staging helpers |
| `tests/eval/identity-pairs.json` *(create)* | The labeled evaluation set — records plus ground-truth clusters |
| `app/GoldenProfile/Eval/EvalSet.php` *(create)* | Loads and validates a fixture file; nothing else |
| `app/GoldenProfile/Eval/MatchScorer.php` *(create)* | Pure scoring: predicted vs. true clusters → precision/recall/F1, false merges, false splits |
| `app/GoldenProfile/Eval/EvalRunner.php` *(create)* | Stages an `EvalSet`, runs the resolver, returns predicted clusters + report |
| `app/Console/Commands/GpEval.php` *(create)* | `php artisan gp:eval` |
| `tests/Unit/MatchScorerTest.php` *(create)* | Scorer maths, no database |
| `tests/Unit/EvalSetShapeTest.php` *(create)* | The fixture is well-formed. No database |
| `tests/Feature/ResolverLadderTest.php` *(create)* | `DeterministicResolver::resolve()` end to end |
| `tests/Feature/EvalGateTest.php` *(create)* | The resolver clears the quality gate on the eval set |
| `.github/workflows/ci.yml` *(create)* | Pint + PHPUnit with a MySQL service |
| `docs/EVALUATION.md` *(create)* | Baseline, how to add pairs, how to read the report |
| `scripts/baseline-key-mix.sql` *(create)* | Read-only measurement of the current key mix |

---

## Task 1: Baseline measurement of the current key mix

Before anything is deleted, record what the hub looks like today. Plan 2 removes the `ssn_hash` tier; this task produces the number that says how much identity binding depends on it. **This does not reopen the scope decision — following the docs stands.** It sizes the blast radius and tells Plan 2 whether compensating keys are needed first.

**Files:**
- Create: `scripts/baseline-key-mix.sql`
- Create: `docs/EVALUATION.md`

**Interfaces:**
- Produces: a committed markdown table of link counts per `match_key`, and the count of identities whose *only* binding evidence is `ssn_hash`. Plan 2 Task 1 reads these numbers.

- [ ] **Step 1: Write the measurement SQL**

Create `scripts/baseline-key-mix.sql`:

```sql
-- Read-only. Run against the production/staging golden_profile hub.
-- Baseline for the GPP conformance programme (plan 1, task 1).

-- 1. How many source links were bound by each deterministic key?
SELECT match_key, match_method, match_state, COUNT(*) AS links
FROM gp_source_link
GROUP BY match_key, match_method, match_state
ORDER BY links DESC;

-- 2. How many identities exist, and how many carry an ssn_hash?
SELECT COUNT(*)                                     AS identities_total,
       SUM(ssn_hash IS NOT NULL AND ssn_hash <> '') AS identities_with_ssn_hash
FROM gp_identity
WHERE status = 'active';

-- 3. The number that matters: identities whose links were bound ONLY by
--    ssn_hash. Removing the tier fragments exactly these.
SELECT COUNT(*) AS identities_bound_only_by_ssn
FROM (
    SELECT identity_id
    FROM gp_source_link
    GROUP BY identity_id
    HAVING SUM(match_key <> 'ssn_hash') = 0
       AND SUM(match_key =  'ssn_hash') > 0
) t;

-- 4. Multi-row identities that would lose their only cross-record evidence.
SELECT COUNT(*) AS at_risk_identities
FROM gp_identity i
WHERE i.status = 'active'
  AND i.ssn_hash IS NOT NULL AND i.ssn_hash <> ''
  AND i.npi IS NULL AND i.upin IS NULL AND i.dea_number IS NULL
  AND (i.canonical_dob IS NULL OR i.canonical_last IS NULL)
  AND i.record_count > 1;

-- 5. Filler-hash exposure, against the 17 hashes / 9,164 people figure
--    recorded in config/golden_profile.php.
SELECT COUNT(*) AS filler_hashes, COALESCE(SUM(distinct_people), 0) AS people_affected
FROM gp_ssn_hash_blocklist;
```

- [ ] **Step 2: Run it against the hub**

```bash
mysql -h <gp-host> -u <gp-user> -p golden_profile < scripts/baseline-key-mix.sql
```

Expected: five result sets. Query 5 errors with `Table 'gp_ssn_hash_blocklist' doesn't exist` if `gp:backfill` has not run since the guard was added — that is a valid result; record it as "not built".

- [ ] **Step 3: Record the numbers**

Create `docs/EVALUATION.md`:

```markdown
# Evaluating match quality

## Baseline — before the GPP conformance programme

Measured <DATE> against the `golden_profile` hub with `scripts/baseline-key-mix.sql`.

| Metric | Value |
|---|---|
| Links by `ssn_hash` | |
| Links by `npi` | |
| Links by `name_dob` | |
| Links by `license_registry` | |
| Links by `probabilistic` | |
| Links by `new` | |
| Active identities | |
| Identities carrying an `ssn_hash` | |
| **Identities bound only by `ssn_hash`** | |
| **At-risk identities** (multi-row, no other key) | |
| Filler hashes on the blocklist | |

**Reading this:** the two bold rows size what Plan 2 (SSN removal) will fragment.
Every identity in "bound only by `ssn_hash`" loses its binding evidence and splits
into one identity per source row unless another key covers it.
```

- [ ] **Step 4: Commit**

```bash
git add scripts/baseline-key-mix.sql docs/EVALUATION.md
git commit -m "docs(eval): record the pre-conformance key mix baseline"
```

---

## Task 2: MySQL-backed hub test harness

No test in the repo has ever exercised the resolver, because every hub connection points at a dead socket. This builds the base class that makes it possible.

**Files:**
- Create: `tests/Support/HubTestCase.php`
- Modify: `.env.example`
- Create: `tests/Feature/ResolverLadderTest.php` (one smoke test here; extended in Task 3)

**Interfaces:**
- Produces: `Tests\Support\HubTestCase` exposing `protected function hub()`, `protected function seedSystem(string $code = 'streamline_local', int $reliability = 50): int`, `protected function stagePerson(array $overrides = []): int` (returns `stg_person_id`), `protected function stageLicense(int $stgPersonId, string $number, ?string $state = null): void`, `protected function blockKey(?string $last, ?string $dob): ?string`, and `protected int $systemId`. Tasks 3 and 6 extend this class.

- [ ] **Step 1: Install dependencies**

```bash
composer install
```

Expected: `vendor/` created, "Generating optimized autoload files". No credentials are required — every dependency is public (see Global Constraints).

- [ ] **Step 2: Create the scratch schema**

```bash
mysql -h 127.0.0.1 -u root -p -e "CREATE DATABASE IF NOT EXISTS gp_cami_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Expected: no output, exit 0.

- [ ] **Step 3: Declare the test connection env**

Append to `.env.example`:

```
# Test hub for tests/Support/HubTestCase.php. A DEDICATED scratch schema —
# HubTestCase migrates it and rolls each test back. Never point this at the real
# hub. Unset means the database-backed tests skip rather than run.
GP_TEST_DB_HOST=127.0.0.1
GP_TEST_DB_PORT=3306
GP_TEST_DB_DATABASE=gp_cami_test
GP_TEST_DB_USERNAME=root
GP_TEST_DB_PASSWORD=root
```

Copy the same block into your local `.env` with real credentials.

- [ ] **Step 4: Write the failing smoke test**

Create `tests/Feature/ResolverLadderTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\Support\HubTestCase;

class ResolverLadderTest extends HubTestCase
{
    public function test_the_harness_migrates_the_hub_schema(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        $this->assertTrue($schema->hasTable('gp_identity'), 'gp_identity was not created');
        $this->assertTrue($schema->hasTable('stg_person'));
        $this->assertTrue($schema->hasTable('gp_source_link'));
    }
}
```

- [ ] **Step 5: Run it to verify it fails**

```bash
vendor/bin/phpunit tests/Feature/ResolverLadderTest.php
```

Expected: FAIL — `Class "Tests\Support\HubTestCase" not found`.

- [ ] **Step 6: Write the harness**

Create `tests/Support/HubTestCase.php`:

```php
<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base case for tests that need a real golden_profile hub.
 *
 * phpunit.xml deliberately points GP_DB_* at a dead socket so no test can
 * silently read or write the shared hub. That protection also made the resolver
 * untestable — nothing in tests/ has ever called resolve(). This class builds a
 * SEPARATE connection from GP_TEST_DB_*, pointed at a scratch schema, and
 * rebinds 'golden_profile' to it for the duration of the test run.
 *
 * MySQL, not SQLite, and deliberately so: the schema reuses index names across
 * tables (idx_identity is on eight of them). MySQL scopes index names per table;
 * SQLite scopes them per database, so `migrate` dies on the second gp_* table.
 * Renaming twenty-odd indexes on a live 13M-row hub to suit a test harness is
 * the wrong trade.
 *
 * Cost control: migrations run ONCE per process (the static flag), and each test
 * runs inside a transaction that is rolled back in tearDown. The engine opens its
 * own transactions in places (Engine::mergeIdentity) — those nest as savepoints
 * and roll back with the outer one.
 *
 * With no GP_TEST_DB_* configured the tests SKIP. That is intentional: a missing
 * test database must never silently fall through to a real one.
 */
abstract class HubTestCase extends TestCase
{
    private static bool $migrated = false;

    protected int $systemId;

    protected function setUp(): void
    {
        parent::setUp();

        $database = env('GP_TEST_DB_DATABASE');

        if (! $database) {
            $this->markTestSkipped('GP_TEST_DB_DATABASE is not set — see .env.example');
        }

        config([
            'database.connections.golden_profile' => [
                'driver' => 'mysql',
                'host' => env('GP_TEST_DB_HOST', '127.0.0.1'),
                'port' => env('GP_TEST_DB_PORT', '3306'),
                'database' => $database,
                'username' => env('GP_TEST_DB_USERNAME', 'root'),
                'password' => env('GP_TEST_DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => 'InnoDB',
            ],
        ]);

        DB::purge('golden_profile');

        $this->guardAgainstTheRealHub($database);

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', [
                '--database' => 'golden_profile',
                '--path' => ['database/migrations'],
                '--force' => true,
            ]);
            self::$migrated = true;
        }

        $this->hub()->beginTransaction();

        $this->systemId = $this->seedSystem();
    }

    protected function tearDown(): void
    {
        if (isset($this->systemId)) {
            $this->hub()->rollBack();
        }

        parent::tearDown();
    }

    /**
     * migrate:fresh DROPS EVERY TABLE. Getting this connection wrong once would
     * destroy the hub, so the name is checked rather than trusted.
     *
     * A substring check on 'test' alone is not enough: the same MySQL server
     * also hosts a database literally named streamline_test, which contains
     * 'test' and would pass such a check. Requiring the name to both start
     * with 'gp_' and contain 'test' admits the intended gp_cami_test while
     * rejecting streamline_test and its neighbours.
     */
    private function guardAgainstTheRealHub(string $database): void
    {
        if (! str_starts_with($database, 'gp_') || ! str_contains($database, 'test')) {
            $this->fail(
                "refusing to migrate '$database': GP_TEST_DB_DATABASE must be a scratch ".
                "schema whose name starts with 'gp_' and contains 'test' (e.g. gp_cami_test)"
            );
        }
    }

    protected function hub()
    {
        return DB::connection('golden_profile');
    }

    /** Register a source system and return its system_id. */
    protected function seedSystem(string $code = 'streamline_local', int $reliability = 50): int
    {
        return (int) $this->hub()->table('gp_source_system')->insertGetId([
            'system_code' => $code.'-'.uniqid(),
            'display_name' => $code,
            'reliability_rank' => $reliability,
            'is_active' => 1,
            'added_at' => now(),
        ]);
    }

    /**
     * Stage one person. Returns stg_person_id. Defaults mirror what the
     * StreamlineLocal connector produces, block_key included. A caller-supplied
     * block_key wins — the merge order matters, so overrides are applied last.
     */
    protected function stagePerson(array $overrides = []): int
    {
        static $nextSourceId = 1000;

        $defaults = [
            'system_id' => $this->systemId,
            'source_table' => 'employees',
            'source_id' => ++$nextSourceId,
            'account_id' => 1,
            'employeelist_id' => 1,
            'first_name' => 'Robert',
            'middle_name' => null,
            'last_name' => 'Smith',
            'name_suffix' => null,
            'date_of_birth' => '1970-04-02',
            'ssn_hash' => null,
            'ssn_last_four' => null,
            'npi' => null,
            'upin' => null,
            'dea_number' => null,
            'address1' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'terminated' => 0,
            'source_modified' => now()->toDateTimeString(),
            'ingested_at' => now(),
        ];

        $row = array_merge($defaults, $overrides);

        if (! array_key_exists('block_key', $overrides)) {
            $row['block_key'] = $this->blockKey($row['last_name'], $row['date_of_birth']);
        }

        return (int) $this->hub()->table('stg_person')->insertGetId($row);
    }

    /** Attach a license to a staged person. */
    protected function stageLicense(int $stgPersonId, string $number, ?string $state = null): void
    {
        $this->hub()->table('stg_person_license')->insert([
            'stg_person_id' => $stgPersonId,
            'license_number' => $number,
            'certification_state' => $state,
            'certification_board' => null,
            'license_type' => null,
            'license_type_id' => null,
            'registry' => null,
            'is_primary' => 1,
        ]);
    }

    /** Same rule as StreamlineLocalConnector::blockKey(). */
    protected function blockKey(?string $last, ?string $dob): ?string
    {
        $last = $last ? trim($last) : null;

        if (! $last) {
            return null;
        }

        return soundex($last).'|'.($dob ? substr((string) $dob, 0, 4) : '____');
    }
}
```

Two notes on choices above. `seedSystem()` suffixes `system_code` with `uniqid()` because the column is `unique` and `migrate:fresh` runs only once — without it the second test would collide. `migrate:fresh` is used rather than `migrate` so a schema left over from a previous branch cannot poison the run; the name guard above it is what makes that safe.

- [ ] **Step 7: Run the smoke test**

```bash
vendor/bin/phpunit tests/Feature/ResolverLadderTest.php
```

Expected: PASS, 1 test, 3 assertions. With `GP_TEST_DB_DATABASE` unset, expected instead: `S` (skipped), 1 skipped test.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add tests/Support/HubTestCase.php tests/Feature/ResolverLadderTest.php .env.example
git commit -m "test(harness): add a scratch-mysql hub so the resolver is testable"
```

---

## Task 3: Resolver ladder tests

The first tests that call `DeterministicResolver::resolve()`. These are the regression net Plans 2–8 lean on.

**Files:**
- Modify: `tests/Feature/ResolverLadderTest.php`

**Interfaces:**
- Consumes: `HubTestCase::stagePerson()`, `stageLicense()`, `hub()`, `$this->systemId`.
- Produces: nothing consumed downstream; this is a leaf.

- [ ] **Step 1: Write the tests**

Append inside the `ResolverLadderTest` class:

```php
    private function resolve(int $stgPersonId): int
    {
        return (new \App\GoldenProfile\Resolution\DeterministicResolver($this->systemId))
            ->resolve($stgPersonId);
    }

    public function test_two_rows_sharing_an_npi_bind_to_one_identity(): void
    {
        $a = $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Robert']);
        $b = $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Bob', 'date_of_birth' => null]);

        $this->assertSame($this->resolve($a), $this->resolve($b));
    }

    public function test_two_rows_sharing_name_and_dob_bind_to_one_identity(): void
    {
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $b = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);

        $this->assertSame($this->resolve($a), $this->resolve($b));
    }

    public function test_same_name_different_dob_stay_separate(): void
    {
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1975-01-09']);
        $b = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia', 'date_of_birth' => '1988-06-30']);

        $this->assertNotSame($this->resolve($a), $this->resolve($b));
    }

    public function test_shared_license_and_state_binds_to_one_identity(): void
    {
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $this->stageLicense($a, 'L-77', 'NY');
        $idA = $this->resolve($a);

        $b = $this->stagePerson(['first_name' => 'Anne', 'last_name' => 'Kowalski', 'date_of_birth' => null]);
        $this->stageLicense($b, 'L-77', 'NY');

        $this->assertSame($idA, $this->resolve($b));
    }

    public function test_same_license_number_in_a_different_state_stays_separate(): void
    {
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $this->stageLicense($a, 'L-77', 'NY');
        $idA = $this->resolve($a);

        $b = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1990-11-11']);
        $this->stageLicense($b, 'L-77', 'CA');

        $this->assertNotSame($idA, $this->resolve($b));
    }

    public function test_resolving_the_same_row_twice_is_idempotent(): void
    {
        $a = $this->stagePerson(['npi' => 1234567893]);

        $this->assertSame($this->resolve($a), $this->resolve($a));
        $this->assertSame(1, $this->hub()->table('gp_source_link')->count());
    }

    public function test_a_pinned_link_is_never_re_enriched(): void
    {
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03']);
        $this->stageLicense($a, 'L-77', 'NY');
        $id = $this->resolve($a);

        $this->hub()->table('gp_source_link')->where('identity_id', $id)->update(['is_pinned' => 1]);
        $this->hub()->table('gp_license')->delete();

        $this->assertSame($id, $this->resolve($a));
        $this->assertSame(0, $this->hub()->table('gp_license')->count(), 'a pinned link must not re-enrich');
    }

    public function test_the_match_key_recorded_matches_the_tier_that_fired(): void
    {
        $a = $this->stagePerson(['npi' => 1234567893]);
        $id = $this->resolve($a);
        $b = $this->stagePerson(['npi' => 1234567893, 'first_name' => 'Bob', 'date_of_birth' => null]);
        $this->resolve($b);

        $keys = $this->hub()->table('gp_source_link')->where('identity_id', $id)
            ->orderBy('link_id')->pluck('match_key')->all();

        $this->assertSame(['new', 'npi'], $keys);
    }

    public function test_a_filler_ssn_hash_does_not_weld_unrelated_people_together(): void
    {
        // config golden_profile.ssn.max_identities_per_hash is 3: a hash carried
        // by more distinct people than that is filler and must not bind.
        $refs = [];
        foreach ([['Ana', 'Reyes', '1980-01-01'], ['Ben', 'Cruz', '1975-02-02'],
            ['Cara', 'Diaz', '1990-03-03'], ['Dan', 'Evans', '1966-04-04']] as [$f, $l, $d]) {
            $refs[] = $this->stagePerson([
                'first_name' => $f, 'last_name' => $l, 'date_of_birth' => $d,
                'ssn_hash' => str_repeat('a', 128),
            ]);
        }

        $ids = array_map(fn ($r) => $this->resolve($r), $refs);

        $this->assertCount(4, array_unique($ids), 'a filler ssn_hash must not collapse four people');
    }
```

- [ ] **Step 2: Run and read the results honestly**

```bash
vendor/bin/phpunit tests/Feature/ResolverLadderTest.php
```

Expected: PASS — these describe existing behaviour. Any FAIL is a real defect in the resolver: record it in `docs/EVALUATION.md` under a new "Known defects found by the harness" heading and fix it in a separate commit. **Do not weaken a test to make it green.**

- [ ] **Step 3: Run the whole suite**

```bash
vendor/bin/phpunit
```

Expected: PASS. The 11 pre-existing test files must stay green.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git add tests/Feature/ResolverLadderTest.php docs/EVALUATION.md
git commit -m "test(resolution): cover the Pass A tier ladder end to end"
```

---

## Task 4: Labeled evaluation set and loader

**Files:**
- Create: `tests/eval/identity-pairs.json`
- Create: `app/GoldenProfile/Eval/EvalSet.php`
- Test: `tests/Unit/EvalSetShapeTest.php`

**Interfaces:**
- Produces: `App\GoldenProfile\Eval\EvalSet` with `public static function load(string $path): self`, `public function records(): array`, `public function licenses(string $ref): array`, `public function truthClusters(): array`, `public function path(): string`. Tasks 5 and 6 consume the first four.

- [ ] **Step 1: Write the fixture**

Create `tests/eval/identity-pairs.json`. Every case is a failure mode named in the wiki or the config comments — same-name-different-person, transitive chains, nicknames, missing DOB, cross-state licenses, filler SSN.

```json
{
  "version": 1,
  "notes": "Labeled identity pairs for gp-cami. `truth` lists ground-truth clusters: every ref in a cluster is the same real person. The ssn-* records exist to measure what plan 2 (SSN removal) costs — when the tier goes, they become a false split and the recall floor must be re-baselined, not lowered silently.",
  "records": [
    { "ref": "smith-a", "first_name": "Robert", "last_name": "Smith", "date_of_birth": "1970-04-02", "npi": 1234567893 },
    { "ref": "smith-b", "first_name": "Bob", "last_name": "Smith", "date_of_birth": null, "npi": 1234567893 },
    { "ref": "smith-c", "first_name": "Robert", "last_name": "Smith", "date_of_birth": "1970-04-02", "npi": null },
    { "ref": "smith-other", "first_name": "Robert", "last_name": "Smith", "date_of_birth": "1991-12-18", "npi": 1987654327 },

    { "ref": "garcia-a", "first_name": "Maria", "last_name": "Garcia", "date_of_birth": "1975-01-09", "npi": 1999999992 },
    { "ref": "garcia-b", "first_name": "Maria", "last_name": "Garcia", "date_of_birth": "1975-01-09", "npi": null },
    { "ref": "garcia-other", "first_name": "Maria", "last_name": "Garcia", "date_of_birth": "1988-06-30", "npi": null },

    { "ref": "kowalski-a", "first_name": "Ann", "last_name": "Kowalski", "date_of_birth": "1981-03-03", "npi": null,
      "licenses": [{ "license_number": "L-77", "certification_state": "NY" }] },
    { "ref": "kowalski-b", "first_name": "Anne", "last_name": "Kowalski", "date_of_birth": null, "npi": null,
      "licenses": [{ "license_number": "L-77", "certification_state": "NY" }] },
    { "ref": "kowalski-other", "first_name": "Ann", "last_name": "Kowalski", "date_of_birth": "1990-11-11", "npi": null,
      "licenses": [{ "license_number": "L-77", "certification_state": "CA" }] },

    { "ref": "chain-a", "first_name": "Peter", "last_name": "Nguyen", "date_of_birth": "1968-07-21", "npi": 1112223339 },
    { "ref": "chain-b", "first_name": "Peter", "last_name": "Nguyen", "date_of_birth": "1968-07-21", "npi": null,
      "licenses": [{ "license_number": "PN-3312", "certification_state": "TX" }] },
    { "ref": "chain-c", "first_name": "Pete", "last_name": "Nguyen", "date_of_birth": null, "npi": null,
      "licenses": [{ "license_number": "PN-3312", "certification_state": "TX" }] },

    { "ref": "nodob-a", "first_name": "Sarah", "last_name": "Okafor", "date_of_birth": null, "npi": null },
    { "ref": "nodob-b", "first_name": "Sarah", "last_name": "Okafor", "date_of_birth": null, "npi": null },

    { "ref": "ssn-a", "first_name": "Grace", "last_name": "Adeyemi", "date_of_birth": "1979-05-14", "npi": null,
      "ssn_hash": "88f4db093a2e6baff836b6892e0da35234713aee63dbc17005dfe54bc5118e38af0d1c89b4dd96b9784d3eee3e5e99b933eafa718f48efaad2628e91c432348a" },
    { "ref": "ssn-b", "first_name": "Gracie", "last_name": "Adeyemi", "date_of_birth": null, "npi": null,
      "ssn_hash": "88f4db093a2e6baff836b6892e0da35234713aee63dbc17005dfe54bc5118e38af0d1c89b4dd96b9784d3eee3e5e99b933eafa718f48efaad2628e91c432348a" }
  ],
  "truth": [
    ["smith-a", "smith-b", "smith-c"],
    ["smith-other"],
    ["garcia-a", "garcia-b"],
    ["garcia-other"],
    ["kowalski-a", "kowalski-b"],
    ["kowalski-other"],
    ["chain-a", "chain-b", "chain-c"],
    ["nodob-a"],
    ["nodob-b"],
    ["ssn-a", "ssn-b"]
  ]
}
```

`nodob-a` / `nodob-b` are two different people sharing a name with no birth date. They are the case the matcher **must not** merge, and the reason `name+dob` requires a DOB.

- [ ] **Step 2: Write the failing loader test**

Create `tests/Unit/EvalSetShapeTest.php` — no database needed, so it extends `Tests\TestCase`:

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Eval\EvalSet;
use Tests\TestCase;

class EvalSetShapeTest extends TestCase
{
    private function set(): EvalSet
    {
        return EvalSet::load(base_path('tests/eval/identity-pairs.json'));
    }

    public function test_every_record_appears_in_exactly_one_truth_cluster(): void
    {
        $set = $this->set();
        $refs = array_column($set->records(), 'ref');
        $clustered = array_merge(...$set->truthClusters());

        sort($refs);
        sort($clustered);

        $this->assertSame($refs, $clustered, 'every record must be in exactly one cluster');
    }

    public function test_the_set_contains_both_error_modes(): void
    {
        $clusters = $this->set()->truthClusters();

        $this->assertGreaterThanOrEqual(
            2, count(array_filter($clusters, fn ($c) => count($c) > 1)),
            'need multi-record clusters to detect false splits'
        );
        $this->assertGreaterThanOrEqual(
            3, count(array_filter($clusters, fn ($c) => count($c) === 1)),
            'need singleton clusters to detect false merges'
        );
    }

    public function test_a_duplicate_ref_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("duplicate ref 'x'");

        EvalSet::loadArray([
            'records' => [['ref' => 'x'], ['ref' => 'x']],
            'truth' => [['x']],
        ], 'memory');
    }

    public function test_a_record_missing_from_truth_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('records missing from truth: y');

        EvalSet::loadArray([
            'records' => [['ref' => 'x'], ['ref' => 'y']],
            'truth' => [['x']],
        ], 'memory');
    }
}
```

- [ ] **Step 3: Run to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/EvalSetShapeTest.php
```

Expected: FAIL — `Class "App\GoldenProfile\Eval\EvalSet" not found`.

- [ ] **Step 4: Write the loader**

Create `app/GoldenProfile/Eval/EvalSet.php`:

```php
<?php

namespace App\GoldenProfile\Eval;

use InvalidArgumentException;

/**
 * A labeled evaluation set: source records plus the ground-truth clusters they
 * belong to. Loading validates the file, because a malformed answer key scores a
 * matcher against nonsense and reports it as a number.
 */
class EvalSet
{
    /** @param list<array<string,mixed>> $records @param list<list<string>> $truth */
    private function __construct(private array $records, private array $truth, private string $path) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("eval set not found: $path");
        }

        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

        return self::loadArray($data, $path);
    }

    /** Same validation, from an already-decoded array. Keeps the rules testable. */
    public static function loadArray(array $data, string $path): self
    {
        foreach (['records', 'truth'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key])) {
                throw new InvalidArgumentException("eval set $path is missing '$key'");
            }
        }

        $refs = [];
        foreach ($data['records'] as $i => $r) {
            if (! isset($r['ref']) || ! is_string($r['ref'])) {
                throw new InvalidArgumentException("record #$i has no string 'ref'");
            }
            if (isset($refs[$r['ref']])) {
                throw new InvalidArgumentException("duplicate ref '{$r['ref']}'");
            }
            $refs[$r['ref']] = true;
        }

        $seen = [];
        foreach ($data['truth'] as $i => $cluster) {
            if (! is_array($cluster) || $cluster === []) {
                throw new InvalidArgumentException("truth cluster #$i is empty");
            }
            foreach ($cluster as $ref) {
                if (! isset($refs[$ref])) {
                    throw new InvalidArgumentException("truth references unknown record '$ref'");
                }
                if (isset($seen[$ref])) {
                    throw new InvalidArgumentException("record '$ref' appears in more than one cluster");
                }
                $seen[$ref] = true;
            }
        }

        $missing = array_diff(array_keys($refs), array_keys($seen));
        if ($missing !== []) {
            throw new InvalidArgumentException('records missing from truth: '.implode(', ', $missing));
        }

        return new self(array_values($data['records']), array_values($data['truth']), $path);
    }

    /** @return list<array<string,mixed>> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<array{license_number:string,certification_state:?string}> */
    public function licenses(string $ref): array
    {
        foreach ($this->records as $r) {
            if ($r['ref'] === $ref) {
                return $r['licenses'] ?? [];
            }
        }

        return [];
    }

    /** @return list<list<string>> */
    public function truthClusters(): array
    {
        return $this->truth;
    }

    public function path(): string
    {
        return $this->path;
    }
}
```

- [ ] **Step 5: Run to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/EvalSetShapeTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add tests/eval/identity-pairs.json app/GoldenProfile/Eval/EvalSet.php tests/Unit/EvalSetShapeTest.php
git commit -m "test(eval): add the labeled identity pair set and its loader"
```

---

## Task 5: The scorer

Pure maths, no database. False merges and false splits are counted separately because the wiki is explicit that they cost differently — a false split is how an excluded provider gets missed.

**Files:**
- Create: `app/GoldenProfile/Eval/MatchScorer.php`
- Test: `tests/Unit/MatchScorerTest.php`

**Interfaces:**
- Produces: `App\GoldenProfile\Eval\MatchScorer::score(array $predicted, array $truth): array` where both arguments are `list<list<string>>` of refs, returning
  `['true_pairs'=>int, 'predicted_pairs'=>int, 'true_positives'=>int, 'false_merges'=>int, 'false_splits'=>int, 'precision'=>float, 'recall'=>float, 'f1'=>float]`.
  Task 6 consumes this exact shape.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/MatchScorerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Eval\MatchScorer;
use Tests\TestCase;

class MatchScorerTest extends TestCase
{
    public function test_a_perfect_prediction_scores_one(): void
    {
        $r = MatchScorer::score([['a', 'b'], ['c']], [['a', 'b'], ['c']]);

        $this->assertSame(1, $r['true_positives']);
        $this->assertSame(0, $r['false_merges']);
        $this->assertSame(0, $r['false_splits']);
        $this->assertSame(1.0, $r['precision']);
        $this->assertSame(1.0, $r['recall']);
        $this->assertSame(1.0, $r['f1']);
    }

    public function test_a_false_merge_costs_precision_not_recall(): void
    {
        // truth: a,b together and c alone. predicted: all three together.
        $r = MatchScorer::score([['a', 'b', 'c']], [['a', 'b'], ['c']]);

        $this->assertSame(1, $r['true_positives']);
        $this->assertSame(2, $r['false_merges']);   // a-c and b-c
        $this->assertSame(0, $r['false_splits']);
        $this->assertEqualsWithDelta(1 / 3, $r['precision'], 1e-9);
        $this->assertSame(1.0, $r['recall']);
    }

    public function test_a_false_split_costs_recall_not_precision(): void
    {
        // truth: a,b,c together. predicted: a,b together and c alone.
        $r = MatchScorer::score([['a', 'b'], ['c']], [['a', 'b', 'c']]);

        $this->assertSame(1, $r['true_positives']);
        $this->assertSame(0, $r['false_merges']);
        $this->assertSame(2, $r['false_splits']);   // a-c and b-c
        $this->assertSame(1.0, $r['precision']);
        $this->assertEqualsWithDelta(1 / 3, $r['recall'], 1e-9);
    }

    public function test_all_singletons_on_both_sides_score_one(): void
    {
        // No pairs anywhere. Vacuously perfect, and must not divide by zero.
        $r = MatchScorer::score([['a'], ['b']], [['a'], ['b']]);

        $this->assertSame(0, $r['true_pairs']);
        $this->assertSame(1.0, $r['precision']);
        $this->assertSame(1.0, $r['recall']);
        $this->assertSame(1.0, $r['f1']);
    }

    public function test_predicting_nothing_together_when_truth_has_pairs_scores_zero_recall(): void
    {
        $r = MatchScorer::score([['a'], ['b']], [['a', 'b']]);

        $this->assertSame(1.0, $r['precision']);  // made no wrong merges
        $this->assertSame(0.0, $r['recall']);
        $this->assertSame(0.0, $r['f1']);
    }

    public function test_the_metrics_are_always_floats(): void
    {
        // PHP returns int from an exact int division: 1/1 is int(1), not 1.0.
        // The declared contract is float, and EvalRunner/GpEval format on it.
        $r = MatchScorer::score([['a', 'b']], [['a', 'b']]);

        $this->assertIsFloat($r['precision']);
        $this->assertIsFloat($r['recall']);
        $this->assertIsFloat($r['f1']);
    }

    public function test_cluster_order_does_not_change_the_score(): void
    {
        $a = MatchScorer::score([['b', 'a'], ['c']], [['a', 'b'], ['c']]);
        $b = MatchScorer::score([['c'], ['a', 'b']], [['c'], ['b', 'a']]);

        $this->assertSame($a, $b);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/MatchScorerTest.php
```

Expected: FAIL — `Class "App\GoldenProfile\Eval\MatchScorer" not found`.

- [ ] **Step 3: Write the scorer**

Create `app/GoldenProfile/Eval/MatchScorer.php`:

```php
<?php

namespace App\GoldenProfile\Eval;

/**
 * Pairwise scoring of a clustering against an answer key.
 *
 * Entity resolution is judged on pairs, not clusters: for every pair of records,
 * did we put them together, and was that right? That makes the two error modes
 * nameable and separately countable, which the GPP wiki asks for explicitly — a
 * false SPLIT (two records of one provider left apart) is how an excluded
 * provider gets missed, and costs more than a false MERGE.
 *
 *   false_merge = predicted together, truth apart -> hurts precision
 *   false_split = predicted apart, truth together -> hurts recall
 *
 * Both sides empty is defined as a perfect score rather than 0/0: a set of
 * genuine singletons the matcher correctly kept apart is a pass, not undefined.
 *
 * Every ratio is cast to float. PHP returns int from an exact int division
 * (1/1 is int(1)), which would break the declared float contract and any
 * assertSame against 1.0 downstream.
 */
class MatchScorer
{
    /**
     * @param  list<list<string>>  $predicted
     * @param  list<list<string>>  $truth
     * @return array{true_pairs:int,predicted_pairs:int,true_positives:int,false_merges:int,false_splits:int,precision:float,recall:float,f1:float}
     */
    public static function score(array $predicted, array $truth): array
    {
        $p = self::pairs($predicted);
        $t = self::pairs($truth);

        $tp = count(array_intersect_key($p, $t));
        $falseMerges = count($p) - $tp;
        $falseSplits = count($t) - $tp;

        $precision = count($p) === 0 ? 1.0 : (float) $tp / count($p);
        $recall = count($t) === 0 ? 1.0 : (float) $tp / count($t);
        $f1 = ($precision + $recall) === 0.0
            ? 0.0
            : (float) (2 * $precision * $recall / ($precision + $recall));

        return [
            'true_pairs' => count($t),
            'predicted_pairs' => count($p),
            'true_positives' => $tp,
            'false_merges' => $falseMerges,
            'false_splits' => $falseSplits,
            'precision' => $precision,
            'recall' => $recall,
            'f1' => $f1,
        ];
    }

    /**
     * Every unordered within-cluster pair, keyed on the two refs joined by a
     * null byte, with the refs sorted so the key is order-independent.
     *
     * A printable separator like "|" is not safe here: a ref is arbitrary
     * data and can itself contain the separator, so two different pairs can
     * produce the same key — cluster ['a|b','c'] and cluster ['a','b|c'] both
     * key to "a|b|c" under a naive '|' join. Joining on "\0" instead avoids
     * that collision.
     *
     * @param  list<list<string>>  $clusters
     * @return array<string,true>
     */
    private static function pairs(array $clusters): array
    {
        $out = [];
        foreach ($clusters as $cluster) {
            $members = array_values(array_unique($cluster));
            sort($members);
            $n = count($members);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $out[$members[$i]."\0".$members[$j]] = true;
                }
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/MatchScorerTest.php
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Eval/MatchScorer.php tests/Unit/MatchScorerTest.php
git commit -m "feat(eval): score clusterings with false merges and splits counted apart"
```

---

## Task 6: Runner, artisan command, and the quality gate

**Files:**
- Create: `app/GoldenProfile/Eval/EvalRunner.php`
- Create: `app/Console/Commands/GpEval.php`
- Create: `tests/Feature/EvalGateTest.php`
- Modify: `docs/EVALUATION.md`

**Interfaces:**
- Consumes: `EvalSet`, `MatchScorer::score()`, `HubTestCase`.
- Produces: `App\GoldenProfile\Eval\EvalRunner::__construct(int $systemId)` and `public function run(EvalSet $set): array` returning `['clusters' => list<list<string>>, 'report' => <MatchScorer::score shape>]`.

- [ ] **Step 1: Write the failing gate test**

Create `tests/Feature/EvalGateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Eval\EvalRunner;
use App\GoldenProfile\Eval\EvalSet;
use Tests\Support\HubTestCase;

class EvalGateTest extends HubTestCase
{
    public function test_the_resolver_clears_the_quality_gate_on_the_eval_set(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $report = (new EvalRunner($this->systemId))->run($set)['report'];

        $message = sprintf(
            'precision %.4f recall %.4f f1 %.4f — %d false merge(s), %d false split(s)',
            $report['precision'], $report['recall'], $report['f1'],
            $report['false_merges'], $report['false_splits']
        );

        // Precision-first per config golden_profile.tracks.identity. A false merge
        // welds two real providers together and nothing downstream undoes it, so
        // the gate on merges is absolute.
        $this->assertSame(0, $report['false_merges'], "false merges are never acceptable — $message");
        $this->assertGreaterThanOrEqual(0.99, $report['precision'], $message);

        // Recall floor sits below the PROJECT_PLAN 0.95 target: Pass B cannot
        // auto-merge today (its implemented weights sum to exactly auto_merge_at),
        // so the deterministic ladder alone sets the ceiling. Raise this as
        // calibration lands. Never lower it to make a build pass.
        $this->assertGreaterThanOrEqual(0.80, $report['recall'], $message);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit tests/Feature/EvalGateTest.php
```

Expected: FAIL — `Class "App\GoldenProfile\Eval\EvalRunner" not found`.

- [ ] **Step 3: Write the runner**

Create `app/GoldenProfile/Eval/EvalRunner.php`. Note it calls `DB::connection('golden_profile')` directly rather than reading `config('golden_profile.connections.hub')`: `DeterministicResolver::hub()` hardcodes that connection name, so reading a different config key here would stage rows in one database and write identities into another.

```php
<?php

namespace App\GoldenProfile\Eval;

use App\GoldenProfile\Resolution\DeterministicResolver;
use Illuminate\Support\Facades\DB;

/**
 * Stages an EvalSet into the hub, resolves every record, and reads back the
 * clustering the engine produced. The hub must already be migrated and carry a
 * gp_source_system row for $systemId.
 *
 * The connection is hardcoded to 'golden_profile' on purpose. It must be the
 * same connection DeterministicResolver::hub() uses — that method hardcodes it
 * too, and does NOT read golden_profile.connections.hub. Reading the config key
 * here would let a caller stage into a scratch database while the resolver wrote
 * identities into the real hub. Point the CONNECTION at a scratch schema
 * (GP_DB_DATABASE), never a different connection name.
 */
class EvalRunner
{
    public function __construct(private int $systemId) {}

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /**
     * @return array{clusters: list<list<string>>, report: array<string,mixed>}
     */
    public function run(EvalSet $set): array
    {
        $stgByRef = [];

        foreach ($set->records() as $r) {
            $ref = $r['ref'];

            $stgByRef[$ref] = (int) $this->hub()->table('stg_person')->insertGetId([
                'system_id' => $this->systemId,
                'source_table' => 'employees',
                'source_id' => crc32($ref),
                'account_id' => 1,
                'employeelist_id' => 1,
                'first_name' => $r['first_name'] ?? null,
                'middle_name' => $r['middle_name'] ?? null,
                'last_name' => $r['last_name'] ?? null,
                'name_suffix' => null,
                'date_of_birth' => $r['date_of_birth'] ?? null,
                'ssn_hash' => $r['ssn_hash'] ?? null,
                'ssn_last_four' => null,
                'npi' => $r['npi'] ?? null,
                'upin' => $r['upin'] ?? null,
                'dea_number' => $r['dea_number'] ?? null,
                'address1' => $r['address1'] ?? null,
                'city' => $r['city'] ?? null,
                'state' => $r['state'] ?? null,
                'zip' => $r['zip'] ?? null,
                'terminated' => 0,
                'source_modified' => now()->toDateTimeString(),
                'ingested_at' => now(),
                'block_key' => $this->blockKey($r['last_name'] ?? null, $r['date_of_birth'] ?? null),
            ]);

            foreach ($set->licenses($ref) as $lic) {
                $this->hub()->table('stg_person_license')->insert([
                    'stg_person_id' => $stgByRef[$ref],
                    'license_number' => $lic['license_number'],
                    'certification_state' => $lic['certification_state'] ?? null,
                    'certification_board' => null,
                    'license_type' => null,
                    'license_type_id' => null,
                    'registry' => null,
                    'is_primary' => 1,
                ]);
            }
        }

        $resolver = new DeterministicResolver($this->systemId);

        $byIdentity = [];
        foreach ($stgByRef as $ref => $stgId) {
            $byIdentity[$resolver->resolve($stgId)][] = $ref;
        }

        $clusters = array_values($byIdentity);

        return [
            'clusters' => $clusters,
            'report' => MatchScorer::score($clusters, $set->truthClusters()),
        ];
    }

    /** Same rule as StreamlineLocalConnector::blockKey(). */
    private function blockKey(?string $last, ?string $dob): ?string
    {
        $last = $last ? trim($last) : null;

        if (! $last) {
            return null;
        }

        return soundex($last).'|'.($dob ? substr((string) $dob, 0, 4) : '____');
    }
}
```

- [ ] **Step 4: Run to verify it passes**

```bash
vendor/bin/phpunit tests/Feature/EvalGateTest.php
```

Expected: PASS. If the recall assertion fails, do **not** lower the floor — read the failure message, find which truth cluster fragmented, and record it in `docs/EVALUATION.md` as a known limitation naming the refs.

- [ ] **Step 5: Write the artisan command**

Create `app/Console/Commands/GpEval.php`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Eval\EvalRunner;
use App\GoldenProfile\Eval\EvalSet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GpEval extends Command
{
    protected $signature = 'gp:eval
        {--set=tests/eval/identity-pairs.json : path to the labeled set}
        {--min-precision=0.99 : fail below this precision}
        {--min-recall=0.80 : fail below this recall}
        {--allow-false-merges=0 : fail above this many false merges}';

    protected $description = 'Score the resolver against a labeled evaluation set';

    public function handle(): int
    {
        $set = EvalSet::load(base_path((string) $this->option('set')));

        // Same connection the resolver writes to — see EvalRunner's docblock.
        $hub = DB::connection('golden_profile');
        $database = $hub->getDatabaseName();

        if ($hub->table('stg_person')->exists() || $hub->table('gp_identity')->exists()) {
            $this->error("refusing to run: '$database' already holds staged people or identities.");
            $this->line('Point GP_DB_DATABASE at an empty scratch schema and migrate it first.');

            return self::FAILURE;
        }

        $systemId = (int) $hub->table('gp_source_system')->insertGetId([
            'system_code' => 'eval-'.uniqid(),
            'display_name' => 'eval harness',
            'reliability_rank' => 50,
            'is_active' => 1,
            'added_at' => now(),
        ]);

        $report = (new EvalRunner($systemId))->run($set)['report'];

        $this->table(['metric', 'value'], [
            ['true pairs', $report['true_pairs']],
            ['predicted pairs', $report['predicted_pairs']],
            ['true positives', $report['true_positives']],
            ['false merges', $report['false_merges']],
            ['false splits', $report['false_splits']],
            ['precision', number_format($report['precision'], 4)],
            ['recall', number_format($report['recall'], 4)],
            ['f1', number_format($report['f1'], 4)],
        ]);

        $failures = [];
        if ($report['false_merges'] > (int) $this->option('allow-false-merges')) {
            $failures[] = "false merges {$report['false_merges']} exceeds the allowance";
        }
        if ($report['precision'] < (float) $this->option('min-precision')) {
            $failures[] = 'precision below floor';
        }
        if ($report['recall'] < (float) $this->option('min-recall')) {
            $failures[] = 'recall below floor';
        }

        foreach ($failures as $f) {
            $this->error($f);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
```

- [ ] **Step 6: Verify the command registers**

```bash
php artisan list gp
```

Expected: `gp:eval` listed alongside `gp:backfill`, `gp:rebuild-aliases`, `gp:rebuild-profile`, `gp:sync`.

- [ ] **Step 7: Document it**

Append to `docs/EVALUATION.md`:

```markdown
## Running the evaluation

```bash
vendor/bin/phpunit tests/Feature/EvalGateTest.php   # the CI gate
php artisan gp:eval                                 # against a scratch hub
```

`gp:eval` refuses to run against a hub that already holds staged people or
identities. To use it, point **`GP_DB_DATABASE`** — the connection itself — at an
empty scratch schema and migrate it. Do not try to redirect it with
`golden_profile.connections.hub`: `DeterministicResolver` hardcodes the
`golden_profile` connection and ignores that key, so a mismatch would write
identities into the real hub.

The database-backed tests use a different mechanism again — `GP_TEST_DB_*`,
which `HubTestCase` reads. Unset, they skip.

## Adding labeled pairs

Edit `tests/eval/identity-pairs.json`. Two rules the loader enforces:

1. Every `ref` appears in exactly one `truth` cluster. A singleton is a
   one-element cluster — not optional, because singletons detect false merges.
2. No duplicate refs, and no truth entry naming an unknown record.

Add the case that broke, not a case like it. When a production mis-match is
found, add its shape here first, watch the gate fail, then fix the matcher.

## Reading the report

| Metric | Meaning | Who it hurts |
|---|---|---|
| `false_merges` | Two real providers welded into one identity | Precision. Gated at 0 — nothing downstream undoes a merge |
| `false_splits` | One provider left as two identities | Recall. This is how an excluded provider gets missed |

Floors are `--min-precision=0.99` and `--min-recall=0.80`. The recall floor sits
below the PROJECT_PLAN target of 0.95 because Pass B cannot auto-merge today: its
implemented weights sum to exactly `auto_merge_at`. Raise the floor as
calibration lands. Never lower it to make a build pass.

**Plan 2 will move this floor.** The `ssn-a`/`ssn-b` pair is bound by the
`ssn_hash` tier. When that tier is removed, they become a false split and recall
drops by a known, measured amount. Re-baseline the floor in that PR and say so in
the description — do not delete the records to keep the number up.
```

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Eval/EvalRunner.php app/Console/Commands/GpEval.php tests/Feature/EvalGateTest.php docs/EVALUATION.md
git commit -m "feat(eval): add gp:eval and gate the resolver on the labeled set"
```

---

## Task 7: CI

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `composer install`, `vendor/bin/pint`, `vendor/bin/phpunit`.
- Produces: a required status check.

- [ ] **Step 1: Nothing to do — no composer secret is needed**

This step originally created a `COMPOSER_AUTH_JSON` secret, because `streamlineverify/security`
was a private dependency. Commit `e64f73d` removed it (see Amendments), so every dependency is
public and `composer install` runs unauthenticated. Skip to Step 2.

- [ ] **Step 2: Write the workflow**

Create `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [master]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: gp_cami_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -proot"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=10

    env:
      GP_TEST_DB_HOST: 127.0.0.1
      GP_TEST_DB_PORT: 3306
      GP_TEST_DB_DATABASE: gp_cami_test
      GP_TEST_DB_USERNAME: root
      GP_TEST_DB_PASSWORD: root

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pdo, pdo_mysql, mbstring, bcmath
          coverage: none

      - name: Cache composer packages
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: composer-${{ hashFiles('composer.lock') }}

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Check code style
        run: vendor/bin/pint --test

      - name: Run tests
        run: vendor/bin/phpunit
```

The eval gate runs inside PHPUnit as `EvalGateTest`, so no separate `gp:eval` step is needed — the command exists for running against a real scratch hub.

- [ ] **Step 3: Verify the same commands pass locally**

```bash
vendor/bin/pint --test && vendor/bin/phpunit
```

Expected: Pint reports no style issues; PHPUnit passes every test. Confirm the run reports **no skipped tests** — a skip means `GP_TEST_DB_*` is unset and the database tests did not actually run.

- [ ] **Step 4: Commit and push**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: run pint and the full test suite including the match-quality gate"
git push -u origin feat/eval-harness
```

- [ ] **Step 5: Open the pull request**

```bash
gh pr create --title "[GPP-1] Foundation: measurable match quality" --body "$(cat <<'EOF'
## What

Plan 1 of the GPP conformance programme. Adds the labeled evaluation set,
precision/recall/F1 scoring, the first database-backed resolver tests, and CI.

| Added | Purpose |
|---|---|
| `tests/Support/HubTestCase.php` | Scratch-MySQL hub so the resolver is testable at all |
| `tests/eval/identity-pairs.json` | Labeled answer key — 17 records, 10 ground-truth clusters |
| `app/GoldenProfile/Eval/` | `EvalSet` loader, `MatchScorer`, `EvalRunner` |
| `app/Console/Commands/GpEval.php` | `php artisan gp:eval` against a scratch hub |
| `.github/workflows/ci.yml` | Pint + PHPUnit with a MySQL service, on every PR |

## Why

The GPP wiki's "Recommendations & Open Risks" makes this its P0: *make match
quality measurable — almost every other item gets easier once this exists.*
`PROJECT_PLAN.md` already gates Phase 2 on precision >= 0.99 and Phase 3 on
recall >= 0.95, but no eval set, scorer or CI existed to enforce either.

Plans 2 (SSN removal) and 3 (SCD-2) both restructure load-bearing matching
machinery. This is the regression net they need.

## Why MySQL and not SQLite for the harness

The schema reuses index names across tables — `idx_identity` is on eight of them,
and `idx_ssn` / `idx_npi` / `idx_dea` / `idx_name_dob` / `idx_zip` / `uq_action` /
`idx_type_value` are each duplicated. MySQL scopes index names per table; SQLite
scopes them per database, so `migrate` dies on the second `gp_*` table. Renaming
twenty-plus indexes on a live 13M-row hub to suit a test harness is the wrong
trade, so the harness uses a scratch MySQL schema instead.

## Gate

False merges are gated at **zero** — precision-first per
`config/golden_profile.tracks.identity`. Recall floors at 0.80 rather than the
0.95 target, because Pass B cannot auto-merge today: its implemented weights sum
to exactly `auto_merge_at`. The floor rises as calibration lands.

## Notes

- No production behaviour changes. Every file is a test, a fixture, a doc, or a
  new command.
- `scripts/baseline-key-mix.sql` records the pre-change key mix; the numbers are
  in `docs/EVALUATION.md` and size the blast radius of the SSN removal in plan 2.
- No composer secret required: `e64f73d` removed the last private dependency, so
  every package resolves from public packagist.
EOF
)"
```

---

## Self-review

**Spec coverage.** The scope answered was "conformance in the Laravel hub" plus "change the code to match the docs" on both contradictions. This plan covers the foundation those depend on; the eight-plan table maps every remaining audit finding to a numbered plan, with the two contradictions as plans 2 and 3. No finding is unassigned.

**Placeholders.** One intentional blank: the baseline metric table in Task 1 Step 3, whose values come from running the SQL against a hub this plan cannot reach. The step says exactly how to fill it.

**Type consistency.** `MatchScorer::score()` returns the same eight keys consumed by `EvalRunner::run()`, `EvalGateTest` and `GpEval::handle()`, with `precision`/`recall`/`f1` cast to float so the contract holds. `EvalSet` exposes `load()`, `loadArray()`, `records()`, `licenses()`, `truthClusters()`, `path()`. `HubTestCase` exposes `hub()`, `seedSystem()`, `stagePerson()`, `stageLicense()`, `blockKey()` and `$systemId`; `EvalRunner` re-implements staging rather than depending on a test class, since it also runs under `gp:eval`.

**Verified against the repo.** `DeterministicResolver::__construct(private int $systemId)` and `resolve(int): int` match. Every NOT-NULL column without a default on `stg_person`, `stg_person_license` and `gp_source_system` is set by the harness. `match_key = 'new'` on a fresh identity is confirmed at `DeterministicResolver.php:88`. The `--path` option is `IS_ARRAY`, hence the array form. `config('golden_profile.connections.hub')` is read only by `SsnHashGuard`, `AliasIndexer` and `GpRebuildAliases` — never by the resolver — which is why Task 6 hardcodes the connection name.

**Known risks carried into execution.**
1. Task 3 Step 2 may reveal existing resolver defects; the plan says record and fix them in separate commits rather than weaken the tests. Expect one or two unplanned fix commits.
2. `migrate:fresh` drops every table in the target schema. The name guard in `HubTestCase` requires the database name to both start with `gp_` and contain `test`, but a mis-set `GP_TEST_DB_DATABASE` is still the single most dangerous value in this plan. Check it before the first run.
3. `HubTestCase` migrates once per process and rolls back per test, so a test that commits its own transaction leaks state into later tests. Nothing in this plan does; watch for it in Plans 3 and 6, which touch write paths.

## Amendments made during execution

This document was revised after a final whole-branch code review found it still
described defects that had already been fixed in the shipped code — it had not
been kept in sync as those per-task fixes landed. Corrections:

- **Composer credentials (originally Task 7 Step 1 and a Global Constraint).** The plan assumed
  `streamlineverify/security` and `streamlineverify/sv` were private dependencies needing a CI
  secret. `sv` was never actually required at all, and `security` was used for exactly one
  constant — `LocalStrategy::getKey()` returns the literal `F1CB3D8DCE44E` and ignores its
  argument. Commit `e64f73d` replicates CAMI's own resolution inline (`subject` +
  `subject_attribute` + `subject_id IS NULL` + `status`, dispatching on the row's `manager`)
  and drops the package. That also fixed a real mismatch: gp-cami had been filtering on
  `subject_attribute` alone, so it could select a different key row than CAMI and every
  `ssn_hash` would silently stop matching. Lock went 122 -> 78 production packages, no private
  repos remain, and CI needs no secret.
- **Schema guard (originally ~line 348).** Changed `str_contains($database, 'test')`
  to `str_starts_with($database, 'gp_') && str_contains($database, 'test')`. The
  substring-only check admits `streamline_test`, a real database on the same
  server; shipped code (`ff0ea9e`) closes that before the harness was ever run
  against real infrastructure.
- **Fixture `ssn_hash` (originally ~lines 659/661).** Replaced the sha256 value
  `e3b0c4...` — which is `sha256("")`, a filler hash and only 64 hex chars — with
  the shipped 128-char sha512 of a literal fixture string. A filler hash is
  exactly what `SsnHashGuard` is designed to block, so the original value would
  have made the `ssn-a`/`ssn-b` pair fail to bind for the wrong reason (`8c4e383`,
  `7222f18` fixed this during execution).
- **Pair key (originally ~line 1072).** Changed the `'|'`-joined key to a
  `"\0"`-joined key. A printable separator collides when a ref itself contains
  that separator; shipped code (`570a750`) fixed this before it shipped.
- **Guard description (originally ~line 1609).** Updated "requires `test` in the
  database name" to describe the actual two-part rule (`gp_` prefix AND `test`),
  matching the schema-guard correction above.
- **Pint was not anticipated.** `vendor/bin/pint --test` failed on master before
  any of this plan's own code was touched — twelve pre-existing files predated
  the style config. That required an unplanned `style:` commit (`4cda323`)
  to clear the codebase before CI could gate on `pint --test` at all. This plan
  did not budget for that step.

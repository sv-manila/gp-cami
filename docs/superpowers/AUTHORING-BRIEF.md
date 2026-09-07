# Shared authoring brief — GPP conformance plans 2–8

You are writing ONE plan in an eight-plan programme. This file holds everything every plan
author needs: the verified state of the repo, the conventions, and the traps. Read it fully
before writing. Do not re-derive these facts — they were established by executing plan 1 and
several were only discovered the hard way.

---

## 1. The programme

`gp-cami` (repo `sv-manila/gp-cami`, **public**) is a Laravel identity-resolution hub. It collapses
scattered person records from `streamline_local` into golden identities and serves them to CAMI over
two REST endpoints.

The programme brings it into conformance with a Confluence design set (the "GPP Wiki" in the DEV
space, plus two CAMI-specific pages). Two scope decisions are **already made by the user and are not
open**:

1. **Conformance inside the Laravel/MySQL hub.** No AWS lakehouse, no Iceberg/Spark, no ML matcher,
   no external government-feed ingestion. `PROJECT_PLAN.md` §8 records that rejection; it stands.
2. **Code changes to match the docs** on the two documented contradictions — SSN storage (plan 2)
   and SCD-2 versioning (plan 3). Do not propose changing the docs instead.

| # | Plan | Depends on | Status |
|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — | **DONE, on the branch** |
| 2 | SSN removal | 1 | written, 9 tasks |
| 3a | SCD-2 versioning — schema + per-row paths | 1 | written, 10 tasks |
| 3b | SCD-2 set-based parity | 3a | written |
| 4 | Individual vs entity | 1, 3a | written |
| 5 | Match keys & data quality | 1 | written, 10 tasks |
| 5b | Pass B blocking | 5 | written |
| 6 | Steward writer layer | 1, 3a | written |
| 7 | Exclusion lifecycle | 1, 3a | written |
| 8 | Incremental profiling | 1, 5 | written |

Ten documents, not eight: plan 3 split into 3a/3b (the boundary is where the technique changes —
per-row PHP calling one primitive, versus set-based SQL reproducing its semantics; 3a ships a
`SetBasedPathGuard` that makes the bulk paths REFUSE to run until 3b lands, so 3a cannot ship a
silently corrupting bulk path). Plan 5 split off 5b for Pass B blocking, which interacts with the
`block_size_cap` silent-split landmine and needed its own eval baseline.

Plan 1 lives at `docs/superpowers/plans/2026-09-03-gpp-conformance-foundation.md`. **Read its
"Programme context" and "Global Constraints" sections**, and its Task 2 (the harness) if your plan
writes tests — you will inherit `HubTestCase`.

Naming: `docs/superpowers/plans/2026-09-03-gpp-conformance-<slug>.md`.

---

## 2. Verified repo state

Branch `feat/eval-harness`, 18 commits ahead of `master` (`17d383d`). Suite: **92 tests, 261
assertions, 0 skipped**, `vendor/bin/pint --test` clean.

### app/
```
Console/Commands/        GpBackfill GpEval GpRebuildAliases GpRebuildProfile GpSync
Exceptions/              TooManyCredentialLinksException
GoldenProfile/
  Connectors/            StreamlineLocalConnector          (source -> stg_person shape)
  Engine.php             backfill/sync/dedup/rollups/finalize orchestrator, 747 lines
  Eval/                  EvalRunner EvalSet MatchScorer     (plan 1)
  Materialize/           AliasIndexer ProfileMaterializer SetFinalizer
  Resolution/            DeterministicResolver ProbabilisticResolver Survivorship
  SqlBackfill.php         set-based backfill, RAW MySQL, 697 lines
  Support/               CredentialSelector NameMatcher SsnHashGuard SsnHasher
Http/
  Controllers/Api/V1/    CredentialSearchController IdentitySearchController
  Requests/              CredentialSearchRequest IdentitySearchRequest
  Resources/             IdentityProfileResource
Models/Gp/               GpBoardAction GpIdentity GpIdentityProfile GpIdentityResolution
                         GpLicense GpModel GpSourceLink
Models/Src/              CredentialMatch Employee ReadOnlyModel      (read-only)
```

### database/migrations/
```
0001_01_01_000000  users            (stock Laravel)
0001_01_01_000001  cache            (stock)
0001_01_01_000002  jobs             (stock)
2026_07_20_133355  personal_access_tokens
2026_07_20_140000  create_golden_profile_schema                 <- the 17 gp_*/stg_* tables
2026_07_20_150000  add_passb_survivorship_pinned_boardactions   <- match_state, suffix,
                                                                   gp_survivorship_audit, gp_board_action
2026_07_25_000000  create_src_mirror_tables      src_credential_match src_match src_exclusion_record
2026_07_25_000001  add_identifiers_and_additional_info   stg_person_identifier gp_identity_identifier
2026_07_25_000002  add_identifiers_to_profile
2026_08_10_000000  create_gp_identity_alias
```
Add new migrations with a `2026_09_..` prefix. **Never edit a migration that has already run** —
the shared hub has them applied. New behaviour = new migration.

### tests/
```
Support/     HubTestCase                     (plan 1 — the DB harness)
Feature/     EvalGateTest ResolverLadderTest CredentialLinkCapTest ExampleTest
Unit/        AliasIndexConfigTest CredentialIdentityGateTest CredentialSelectorTest
             DeterministicKeyConfigTest EvalSetShapeTest MatchScorerTest
             ProbabilisticScoringTest SsnHashGuardTest SsnHasherTest
             TooManyCredentialLinksExceptionTest ExampleTest
```

### Artisan commands
```
gp:backfill  gp:sync {system=streamline_local} {--chunk=1000}
gp:eval  gp:rebuild-aliases  gp:rebuild-profile {--identity=}
```

---

## 3. Environment facts — do not re-derive these

- **`composer install` needs NO credentials.** Every dependency is public packagist (Laravel 13.20,
  Sanctum, Tinker, Predis). The last private package, `streamlineverify/security`, was removed in
  `e64f73d`; 78 production packages remain. `vendor/` exists.
- **PHP 8.4.12 local, `require` is `^8.3`.** Laravel **13**.20 (not 12). PHPUnit 12.5.
- **MySQL for tests is `192.168.56.22`, root/root, schema `gp_cami_test`.** It is a VirtualBox VM
  (`vagrant up` in `C:\projects\dramiel\client`). The hostname
  `dramiel.app.streamlineverify.local` does NOT resolve on this machine — use the IP.
  The same server also hosts `streamline_local`, `streamline_test`, `streamline_integration`,
  `admin_dash_sb`. **`gp_cami_test` is the only safe write target.**
- **`mysql` CLI is NOT on PATH.** Use PHP PDO for any ad-hoc SQL.
- **No production hub access.** Nobody in this environment has credentials for the real
  `golden_profile` hub. Any step needing prod data must be written as a step for a human, with the
  command, and must not block the rest of the plan.

### THE SQLITE TRAP — the single most expensive mistake plan 1 made

Do **not** propose SQLite for any test harness. The schema reuses index names across tables —
`idx_identity` is on eight of them, and `idx_ssn`, `idx_npi`, `idx_dea`, `idx_name_dob`, `idx_zip`,
`uq_action`, `idx_type_value` are each duplicated. MySQL scopes index names per table; **SQLite
scopes them per database**, so `migrate` dies at the second `gp_*` table with
`index idx_identity already exists`. Plan 1 specified SQLite and was dead on arrival at its first
command. Use `Tests\Support\HubTestCase`, which is already MySQL-backed.

### `SqlBackfill` cannot be tested on anything but MySQL

It is raw MySQL — `INSERT … SELECT`, backticks, `NOW()`, `CONCAT_WS`. If your plan changes
resolution semantics, it almost certainly must change **both** `DeterministicResolver` (per-row) and
`SqlBackfill` (set-based), and the two must agree. Plan 1's Task 3 covers the per-row path only.
Say explicitly in your plan whether the set-based path needs the same change, and add a task for it
if so. Divergence between them is a live risk: they already have a documented tiebreak-ordering
agreement (`Survivorship` ends in `link_id ASC` to match `SetFinalizer`'s SQL) precisely because a
mismatch broke the "rebuild produces a byte-identical profile" invariant.

---

## 4. What `HubTestCase` gives you

`tests/Support/HubTestCase.php`. Extend it for any test needing a database.

```php
protected int $systemId;                                   // a seeded gp_source_system row
protected function hub();                                  // the golden_profile connection
protected function seedSystem(string $code = 'streamline_local', int $reliability = 50): int;
protected function stagePerson(array $overrides = []): int; // -> stg_person_id
protected function stageLicense(int $stgPersonId, string $number, ?string $state = null): void;
protected function blockKey(?string $last, ?string $dob): ?string;
```

- Reads `GP_TEST_DB_*` from `.env`, **skips** when unset (never silently falls back to a real hub).
- Runs `migrate:fresh` **once per process**; each test is wrapped in a transaction rolled back in
  `tearDown()`.
- `guardAgainstTheRealHub()` refuses any database whose name does not **start with `gp_` AND contain
  `test`**. This is load-bearing: the weaker `str_contains($db, 'test')` admitted the real
  `streamline_test` schema. Do not weaken it.
- `stagePerson()` defaults: Robert Smith, dob 1970-04-02, all identifiers null, `block_key` computed.
  An explicit `block_key` override wins.
- **`tearDown()` sweeps every `gp_`/`stg_`/`src_` table unconditionally, as of `cf38efd`.** The
  rollback alone was not isolation: MySQL implicitly commits on any DDL, so one CREATE TABLE inside
  the code under test ends the transaction at the server while Laravel still believes it is open —
  and Laravel's `causedByConcurrencyError()` matches "There is no active transaction", so
  `rollBack()` returns successfully having rolled back nothing. `SsnHashGuard`'s
  `CREATE TABLE IF NOT EXISTS` is exactly that shape and is reached from
  `SqlBackfill::resolveDeterministic()`. **Consequence for plan authors: you do NOT need to work
  around this in your own tests.** `deleteAllHubRows()` is also `protected`, so a test that
  knowingly commits can call it directly.

---

## 5. The eval gate — every plan that touches matching MUST address it

`tests/Feature/EvalGateTest.php` scores the resolver against `tests/eval/identity-pairs.json`
(17 records, 10 truth clusters, 9 true pairs). Current measured result:

**precision 1.0000 · recall 1.0000 · F1 1.0000 · 0 false merges · 0 false splits**

The gate asserts, in this order:
```php
assertGreaterThanOrEqual(9, $report['true_pairs'])      // the set must not shrink
assertSame(0, $report['false_merges'])                  // absolute — precision-first
assertGreaterThanOrEqual(0.99, $report['precision'])    // programme floor
assertGreaterThanOrEqual(0.80, $report['recall'])       // programme floor
assertSame(0, $report['false_splits'])                  // ratchet on measured
assertSame(1.0, $report['recall'])                      // ratchet on measured
```

Rules for your plan:

- If your change alters matching, the plan MUST contain a task that **re-runs the gate, records the
  new numbers, and re-baselines the two ratchet assertions in the same commit**, stating the new
  values and why they moved.
- **Never** instruct anyone to lower `min-precision`, lower `min-recall`, delete an assertion, or
  remove records from the fixture to make the gate pass. `docs/EVALUATION.md` forbids it explicitly.
- If your change ADDS a matching capability, add fixture records that exercise it and raise the
  ratchet. `MatchScorer::score([], [])` returns a perfect score, which is why `true_pairs >= 9`
  exists — respect that pattern if you change the denominator.
- `docs/EVALUATION.md` is the place to record measured numbers. Its **baseline table is still all
  `PENDING`** — it needs production hub access nobody here has.

---

## 6. Known landmines in the current code

Cite these in your plan where relevant; they are all verified.

| Where | What |
|---|---|
| `config/golden_profile.php` probabilistic weights | Implemented weights sum to **exactly** `auto_merge_at` (0.92) because `provider_type` (0.08) has no source column. `auto_match` is therefore unreachable in practice; Pass B only ever reaches the review band. `ProbabilisticResolver::warnIfAutoMergeUnreachable()` logs it; `ProbabilisticScoringTest` pins it. |
| `ProbabilisticResolver.php:86-93` | Over `block_size_cap` it returns `no_match` and the caller **mints a new identity stamped `auto_match`** — a silent false split. The config comment claims blocks are "flagged for steward"; that half does not exist. Null-DOB buckets like `D500\|____` hold ~107k people. |
| `gp_identity_resolution` | Table + model exist; **never written**. `CredentialSearchController::priorResolution()` therefore always returns null. Plan 6 owns fixing this. |
| `gp_board_action` | Schema + rollups exist; **zero inserts**. `board_action_count` / `has_active_board_action` permanently 0. Plan 6 owns this. |
| `gp_identity_exclusion.link_state` | Defaults to `candidate`, **never transitions**. Plan 6/7. |
| `survivorship.status_severity`, `internal_verified_decay_days` | Configured, **read nowhere**. Plan 6. |
| `gp_identity_identifier` (DEA, MMIS) | Not a Pass A tier and not a blocking key. Only influences identity after the fact via `Engine::mergeByIdentifier` in `dedup`. Plan 5. |
| NPI | No check-digit validation anywhere; only screen is `$npi > 0` in the connector. It is a 0.99 tier AND a hard-no signal. Plan 5. **Verified 2026-09-04:** two eval-fixture NPIs are check-digit INVALID — `1987654327` (smith-other) and `1112223339` (chain-a); correct values are `1987654328` / `1112223338`. Harmless today (neither is shared between records, and the chain-* cluster merges via name+dob and licence, not NPI) but they become silent no-ops once validation lands. Plan 5 Task 2 owns the correction — do not duplicate it. |
| Entities | No `entity_type` / `org_name` anywhere. Business names ride in as **aliases**: 107,882 of 107,888 `gp_identity_alias` rows have a null surname, i.e. almost every alias is an org name in a person structure. Plan 4. |
| `EvalSet::licenses()` | Returns `[]` for an unknown ref as well as a known ref with no licenses. |
| `MatchScorer::pairs()` | Keys on a null byte. Docblock's "cannot appear in a ref" is a data-domain assumption, not a guarantee. |
| `GpEval` | Guards on schema name (`gp_` + `test`) then emptiness; wraps in a transaction it rolls back. |
| `SsnHasher` | As of `e64f73d` it replicates CAMI's own lookup inline: `subject` + `subject_attribute` + `subject_id IS NULL` + `status='1'`, dispatching on the row's `manager`. Local key comes from `GP_SSN_LOCAL_MANAGER_KEY`. **Plan 2 deletes this file.** |
| `tests/Unit/SsnHashGuardTest.php` | Emits a PHPUnit notice (mock without expectations). Test output is not fully pristine. |

---

## 7. Plan document format — follow exactly

Start with this header, filled in:

```markdown
# <Feature Name> Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** <one sentence>

**Architecture:** <2-3 sentences on approach>

**Tech Stack:** PHP ^8.3 (8.4.12 local), Laravel 13.20, PHPUnit 12.5, Laravel Pint, MySQL 8.

## Global Constraints

<copy section 8 below verbatim, plus anything specific to your plan>

---

## Programme context — this is plan N of 8

<the dependency table from section 1, with your row bolded, plus 2-3 sentences on why this
plan sits where it does in the order>

---

## File Structure

<table: every file created or modified, and its one responsibility>

---

## Task N: <name>

**Files:**
- Create: `exact/path`
- Modify: `exact/path:LINE-LINE`
- Test: `exact/path`

**Interfaces:**
- Consumes: <exact signatures from earlier tasks>
- Produces: <exact function names, parameter and return types later tasks rely on>

- [ ] **Step 1: Write the failing test**
<complete test code in a fenced php block>

- [ ] **Step 2: Run it to verify it fails**
Run: `vendor/bin/phpunit path --filter=name`
Expected: FAIL with "<exact message>"

- [ ] **Step 3: Write the implementation**
<complete code>

- [ ] **Step 4: Run to verify it passes**
Run: ...
Expected: PASS

- [ ] **Step 5: Commit**
```bash
git add <paths>
git commit -m "type(scope): imperative summary"
```
```

End the document with a `## Self-review` section: spec coverage, placeholder scan, type consistency,
and known risks carried into execution.

### Hard rules on content

- **Every step that changes code shows the code.** No "add appropriate error handling", no "similar
  to Task N", no "write tests for the above", no TBD.
- **Exact file paths, exact commands, exact expected output.**
- Tasks are bite-sized: one action per step, 2–5 minutes. Test → watch it fail → implement → watch
  it pass → commit.
- A task ends with an independently testable deliverable. Split only where a reviewer could
  reasonably reject one task while approving its neighbour.
- Repeat code rather than cross-referencing — the implementer sees only its own task brief.
- Never reference a type, function or method that no task defines.
- Aim for 5–9 tasks. If your scope needs more than about 10, say so in Self-review and propose a
  split rather than writing an unexecutable document.

---

## 8. Global Constraints block — copy verbatim into your plan

```markdown
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
```

---

## 9. Tone

Write like the existing code comments: state the reason, not just the rule. Where a decision has a
non-obvious cost, name the cost. Where a number was measured, give the measurement. The plans that
executed well were the ones whose comments explained why a thing was the way it was — an implementer
who understands the reason makes better calls when reality differs from the plan.

# GPP Conformance — SCD-2 Set-Based Parity (plan 3b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the two set-based bulk paths — `SetFinalizer` and `SqlBackfill`, ~1,400 lines of raw MySQL — to the slowly-changing-dimension write rule plan 3a put in `Versioner`, reproducing that primitive's loose-comparison semantics in `INSERT … SELECT` form, then delete `SetBasedPathGuard` on the strength of a test that proves the per-row and set-based paths mint the same clusters, the same version counts and the same profile rows from identical input.

**Architecture:** Two new classes carry the whole conversion so the rule cannot drift across the seven set-based write sites: `VersionerSql` renders `Versioner::same()` and `Versioner::differs()` as SQL expressions (byte-exact via `CAST(… AS BINARY)`, NULL-strict, with the date-prefix rule intact), and `SetVersionWriter` is the set-based twin of `Versioner::write()` — build the incoming set into a scratch table, materialise the complete next version for the keys that actually differ, flip, insert. `SetFinalizer::survivorship()`'s nine per-field `UPDATE`s collapse into one ranked-winner pivot plus one `writeIdentities()` call; `SqlBackfill`'s `backfillIdentityKeys()`, `enrich()` and `rollup()` become `SetVersionWriter` calls; `residualCreateAndLink()` stops borrowing `merged_into` as scratch and gets its own `stg_seed_id` column. The proof is a parity harness that abandons `HubTestCase`'s per-test transaction on purpose — every set-based entry point issues DDL, which implicitly commits in MySQL — and isolates the two runs with an explicit `TRUNCATE` sweep instead.

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

Specific to this plan:

- **This plan starts from plan 3a's end state.** The schema is versioned
  (`2026_09_04_000100_add_scd2_versioning`), `gp_identity_credential.current` has already been
  renamed to `source_current` (`2026_09_04_000000`), `App\GoldenProfile\Support\Versioner` exists,
  and `SetBasedPathGuard` makes `SetFinalizer::run()` and `SqlBackfill::transform()` throw. Read
  `docs/superpowers/plans/2026-09-03-gpp-conformance-scd2-versioning.md` §"Which tables are
  versioned", §"Row growth, indexes, and the performance cliff", Task 5 (`Versioner`) and Task 10
  (the guard) before starting.
- **The guard only covers `run()` and `transform()`.** `SetFinalizer::survivorship()`,
  `SetFinalizer::materialize()`, `SqlBackfill::indexStaging()`, `SqlBackfill::resolveDeterministic()`,
  `SqlBackfill::enrich()` and `SqlBackfill::rollup()` are all callable while it is in place. Every
  test in this plan drives those methods directly; nothing here needs the guard lifted before
  Task 8.
- **MySQL implicitly commits on DDL, and every bulk entry point issues some.**
  `SetFinalizer::survivorship()` and `SqlBackfill::residualCreateAndLink()` `ALTER TABLE gp_identity`
  to drop and rebuild the five key indexes; `SqlBackfill::indexStaging()` `ALTER TABLE stg_person`;
  `SsnHashGuard::buildBlocklistTable()` runs `CREATE TABLE IF NOT EXISTS` and `TRUNCATE`. Any of
  those commits `HubTestCase`'s per-test transaction mid-test and leaks the fixture into every later
  test in the process. Task 1 closes that off two ways: the index maintenance is skipped while a
  transaction is open, and a `SetBasedTestCase` harness gives up the transaction on purpose and
  cleans with `TRUNCATE`. **`CREATE`/`ALTER`/`DROP TEMPORARY TABLE` do NOT implicitly commit** —
  that exemption is what makes the scratch-table design in this plan legal inside a transaction.
- **A `TEMPORARY` table may be referenced only once per statement.** MySQL 8 raises
  `Can't reopen table` otherwise. Every statement in this plan touches each scratch table at most
  once; if you refactor one to join a scratch table to itself, materialise a second copy.
- **`current` is not `alive`, and a missed filter does not throw.** Same two rules as 3a. Every
  aggregate in `materializeRange()` counts rows, so a missing `AND current = 1` doubles a count and
  duplicates a JSON entry silently. Every read-path step below ends with a test that asserts the
  count, not just the shape.
- **The natural-key uniques are NULL-permissive, so `uq_*_current` cannot enforce single-current
  for a key with a NULL part.** 3a built `current_key` with `CONCAT` (NULL-propagating) precisely to
  reproduce that. Consequence for this plan: every key join in set-based SQL uses `<=>`, not `=`, or
  a licence with a NULL `certification_state` gets a duplicate row on every run — which is what the
  current `ON DUPLICATE KEY UPDATE` code already does. See "The NULL-key trap" below.

---

## Programme context — this is plan 3b of a ten-document programme

| # | Plan | Depends on | Status |
|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — | DONE, merged into this branch |
| 2 | SSN removal | 1 | written |
| 3a | SCD-2 versioning — schema, `Versioner`, the per-row paths | 1 | written |
| **3b** | **SCD-2 set-based parity** *(this document)* | **3a** | **this document** |
| 4 | Individual vs entity | 1, 3 | to write |
| 5 | Match keys & data quality | 1 | written |
| 6 | Steward writer layer | 1, 3 | to write |
| 7 | Exclusion lifecycle | 1, 3 | to write |
| 8 | Incremental profiling | 1, 5 | to write |

**Why here, and why it is a separate document.** 3a's Self-review put the split exactly where the
*technique* changes rather than where the file does: everything in 3a is per-row PHP calling one
primitive, everything here is set-based SQL that has to reproduce that primitive's semantics in
`INSERT … SELECT` form. Splitting anywhere else — "schema first, then writes, then reads" was the
other candidate — would have left the hub with a versioned schema and half its write paths
unversioned, which is the silent-corruption window `SetBasedPathGuard` exists to close.

**Nothing else in the programme depends on 3b.** Plans 4, 6 and 7 need the versioned schema and the
`Versioner` primitive, and 3a delivers both. What they get from 3b is the ability to run
`gp:backfill` at all: while the guard stands, the only working load path is the per-row one, which is
~350M small statements over 13.4M source rows. So 3b is not on anyone's critical path for
correctness and squarely on the critical path for operability.

**Interaction with plan 2 (SSN removal).** Both plans edit the same four regions of `SetFinalizer`
and `SqlBackfill`. See "Landing order against plan 2" below for the recommendation and the exact
edit-by-edit consequence of flipping it.

---

## What "parity" means here, and what it cannot mean

The repo already carries a documented invariant: a set-based materialize and
`ProfileMaterializer::rebuild()` produce **byte-identical** profile rows. `Survivorship`'s final
tiebreak is pinned to `link_id ASC` "to match SetFinalizer's SQL ordering … because a mismatch broke
the *rebuild produces a byte-identical profile* invariant". That invariant is the acceptance test for
Task 4 and the backbone of Task 8.

It is also, read literally, not achievable for every column, and pretending otherwise would make
Task 8 unexecutable. Three columns are genuinely order-dependent in a way MySQL 8 cannot pin:

| Column | Why byte-identity is not available | What this plan does |
|---|---|---|
| `licenses`, `addresses`, `identifiers`, `credentials`, `exclusions`, `board_actions`, `resolutions`, `aliases`, `source_records`, `accounts` | `JSON_ARRAYAGG` has no `ORDER BY` in MySQL 8. Element order is whatever the aggregation happened to see. The per-row path builds the array in query-return order, which is also unpinned. | Compare as **multisets**: decode, sort by the canonical JSON of each element, re-encode, compare. The parity helper does this; scalars stay byte-compared. Recorded in `docs/SCD2.md`. |
| `address1`/`city`/`state`/`zip` (the primary-address scalars) | `ProfileMaterializer` picks `firstWhere('is_primary', 1) ?? first()` over an **unordered** result; `SetFinalizer`'s `$prim` uses `ROW_NUMBER() … ORDER BY is_primary DESC, address_id ASC`. They agree only by luck. | Task 4 adds `->orderBy('address_id')` to the per-row read. One line, removes a real nondeterminism, makes the invariant true rather than lucky. |
| `terminated`, `dea_number` fallback, `ssn_last_four` | Same shape: the per-row reads have no tiebreak where the set-based ones do (`source_modified DESC, stg_person_id DESC`; `MAX(id_value)`; `stg_person_id ASC`). | Task 4 adds the matching tiebreaks to `ProfileMaterializer`. `ssn_last_four` is deleted outright by plan 2 — see the landing-order section. |

And two divergences are **pre-existing, out of scope, and must be designed around rather than
fixed**, because fixing either would change matching and this plan asserts the eval gate does not
move:

| Divergence | Per-row | Set-based | Handling |
|---|---|---|---|
| Which staged row's value wins for a repeated licence/address | last observation wins (`updateOrInsert` per row) | `MAX(...)` over the group | The Task 8 fixture gives every repeated licence/address identical non-key attributes, so both paths land on the same value. Stated as a fixture constraint and a known risk. |
| `backfillIdentityKeys` filler-SSN screen | `DeterministicResolver::backfillKeys()` skips a blocked `ssn_hash` | set-based `backfillIdentityKeys()` has no blocklist check at all | **Not fixed here.** Adding the screen is a matching change, and plan 2 deletes `ssn_hash` from every one of these paths. Recorded in `docs/SCD2.md` as a divergence owned by plan 2. |

---

## Reproducing `Versioner::same()` in SQL — the crux

3a's `Versioner::same()` is the reason the two paths can disagree about how many versions to mint
from identical input, and 3a's Known Risk 2 names it as the loosest part of the design. Here it is,
with the SQL that has to reach the same verdict on every column type in the six versioned tables:

```php
    private function same($stored, $incoming): bool
    {
        if ($stored === null || $incoming === null) {
            return $stored === null && $incoming === null;
        }

        $a = $stored instanceof \DateTimeInterface ? $stored->format('Y-m-d H:i:s') : (string) $stored;
        $b = $incoming instanceof \DateTimeInterface ? $incoming->format('Y-m-d H:i:s') : (string) $incoming;

        if (strlen($a) === 10 && strlen($b) >= 10 && str_starts_with($b, $a)) {
            return true;
        }
        if (strlen($b) === 10 && strlen($a) >= 10 && str_starts_with($a, $b)) {
            return true;
        }

        return $a === $b;
    }
```

Four properties have to be carried across, and each one has a specific SQL consequence:

**1. NULL is strict, and that is not `<=>`.** `same()` returns true only when *both* sides are null,
false when one is. `<=>` agrees on both of those, so `<=>` alone would be right *for the equality
branch* — but the loose branches must not run when either side is null, and `LEFT(NULL, 10) = NULL`
is NULL, not false, which in a `NOT (…)` wrapper degrades to "unknown" and then to "no change". So
the null case is handled by an explicit leading `CASE WHEN` arm rather than left to three-valued
logic. The one place `<=>` *is* used is key joins and the derived-column no-op check, where
NULL-means-equal is what `Versioner`'s Laravel `->where($key)` does (Laravel converts a null value
under `=` into `whereNull`).

**2. `$a === $b` is a byte comparison; MySQL `=` is not.** `gp_identity` and `stg_person` are
`utf8mb4_unicode_ci`, so `'SMITH' = 'Smith'` is TRUE in the server and FALSE in PHP. Left alone,
the per-row path would mint a version for a case change and the set-based path would not — and worse,
the set-based canonical value would then be frozen at `'SMITH'` forever while the per-row path
tracked the source. So every comparison is wrapped in `CAST(… AS BINARY)`, which forces a byte
comparison on both sides. This is deliberate and it is the single most important line in
`VersionerSql`.

**3. `strlen()` is bytes and `str_starts_with()` is bytes; `CHAR_LENGTH()` and `LEFT()` are
characters.** A 10-character non-ASCII value has `strlen() > 10` but `CHAR_LENGTH() = 10`, so
`CHAR_LENGTH` would arm the date-prefix branch on a name PHP would never arm it on. `LENGTH()`
returns bytes, and `LEFT()` applied to an argument already cast to `BINARY` operates on bytes. So the
prefix branches read `LENGTH(CAST(x AS BINARY))` and `LEFT(CAST(x AS BINARY), 10)`.

**4. Type rendering has to match PDO's.** `same()` casts whatever PDO handed back; the SQL casts the
column. They agree column by column, and the table below is the audit — this is what makes it safe to
compare a `DATE` against a scratch `VARCHAR(500)` holding a staged date:

| Column type in the six versioned tables | PHP `(string)` of the PDO value | `CAST(col AS BINARY)` | Verdict |
|---|---|---|---|
| `DATE` (`canonical_dob`) | `'1970-04-02'` | `'1970-04-02'` | identical, `LENGTH` 10 both sides |
| `DATETIME` (`date_resolved`, `date_created`) | `'2026-09-04 12:00:00'` | `'2026-09-04 12:00:00'` | identical, `LENGTH` 19 |
| `BIGINT UNSIGNED` (`npi`, `merged_into`, `exclusion_record_id`) | `'1234567893'` | `'1234567893'` | identical |
| `INT` (`match_summary_status_code`, `record_count`) | `'42'` | `'42'` | identical |
| `TINYINT` (`match_is_valid`, `source_current`, `is_verified`, `is_primary`, `is_*_match`) | `'0'` / `'1'` | `'0'` / `'1'` | identical |
| `VARCHAR`/`CHAR` (`canonical_*`, `ssn_hash`, `upin`, `dea_number`, `registry`, `license_*`, `address*`, `city`, `state`, `zip`, `id_type`, `id_value`) | the bytes | the bytes (`CHAR` trailing spaces are already stripped on retrieval, and plain `BINARY` with no length does not re-pad) | identical |
| `ENUM` (`link_state`, `status`) | `'confirmed'` | `'confirmed'` | identical |
| `DECIMAL(5,4)` (`link_confidence`) | `'0.9900'` | `'0.9900'` | identical **as long as nobody passes a float** — see the trap below |

> **Trap worth naming: never route a `DECIMAL` through `attributes` from a PHP float.** `0.99` casts
> to `'0.99'` and the column round-trips as `'0.9900'`, so `same()` would report a change on every
> single write and mint a version per write forever. Today neither path passes `link_confidence` at
> all (it is in `Versioner::TABLES` but no caller supplies it, so it carries forward), which is why
> this has not bitten. If a future plan starts writing it, write it as a string with the column's
> scale.

**Where the date-prefix rule actually fires.** In the set-based paths: nowhere. Every incoming value
for `canonical_dob` comes from `stg_person.date_of_birth`, a `DATE`, so both sides are `LENGTH` 10 and
the equality branch settles it; `date_resolved` is `DATETIME` on both sides at `LENGTH` 19. The branch
is still implemented, for two reasons: 3a's per-row path *can* arm it (a caller handing over a Carbon
or a `DATETIME` string for a `DATE` column), and a plan that changes a column type must not silently
acquire a divergence. Task 2's differential test exercises it directly.

**The other half of `differs()`: absent versus NULL.** `Versioner::differs()` skips a column that is
not a key of `$incoming` — absent means "carry forward, no change" — but *compares* a column present
with a NULL value. That distinction is load-bearing and it splits the set-based callers in two:

| Semantics | Which callers | SQL form |
|---|---|---|
| **NULL means absent** — a survivorship field with no non-blank candidate, a `backfillIdentityKeys` column that has nothing to add | `SetFinalizer::survivorship()`, `SqlBackfill::backfillIdentityKeys()` | `VersionerSql::differsOnPresent()` — `expr IS NOT NULL AND NOT same(stored, expr)` |
| **NULL is a value** — every attribute the rollups and `enrich()` pass unconditionally, `date_resolved` included | `SqlBackfill::enrich()`, `SqlBackfill::rollup()` | `VersionerSql::differsOnAll()` — `NOT same(stored, expr)` |

Getting this backwards is silent in both directions: `differsOnAll` where `differsOnPresent` belongs
mints a version every time a field loses all its candidates; `differsOnPresent` where `differsOnAll`
belongs never records a credential whose `date_resolved` was cleared.

---

## The NULL-key trap — a pre-existing defect this plan has to fix to reach parity

`gp_license`'s natural key is `(identity_id, license_number, certification_state, certification_board)`
and the last two are nullable. `gp_address`'s is `(identity_id, address1, city, state, zip)` and four
of those are nullable. MySQL never treats two NULLs as equal in a unique index, so `uq_lic` does not
constrain a licence with a NULL state — and `ON DUPLICATE KEY UPDATE` therefore never fires for one:

```
enrich() run 1 -> INSERT (7, 'L-77', NULL, NULL, ...)   -> row inserted
enrich() run 2 -> INSERT (7, 'L-77', NULL, NULL, ...)   -> uq_lic does not match -> ANOTHER row
```

The per-row path does not have this bug: `updateOrInsert(['certification_state' => null, …])` becomes
`WHERE certification_state IS NULL`, which matches. So today the two paths already disagree on
`license_count` for any licence with a NULL state, and a set-based `enrich()` is not idempotent.
Under versioning it gets worse — `current_key` is `CONCAT(...)`, NULL-propagating by 3a's explicit
design, so `uq_lic_current` does not constrain those rows either and *two current versions* of the
same licence become legal.

This plan fixes it as a consequence rather than as a feature: **every natural-key join in set-based
SQL uses `<=>`**, which is exactly what `Versioner::current()`'s `->where($key)` does. The result is
one current version per key including NULL-keyed ones, enforced by the code even where the index
cannot enforce it.

Two things follow, and both are recorded in `docs/SCD2.md`:

- `license_count` / `address_count` will **fall** for identities whose licences carry a NULL state,
  because the duplicates stop being created. That is a correction, not a regression, and it does not
  touch matching: `Engine::mergeByLicense()` groups on `(license_number, certification_state,
  certification_board)` and sees the same distinct values either way.
- The database still cannot enforce single-current for a NULL-keyed row. If a future plan wants that
  guarantee in the schema, the move is a `current_key` built from `COALESCE(part, CHAR(30 USING
  utf8mb4))` per part rather than raw `CONCAT` — a record-separator sentinel for NULL. That is a
  migration on the largest tables in the hub and it changes 3a's deliberate NULL-permissive choice,
  so it is out of scope here and flagged for plan 5.

---

## Restructuring `SetFinalizer::survivorship()` — why the index-free design survives

Commit `9c3f11c` ("perf(finalize): index-free survivorship + chunked resumable materialize") drops
`gp_identity`'s five key indexes before the per-field canonical `UPDATE`s and rebuilds them once at
the end. Its message records the reason: the nine `UPDATE`s rewrite indexed columns (`ssn_hash`,
`npi`, `upin`, `dea_number`, and `canonical_last/first/dob` through `idx_name_dob`) across every row,
"so maintaining the secondary indexes row-by-row was the bottleneck (~tens of min per field)".

The restructure removes those nine `UPDATE`s. It does **not** remove the reason to drop the indexes,
because 3a appended `current` to all five of them:

| Statement | Index work it does after 3a |
|---|---|
| the flip — `UPDATE gp_identity SET current = 0` for every superseded identity | rewrites one entry in **all five** key indexes per row, because `current` is now their trailing column, plus removes the `uq_identity_current` entry as `current_key` goes NULL |
| the insert — one new version per changed identity | builds five entries per row |

So the conclusion holds and the docblock has to say why the *reason* changed. What also has to be
said is what is **not** dropped: `uq_identity_current`. It is the only thing that turns "two current
versions of one identity" from a silent duplicate row into a duplicate-key error, and the
flip-then-insert ordering exists precisely because it is enforced. Dropping it for speed would remove
the guarantee at the exact moment the code starts depending on it.

**The cost model, honestly.** Today: 9 × (one full-table `UPDATE` of indexed columns + two
`INSERT … SELECT` over the ranked CTE) + one `UPDATE` for `record_count`/`last_updated`, i.e. 27
evaluations of the ranked window function and nine 13M-row index-maintaining updates. After: 9 × (one
`INSERT` of winners into a scratch table + one `INSERT` into `gp_attribute`) + one pivot + one audit
insert + one scratch build + one flip + one insert + one `record_count` update — 18 evaluations of the
ranked window function, and the two expensive `gp_identity` statements are **restricted to the
identities that actually changed**. On a steady-state re-finalize that is near zero rows, which is the
whole point of 3a's no-change rule: `finalizeAll()` recomputes every identity, and a naive versioner
would mint ~13.38M `gp_identity` rows per run. On the first post-migration finalize it can be every
identity, once.

**Why the scratch table is `LIKE gp_identity` and not `AS SELECT`.** `CREATE TEMPORARY TABLE … AS
SELECT` infers column types from the expressions, so `COALESCE(w.canonical_dob, i.canonical_dob)` —
a `VARCHAR(500)` scratch column against a `DATE` — would land in the scratch table as a string and
then convert on the way into `gp_identity`. Under `strict` (Laravel sets `STRICT_TRANS_TABLES`) a
single malformed date turns that into a hard failure halfway through a 13M-row insert. `LIKE` copies
the exact types, the exact defaults and the exact NOT NULL flags, so the conversion happens once, in
the scratch insert, where it is cheap to diagnose. The copied secondary indexes are dropped
immediately — they are pure cost on a write-once scratch table — and the list is read from
`information_schema` for `gp_identity` rather than hard-coded, so it cannot drift when plan 2 removes
`idx_ssn`.

---

## Concurrency under the 16 parallel staging workers

`SqlBackfill::DEADLOCK_RETRIES = 5` was sized for 16 workers doing concurrent `INSERT IGNORE`s into
`stg_person`, taking insert-intention gap locks and deadlocking "even on disjoint id ranges". Nothing
in this plan changes that: `stage()` writes only `stg_*` and `src_*`, none of which are versioned, and
keeps its `transaction($fn, self::DEADLOCK_RETRIES)` wrappers untouched.

The versioned half is different in kind, and the plan states the rule rather than hoping:

- **`transform()` and `SetFinalizer::run()` are whole-hub and single-process by contract.** The
  existing docblock already says "(single process)" and the shard entry points are `dedup()` and
  `finalizeAll()`, not these. Under versioning the contract stops being advisory: two concurrent
  `transform()`s would each build a scratch table of "keys that differ from the current version",
  then each flip and each insert, and the loser gets a duplicate-key error from `uq_*_current` —
  loud, but only after doing hours of work. Task 8 therefore takes a named MySQL advisory lock
  (`GET_LOCK('gp_scd2_bulk', 0)`) at both entry points and fails immediately with a message naming
  the other holder. `GET_LOCK` is not DDL and does not implicitly commit.
- **Each flip-and-insert pair runs inside one `transaction($fn, self::DEADLOCK_RETRIES)`.** Not for
  the exclusive case — for the concurrent-`gp:sync` case, where a per-row `Versioner::write()` on one
  identity can contend with the bulk flip. `Versioner::write()` has no retry (3a Known Risk 3); the
  bulk side does, so the bulk side is the one that yields.
- **A crash between the flip and the insert is recoverable, and that is by design.** The pair is
  transactional, so it cannot half-apply; but if the whole transform dies after some tables and
  before others, the surviving state is "a natural key whose latest version has `current = 0`".
  `Versioner::write()` handles that explicitly — `$latest` not current means write a new version
  without flipping — so the per-row path revives it at the next version number, and so does
  `SetVersionWriter` (the same `l.current = 0 OR differs…` predicate). No manual repair step.
- **`materialize()` keeps its autocommitted per-chunk DELETE+INSERT.** It writes only
  `gp_identity_profile`, which is unversioned and rebuildable, and its resumability is worth more
  than transactional coverage. That is unchanged from today.

---

## Landing order against plan 2 (SSN removal)

**Recommendation: land 3b first, then plan 2.** Three reasons, in order of weight:

1. **Plan 2's own set-based test is unsound without 3b's Task 1.**
   `tests/Feature/SetBackfillParityTest` (plan 2 Task 3) extends `HubTestCase` and calls
   `SqlBackfill::indexStaging()` and `SqlBackfill::resolveDeterministic()`. Both issue `ALTER TABLE`
   (and `resolveDeterministic()` reaches `SsnHashGuard::buildBlocklistTable()`, which issues
   `CREATE TABLE` and `TRUNCATE`). Each of those implicitly commits the per-test transaction, so the
   test's fixture is committed into `gp_cami_test` and every later test in the process sees it. The
   assertions still pass; the pollution is silent. 3b Task 1 removes the DDL from the transactional
   path and ships `SetBasedTestCase` for the cases that genuinely need it.
2. **Plan 2's Task 7 column-drop migration is gated on a measurement from a hub nobody in this
   environment can reach** (its Task 1 hands a script to someone with credentials, and its rollout
   decision rule depends on the answer). 3b must not queue behind an unbounded wait.
3. **After 3b, essentially every plan-2 edit still applies as written.** 3b leaves
   `SetFinalizer::IDENTITY_FIELDS`, `IDENTITY_KEY_INDEXES`, the `$ssn4` subquery and the profile
   `INSERT` column list structurally intact, so plan 2's Task 4 Step 4 (a)–(e) land unchanged apart
   from the `` `current` `` suffixes 3a already added to `IDENTITY_KEY_INDEXES` (3a Known Risk 5
   already owns that conflict and it exists whichever way round 3b goes).

**If the order flips — plan 2 lands first — here is precisely what changes in this document:**

| Where | Change |
|---|---|
| Task 3, `SetFinalizer::IDENTITY_FIELDS` | eight fields, not nine. Delete `'ssn_hash' => 'ssn_hash'` from every list in Task 3, including `$survivorshipColumns` and the pivot loop. The restructure is otherwise identical — it iterates the constant. |
| Task 3, `withoutIdentityKeyIndexes()` docblock | drop `ssn_hash` from the "rewrites indexed columns" sentence. |
| Task 4, `$ssn4` | already deleted by plan 2 Task 4 Step 4(d). Skip Step 3's `$ssn4` paragraph and Step 4's `ssnLastFour()` tiebreak edit; the `ssn_last_four` row of the ordering table above becomes moot. |
| Task 4, the profile `INSERT` | already missing `ssn_hash` / `ssn_last_four`. The one edit that remains is `AND i.\`current\` = 1` on the outer `FROM gp_identity i`. |
| Task 6, `backfillIdentityKeys()` | five proposed columns become four (`npi`, `upin`, `dea_number`, `canonical_dob`) plus the three name columns. The filler-SSN divergence noted above disappears entirely — delete that row from the `docs/SCD2.md` divergence table. |
| Task 6, tier filters | `SqlBackfill::KEY_TIERS` is `['npi','upin','dea_number']`, so `tierCreate`/`tierLink` run three times, not four. The `$guard` / `SsnHashGuard` argument in both methods is gone; drop the `ssn_hash` special-casing from the step. |
| Task 1 | `SsnHashGuard` no longer exists, so Step 3 (its DDL removal) is dropped and its two assertions come out of `BulkIndexTransactionSafetyTest`. **Task 1's index guard is still required** — `ALTER TABLE stg_person` and `ALTER TABLE gp_identity` remain. |
| Task 8, `EvalGateBothPathsTest` | the expected report is plan 2's re-baselined numbers, not 1.0/1.0. The *parity* assertion (both paths agree) is unchanged and is the point. |
| Everywhere | expected suite totals shift by plan 2's net test delta. |

Neither plan blocks the other, and the parity machinery (`VersionerSql`, `SetVersionWriter`,
`SetBasedTestCase`, the parity assertions) is indifferent to which tiers exist.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/GoldenProfile/Support/VersionerSql.php` *(create)* | `Versioner::same()` / `differs()` as SQL expressions, plus the NULL-safe key-join renderer. The one place the set-based comparison semantics live. |
| `app/GoldenProfile/Support/SetVersionWriter.php` *(create)* | The set-based twin of `Versioner::write()`: `write()` for the five child tables, `writeIdentities()` for `gp_identity`. Both are flip-then-insert restricted to keys that actually differ. |
| `app/GoldenProfile/Support/Versioner.php` *(modify)* | `same()` becomes `public static` so its SQL twin can be pinned against it; `carryForward()` drops `stg_seed_id`. |
| `app/GoldenProfile/Support/SsnHashGuard.php` *(modify)* | `buildBlocklistTable()` stops issuing DDL on a path a transaction can be open on. **Deleted outright by plan 2.** |
| `app/GoldenProfile/Materialize/SetFinalizer.php` *(modify)* | `survivorship()` restructured into one all-fields versioned pass; `materializeRange()`'s aggregates filtered to `current = 1`; index maintenance transaction-guarded; `run()` takes the bulk lock. |
| `app/GoldenProfile/SqlBackfill.php` *(modify)* | Tier reads filtered; `backfillIdentityKeys()` versioned; `residualCreateAndLink()` uses `stg_seed_id`; `enrich()` and `rollup()` versioned; index maintenance transaction-guarded; `transform()` takes the bulk lock. |
| `app/GoldenProfile/Materialize/ProfileMaterializer.php` *(modify)* | Deterministic ordering on the four order-dependent scalar picks, so the byte-identical invariant is true rather than lucky. |
| `app/GoldenProfile/Eval/EvalRunner.php` *(modify)* | Staging extracted into `stage()`; new `runSetBased()` scores the same fixture through the set-based ladder. |
| `app/GoldenProfile/Support/SetBasedPathGuard.php` *(delete)* | Its own docblock says deleting it is part of this plan. Task 8, with the parity proof in the same commit. |
| `database/migrations/2026_09_04_000200_add_stg_seed_id_to_gp_identity.php` *(create)* | The residual step's own scratch column, replacing its reuse of `merged_into`. |
| `docs/SCD2.md` *(modify)* | The set-based register: which statements version what, the NULL-key correction, the JSON-order caveat, the surviving per-row/set-based divergences, and the bulk-lock rule. |
| `docs/EVALUATION.md` *(modify)* | Records that the gate held **and** that both paths score it identically. |
| `tests/Support/SetBasedTestCase.php` *(create)* | Harness for tests that must let the bulk paths commit: gives up `HubTestCase`'s transaction on purpose, isolates with `TRUNCATE`, and provides the parity snapshot helpers. |
| `tests/Feature/BulkIndexTransactionSafetyTest.php` *(create)* | The bulk classes issue no DDL while a transaction is open. |
| `tests/Feature/BulkPathHarnessTest.php` *(create)* | `SetBasedTestCase` starts clean, ends clean, and the set-based ladder runs end to end under it. |
| `tests/Feature/VersionerSqlTest.php` *(create)* | Differential: `VersionerSql::same()` and `Versioner::same()` agree on every interesting pair, in user variables and in real column types. |
| `tests/Feature/SetVersionWriterTest.php` *(create)* | No-change → no version; change → version 2 with the old row flipped; a retired chain revives at the next number; a NULL-keyed row does not duplicate. |
| `tests/Feature/SetSurvivorshipVersioningTest.php` *(create)* | The restructured pass mints one version for a changed identity, none for an unchanged one, and leaves `gp_attribute`/`gp_survivorship_audit` byte-identical to today. |
| `tests/Feature/SetMaterializeParityTest.php` *(create)* | A set-based materialize and `ProfileMaterializer::rebuild()` agree, with superseded rows present. |
| `tests/Feature/SetResidualScratchTest.php` *(create)* | The residual step never writes a `stg_person_id` into `merged_into`, and clears its own scratch column. |
| `tests/Feature/SetResolveVersioningTest.php` *(create)* | Tiers ignore superseded versions; `backfillIdentityKeys` versions instead of overwriting and is idempotent. |
| `tests/Feature/SetEnrichRollupVersioningTest.php` *(create)* | `enrich()` and `rollup()` version, are idempotent, and agree with `Versioner::write()` over the same rows. |
| `tests/Feature/SetBasedParityTest.php` *(create)* | End to end: two runs, identical input, identical clusters, identical version counts, identical profile rows. |
| `tests/Feature/EvalGateBothPathsTest.php` *(create)* | The eval set through both ladders, scored identically, at the recorded numbers. |
| `tests/Feature/SetBasedPathGuardTest.php` *(delete)* | Goes with the guard. |

---

## Task 1: Make the bulk paths runnable under test

Nothing in this plan can be verified until the bulk classes stop implicitly committing. `ALTER TABLE`
causes an implicit `COMMIT` in MySQL, and three separate places issue one on a path a test's
transaction is open on. The fix is two-sided: skip the index maintenance while a transaction is open
(it is a bulk optimisation and worthless on a handful of rows), and ship a harness for the tests that
genuinely need the bulk paths to commit.

**Files:**
- Modify: `app/GoldenProfile/Materialize/SetFinalizer.php:87`, `:135`, `:147-163`
- Modify: `app/GoldenProfile/SqlBackfill.php:92-114`, `:551-556`, `:575`, `:596-616`
- Modify: `app/GoldenProfile/Support/SsnHashGuard.php:94-106`
- Create: `tests/Support/SetBasedTestCase.php`
- Test: `tests/Feature/BulkIndexTransactionSafetyTest.php`
- Test: `tests/Feature/BulkPathHarnessTest.php`

**Interfaces:**
- Produces: `SetFinalizer::withoutIdentityKeyIndexes(callable $fn): void` (private);
  `SqlBackfill::withoutIdentityKeyIndexes(callable $fn): void` (private);
  `Tests\Support\SetBasedTestCase` with `protected function backfillSystemId(): int`,
  `protected function wipeHub(): void`, `protected function hubTables(): array`.
- Consumed by: Tasks 3, 5, 6, 7, 8.

- [ ] **Step 1: Write the failing transaction-safety test**

Create `tests/Feature/BulkIndexTransactionSafetyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\SqlBackfill;
use Tests\Support\HubTestCase;

/**
 * The bulk classes drop and rebuild indexes around their big statements. In MySQL
 * ALTER TABLE causes an implicit COMMIT, so doing that while HubTestCase's
 * per-test transaction is open commits the fixture and leaks it into every later
 * test in the process — silently: the test that did it still passes.
 *
 * So the index maintenance is skipped whenever a transaction is open. It is a bulk
 * optimisation (9c3f11c measured it at tens of minutes per field over 13M rows)
 * and on the handful of rows a test stages it buys nothing at all.
 *
 * This test asserts the absence of the commit, which is the only observable that
 * matters: transactionLevel() unchanged, and no index actually built.
 */
class BulkIndexTransactionSafetyTest extends HubTestCase
{
    public function test_index_staging_issues_no_ddl_inside_a_transaction(): void
    {
        $this->assertSame(1, $this->hub()->transactionLevel(), 'HubTestCase should have opened one');

        (new SqlBackfill)->indexStaging();

        $this->assertSame(
            1, $this->hub()->transactionLevel(),
            'indexStaging() committed the test transaction — ALTER TABLE is an implicit COMMIT'
        );
        $this->assertFalse($this->indexExists('stg_person', 'stg_npi'));
    }

    public function test_the_set_based_survivorship_issues_no_ddl_inside_a_transaction(): void
    {
        $stg = $this->stagePerson(['npi' => 1234567893]);
        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);
        $this->hub()->table('gp_source_link')->insert([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => $this->hub()->table('stg_person')
                ->where('stg_person_id', $stg)->value('source_id'),
            'account_id' => 1, 'employeelist_id' => 1, 'match_method' => 'deterministic',
            'match_key' => 'npi', 'match_score' => 0.99, 'linked_at' => now(),
        ]);

        (new SetFinalizer)->survivorship();

        $this->assertSame(
            1, $this->hub()->transactionLevel(),
            'survivorship() committed the test transaction'
        );
        $this->assertTrue(
            $this->indexExists('gp_identity', 'idx_name_dob'),
            'the index was dropped and not rebuilt — the guard must skip both halves, not just one'
        );
    }

    public function test_the_ssn_blocklist_builder_issues_no_ddl_inside_a_transaction(): void
    {
        // Deleted by plan 2 along with the whole SSN feature. Until then it sits on
        // resolveDeterministic()'s path and runs CREATE TABLE + TRUNCATE, both of
        // which implicitly commit.
        (new SqlBackfill)->resolveDeterministic();

        $this->assertSame(
            1, $this->hub()->transactionLevel(),
            'resolveDeterministic() committed the test transaction'
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->hub()->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/BulkIndexTransactionSafetyTest.php`

Expected: FAIL, 3 of 3. The first message is
`indexStaging() committed the test transaction — ALTER TABLE is an implicit COMMIT`
(`Failed asserting that 0 is identical to 1`) — Laravel's transaction counter is decremented by the
server-side commit on the next `rollBack()`/`commit()` accounting, and `stg_npi` exists.

- [ ] **Step 3: Guard the index maintenance in `SetFinalizer`**

In `app/GoldenProfile/Materialize/SetFinalizer.php`, add the wrapper next to the existing
drop/add pair and route `survivorship()` through it. Replace lines 147–163 (`dropIdentityKeyIndexes`
and `addIdentityKeyIndexes`) with:

```php
    /**
     * Run $fn with gp_identity's five key indexes dropped, then rebuilt.
     *
     * Why this still pays after the SCD-2 restructure removed the nine per-field
     * UPDATEs: 2026_09_04_000100_add_scd2_versioning appended `current` to all
     * five, so the flip (UPDATE … SET current = 0) rewrites one entry in every one
     * of them per superseded identity, and the insert that follows builds five
     * entries per new version. The reason changed; the conclusion did not —
     * 9c3f11c measured the un-dropped version of this pass at "~tens of min per
     * field".
     *
     * uq_identity_current is deliberately NOT dropped. It is the only thing that
     * turns "two current versions of one identity" from a silent duplicate row
     * into a duplicate-key error, and the flip-then-insert ORDER exists because it
     * is enforced. Dropping it for speed would remove the guarantee at exactly the
     * moment this code starts depending on it.
     *
     * Skipped entirely while a transaction is open. ALTER TABLE causes an implicit
     * COMMIT in MySQL, so dropping an index mid-transaction commits whatever the
     * caller had open — for HubTestCase that is the fixture of the running test,
     * which then leaks into every later test in the process without anything
     * failing. Inside a transaction the data set is a handful of rows and the
     * optimisation is worth nothing, so skipping loses nothing either.
     */
    private function withoutIdentityKeyIndexes(callable $fn): void
    {
        $bulk = $this->hub()->transactionLevel() === 0;

        if ($bulk) {
            foreach (array_keys(self::IDENTITY_KEY_INDEXES) as $name) {
                if ($this->indexExists('gp_identity', $name)) {
                    $this->hub()->statement("ALTER TABLE gp_identity DROP INDEX `$name`");
                }
            }
        }

        try {
            $fn();
        } finally {
            if ($bulk) {
                foreach (self::IDENTITY_KEY_INDEXES as $name => $cols) {
                    if (! $this->indexExists('gp_identity', $name)) {
                        $this->hub()->statement("ALTER TABLE gp_identity ADD INDEX `$name` ($cols)");
                    }
                }
            }
        }
    }
```

Then in `survivorship()`, replace the bare `$this->dropIdentityKeyIndexes();` at line 87 and the
`$this->addIdentityKeyIndexes();` at line 135 by wrapping the body between them. For now — Task 3
rewrites this method wholesale — that is:

```php
        $this->withoutIdentityKeyIndexes(function () use ($hub, $rank) {
            foreach (self::IDENTITY_FIELDS as $canonical => $srcCol) {
                // ... the existing per-field body, unchanged
            }

            // record_count + last_updated (Survivorship folds these in per identity).
            $hub->statement('
                UPDATE gp_identity i
                JOIN ( SELECT identity_id, COUNT(*) c FROM gp_source_link GROUP BY identity_id ) k
                  ON k.identity_id = i.identity_id
                SET i.record_count = k.c, i.last_updated = NOW()');
        });
```

- [ ] **Step 4: Guard the index maintenance in `SqlBackfill`**

Identically in `app/GoldenProfile/SqlBackfill.php`, replace lines 596–616
(`dropIdentityKeyIndexes` / `addIdentityKeyIndexes`) with:

```php
    /**
     * Run $fn with gp_identity's five key indexes dropped, then rebuilt.
     *
     * residualCreateAndLink()'s insert is the single biggest in the pipeline — one
     * identity per still-unlinked staged row, potentially millions — and after
     * 2026_09_04_000100_add_scd2_versioning each of the five indexes it would
     * maintain is one column wider. The earlier key tiers already finished (they
     * needed the indexes) and dedup runs after (it needs them), so the window is
     * safe.
     *
     * uq_identity_current stays. See the identical note in
     * Materialize\SetFinalizer::withoutIdentityKeyIndexes() — the two copies of
     * this reasoning are deliberate; IdentityKeyIndexParityTest pins the constants
     * they share.
     *
     * Skipped while a transaction is open: ALTER TABLE implicitly COMMITs in
     * MySQL, which would commit a test's fixture and leak it into the rest of the
     * process. On the row counts a test stages the optimisation is worthless.
     */
    private function withoutIdentityKeyIndexes(callable $fn): void
    {
        $bulk = $this->hub()->transactionLevel() === 0;

        if ($bulk) {
            foreach (array_keys(self::IDENTITY_KEY_INDEXES) as $name) {
                if ($this->indexExists('gp_identity', $name)) {
                    $this->hub()->statement("ALTER TABLE gp_identity DROP INDEX `$name`");
                }
            }
        }

        try {
            $fn();
        } finally {
            if ($bulk) {
                foreach (self::IDENTITY_KEY_INDEXES as $name => $cols) {
                    if (! $this->indexExists('gp_identity', $name)) {
                        $this->hub()->statement("ALTER TABLE gp_identity ADD INDEX `$name` ($cols)");
                    }
                }
            }
        }
    }
```

Then wrap `residualCreateAndLink()`'s body (replacing its `$this->dropIdentityKeyIndexes();` at the
top and `$this->addIdentityKeyIndexes();` at the bottom):

```php
    private function residualCreateAndLink(): void
    {
        $this->withoutIdentityKeyIndexes(function () {
            // ... the existing three statements, unchanged for now (Task 5 rewrites them)
        });
    }
```

And guard `indexStaging()` the same way, since it too issues `ALTER TABLE`:

```php
    public function indexStaging(?callable $log = null): void
    {
        // ALTER TABLE implicitly COMMITs. Adding a staging index while a caller has
        // a transaction open would commit it, so this is a no-op there — the
        // indexes exist to turn the resolve tiers' GROUP BY / NOT EXISTS into index
        // lookups over millions of rows, which a transactional caller does not have.
        if ($this->hub()->transactionLevel() > 0) {
            return;
        }

        $indexes = [
            'stg_ssn' => 'ssn_hash',
            'stg_npi' => 'npi',
            'stg_upin' => 'upin',
            'stg_dea' => 'dea_number',
            'stg_namedob' => 'last_name, first_name, date_of_birth',
        ];
        foreach ($indexes as $name => $cols) {
            if (! $this->indexExists('stg_person', $name)) {
                if ($log) {
                    $log('index', "stg_person($cols)");
                }
                $this->hub()->statement("ALTER TABLE stg_person ADD INDEX `$name` ($cols)");
            }
        }
    }
```

> **Note for whoever lands plan 2 second.** Plan 2's
> `SetBackfillParityTest::test_staging_no_longer_indexes_the_ssn_hash_column` asserts `stg_ssn` is
> absent after `indexStaging()`. With this guard it passes vacuously under `HubTestCase`. Point that
> test at `Tests\Support\SetBasedTestCase` (below) so `indexStaging()` actually runs and the
> assertion means something.

- [ ] **Step 5: Take the DDL out of the blocklist builder**

In `app/GoldenProfile/Support/SsnHashGuard.php`, replace the first two statements of
`buildBlocklistTable()`:

```php
    public function buildBlocklistTable(): int
    {
        $hub = $this->hub();
        $cap = $this->maxIdentitiesPerHash();

        // CREATE TABLE and TRUNCATE both cause an implicit COMMIT in MySQL, and
        // this method sits on resolveDeterministic()'s path — so running either
        // while a caller has a transaction open commits it. Create only when the
        // table is genuinely absent (checked, not IF NOT EXISTS, which commits
        // regardless), and clear with DELETE, which is transactional. The blocklist
        // holds a few thousand rows at most and has no AUTO_INCREMENT, so TRUNCATE
        // bought nothing here.
        $exists = $hub->selectOne(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            ['gp_ssn_hash_blocklist'],
        );

        if (! $exists) {
            $hub->statement('CREATE TABLE gp_ssn_hash_blocklist (
                ssn_hash VARCHAR(255) NOT NULL,
                reason VARCHAR(32) NOT NULL,
                distinct_people INT NOT NULL DEFAULT 0,
                PRIMARY KEY (ssn_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }

        $hub->table('gp_ssn_hash_blocklist')->delete();

        // ... the rest of the method unchanged
```

> The first call in a fresh schema still creates the table, and that still commits. `HubTestCase`
> runs `migrate:fresh` once per process and `gp_ssn_hash_blocklist` is not a migration, so the very
> first test in a process to reach this line pays it. `BulkIndexTransactionSafetyTest`'s third
> assertion is therefore ordering-sensitive by nature; it is written to run after
> `BulkPathHarnessTest` has already created the table (PHPUnit runs `tests/Feature` alphabetically,
> and `BulkIndexTransactionSafetyTest` sorts after `BulkPathHarnessTest`). If it ever fails in
> isolation, that is the reason — and it is the argument for `SetBasedTestCase` rather than for
> weakening the assertion.

- [ ] **Step 6: Write the set-based harness**

Create `tests/Support/SetBasedTestCase.php`:

```php
<?php

namespace Tests\Support;

use App\GoldenProfile\SqlBackfill;

/**
 * Base case for tests that drive the SET-BASED paths.
 *
 * HubTestCase isolates each test with a transaction it rolls back. That does not
 * work here, and the reason is not fixable: MySQL causes an implicit COMMIT on
 * DDL, and the set-based paths legitimately issue some — SetFinalizer and
 * SqlBackfill ALTER gp_identity to drop and rebuild the five key indexes around
 * their bulk statements, and SsnHashGuard creates its blocklist table. Task 1 of
 * plan 3b skips the index maintenance while a transaction is open, which makes the
 * paths SAFE under HubTestCase but also makes them run in a shape no production
 * run ever uses. Tests that need the real shape — and every parity test does —
 * come here instead.
 *
 * So: give the transaction up deliberately, and isolate with TRUNCATE. TRUNCATE
 * rather than DELETE for two reasons. It resets AUTO_INCREMENT, so two runs of the
 * same fixture inside one test mint the same identity_ids, which is what lets the
 * parity assertions compare profile rows column by column instead of guessing at a
 * mapping. And it is the only cleanup that is complete regardless of what the
 * previous test committed.
 *
 * COST, stated plainly: this is not free isolation. A test here can leave the
 * schema dirty if it dies between wipes, and it truncates tables a concurrently
 * running process would be using. phpunit is single-process here, and wipeHub()
 * runs in both setUp and tearDown so a crashed test cannot poison its successor.
 */
abstract class SetBasedTestCase extends HubTestCase
{
    /**
     * Every table migrate:fresh creates, plus the two the engine creates at
     * runtime. Listed rather than discovered so a table added by a later migration
     * shows up as a failing parity test (something was not wiped) instead of as a
     * mysterious cross-test dependency.
     */
    protected function hubTables(): array
    {
        return [
            'gp_source_system', 'gp_identity', 'gp_source_link', 'gp_edge', 'gp_attribute',
            'gp_identity_credential', 'gp_identity_exclusion', 'gp_identity_resolution',
            'gp_resolution_log', 'gp_watermark', 'gp_identity_profile', 'gp_license',
            'gp_address', 'gp_survivorship_audit', 'gp_board_action', 'gp_identity_identifier',
            'gp_identity_alias',
            'stg_person', 'stg_person_alias', 'stg_person_address', 'stg_person_license',
            'stg_person_identifier',
            'src_credential_match', 'src_match', 'src_exclusion_record',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // HubTestCase opened a transaction. Close it before anything DDL-bearing
        // runs, so no statement in this test can commit it out from under us.
        $this->hub()->rollBack();

        $this->wipeHub();

        $this->systemId = $this->seedSystem();
    }

    protected function tearDown(): void
    {
        $this->wipeHub();

        // HubTestCase::tearDown() calls rollBack() because $this->systemId is set.
        // At transaction level 0 Laravel's rollBack() returns early, so it is a
        // no-op rather than an error.
        parent::tearDown();
    }

    /** Empty every hub table and reset every AUTO_INCREMENT. */
    protected function wipeHub(): void
    {
        $hub = $this->hub();

        foreach ($this->hubTables() as $table) {
            $hub->statement("TRUNCATE TABLE `$table`");
        }

        // Created at runtime by SsnHashGuard, not by a migration, so it is not in
        // the list above and may not exist yet. Plan 2 deletes the whole feature.
        $hub->statement('DROP TABLE IF EXISTS gp_ssn_hash_blocklist');
    }

    /**
     * SqlBackfill::SYSTEM_CODE is the literal 'streamline_local' and ensureSystem()
     * inserts it; HubTestCase::seedSystem() suffixes a uniqid(). The two system_ids
     * differ, so anything staged for the set-based path must use THIS one.
     *
     * It matters beyond the id: Survivorship::authorityRank() looks the system_code
     * up in config('golden_profile.survivorship.field_authority.identity'), where
     * 'streamline_local' ranks 2 and an unlisted code falls to 100 - reliability =
     * 50. A parity test that ran one path under a uniqid'd code and the other under
     * the real one would be comparing two different authority orders.
     */
    protected function backfillSystemId(): int
    {
        new SqlBackfill;   // ensureSystem() inserts the row if it is absent

        return (int) $this->hub()->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');
    }
}
```

- [ ] **Step 7: Write the harness test**

Create `tests/Feature/BulkPathHarnessTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\SqlBackfill;
use Tests\Support\SetBasedTestCase;

/**
 * The harness itself, tested. If wipeHub() misses a table or backfillSystemId()
 * returns the wrong system, every parity test downstream fails for the wrong
 * reason and the failure looks like a versioning bug.
 */
class BulkPathHarnessTest extends SetBasedTestCase
{
    public function test_the_harness_starts_from_an_empty_schema(): void
    {
        foreach ($this->hubTables() as $table) {
            if ($table === 'gp_source_system') {
                continue;   // setUp seeds exactly one row here
            }

            $this->assertSame(
                0, (int) $this->hub()->table($table)->count(),
                "$table was not wiped — a previous test's rows are visible"
            );
        }

        $this->assertSame(0, $this->hub()->transactionLevel(),
            'the harness must not be holding a transaction the bulk paths would commit');
    }

    public function test_the_set_based_ladder_runs_end_to_end_under_the_harness(): void
    {
        $systemId = $this->backfillSystemId();

        foreach ([['Robert', 'Smith', '1970-04-02'], ['Bob', 'Smith', null]] as [$f, $l, $d]) {
            $this->stagePerson([
                'system_id' => $systemId, 'first_name' => $f, 'last_name' => $l,
                'date_of_birth' => $d, 'npi' => 1234567893,
            ]);
        }

        $backfill = new SqlBackfill;
        $backfill->indexStaging();
        $backfill->resolveDeterministic();

        // The npi tier binds both rows to one identity. The point of the assertion
        // is not the tier (ResolverLadderTest covers that) — it is that the whole
        // DDL-bearing path completed without the harness fighting it.
        $this->assertSame(
            1,
            $this->hub()->table('gp_source_link')->where('system_id', $systemId)
                ->distinct()->count('identity_id')
        );
        $this->assertTrue(
            (bool) $this->hub()->selectOne(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                ['stg_person', 'stg_npi']
            ),
            'indexStaging() should build for real outside a transaction'
        );
    }
}
```

- [ ] **Step 8: Run to verify both pass**

Run: `vendor/bin/phpunit tests/Feature/BulkPathHarnessTest.php tests/Feature/BulkIndexTransactionSafetyTest.php`
Expected: PASS, 5 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 142 tests, 0 skipped. (3a ends at 137; this task adds 5.)

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Materialize/SetFinalizer.php \
        app/GoldenProfile/SqlBackfill.php \
        app/GoldenProfile/Support/SsnHashGuard.php \
        tests/Support/SetBasedTestCase.php \
        tests/Feature/BulkIndexTransactionSafetyTest.php \
        tests/Feature/BulkPathHarnessTest.php
git commit -m "test(bulk): stop the set-based paths committing a test transaction"
```

---

## Task 2: `VersionerSql` and `SetVersionWriter` — the set-based twin of `Versioner`

3a put the write rule in one class because eight call sites would otherwise diverge, and cited the
codebase's own precedent for that failure. Seven set-based write sites are about to appear. Same
argument, same answer: the comparison semantics go in `VersionerSql` and the flip-and-insert goes in
`SetVersionWriter`, and the SQL `same()` is pinned against the PHP `same()` by a differential test —
because a rule with two implementations that cannot be compared is exactly the hazard.

**Files:**
- Create: `app/GoldenProfile/Support/VersionerSql.php`
- Create: `app/GoldenProfile/Support/SetVersionWriter.php`
- Modify: `app/GoldenProfile/Support/Versioner.php` (`same()` visibility, `carryForward()`)
- Test: `tests/Feature/VersionerSqlTest.php`
- Test: `tests/Feature/SetVersionWriterTest.php`

**Interfaces:**
- Consumes: `Versioner::TABLES`, `Versioner::spec(string): array`,
  `Versioner::same($stored, $incoming): bool` (made public static in this task).
- Produces:
  - `VersionerSql::same(string $a, string $b): string`
  - `VersionerSql::differsOnAll(string $storedAlias, array $incoming): string`
  - `VersionerSql::differsOnPresent(string $storedAlias, array $incoming): string`
  - `VersionerSql::keysEqual(string $left, string $right, array $columns): string`
  - `SetVersionWriter::__construct(?string $connection = null)`
  - `SetVersionWriter::realColumns(string $table): array`
  - `SetVersionWriter::write(string $table, string $incoming, array $compared): array{new_versions: int}`
  - `SetVersionWriter::writeIdentities(string $proposals, array $columns, array $derived = []): int`
- Consumed by: Tasks 3, 6, 7.

- [ ] **Step 1: Write the failing differential test**

Create `tests/Feature/VersionerSqlTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Support\Versioner;
use App\GoldenProfile\Support\VersionerSql;
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
            ['SMITH', 'Smith', 'case only — ci collation says equal, PHP === says different'],
            ['Smith', 'Smith ', 'trailing space'],
            [null, null, 'both null — same() is TRUE only here'],
            [null, 'Smith', 'stored null, incoming set'],
            ['Smith', null, 'stored set, incoming null'],
            ['', '', 'both empty'],
            ['', null, 'empty vs null — genuinely different, this is what makes the uniques NULL-permissive'],
            ['1234567893', '1234567893', 'npi as two strings'],
            ['0', '0', 'tinyint round trip'],
            ['0', '', 'zero vs empty'],
            ['1970-04-02', '1970-04-02', 'date as date'],
            ['1970-04-02', '1970-04-02 00:00:00', 'DATE vs DATETIME — the prefix rule, forwards'],
            ['1970-04-02 00:00:00', '1970-04-02', 'the prefix rule, backwards'],
            ['1970-04-02', '1970-04-03 00:00:00', 'prefix rule must NOT fire on a different day'],
            ['1970-04-0', '1970-04-0X', 'nine bytes then a divergence — neither side is length 10'],
            ['abcdefghij', 'abcdefghijkl', 'ten bytes that are not a date — the rule is length-based, not date-aware'],
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
            'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
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
            'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/VersionerSqlTest.php`

Expected: FAIL, 5 of 5 — `Class "App\GoldenProfile\Support\VersionerSql" not found`.

- [ ] **Step 3: Make `Versioner::same()` comparable**

Two edits to `app/GoldenProfile/Support/Versioner.php`.

(a) Change the visibility of `same()` and its docblock's closing paragraph:

```php
    /**
     * Value equality as the DATABASE sees it after a round trip.
     *
     * Compared loosely on purpose. A DATE column comes back as '1970-04-02' but is
     * written as '1970-04-02' or a Carbon or a DATETIME string; npi comes back as a
     * string '1234567893' and is written as int 1234567893; is_verified comes back
     * as '0'. Strict comparison would call every one of those a change and mint a
     * version on every single write, which is precisely the runaway this class
     * exists to prevent. Nulls are compared strictly, since NULL and '' are
     * genuinely different here (they are what makes the natural-key uniques
     * NULL-permissive).
     *
     * PUBLIC AND STATIC so its SQL twin can be pinned against it.
     * Support\VersionerSql::same() renders this same rule as an SQL expression for
     * the set-based paths, and VersionerSqlTest asserts the two agree pair by pair.
     * A rule with two implementations that cannot be compared is the exact failure
     * this class was created to prevent — see the tiebreak note at the top.
     */
    public static function same($stored, $incoming): bool
    {
        if ($stored === null || $incoming === null) {
            return $stored === null && $incoming === null;
        }

        $a = $stored instanceof \DateTimeInterface ? $stored->format('Y-m-d H:i:s') : (string) $stored;
        $b = $incoming instanceof \DateTimeInterface ? $incoming->format('Y-m-d H:i:s') : (string) $incoming;

        // A DATE column round-trips as 'Y-m-d' while callers hand over staged
        // values that may carry a time. Compare on the date when both look like
        // timestamps of the same day.
        if (strlen($a) === 10 && strlen($b) >= 10 && str_starts_with($b, $a)) {
            return true;
        }
        if (strlen($b) === 10 && strlen($a) >= 10 && str_starts_with($a, $b)) {
            return true;
        }

        return $a === $b;
    }
```

(b) In `differs()`, the one call site becomes static:

```php
            if (! self::same($row->$column ?? null, $incoming[$column])) {
                return true;
            }
```

(c) In `carryForward()`, drop the residual step's scratch column so it cannot ride into a version:

```php
    /** Every column of the previous version except its surrogate key and version bookkeeping. */
    private function carryForward(?object $latest, array $spec): array
    {
        if ($latest === null) {
            return [];
        }

        $row = (array) $latest;

        // stg_seed_id is SqlBackfill::residualCreateAndLink()'s scratch carrier
        // (2026_09_04_000200_add_stg_seed_id_to_gp_identity), cleared in the same
        // step that sets it. Unsetting it here means a run that died between the
        // two cannot preserve a stale stg_person_id through every future version.
        unset($row['current'], $row['version_no'], $row['current_key'], $row['stg_seed_id']);

        if ($spec['surrogate'] !== null) {
            unset($row[$spec['surrogate']]);
        }

        return $row;
    }
```

> `unset()` on a key that does not exist is a no-op, so this line is safe to write before Task 5's
> migration adds the column.

- [ ] **Step 4: Write `VersionerSql`**

Create `app/GoldenProfile/Support/VersionerSql.php`:

```php
<?php

namespace App\GoldenProfile\Support;

/**
 * Versioner's comparison rule, rendered as SQL expressions for the set-based
 * paths.
 *
 * WHY THIS EXISTS
 * ---------------
 * Versioner::write() decides "did any golden fact actually change?" per row, in
 * PHP. SetFinalizer and SqlBackfill have to reach the SAME verdict for millions of
 * rows inside INSERT … SELECT. If the two disagree on one value pair, the per-row
 * and set-based paths mint different numbers of versions from identical input —
 * and the symptom (a version count off by one, or a canonical value frozen at a
 * stale spelling) surfaces nowhere near the cause. VersionerSqlTest pins them
 * together pair by pair.
 *
 * THE FOUR THINGS THAT HAD TO BE CARRIED ACROSS
 * ---------------------------------------------
 * 1. NULL IS STRICT, AND THAT IS NOT WHAT <=> DOES FOR THE LOOSE BRANCHES.
 *    same() is TRUE only when both sides are null. <=> agrees about that, but the
 *    prefix branches must not run when either side is null: LEFT(NULL, 10) = NULL
 *    is NULL, which inside a NOT(...) degrades to "unknown" and then to "no
 *    change". So the null case gets its own leading CASE arm.
 *
 * 2. PHP === IS A BYTE COMPARISON; MySQL = IS NOT. These tables are
 *    utf8mb4_unicode_ci, so 'SMITH' = 'Smith' is TRUE in the server and FALSE in
 *    PHP. Left alone, the per-row path would version a case change and the
 *    set-based path would not — and the set-based canonical value would then stay
 *    at 'SMITH' forever while the per-row path tracked the source. Every
 *    comparison is therefore wrapped in CAST(… AS BINARY). This is the single most
 *    important line in the class.
 *
 * 3. strlen() AND str_starts_with() ARE BYTES; CHAR_LENGTH() AND LEFT() ARE
 *    CHARACTERS. A ten-CHARACTER non-ASCII value has strlen() > 10, so CHAR_LENGTH
 *    would arm the date-prefix branch on a name PHP would never arm it on.
 *    LENGTH() is bytes, and LEFT() over an argument already cast to BINARY is
 *    bytes.
 *
 * 4. TYPE RENDERING HAS TO MATCH PDO'S. It does, column type by column type — see
 *    the audit table in the plan document. The practical consequence is that a
 *    DATE column can be compared against a VARCHAR scratch column holding the same
 *    date and both sides render '1970-04-02'.
 *
 * WHERE THE DATE-PREFIX RULE ACTUALLY FIRES: in the set-based paths, nowhere. Every
 * incoming canonical_dob comes from stg_person.date_of_birth (a DATE, LENGTH 10
 * both sides) and every date_resolved is DATETIME on both sides. It is implemented
 * anyway because the per-row path CAN arm it (a caller handing a Carbon for a DATE
 * column) and because a future column-type change must not silently acquire a
 * divergence.
 *
 * ONE TRAP: never route a DECIMAL through attributes from a PHP float. 0.99 casts
 * to '0.99' and the column round-trips as '0.9900', so same() would report a change
 * on every write and mint a version per write forever. Nothing passes
 * link_confidence today, which is why this has not bitten.
 */
class VersionerSql
{
    /**
     * An expression that is TRUE exactly when Versioner::same($a, $b) is TRUE.
     *
     * $a and $b are SQL, not values — a qualified column (`i`.`canonical_last`), a
     * user variable, or any scalar expression. They are evaluated more than once,
     * so pass column references rather than subqueries; every caller in this
     * codebase materialises the incoming side into a scratch table first, which is
     * what makes that cheap.
     */
    public static function same(string $a, string $b): string
    {
        $ab = "CAST($a AS BINARY)";
        $bb = "CAST($b AS BINARY)";

        return "(CASE
            WHEN $a IS NULL OR $b IS NULL THEN ($a IS NULL AND $b IS NULL)
            WHEN LENGTH($ab) = 10 AND LENGTH($bb) >= 10 AND LEFT($bb, 10) = $ab THEN TRUE
            WHEN LENGTH($bb) = 10 AND LENGTH($ab) >= 10 AND LEFT($ab, 10) = $bb THEN TRUE
            ELSE $ab = $bb
        END)";
    }

    /**
     * Versioner::differs() where EVERY listed column is present in the incoming
     * payload — a NULL incoming value is a comparison, not an absence.
     *
     * This is the shape SqlBackfill::enrich() and ::rollup() need: their per-row
     * counterparts build the attribute array unconditionally, so a credential whose
     * date_resolved was cleared reads as a change and must be versioned.
     *
     * @param  string  $storedAlias  table alias holding the stored row
     * @param  array<string,string>  $incoming  target column => SQL for the incoming value
     */
    public static function differsOnAll(string $storedAlias, array $incoming): string
    {
        $terms = [];

        foreach ($incoming as $column => $expr) {
            $terms[] = 'NOT '.self::same("$storedAlias.`$column`", $expr);
        }

        // FALSE, not '': gp_identity_identifier declares no attributes at all (its
        // key is the whole fact), and an empty string would break the enclosing
        // WHERE rather than saying "nothing can differ".
        return $terms === [] ? 'FALSE' : '('.implode(' OR ', $terms).')';
    }

    /**
     * Versioner::differs() where a NULL incoming value means the column was ABSENT
     * from the payload — carry forward, not a change.
     *
     * This is the shape SetFinalizer::survivorship() and
     * SqlBackfill::backfillIdentityKeys() need: a survivorship field with no
     * non-blank candidate produces no entry in $update at all, and a backfill
     * column with nothing to add is likewise simply not passed. Modelling that as
     * "the incoming expression is NULL" is exact, because a survivorship winner is
     * never NULL (the ranked CTE filters on IS NOT NULL AND TRIM(...) <> '') and a
     * backfill proposal is NULL precisely when there is nothing to propose.
     *
     * @param  array<string,string>  $incoming  target column => SQL for the incoming value
     */
    public static function differsOnPresent(string $storedAlias, array $incoming): string
    {
        $terms = [];

        foreach ($incoming as $column => $expr) {
            $terms[] = "($expr IS NOT NULL AND NOT ".self::same("$storedAlias.`$column`", $expr).')';
        }

        return $terms === [] ? 'FALSE' : '('.implode(' OR ', $terms).')';
    }

    /**
     * NULL-safe natural-key equality between two aliases carrying the same column
     * names.
     *
     * <=> and never =. Versioner::current() matches a key with ->where($key), and
     * Laravel converts a null value under '=' into IS NULL, so the per-row path
     * treats two NULL key parts as equal. A set-based join with = would not, and
     * gp_license's certification_state / certification_board and gp_address's
     * city / state / zip are all nullable — so every run would insert a duplicate
     * row for a licence with a NULL state instead of finding the existing one.
     * That is not hypothetical: it is what the ON DUPLICATE KEY UPDATE code this
     * replaces already does, because uq_lic cannot match a NULL either.
     *
     * @param  list<string>  $columns
     */
    public static function keysEqual(string $left, string $right, array $columns): string
    {
        return implode(' AND ', array_map(
            fn ($c) => "$left.`$c` <=> $right.`$c`",
            $columns
        ));
    }
}
```

- [ ] **Step 5: Run the differential test**

Run: `vendor/bin/phpunit tests/Feature/VersionerSqlTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Write the writer's failing test**

Create `tests/Feature/SetVersionWriterTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Support\SetVersionWriter;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * SetVersionWriter is the set-based twin of Versioner::write(). Its contract is
 * the same one, asserted the same way: no version for an unchanged key, one
 * version with the old row flipped for a changed key, and a retired chain revived
 * at the next number rather than restarted.
 *
 * The tests drive it through gp_license, which is the hardest of the five child
 * tables: a surrogate primary key, a four-column natural key with TWO nullable
 * parts, one onCreate column and one column (is_verified) that no write path ever
 * passes and which must therefore carry forward untouched.
 */
class SetVersionWriterTest extends HubTestCase
{
    private int $identityId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);
    }

    /** One incoming licence row in a scratch table, ready for write(). */
    private function stageIncomingLicence(?string $state, ?string $type): void
    {
        $this->hub()->statement('DROP TEMPORARY TABLE IF EXISTS tmp_lic_in');
        $this->hub()->statement('CREATE TEMPORARY TABLE tmp_lic_in (
            identity_id BIGINT UNSIGNED NOT NULL,
            license_number VARCHAR(100) NOT NULL,
            certification_state VARCHAR(65) NULL,
            certification_board VARCHAR(10) NULL,
            license_type VARCHAR(100) NULL,
            license_type_id VARCHAR(100) NULL,
            registry VARCHAR(255) NULL,
            source_link_id BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->hub()->table('tmp_lic_in')->insert([
            'identity_id' => $this->identityId,
            'license_number' => 'L-77',
            'certification_state' => $state,
            'certification_board' => null,
            'license_type' => $type,
            'license_type_id' => null,
            'registry' => null,
            'source_link_id' => 1,
        ]);
    }

    private const COMPARED = ['license_type', 'license_type_id', 'registry'];

    public function test_a_new_key_gets_version_one(): void
    {
        $this->stageIncomingLicence('CA', 'RN');

        $result = (new SetVersionWriter)->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->assertSame(1, $result['new_versions']);

        $rows = $this->hub()->table('gp_license')->where('identity_id', $this->identityId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->version_no);
        $this->assertSame(1, (int) $rows[0]->current);
        $this->assertSame('RN', $rows[0]->license_type);
        $this->assertSame(1, (int) $rows[0]->source_link_id);
        $this->assertNotNull($rows[0]->date_created);
    }

    public function test_an_unchanged_key_mints_nothing(): void
    {
        $this->stageIncomingLicence('CA', 'RN');
        $writer = new SetVersionWriter;
        $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        // Same input again. This is the property that keeps a bulk run from
        // multiplying gp_license by four figures on the pile-up identities, where
        // thousands of source rows re-observe the same licence.
        $this->stageIncomingLicence('CA', 'RN');
        $result = $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->assertSame(0, $result['new_versions']);
        $this->assertSame(1, (int) $this->hub()->table('gp_license')
            ->where('identity_id', $this->identityId)->count());
    }

    public function test_a_changed_attribute_supersedes_and_carries_the_rest_forward(): void
    {
        $this->stageIncomingLicence('CA', 'RN');
        $writer = new SetVersionWriter;
        $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        // is_verified is written by no path at all — Versioner declares it an
        // attribute but nothing supplies it, so it must survive a version bump.
        $this->hub()->table('gp_license')->where('identity_id', $this->identityId)
            ->update(['is_verified' => 1]);

        $this->stageIncomingLicence('CA', 'LPN');
        $result = $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->assertSame(1, $result['new_versions']);

        $rows = $this->hub()->table('gp_license')->where('identity_id', $this->identityId)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertSame([1, 0], [(int) $rows[0]->version_no, (int) $rows[0]->current]);
        $this->assertSame([2, 1], [(int) $rows[1]->version_no, (int) $rows[1]->current]);
        $this->assertSame('LPN', $rows[1]->license_type);
        $this->assertSame(1, (int) $rows[1]->is_verified, 'is_verified must carry forward');
        $this->assertSame(1, (int) $rows[1]->source_link_id, 'source_link_id is onCreate — carried, not rewritten');
        $this->assertNotSame($rows[0]->license_id, $rows[1]->license_id, 'a new version gets its own surrogate');
    }

    public function test_a_null_key_part_is_matched_rather_than_duplicated(): void
    {
        // uq_lic cannot constrain a licence with a NULL certification_state, and
        // neither can uq_lic_current (current_key is CONCAT, which propagates
        // NULL). So the code has to do it, with <=> in the key join. Without that,
        // this test finds two rows and a set-based enrich is not idempotent.
        $this->stageIncomingLicence(null, 'RN');
        $writer = new SetVersionWriter;
        $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->stageIncomingLicence(null, 'RN');
        $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->assertSame(
            1, (int) $this->hub()->table('gp_license')->where('identity_id', $this->identityId)->count(),
            'a NULL certification_state was duplicated — the key join must use <=>, not ='
        );
    }

    public function test_a_fully_retired_chain_revives_at_the_next_number(): void
    {
        // Versioner::write() takes the highest version_no whether or not it is
        // current, so a key whose versions were all retired (a merge collision, see
        // Versioner::repointForMerge) continues the numbering instead of colliding
        // with version 1. The set-based path must agree.
        $this->stageIncomingLicence('CA', 'RN');
        $writer = new SetVersionWriter;
        $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        (new Versioner)->retire('gp_license', [
            'identity_id' => $this->identityId, 'license_number' => 'L-77',
            'certification_state' => 'CA', 'certification_board' => null,
        ]);

        $this->stageIncomingLicence('CA', 'RN');
        $result = $writer->write('gp_license', 'tmp_lic_in', self::COMPARED);

        $this->assertSame(1, $result['new_versions'], 'a retired key must be revived even when nothing changed');

        $rows = $this->hub()->table('gp_license')->where('identity_id', $this->identityId)
            ->orderBy('version_no')->get();
        $this->assertSame([1, 2], [(int) $rows[0]->version_no, (int) $rows[1]->version_no]);
        $this->assertSame(1, (int) $rows[1]->current);
    }

    public function test_write_identities_versions_only_what_changed(): void
    {
        $second = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Grace', 'canonical_last' => 'Adeyemi', 'canonical_dob' => '1979-05-14',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->hub()->statement('DROP TEMPORARY TABLE IF EXISTS tmp_ident_in');
        $this->hub()->statement('CREATE TEMPORARY TABLE tmp_ident_in (
            identity_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            canonical_first VARCHAR(500) NULL,
            canonical_last VARCHAR(500) NULL,
            c INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->hub()->table('tmp_ident_in')->insert([
            // changed first name, and a record_count bump
            ['identity_id' => $this->identityId, 'canonical_first' => 'Bob',
                'canonical_last' => null, 'c' => 4],
            // identical facts, record_count bump only
            ['identity_id' => $second, 'canonical_first' => 'Grace',
                'canonical_last' => 'Adeyemi', 'c' => 7],
        ]);

        $minted = (new SetVersionWriter)->writeIdentities(
            'tmp_ident_in',
            ['canonical_first', 'canonical_last'],
            ['record_count' => 'p.`c`'],
        );

        $this->assertSame(1, $minted, 'only the identity whose golden facts moved may be versioned');

        $changed = $this->hub()->table('gp_identity')->where('identity_id', $this->identityId)
            ->orderBy('version_no')->get();
        $this->assertCount(2, $changed);
        $this->assertSame(0, (int) $changed[0]->current);
        $this->assertSame('Bob', $changed[1]->canonical_first);
        $this->assertSame('Smith', $changed[1]->canonical_last, 'a NULL proposal carries the old value forward');
        $this->assertSame(4, (int) $changed[1]->record_count, 'derived values land on the new version');

        // A derived-only movement is written IN PLACE. This is the rule that keeps
        // Engine::finalizeAll() from minting ~13.38M gp_identity rows per run.
        $unchanged = $this->hub()->table('gp_identity')->where('identity_id', $second)->get();
        $this->assertCount(1, $unchanged);
        $this->assertSame(7, (int) $unchanged[0]->record_count);
    }
}
```

- [ ] **Step 7: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/SetVersionWriterTest.php`

Expected: FAIL, 6 of 6 — `Class "App\GoldenProfile\Support\SetVersionWriter" not found`.

- [ ] **Step 8: Write `SetVersionWriter`**

Create `app/GoldenProfile/Support/SetVersionWriter.php`:

```php
<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;

/**
 * The set-based twin of Versioner::write().
 *
 * Versioner exists because eight per-row call sites would otherwise each grow
 * their own flip-and-insert and drift apart — and this codebase already has a
 * documented instance of exactly that (Survivorship's tiebreak had to be pinned to
 * link_id ASC to match SetFinalizer's SQL "because a mismatch broke the
 * rebuild-produces-a-byte-identical-profile invariant"). Seven SET-BASED write
 * sites are about to appear. Same argument, same answer: the rule lives here.
 *
 * THE SHAPE, AND WHY IT IS FOUR STATEMENTS AND NOT ONE
 * ---------------------------------------------------
 * uq_<t>_current makes at most one CURRENT row per natural key, so an insert that
 * ran before the flip would collide with the row it is about to supersede. And the
 * new version's carried-forward columns have to be read BEFORE the flip, because
 * after it there is no current row to read them from. So:
 *
 *   1. latest   — one row per natural key: the highest version_no, current or not.
 *                 "Or not" matters: Versioner::write() takes the highest version
 *                 regardless of currency, so a key whose chain was fully retired
 *                 (a merge collision — see Versioner::repointForMerge) revives at
 *                 the next number rather than colliding with version 1.
 *   2. todo     — the keys that need a successor: their latest is not current, OR
 *                 an attribute differs. Materialised so the predicate is evaluated
 *                 ONCE and the flip and the insert cannot disagree about it.
 *   3. flip     — current = 0 for those keys. Only `current`: date_updated on a
 *                 version means "when this version was written", and an audit trail
 *                 whose rows get restamped every time they are superseded has lost
 *                 the thing it was keeping.
 *   4. insert   — the successors, plus (separately) version 1 for keys with no
 *                 history at all. Two statements because Versioner::write() itself
 *                 has two branches: with no $latest it applies onCreate and takes
 *                 the column defaults for everything else, and with one it carries
 *                 the previous row forward. A single statement would have to
 *                 COALESCE every NOT NULL column against its own default, read out
 *                 of information_schema, to survive strict mode.
 *
 * Steps 3 and 4 run inside one transaction with SqlBackfill's deadlock retry
 * count. Not for the exclusive bulk case — transform() and SetFinalizer::run()
 * hold a named lock — but for the concurrent gp:sync case, where a per-row
 * Versioner::write() on one identity contends with the bulk flip.
 * Versioner::write() has no retry; the bulk side does, so the bulk side yields.
 *
 * A crash between the flip and the insert cannot half-apply. A crash between
 * TABLES leaves a key whose latest version has current = 0, which both paths
 * handle by design (step 2's `l.current = 0` arm, and Versioner::write()'s
 * $isCurrent = false branch) — so there is no repair step.
 *
 * SCRATCH TABLES ARE TEMPORARY, AND THAT IS LOAD-BEARING TWICE OVER.
 * CREATE/ALTER/DROP TEMPORARY TABLE are the exemptions to MySQL's
 * implicit-commit-on-DDL rule, so this class is legal inside a caller's
 * transaction. They are also per-session, so two concurrent transforms cannot see
 * each other's scratch — which is why the exclusivity guarantee has to come from
 * the advisory lock at the entry points and not from here. Each is dropped before
 * creation as well as after: a temporary table created inside a transaction is NOT
 * rolled back with it, so a failed run leaves one behind.
 */
class SetVersionWriter
{
    /**
     * transaction() retry attempts for InnoDB deadlocks. Same sizing as
     * SqlBackfill::DEADLOCK_RETRIES, which was measured against 16 parallel
     * staging workers.
     */
    private const DEADLOCK_RETRIES = 5;

    public function __construct(private ?string $connection = null) {}

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }

    /**
     * A table's real (non-generated) columns, in ordinal order.
     *
     * Read from the catalogue rather than hard-coded so the carry-forward cannot
     * silently miss a column a later migration adds — which would write a NULL into
     * it on every version bump. current_key is excluded because it is VIRTUAL and
     * cannot be inserted into.
     *
     * @return list<string>
     */
    public function realColumns(string $table): array
    {
        return array_map(
            fn ($r) => $r->column_name,
            $this->db()->select(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ?
                   AND extra NOT LIKE '%GENERATED%'
                 ORDER BY ordinal_position",
                [$table],
            ),
        );
    }

    /**
     * Version a whole set of one child table's natural keys at once.
     *
     * $incoming must name a table (temporary is fine) holding EXACTLY ONE ROW PER
     * NATURAL KEY, with columns named as the target's: every key column, every
     * column in $compared, and every onCreate column from Versioner's spec. More
     * than one row per key is a caller bug and shows up as a duplicate-key error
     * from uq_<t>_current, which is the loud failure the index exists for.
     *
     * $compared uses differsOnAll semantics — a NULL incoming value is compared,
     * not treated as absent — because every per-row counterpart of these writers
     * builds its attribute array unconditionally. Attributes NOT listed in
     * $compared carry forward untouched; gp_license.is_verified is the example,
     * declared an attribute by Versioner but supplied by no write path, and passing
     * it here as a literal 0 would reset a verified licence and mint a version
     * doing it.
     *
     * @param  string  $table     one of Versioner::TABLES
     * @param  string  $incoming  scratch table name
     * @param  list<string>  $compared
     * @return array{new_versions: int}
     */
    public function write(string $table, string $incoming, array $compared): array
    {
        $spec = Versioner::spec($table);
        $db = $this->db();
        $key = $spec['key'];
        $keyList = implode(', ', array_map(fn ($c) => "`$c`", $key));
        $all = $this->realColumns($table);

        $latest = "tmp_ver_{$table}_latest";
        $todo = "tmp_ver_{$table}_todo";

        // 1. latest — highest version per key, current or not.
        $latestCols = implode(', ', array_map(fn ($c) => "g.`$c`", $all));
        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$latest`");
        $db->statement("CREATE TEMPORARY TABLE `$latest` (INDEX idx_key ($keyList)) ENGINE=InnoDB AS
            SELECT $latestCols
            FROM `$table` g
            JOIN ( SELECT $keyList, MAX(`version_no`) mx FROM `$table` GROUP BY $keyList ) m
              ON ".VersionerSql::keysEqual('g', 'm', $key)." AND g.`version_no` = m.mx");

        // 2. todo — the keys that need a successor, decided once.
        $incomingMap = [];
        foreach ($compared as $column) {
            $incomingMap[$column] = "n.`$column`";
        }
        $todoCols = implode(', ', array_map(fn ($c) => "l.`$c`", $key));
        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$todo`");
        $db->statement("CREATE TEMPORARY TABLE `$todo` (INDEX idx_key ($keyList)) ENGINE=InnoDB AS
            SELECT $todoCols
            FROM `$incoming` n
            JOIN `$latest` l ON ".VersionerSql::keysEqual('l', 'n', $key)."
            WHERE l.`current` = 0 OR ".VersionerSql::differsOnAll('l', $incomingMap));

        // 4a. successors — carry the previous version forward, overlay the incoming
        //     attributes, bump the version. The surrogate is omitted so the new
        //     version gets its own; onCreate columns come from `latest`, never from
        //     `incoming`, because they record which source row ESTABLISHED the fact.
        $successorCols = array_values(array_diff($all, [$spec['surrogate']]));
        $successorSelect = [];
        foreach ($successorCols as $column) {
            $successorSelect[] = match (true) {
                $column === 'version_no' => 'l.`version_no` + 1',
                $column === 'current' => '1',
                $column === $spec['updated'] => 'NOW()',
                in_array($column, $compared, true) => "n.`$column`",
                default => "l.`$column`",
            }." AS `$column`";
        }
        $successorList = implode(', ', array_map(fn ($c) => "`$c`", $successorCols));

        // 4b. version 1 for keys with no history. Only the columns we actually have
        //     are named, so every other column takes its schema default — which is
        //     what Versioner::write() does when $latest is null.
        $firstCols = array_values(array_unique([
            ...$key, ...$compared, ...$spec['onCreate'],
            'version_no', 'current', $spec['created'], $spec['updated'],
        ]));
        $firstSelect = [];
        foreach ($firstCols as $column) {
            $firstSelect[] = match (true) {
                $column === 'version_no' => '1',
                $column === 'current' => '1',
                $column === $spec['created'], $column === $spec['updated'] => 'NOW()',
                default => "n.`$column`",
            }." AS `$column`";
        }
        $firstList = implode(', ', array_map(fn ($c) => "`$c`", $firstCols));

        $minted = $db->transaction(function () use (
            $db, $table, $incoming, $latest, $todo, $key,
            $successorList, $successorSelect, $firstList, $firstSelect
        ) {
            // 3. flip. A key whose chain was already fully retired matches nothing
            //    here, which is the correct no-op.
            $db->affectingStatement("
                UPDATE `$table` t
                JOIN `$todo` d ON ".VersionerSql::keysEqual('t', 'd', $key)."
                SET t.`current` = 0
                WHERE t.`current` = 1");

            $successors = (int) $db->affectingStatement("
                INSERT INTO `$table` ($successorList)
                SELECT ".implode(', ', $successorSelect)."
                FROM `$todo` d
                JOIN `$latest` l ON ".VersionerSql::keysEqual('l', 'd', $key)."
                JOIN `$incoming` n ON ".VersionerSql::keysEqual('n', 'd', $key));

            $created = (int) $db->affectingStatement("
                INSERT INTO `$table` ($firstList)
                SELECT ".implode(', ', $firstSelect)."
                FROM `$incoming` n
                LEFT JOIN `$latest` l ON ".VersionerSql::keysEqual('l', 'n', $key)."
                WHERE l.`version_no` IS NULL");

            return $successors + $created;
        }, self::DEADLOCK_RETRIES);

        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$todo`");
        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$latest`");

        return ['new_versions' => $minted];
    }

    /**
     * Version gp_identity from a scratch table of PROPOSED values.
     *
     * gp_identity is different from the five child tables in three ways that make a
     * separate method cheaper than a parameter:
     *
     *   - its natural key IS its surrogate, so there is no "new key" branch. An
     *     identity is minted by a resolve tier, never by a versioned write.
     *   - the carry-forward is the WHOLE row, so the scratch table is built LIKE
     *     gp_identity to get its exact types (see below).
     *   - NULL in $proposals means "nothing proposed for this column" — carry
     *     forward — not "the value is NULL". That is Versioner::differs()'s
     *     absent-key branch, and it is what a survivorship field with no non-blank
     *     candidate, or a backfill column with nothing to add, produces.
     *
     * WHY LIKE AND NOT AS SELECT. CREATE TEMPORARY TABLE … AS SELECT infers column
     * types from the expressions, so COALESCE(p.canonical_dob, i.canonical_dob) —
     * a VARCHAR(500) scratch column against a DATE — would land as a string and
     * convert on the way into gp_identity. Under strict mode (Laravel sets
     * STRICT_TRANS_TABLES) one malformed date then fails a 13M-row insert halfway.
     * LIKE copies the exact types, defaults and NOT NULL flags, so the conversion
     * happens once, in the scratch insert, where it is cheap to see. The copied
     * secondary indexes are pure cost on a write-once scratch table and are dropped
     * immediately — read out of information_schema for gp_identity rather than
     * listed, so the drop cannot break when plan 2 removes idx_ssn.
     *
     * @param  string  $proposals  scratch table: identity_id plus a column per proposed fact
     * @param  list<string>  $columns  the proposed columns
     * @param  array<string,string>  $derived  target column => SQL over alias `p`
     * @return int versions minted
     */
    public function writeIdentities(string $proposals, array $columns, array $derived = []): int
    {
        $db = $this->db();
        $all = $this->realColumns('gp_identity');
        $next = 'tmp_ver_identity_next';

        $select = [];
        foreach ($all as $column) {
            $select[] = match (true) {
                $column === 'version_no' => 'i.`version_no` + 1',
                $column === 'current' => '1',
                $column === 'last_updated' => 'NOW()',
                isset($derived[$column]) => $derived[$column],
                in_array($column, $columns, true) => "COALESCE(p.`$column`, i.`$column`)",
                default => "i.`$column`",
            }." AS `$column`";
        }
        $list = implode(', ', array_map(fn ($c) => "`$c`", $all));

        $incomingMap = [];
        foreach ($columns as $column) {
            $incomingMap[$column] = "p.`$column`";
        }

        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$next`");
        $db->statement("CREATE TEMPORARY TABLE `$next` LIKE gp_identity");
        foreach ($db->select(
            "SELECT DISTINCT index_name FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'gp_identity'
               AND index_name <> 'PRIMARY'"
        ) as $index) {
            $db->statement("ALTER TABLE `$next` DROP INDEX `{$index->index_name}`");
        }

        $db->statement("
            INSERT INTO `$next` ($list)
            SELECT ".implode(', ', $select)."
            FROM gp_identity i
            JOIN `$proposals` p ON p.`identity_id` = i.`identity_id`
            WHERE i.`current` = 1
              AND ".VersionerSql::differsOnPresent('i', $incomingMap));

        $minted = $db->transaction(function () use ($db, $next, $list) {
            $db->affectingStatement("
                UPDATE gp_identity i
                JOIN `$next` n ON n.`identity_id` = i.`identity_id`
                SET i.`current` = 0
                WHERE i.`current` = 1");

            return (int) $db->affectingStatement(
                "INSERT INTO gp_identity ($list) SELECT $list FROM `$next`"
            );
        }, self::DEADLOCK_RETRIES);

        // Derived values are written onto the CURRENT version in place — the new
        // versions above already carry them, so this catches the identities that
        // did not change. The <=> guard is a pure optimisation with an identical
        // outcome: Versioner::write() writes derived unconditionally, and writing a
        // value equal to the one already there is indistinguishable from not.
        foreach ($derived as $column => $expr) {
            $db->affectingStatement("
                UPDATE gp_identity i
                JOIN `$proposals` p ON p.`identity_id` = i.`identity_id`
                SET i.`$column` = $expr
                WHERE i.`current` = 1 AND NOT (i.`$column` <=> ($expr))");
        }

        $db->statement("DROP TEMPORARY TABLE IF EXISTS `$next`");

        return $minted;
    }
}
```

- [ ] **Step 9: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/SetVersionWriterTest.php`
Expected: PASS, 6 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 153 tests, 0 skipped.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Support/VersionerSql.php \
        app/GoldenProfile/Support/SetVersionWriter.php \
        app/GoldenProfile/Support/Versioner.php \
        tests/Feature/VersionerSqlTest.php \
        tests/Feature/SetVersionWriterTest.php
git commit -m "feat(scd2): render the versioning write rule as set-based SQL"
```

---

## Task 3: Restructure `SetFinalizer::survivorship()` into one all-fields versioned pass

The hardest single piece of work in the programme. Nine per-field `UPDATE gp_identity SET col = v`
statements have to become one ranked-winner pivot, one comparison against the current version, and
one flip-and-insert restricted to the identities that actually differ — because none of the nine can
mint a version without minting up to nine per identity per run, and `Engine::finalizeAll()` recomputes
every identity.

Read "Restructuring `SetFinalizer::survivorship()` — why the index-free design survives" above before
starting. The short version: commit `9c3f11c` dropped the five key indexes for a reason that no longer
applies, and the indexes still have to be dropped for a different one — 3a appended `current` to all
five, so the flip rewrites an entry in every one of them.

**Files:**
- Modify: `app/GoldenProfile/Materialize/SetFinalizer.php:62-136` (the whole survivorship half)
- Test: `tests/Feature/SetSurvivorshipVersioningTest.php`

**Interfaces:**
- Consumes: `SetVersionWriter::writeIdentities(string $proposals, array $columns, array $derived = []): int`,
  `SetFinalizer::withoutIdentityKeyIndexes(callable $fn): void` (Task 1).
- Produces: `SetFinalizer::survivorship(): void` — signature unchanged, so `run()` and
  `Engine::finalizeAllSet()` need no edit. New private helpers
  `SetFinalizer::rankedCandidatesSql(string $srcCol): string`,
  `SetFinalizer::buildWinners(): void`, `SetFinalizer::dropWinnerTables(): void`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SetSurvivorshipVersioningTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\SetFinalizer;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * The set-based survivorship pass, versioned.
 *
 * Four properties, in the order they matter:
 *
 *  1. A canonical winner that moved mints ONE version for that identity — not one
 *     per changed field, which is what nine independent per-field writes would do.
 *  2. An identity whose winners are unchanged mints NOTHING. This is the rule that
 *     keeps finalizeAll() from adding ~13.38M gp_identity rows a run.
 *  3. record_count is DERIVED: it moves on the current row in place and never on
 *     its own mints a version, because gp_source_link already records when each
 *     link was made with better resolution than a version row would.
 *  4. gp_attribute and gp_survivorship_audit are unchanged in content. They are
 *     per-observation provenance — they ARE the history, so they do not have one
 *     (docs/SCD2.md) — and the restructure moves WHERE they are written from, not
 *     what lands in them.
 *
 * Runs under HubTestCase, not SetBasedTestCase: Task 1's guard makes the index
 * maintenance a no-op inside a transaction, and none of these assertions is about
 * the indexes.
 */
class SetSurvivorshipVersioningTest extends HubTestCase
{
    /** An identity with one linked staged row. Returns [identityId, stgPersonId, linkId]. */
    private function seedLinkedIdentity(array $person = [], array $identity = []): array
    {
        $stg = $this->stagePerson($person);
        $row = $this->hub()->table('stg_person')->where('stg_person_id', $stg)->first();

        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId(array_merge([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => $row->first_name,
            'canonical_middle' => $row->middle_name,
            'canonical_last' => $row->last_name,
            'canonical_dob' => $row->date_of_birth,
            'npi' => $row->npi,
            'confidence' => 1.0, 'record_count' => 1, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ], $identity));

        $linkId = (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => $row->source_id,
            'account_id' => 1, 'employeelist_id' => 1, 'match_method' => 'deterministic',
            'match_key' => 'name_dob', 'match_score' => 0.95, 'linked_at' => now(),
        ]);

        return [$identityId, $stg, $linkId];
    }

    public function test_an_unchanged_identity_mints_no_version(): void
    {
        [$identityId] = $this->seedLinkedIdentity();

        (new SetFinalizer)->survivorship();

        $this->assertSame(
            1, (int) $this->hub()->table('gp_identity')->where('identity_id', $identityId)->count(),
            'survivorship minted a version for an identity whose winners did not move'
        );
        $this->assertSame(1, (int) $this->hub()->table('gp_identity')
            ->where('identity_id', $identityId)->value('version_no'));
    }

    public function test_running_it_twice_is_still_one_version(): void
    {
        [$identityId] = $this->seedLinkedIdentity();

        $finalizer = new SetFinalizer;
        $finalizer->survivorship();
        $finalizer->survivorship();

        $this->assertSame(
            1, (int) $this->hub()->table('gp_identity')->where('identity_id', $identityId)->count(),
            'the pass is not idempotent — a second run with identical input must add nothing'
        );
    }

    public function test_several_moved_fields_mint_exactly_one_version(): void
    {
        // The whole reason the nine per-field UPDATEs had to go: each of them would
        // have had to version independently.
        [$identityId, $stg] = $this->seedLinkedIdentity();

        $this->hub()->table('stg_person')->where('stg_person_id', $stg)->update([
            'first_name' => 'Bob', 'middle_name' => 'Q', 'last_name' => 'Smyth',
            'source_modified' => now()->addMinute()->toDateTimeString(),
        ]);

        (new SetFinalizer)->survivorship();

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $identityId)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows, 'three moved fields must produce ONE version, not three');
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('Robert', $rows[0]->canonical_first);
        $this->assertSame(1, (int) $rows[1]->current);
        $this->assertSame('Bob', $rows[1]->canonical_first);
        $this->assertSame('Q', $rows[1]->canonical_middle);
        $this->assertSame('Smyth', $rows[1]->canonical_last);
        $this->assertSame(
            '1970-04-02', substr((string) $rows[1]->canonical_dob, 0, 10),
            'a field with an unchanged winner must carry forward, not go NULL'
        );
    }

    public function test_a_field_that_lost_all_its_candidates_carries_forward(): void
    {
        // No non-blank candidate means the field is ABSENT from the payload, which
        // Versioner::differs() skips. differsOnPresent models that as a NULL
        // incoming expression; differsOnAll would have called it a change and blanked
        // the canonical value.
        [$identityId, $stg] = $this->seedLinkedIdentity();

        $this->hub()->table('stg_person')->where('stg_person_id', $stg)
            ->update(['middle_name' => 'Q']);
        $finalizer = new SetFinalizer;
        $finalizer->survivorship();

        $this->hub()->table('stg_person')->where('stg_person_id', $stg)
            ->update(['middle_name' => '   ']);
        $finalizer->survivorship();

        $current = $this->hub()->table('gp_identity')
            ->where('identity_id', $identityId)->where('current', 1)->first();

        $this->assertSame('Q', $current->canonical_middle,
            'losing every candidate must carry the last known winner forward');
        $this->assertSame(2, (int) $current->version_no,
            'and it must not mint a version of its own');
    }

    public function test_record_count_moves_in_place_without_minting_a_version(): void
    {
        [$identityId] = $this->seedLinkedIdentity();
        (new SetFinalizer)->survivorship();

        // A second source row for the same identity: record_count 1 -> 2, and no
        // canonical value moves (identical person data).
        $second = $this->stagePerson();
        $row = $this->hub()->table('stg_person')->where('stg_person_id', $second)->first();
        $this->hub()->table('gp_source_link')->insert([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => $row->source_id,
            'account_id' => 1, 'employeelist_id' => 1, 'match_method' => 'deterministic',
            'match_key' => 'name_dob', 'match_score' => 0.95, 'linked_at' => now(),
        ]);

        (new SetFinalizer)->survivorship();

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $identityId)->get();

        $this->assertCount(1, $rows, 'a record_count bump alone must never mint a version');
        $this->assertSame(2, (int) $rows[0]->record_count);
    }

    public function test_provenance_is_written_exactly_as_before(): void
    {
        // gp_attribute keeps every candidate with the winner flagged;
        // gp_survivorship_audit keeps the winner only. Both are fully rebuilt each
        // pass, so both must be idempotent, and neither is versioned.
        [$identityId, , $linkId] = $this->seedLinkedIdentity(['middle_name' => 'Q']);

        $finalizer = new SetFinalizer;
        $finalizer->survivorship();
        $finalizer->survivorship();

        $attrs = $this->hub()->table('gp_attribute')->where('identity_id', $identityId)
            ->orderBy('attr_name')->get();

        // first, middle, last, dob — the four fields the fixture supplies.
        $this->assertSame(4, $attrs->count(), 'provenance must be rebuilt, not appended to');
        $this->assertTrue($attrs->every(fn ($a) => (int) $a->is_canonical === 1),
            'a single candidate per field is always the winner');
        $this->assertTrue($attrs->every(fn ($a) => (int) $a->source_link_id === $linkId));

        $audit = $this->hub()->table('gp_survivorship_audit')
            ->where('identity_id', $identityId)->get();

        $this->assertSame(4, $audit->count());
        $this->assertStringStartsWith('authority[', $audit->first()->rule_applied);
        $this->assertStringEndsWith('] + recency', $audit->first()->rule_applied);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/SetSurvivorshipVersioningTest.php`

Expected: FAIL, 2 of 6. The unversioned `UPDATE` overwrites in place, so:
- `test_an_unchanged_identity_mints_no_version` — PASSES (nothing was ever inserted).
- `test_running_it_twice_is_still_one_version` — PASSES, for the same wrong reason.
- `test_several_moved_fields_mint_exactly_one_version` — FAILS,
  `Failed asserting that actual size 1 matches expected size 2.`
- `test_a_field_that_lost_all_its_candidates_carries_forward` — FAILS,
  `Failed asserting that 1 is identical to 2.`
- `test_record_count_moves_in_place_without_minting_a_version` — PASSES (also for the wrong reason).
- `test_provenance_is_written_exactly_as_before` — PASSES; it is the control, and if it fails after
  Step 3 the restructure changed provenance, which it must not.

So 2 failures and 4 passes before the change, 6 passes after. The two failing tests are the ones that
can only pass with versioning; the four passing ones are the regression net that catches a restructure
which starts minting versions it should not.

- [ ] **Step 3: Rewrite the survivorship half**

Replace `app/GoldenProfile/Materialize/SetFinalizer.php` lines 62–136 (everything from the
`// ---- 1. SURVIVORSHIP` banner to the end of `survivorship()`, leaving `IDENTITY_KEY_INDEXES` and
`withoutIdentityKeyIndexes()` below it alone) with:

```php
    // ---- 1. SURVIVORSHIP --------------------------------------------------

    /**
     * Per-field winner = highest field_authority (by source system_code), then
     * newest source_modified, then link_id ASC. Mirrors Resolution\Survivorship
     * exactly, but as one pass over every identity instead of per identity.
     *
     * WHY THIS IS ONE ALL-FIELDS PASS AND NOT NINE
     * -------------------------------------------
     * It used to be nine independent statements: for each canonical field, one
     * UPDATE gp_identity SET <field> = <winner>. Under SCD-2 not one of them can
     * mint a version without minting up to NINE per identity per run — and
     * Engine::finalizeAll() recomputes every identity, so that is up to ~120M
     * gp_identity rows on a hub of 13.38M identities. So the nine winners are
     * pivoted into ONE row per identity, compared against the current version as a
     * whole, and written as a single version for the identities that actually
     * differ. Versioner::write() makes the same decision per row; the two have to
     * reach the same verdict, which is what Support\VersionerSql is for and what
     * SetBasedParityTest proves.
     *
     * WHAT THE THREE CATEGORIES BECOME HERE
     * -------------------------------------
     *   attributes  the nine canonical fields. A field with no non-blank candidate
     *               is ABSENT, not NULL — it carries the previous version's value
     *               forward, exactly as ->update($update) used to leave it alone.
     *               VersionerSql::differsOnPresent() models that.
     *   derived     record_count. Written onto the current version IN PLACE and
     *               never a reason to version: gp_source_link already records when
     *               each link was made with better resolution than a version row
     *               would, and versioning on a bump would add one identity row per
     *               source row (~13.4M on a backfill).
     *   last_updated  no longer written unconditionally. This method used to set it
     *               to NOW() on every finalize, so it answered "when did we last
     *               look"; SetVersionWriter stamps it only on a version that is
     *               actually written, so it now answers "when did the golden facts
     *               last change". That is the doc's date_updated meaning and it is
     *               a visible change in both API endpoints — see docs/SCD2.md.
     *
     * gp_attribute and gp_survivorship_audit are NOT versioned and are unchanged in
     * content: they are per-observation provenance, i.e. they ARE the history, so
     * they do not have one. Both are still fully rebuilt each pass for these nine
     * attribute names, which is what keeps them idempotent.
     */
    public function survivorship(): void
    {
        $hub = $this->hub();
        $names = array_keys(self::IDENTITY_FIELDS);
        $nameList = "'".implode("','", $names)."'";

        // Provenance is fully rebuilt for these identity fields (idempotent).
        $hub->statement("DELETE FROM gp_attribute WHERE attr_name IN ($nameList)");
        $hub->statement("DELETE FROM gp_survivorship_audit WHERE attribute_name IN ($nameList)");

        $this->withoutIdentityKeyIndexes(function () {
            $this->buildWinners();

            // One version per changed identity; record_count in place for the rest.
            (new SetVersionWriter)->writeIdentities(
                'tmp_surv_winner',
                array_keys(self::IDENTITY_FIELDS),
                ['record_count' => 'p.`c`'],
            );
        });

        $this->dropWinnerTables();
    }

    /**
     * Ranked candidates for one staged column: non-blank values, best authority
     * then newest, link_id as a deterministic final tiebreak.
     *
     * The link_id ASC tail is not cosmetic. Resolution\Survivorship's comparator
     * ends in the same tiebreak specifically to match this ordering, "because a
     * mismatch broke the rebuild-produces-a-byte-identical-profile invariant". Two
     * candidates tied on authority and recency must crown the same winner on both
     * paths or the two mint different versions from identical input.
     */
    private function rankedCandidatesSql(string $srcCol): string
    {
        $rank = $this->authorityRankSql('ss');

        return "
            SELECT l.identity_id, l.link_id, l.system_id, ss.system_code,
                   sp.`$srcCol` AS v,
                   ROW_NUMBER() OVER (
                       PARTITION BY l.identity_id
                       ORDER BY ($rank) ASC, sp.source_modified DESC, l.link_id ASC
                   ) rn
            FROM gp_source_link l
            JOIN stg_person sp
              ON sp.system_id = l.system_id AND sp.source_table = l.source_table AND sp.source_id = l.source_id
            JOIN gp_source_system ss ON ss.system_id = l.system_id
            WHERE sp.`$srcCol` IS NOT NULL AND TRIM(sp.`$srcCol`) <> ''";
    }

    /**
     * Build tmp_surv_field (winner per identity per field) and tmp_surv_winner (one
     * pivoted row per identity, plus its record_count), and rewrite provenance.
     *
     * TEMPORARY tables on purpose, twice over: CREATE/DROP TEMPORARY TABLE are the
     * exemptions to MySQL's implicit-commit-on-DDL rule, so this is legal inside a
     * caller's transaction, and they are per-session so two concurrent runs cannot
     * collide on them. They are dropped before creation as well as after, because a
     * temporary table created inside a transaction is not rolled back with it.
     *
     * COST, relative to what this replaces. Before: 27 evaluations of the ranked
     * window function (three statements per field) plus nine 13M-row UPDATEs of
     * indexed columns. After: 18 evaluations (two per field), one pivot, one audit
     * insert, and two gp_identity statements RESTRICTED TO THE IDENTITIES THAT
     * CHANGED — near zero on a steady-state re-finalize.
     */
    private function buildWinners(): void
    {
        $hub = $this->hub();

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_surv_field');
        $hub->statement('CREATE TEMPORARY TABLE tmp_surv_field (
            identity_id BIGINT UNSIGNED   NOT NULL,
            attr_name   VARCHAR(64)       NOT NULL,
            v           VARCHAR(500)      NULL,
            link_id     BIGINT UNSIGNED   NOT NULL,
            system_id   SMALLINT UNSIGNED NOT NULL,
            system_code VARCHAR(32)       NULL,
            PRIMARY KEY (identity_id, attr_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        foreach (self::IDENTITY_FIELDS as $canonical => $srcCol) {
            $ranked = $this->rankedCandidatesSql($srcCol);

            // The winner. LEFT(v, 500) matches gp_survivorship_audit.surviving_value,
            // which is what this column feeds; every one of the nine source columns
            // is at most 255 wide, so it never actually truncates.
            $hub->statement("
                INSERT INTO tmp_surv_field (identity_id, attr_name, v, link_id, system_id, system_code)
                SELECT identity_id, '$canonical', LEFT(v, 500), link_id, system_id, system_code
                FROM ($ranked) r WHERE rn = 1");

            // every candidate -> gp_attribute (winner flagged is_canonical)
            $hub->statement("
                INSERT INTO gp_attribute (identity_id, attr_name, attr_value, source_link_id, is_canonical, observed_at)
                SELECT identity_id, '$canonical', LEFT(v, 255), link_id, IF(rn = 1, 1, 0), NOW()
                FROM ($ranked) r");
        }

        // winners -> gp_survivorship_audit. One statement for all nine fields now
        // that the winners are materialised, where it used to be one per field.
        $hub->statement("
            INSERT INTO gp_survivorship_audit
                (identity_id, attribute_name, surviving_value, system_id, source_link_id, rule_applied, decided_at)
            SELECT identity_id, attr_name, v, system_id, link_id,
                   CONCAT('authority[', system_code, '] + recency'), NOW()
            FROM tmp_surv_field");

        // Pivot: one row per identity, a column per canonical field, plus the
        // record_count Survivorship folds in per identity.
        //
        // MAX() is a pivot here, not a choice of value: tmp_surv_field's primary key
        // is (identity_id, attr_name), so at most one row can match each CASE.
        //
        // Driven from gp_source_link, not from tmp_surv_field, and LEFT JOINed: an
        // identity with links but no non-blank value anywhere still needs its
        // record_count, and Resolution\Survivorship::recompute() likewise returns
        // early only when the identity has NO links at all. An identity with zero
        // links appears in neither and is left completely alone by both paths.
        $pivot = [];
        foreach (array_keys(self::IDENTITY_FIELDS) as $canonical) {
            $pivot[] = "MAX(CASE WHEN f.attr_name = '$canonical' THEN f.v END) AS `$canonical`";
        }

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_surv_winner');
        $hub->statement('CREATE TEMPORARY TABLE tmp_surv_winner (INDEX idx_id (identity_id)) ENGINE=InnoDB AS
            SELECT k.identity_id, k.c, '.implode(', ', $pivot).'
            FROM ( SELECT identity_id, COUNT(*) c FROM gp_source_link GROUP BY identity_id ) k
            LEFT JOIN tmp_surv_field f ON f.identity_id = k.identity_id
            GROUP BY k.identity_id, k.c');
    }

    private function dropWinnerTables(): void
    {
        $this->hub()->statement('DROP TEMPORARY TABLE IF EXISTS tmp_surv_winner');
        $this->hub()->statement('DROP TEMPORARY TABLE IF EXISTS tmp_surv_field');
    }
```

And add the import at the top of the file, beside the existing `use Illuminate\Support\Facades\DB;`:

```php
use App\GoldenProfile\Support\SetVersionWriter;
```

> **Why the scratch pivot columns are `VARCHAR(500)`, and why comparing them to a `DATE` is safe.**
> `tmp_surv_field.v` has to hold any of the nine source columns, so it is as wide as the widest.
> `VersionerSql::same()` casts both sides to `BINARY`, and `CAST(DATE AS BINARY)` and
> `CAST(VARCHAR AS BINARY)` both render `'1970-04-02'` — see the type-rendering audit in this plan's
> crux section. The conversion back to `DATE` happens once, inside
> `SetVersionWriter::writeIdentities()`, whose scratch table is `LIKE gp_identity` precisely so that
> conversion is typed and diagnosable rather than smeared across a 13M-row insert.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/SetSurvivorshipVersioningTest.php`
Expected: PASS, 6 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 159 tests, 0 skipped.

If `test_provenance_is_written_exactly_as_before` fails, the restructure changed provenance. The two
likely causes are a missing `DELETE` (rows appended rather than rebuilt) and an audit insert reading
the raw ranked CTE instead of `tmp_surv_field` (duplicated winners). Fix the statement — never relax
the assertion.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Materialize/SetFinalizer.php \
        tests/Feature/SetSurvivorshipVersioningTest.php
git commit -m "feat(scd2): version set-based survivorship as one all-fields pass"
```

---

## Task 4: Filter `SetFinalizer::materializeRange()`'s aggregates to `current = 1`

Every one of these subqueries `COUNT`s and `JSON_ARRAYAGG`s every row for an identity. Unfiltered they
aggregate history: `license_count` doubles on the first re-observation and the `licenses` JSON carries
every superseded version. Nothing throws. The acceptance test is the repo's own documented invariant —
a set-based materialize and `ProfileMaterializer::rebuild()` produce the same profile row — which is
also why this task fixes four order-dependent scalar picks on the per-row side. Independent of Task 3
and independently reviewable: this task adds read filters and changes no write.

**Files:**
- Modify: `app/GoldenProfile/Materialize/SetFinalizer.php:247-390` (`materializeRange`)
- Modify: `app/GoldenProfile/Materialize/ProfileMaterializer.php:66-73`, `:114-115`, `:192-200`
- Test: `tests/Feature/SetMaterializeParityTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: no signature change. `SetFinalizer::materialize(?callable $progress = null): void` and
  `ProfileMaterializer::rebuild(int $identityId): void` keep their contracts.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SetMaterializeParityTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Materialize\SetFinalizer;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * The profile is a projection of the CURRENT version of everything. It is itself
 * unversioned — a rebuildable read model whose rows reach 100MB of JSON, so
 * versioning it would multiply 100MB rows to record nothing the versioned tables
 * do not already hold — which makes these read filters the only thing keeping it
 * correct. A missing one does not throw: it doubles a count and duplicates a JSON
 * entry.
 *
 * The acceptance test is the invariant the repo already documents: a set-based
 * materialize and ProfileMaterializer::rebuild() produce the same row. The
 * comparison is byte-exact for scalars and multiset-exact for the JSON aggregates,
 * because MySQL 8 has no ORDER BY inside JSON_ARRAYAGG and neither path's element
 * order is pinned. See docs/SCD2.md.
 */
class SetMaterializeParityTest extends HubTestCase
{
    /** Columns neither path can be expected to match: identity-scoped or clock-scoped. */
    private const VOLATILE = ['identity_uuid', 'first_seen', 'last_updated', 'profile_built_at'];

    /** Columns holding a JSON array whose element ORDER is not pinned by either path. */
    private const JSON_ARRAYS = [
        'identifiers', 'addresses', 'licenses', 'aliases', 'source_records',
        'accounts', 'credentials', 'exclusions', 'board_actions', 'resolutions',
    ];

    private int $identityId;

    private int $linkId;

    protected function setUp(): void
    {
        parent::setUp();

        $stg = $this->stagePerson(['npi' => 1234567893, 'terminated' => 0]);
        $source = $this->hub()->table('stg_person')->where('stg_person_id', $stg)->value('source_id');

        $this->identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'npi' => 1234567893, 'confidence' => 1.0, 'record_count' => 1, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->linkId = (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $this->identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => $source,
            'account_id' => 1, 'employeelist_id' => 1, 'match_method' => 'deterministic',
            'match_key' => 'npi', 'match_score' => 0.99, 'linked_at' => now(),
        ]);
    }

    /**
     * One current version and one superseded version of every child fact. If a read
     * filter is missing, the count doubles and the JSON carries the stale value.
     */
    private function seedTwoVersionsOfEverything(): void
    {
        $hub = $this->hub();

        foreach ([['RN', 1, 0], ['LPN', 2, 1]] as [$type, $version, $current]) {
            $hub->table('gp_license')->insert([
                'identity_id' => $this->identityId, 'license_number' => 'L-77',
                'certification_state' => 'CA', 'certification_board' => 'BRN',
                'license_type' => $type, 'license_type_id' => null, 'registry' => 'CA-BRN',
                'is_verified' => 0, 'source_link_id' => $this->linkId,
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
        }

        foreach ([['Apt 1', 1, 0], ['Apt 2', 2, 1]] as [$address2, $version, $current]) {
            $hub->table('gp_address')->insert([
                'identity_id' => $this->identityId, 'address1' => '1 Main St',
                'address2' => $address2, 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701',
                'is_primary' => 1, 'source_link_id' => $this->linkId,
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
        }

        foreach ([[1, 0], [2, 1]] as [$version, $current]) {
            $hub->table('gp_identity_identifier')->insert([
                'identity_id' => $this->identityId, 'id_type' => 'dea', 'id_value' => 'BX1234563',
                'source_link_id' => $this->linkId,
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
            $hub->table('gp_identity_credential')->insert([
                'identity_id' => $this->identityId, 'credential_match_id' => 501,
                'system_id' => $this->systemId, 'registry' => 'CA-BRN',
                'match_summary_status' => 'Verified', 'match_summary_status_code' => 1,
                'match_is_valid' => 1, 'source_current' => 1, 'date_resolved' => null,
                'link_state' => 'confirmed', 'link_confidence' => null,
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
            $hub->table('gp_identity_exclusion')->insert([
                'identity_id' => $this->identityId, 'match_id' => 601,
                'system_id' => $this->systemId, 'exclusion_record_id' => null, 'registry' => 'LEIE',
                'is_ssn_match' => 0, 'is_npi_match' => 1, 'is_canonical_name_match' => 1,
                'is_upin_match' => 0, 'is_license_number_match' => 0,
                'link_state' => 'candidate', 'link_confidence' => null,
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
        }
    }

    /** The profile row, volatile columns dropped and JSON arrays canonicalised. */
    private function snapshot(): array
    {
        $row = (array) $this->hub()->table('gp_identity_profile')
            ->where('identity_id', $this->identityId)->first();

        foreach (self::VOLATILE as $column) {
            unset($row[$column]);
        }

        foreach (self::JSON_ARRAYS as $column) {
            $decoded = json_decode((string) $row[$column], true) ?? [];
            $encoded = array_map(fn ($e) => json_encode($e), $decoded);
            sort($encoded);
            $row[$column] = $encoded;
        }

        return $row;
    }

    public function test_the_aggregates_count_current_rows_only(): void
    {
        $this->seedTwoVersionsOfEverything();

        (new SetFinalizer)->materialize();

        $row = $this->hub()->table('gp_identity_profile')
            ->where('identity_id', $this->identityId)->first();

        $this->assertSame(1, (int) $row->license_count, 'licenses aggregated a superseded version');
        $this->assertSame(1, (int) $row->address_count, 'addresses aggregated a superseded version');
        $this->assertSame(1, (int) $row->identifier_count, 'identifiers aggregated a superseded version');
        $this->assertSame(1, (int) $row->credential_count, 'credentials aggregated a superseded version');
        $this->assertSame(1, (int) $row->exclusion_count, 'exclusions aggregated a superseded version');
    }

    public function test_the_json_carries_the_current_version_and_not_the_old_one(): void
    {
        $this->seedTwoVersionsOfEverything();

        (new SetFinalizer)->materialize();

        $row = $this->hub()->table('gp_identity_profile')
            ->where('identity_id', $this->identityId)->first();

        $this->assertStringContainsString('LPN', $row->licenses);
        $this->assertStringNotContainsString('RN"', $row->licenses);
        $this->assertStringContainsString('Apt 2', $row->addresses);
        $this->assertStringNotContainsString('Apt 1', $row->addresses);
    }

    public function test_the_primary_address_scalars_come_from_the_current_version(): void
    {
        // $prim is a window function over gp_address, so it needs the filter in its
        // OWN source, not just in the outer query. Unfiltered, ORDER BY is_primary
        // DESC, address_id ASC picks the OLDEST version, which is the superseded one.
        $this->seedTwoVersionsOfEverything();

        (new SetFinalizer)->materialize();

        $row = $this->hub()->table('gp_identity_profile')
            ->where('identity_id', $this->identityId)->first();

        $this->assertSame('1 Main St', $row->address1);
        $this->assertSame('62701', $row->zip);
        $this->assertStringContainsString('Apt 2', $row->addresses);
    }

    public function test_the_two_materialize_paths_agree(): void
    {
        // The documented invariant. It is why Survivorship's final tiebreak is
        // pinned to link_id ASC to match SetFinalizer's SQL.
        $this->seedTwoVersionsOfEverything();

        (new SetFinalizer)->materialize();
        $setBased = $this->snapshot();

        $this->hub()->table('gp_identity_profile')->where('identity_id', $this->identityId)->delete();
        (new ProfileMaterializer)->rebuild($this->identityId);
        $perRow = $this->snapshot();

        $this->assertSame(
            $setBased, $perRow,
            'the set-based materialize and ProfileMaterializer::rebuild() disagree'
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/SetMaterializeParityTest.php`

Expected: FAIL, 4 of 4.
- `test_the_aggregates_count_current_rows_only` — `licenses aggregated a superseded version`
  (`Failed asserting that 2 is identical to 1`).
- `test_the_json_carries_the_current_version_and_not_the_old_one` — the `licenses` JSON contains
  both `"RN"` and `"LPN"`.
- `test_the_primary_address_scalars_come_from_the_current_version` — the `addresses` JSON contains
  `Apt 1`.
- `test_the_two_materialize_paths_agree` — both paths are wrong, but differently: the per-row
  `$primary` pick is `firstWhere('is_primary', 1)` over an unordered result while `$prim` orders by
  `address_id ASC`, so the two disagree on `address2` even before the filters land.

- [ ] **Step 3: Filter the aggregates**

Six edits inside `app/GoldenProfile/Materialize/SetFinalizer.php::materializeRange()`. The range
predicates are unchanged; each subquery gains `current = 1`.

(a) Add a shared fragment just after the two range predicates (`$r` / `$rL`), so the filter is
written once and cannot be applied to five subqueries and forgotten on the sixth:

```php
        // The profile is a projection of the CURRENT version of everything, and it
        // is not itself versioned (a rebuildable read model whose rows reach 100MB
        // of JSON — docs/SCD2.md). These filters are therefore the only thing
        // keeping it correct, and a missing one does not throw: it doubles a count
        // and duplicates a JSON entry.
        //
        // gp_board_action and gp_identity_resolution are absent on purpose: the
        // first is append-only, and the second was already SCD-2 before this
        // programme and is filtered on its own is_current flag below. So are $src,
        // $acct, $alias, $term and $ssn4 — they read gp_source_link and stg_person,
        // neither of which is versioned.
        $rv = "$r AND `current` = 1";
```

(b) The five aggregate subqueries: replace `WHERE $r` with `WHERE $rv` in `$lic`, `$addr`, `$cred`
and `$excl`, and inside `$idt`'s inner `SELECT DISTINCT`:

```php
        $lic = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('number',license_number,'state',certification_state,
                        'board',certification_board,'type',license_type,'registry',registry,
                        'verified',{$jb('is_verified=1')})) js
                FROM gp_license WHERE $rv GROUP BY identity_id";

        $idt = "SELECT identity_id,
                    COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('type',id_type,'value',id_value)) js,
                    MAX(CASE WHEN id_type='dea' THEN id_value END) dea
                FROM (SELECT DISTINCT identity_id,id_type,id_value FROM gp_identity_identifier WHERE $rv) u
                GROUP BY identity_id";

        $addr = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('type', IF(is_primary=1,'primary','alt'),
                        'address1',address1,'address2',address2,'city',city,'state',state,'zip',zip)) js
                 FROM gp_address WHERE $rv GROUP BY identity_id";

        // primary address scalars: is_primary first, then lowest address_id.
        //
        // The filter has to be in THIS subquery, not only in the outer query: it is
        // a window function, so an unfiltered source ranks superseded versions
        // alongside current ones — and address_id ASC then picks the OLDEST, which
        // is exactly the wrong one. A new version gets a new address_id, because
        // Versioner drops the surrogate when it carries a row forward.
        $prim = "SELECT identity_id, address1, city, state, zip FROM (
                    SELECT identity_id, address1, city, state, zip,
                        ROW_NUMBER() OVER (PARTITION BY identity_id ORDER BY is_primary DESC, address_id ASC) rn
                    FROM gp_address WHERE $rv ) t WHERE rn = 1";

        $cred = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('credential_match_id',credential_match_id,'registry',registry,
                        'status',match_summary_status,'status_code',match_summary_status_code,
                        'valid',{$jb('match_is_valid=1')},'current',{$jb('source_current=1')},'link_state',link_state)) js
                 FROM gp_identity_credential WHERE $rv GROUP BY identity_id";

        $excl = "SELECT identity_id, COUNT(*) cnt,
                    MAX(link_state <> 'rejected') act,
                    JSON_ARRAYAGG(JSON_OBJECT('match_id',match_id,'registry',registry,
                        'is_ssn_match',{$jb('is_ssn_match=1')},'is_npi_match',{$jb('is_npi_match=1')},
                        'is_canonical_name_match',{$jb('is_canonical_name_match=1')},
                        'is_license_number_match',{$jb('is_license_number_match=1')},'link_state',link_state)) js
                 FROM gp_identity_exclusion WHERE $rv GROUP BY identity_id";
```

> **The `$prim` / `$ssn4` / `$term` question, answered explicitly.** 3a's proposed task list said all
> three window functions need the filter in their `PARTITION BY` sources. Only `$prim` does.
> `$ssn4` and `$term` read `gp_source_link → stg_person`, and neither table is versioned —
> `stg_person` is input rather than golden fact (staging is a mirror of CAMI's live state, keyed
> `uq_src` and refreshed by `insertOrIgnore`), so there is no `current` column on either to filter on
> and adding one would be a 13M-row duplication of an audit trail this hub does not own. Adding
> `current = 1` to them would be a fatal `Unknown column`, which is the good kind of wrong. What they
> *do* need is a deterministic tiebreak on the per-row side, which is Step 4.

(c) The outer query's identity read. `gp_identity` is versioned, and without this the profile gains a
row per superseded version — which then collides on `gp_identity_profile`'s `identity_id` primary key,
so this one *does* throw, loudly, on the second version of any identity:

```php
        FROM gp_identity i
        LEFT JOIN ($lic) lic     ON lic.identity_id = i.identity_id
        LEFT JOIN ($idt) idt     ON idt.identity_id = i.identity_id
        LEFT JOIN ($addr) addr   ON addr.identity_id = i.identity_id
        LEFT JOIN ($prim) prim   ON prim.identity_id = i.identity_id
        LEFT JOIN ($cred) cred   ON cred.identity_id = i.identity_id
        LEFT JOIN ($excl) excl   ON excl.identity_id = i.identity_id
        LEFT JOIN ($board) board ON board.identity_id = i.identity_id
        LEFT JOIN ($res) res     ON res.identity_id = i.identity_id
        LEFT JOIN ($src) src     ON src.identity_id = i.identity_id
        LEFT JOIN ($acct) acct   ON acct.identity_id = i.identity_id
        LEFT JOIN ($alias) alias ON alias.identity_id = i.identity_id
        LEFT JOIN ($term) term   ON term.identity_id = i.identity_id
        LEFT JOIN ($ssn4) ssn4   ON ssn4.identity_id = i.identity_id
        WHERE i.identity_id >= $lo AND i.identity_id < $hi AND i.`current` = 1");
```

(d) `materialize()`'s chunk bounds read `MIN`/`MAX` over `gp_identity`. Those are the same whether or
not superseded versions are included, so the statement is left alone — but the reason is worth
recording so a later reader does not "fix" it:

```php
        $hub = $this->hub();
        // MIN/MAX over every version, deliberately: a superseded version carries the
        // same identity_id as its successor, so the bounds are identical either way
        // and adding `current` = 1 here would only cost an index probe per chunk.
        $b = $hub->selectOne('SELECT MIN(identity_id) lo, MAX(identity_id) hi FROM gp_identity');
```

- [ ] **Step 4: Make the per-row picks deterministic**

Four edits to `app/GoldenProfile/Materialize/ProfileMaterializer.php`. Each removes a genuine
nondeterminism, not a stylistic one: the set-based side pins an order and the per-row side did not, so
the documented byte-identical invariant held by luck. Task 3a already added the `current = 1` filters
here; these are the orderings on top.

(a) The identifiers read and the DEA fallback (was lines 66–70):

```php
        $identifiers = $hub->table('gp_identity_identifier')
            ->where('identity_id', $identityId)->where('current', 1)
            ->orderBy('id')->get()
            ->map(fn ($r) => ['type' => $r->id_type, 'value' => $r->id_value])
            ->unique(fn ($r) => $r['type'].'|'.$r['value'])->values();

        // Fall back the profile's dea_number column to a DEA identifier for display.
        //
        // max(), not firstWhere(): SetFinalizer's $idt picks
        // MAX(CASE WHEN id_type='dea' THEN id_value END), so an identity carrying two
        // DEA identifiers would otherwise get a different fallback from each path and
        // break the byte-identical-profile invariant.
        $deaFromIdentifier = $identifiers->where('type', 'dea')->max('value');
```

(b) The addresses read and the primary pick (was lines 72–73):

```php
        $addresses = $hub->table('gp_address')
            ->where('identity_id', $identityId)->where('current', 1)
            ->orderBy('address_id')->get();

        // is_primary first, then lowest address_id — the same order as
        // SetFinalizer's $prim window function. Without the orderBy this read
        // returned rows in whatever order the server chose and firstWhere() could
        // pick a different address than the bulk path did.
        $primary = $addresses->firstWhere('is_primary', 1) ?? $addresses->first();
```

(c) The licences read (was line 59), for the same reason — the JSON element order is compared as a
multiset, but a stable read order makes a diff readable when one does fail:

```php
        $licenses = $hub->table('gp_license')
            ->where('identity_id', $identityId)->where('current', 1)
            ->orderBy('license_id')->get()
            ->map(fn ($l) => [
                'number' => $l->license_number, 'state' => $l->certification_state,
                'board' => $l->certification_board, 'type' => $l->license_type,
                'registry' => $l->registry, 'verified' => (bool) $l->is_verified,
            ])->values();
```

(d) The `terminated` pick (was lines 114–115) and `ssnLastFour()` (was lines 192–200), matching
`$term`'s and `$ssn4`'s orderings exactly:

```php
        // terminated flag = latest staged person's flag. The stg_person_id DESC tail
        // matches SetFinalizer's $term window (source_modified DESC, stg_person_id
        // DESC); without it two rows with the same source_modified could resolve
        // differently on the two paths.
        $terminated = $stgIds->isEmpty() ? null : (int) $hub->table('stg_person')
            ->whereIn('stg_person_id', $stgIds)
            ->orderByDesc('source_modified')->orderByDesc('stg_person_id')
            ->value('terminated');
```

```php
    /**
     * Lowest stg_person_id with a non-null ssn_last_four — the same pick as
     * SetFinalizer's $ssn4 window (ORDER BY stg_person_id ASC).
     *
     * Deleted by plan 2 along with the column.
     */
    private function ssnLastFour($stgIds): ?string
    {
        if ($stgIds->isEmpty()) {
            return null;
        }

        return $this->hub()->table('stg_person')->whereIn('stg_person_id', $stgIds)
            ->whereNotNull('ssn_last_four')
            ->orderBy('stg_person_id')
            ->value('ssn_last_four');
    }
```

- [ ] **Step 5: Record the JSON-order caveat in `docs/SCD2.md`**

Append to `docs/SCD2.md`:

```markdown
## What "byte-identical profile" means after the set-based conversion

`Resolution\Survivorship`'s final tiebreak is pinned to `link_id ASC` to match `SetFinalizer`'s SQL
"because a mismatch broke the *rebuild produces a byte-identical profile* invariant". Plan 3b keeps
that invariant and narrows what it claims, because read literally it was never available for every
column:

- **Scalars are byte-identical.** `first_name` … `zip`, every `*_count`, `terminated`, `confidence`,
  `has_active_exclusion`, `has_active_board_action`. Plan 3b made four of them actually deterministic
  rather than incidentally so: the primary-address pick, the `dea_number` fallback, `terminated` and
  `ssn_last_four` all had a tiebreak on the set-based side and none on the per-row side.
- **JSON arrays agree as multisets, not byte-for-byte.** MySQL 8 has no `ORDER BY` inside
  `JSON_ARRAYAGG`, and the per-row path builds its arrays in query-return order, which is equally
  unpinned. So `licenses`, `addresses`, `identifiers`, `credentials`, `exclusions`, `board_actions`,
  `resolutions`, `aliases`, `source_records` and `accounts` are compared by decoding, sorting the
  elements by their canonical JSON, and re-encoding. `SetMaterializeParityTest` and
  `SetBasedParityTest` both do this.
- **Four columns are excluded outright**: `identity_uuid` (minted by `UUID()` on one path and
  `Str::uuid()` on the other), `first_seen`, `last_updated` and `profile_built_at` (all `NOW()`).

Anyone tightening this to true byte-identity needs an ordered JSON aggregate, which means either a
MySQL version that has one or building the arrays with a correlated `GROUP_CONCAT(... ORDER BY ...)`
and `CAST(... AS JSON)`. Neither is worth the cost of a hand-rolled JSON encoder in SQL.
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/SetMaterializeParityTest.php`
Expected: PASS, 4 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 163 tests, 0 skipped.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Materialize/SetFinalizer.php \
        app/GoldenProfile/Materialize/ProfileMaterializer.php \
        docs/SCD2.md \
        tests/Feature/SetMaterializeParityTest.php
git commit -m "fix(scd2): materialize profiles from current versions only"
```

---

## Task 5: Give `residualCreateAndLink()` its own scratch column

`SqlBackfill::residualCreateAndLink()` writes a `stg_person_id` into `gp_identity.merged_into`, joins
back on it to build the links, then clears it — its own comment calls it "the unused `merged_into`
column as a temporary stg_person_id carrier so the 1:1 create + link stays fully set-based". Under 3a
`merged_into` is a **golden attribute**: it records which identity a merged one went to, and it is one
of the eleven columns `Versioner` compares. So the reuse would write a `stg_person_id` into a golden
field and mint a version doing it — twice per identity, once on the write and once on the clear.

**Files:**
- Create: `database/migrations/2026_09_04_000200_add_stg_seed_id_to_gp_identity.php`
- Modify: `app/GoldenProfile/SqlBackfill.php:551-580` (`residualCreateAndLink`)
- Test: `tests/Feature/SetResidualScratchTest.php`

**Interfaces:**
- Consumes: `SqlBackfill::withoutIdentityKeyIndexes(callable $fn): void` (Task 1).
- Produces: column `gp_identity.stg_seed_id BIGINT UNSIGNED NULL`. No signature change.

**Why a column and not a temporary table.** The mapping `identity_id ↔ stg_person_id` is *created* by
the `INSERT … SELECT` that mints the identities, and MySQL gives no way to capture the generated
auto-increment values into a second table from that statement — `LAST_INSERT_ID()` returns the first
of the batch, and relying on the block being contiguous breaks the moment another worker interleaves.
Pre-assigning ids from `MAX(identity_id)` plus `ROW_NUMBER()` has the same race. So the carrier has to
be a column on the inserted row; the only defect in the current code is that it borrows one that now
means something.

**Why no index on it, and what that costs.** The link `INSERT` joins `stg_person` on
`i.stg_seed_id`, and the final clear scans for non-null values — both of which the current code does
against `merged_into`, which is likewise unindexed. Adding an index would be maintained during the
single biggest insert in the pipeline (one identity per still-unlinked staged row, potentially
millions), inside the very window Task 1's `withoutIdentityKeyIndexes()` exists to keep index-free.
So the cost profile is deliberately identical to today's: two full scans of `gp_identity` per residual
run, which is why the residual step is a bulk-only path and not something `gp:sync` calls.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SetResidualScratchTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\SqlBackfill;
use Tests\Support\HubTestCase;

/**
 * The residual tier gives every still-unlinked staged row its own identity, and it
 * has to carry stg_person_id out of the INSERT that mints the identity_id so the
 * link insert can join the two — there is no way to capture generated
 * auto-increment values into a second table from one statement.
 *
 * It used to borrow gp_identity.merged_into for that. Under SCD-2 merged_into is a
 * golden attribute (Versioner compares it), so the borrow would write a
 * stg_person_id into a golden field, mint a version recording it, and mint a second
 * version clearing it — and any later read of merged_into would follow a merge
 * pointer to an identity that does not exist.
 */
class SetResidualScratchTest extends HubTestCase
{
    private function backfillSystemId(): int
    {
        new SqlBackfill;

        return (int) $this->hub()->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');
    }

    public function test_gp_identity_carries_a_scratch_column_of_its_own(): void
    {
        $this->assertTrue(
            $this->hub()->getSchemaBuilder()->hasColumn('gp_identity', 'stg_seed_id'),
            'the residual step needs its own carrier; merged_into is a golden attribute now'
        );
    }

    public function test_the_residual_tier_never_writes_merged_into(): void
    {
        $systemId = $this->backfillSystemId();

        // Three rows with no usable key at all: no npi, no upin, no dea, and each a
        // different person, so nothing above the residual tier can bind them.
        foreach ([['Ada', 'Nwosu'], ['Bruno', 'Kalinowski'], ['Chen', 'Watanabe']] as [$f, $l]) {
            $this->stagePerson([
                'system_id' => $systemId, 'first_name' => $f, 'last_name' => $l,
                'date_of_birth' => null,
            ]);
        }

        (new SqlBackfill)->resolveDeterministic();

        $this->assertSame(
            3, (int) $this->hub()->table('gp_identity')->count(),
            'the residual tier should mint one identity per unlinked row'
        );
        $this->assertSame(
            0, (int) $this->hub()->table('gp_identity')->whereNotNull('merged_into')->count(),
            'the residual tier wrote a stg_person_id into merged_into — a golden attribute'
        );
        $this->assertSame(
            3, (int) $this->hub()->table('gp_source_link')->where('system_id', $systemId)->count(),
            'every residual identity must get its link'
        );
    }

    public function test_the_scratch_column_is_cleared_and_mints_no_versions(): void
    {
        $systemId = $this->backfillSystemId();
        $this->stagePerson([
            'system_id' => $systemId, 'first_name' => 'Ada', 'last_name' => 'Nwosu',
            'date_of_birth' => null,
        ]);

        (new SqlBackfill)->resolveDeterministic();

        $rows = $this->hub()->table('gp_identity')->get();

        $this->assertCount(1, $rows, 'creating an identity is one row, never a version pair');
        $this->assertNull($rows[0]->stg_seed_id, 'the carrier must be cleared in the same step that sets it');
        $this->assertSame(1, (int) $rows[0]->version_no);
        $this->assertSame(1, (int) $rows[0]->current);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/SetResidualScratchTest.php`

Expected: FAIL, 3 of 3.
- `test_gp_identity_carries_a_scratch_column_of_its_own` — `the residual step needs its own carrier`.
- `test_the_residual_tier_never_writes_merged_into` — the residual insert leaves `merged_into` set
  only transiently, so this one may pass or fail depending on whether the final clear ran; it fails
  on the third assertion because `stg_seed_id` does not exist, so nothing joins.
- `test_the_scratch_column_is_cleared_and_mints_no_versions` — `Unknown column 'stg_seed_id'` when
  reading the row back.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_04_000200_add_stg_seed_id_to_gp_identity.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A scratch carrier for SqlBackfill::residualCreateAndLink().
 *
 * The residual tier mints one identity per still-unlinked staged row and then has
 * to link the two. It cannot do that without carrying stg_person_id out of the
 * INSERT that generated the identity_id: MySQL offers no way to capture a batch's
 * generated auto-increment values into a second table, LAST_INSERT_ID() returns
 * only the first of the batch, and assuming the block is contiguous breaks as soon
 * as another writer interleaves. So the carrier is a column on the inserted row.
 *
 * It used to be gp_identity.merged_into, described in that method as "the unused
 * merged_into column as a temporary stg_person_id carrier so the 1:1 create + link
 * stays fully set-based". 2026_09_04_000100_add_scd2_versioning made merged_into a
 * golden attribute — Versioner compares it, and it records which identity a merged
 * one went to — so the borrow would now write a stg_person_id into a golden field,
 * mint a version recording that, and mint a second version clearing it. Worse, any
 * read of merged_into between the two statements follows a merge pointer to an
 * identity that does not exist.
 *
 * NULLABLE, DEFAULT NULL, at the end of the row, so ADD COLUMN is ALGORITHM=INSTANT
 * (MySQL 8.0.12+) — a metadata change on a 13.4M-row table, no rebuild, no UPDATE
 * pass.
 *
 * DELIBERATELY UNINDEXED. The link INSERT joins stg_person on it and the final clear
 * scans for non-null values, both of which the merged_into version already did
 * unindexed. An index here would be maintained during the single biggest insert in
 * the pipeline, inside the exact window
 * SqlBackfill::withoutIdentityKeyIndexes() exists to keep index-free. The cost is
 * two full scans of gp_identity per residual run, which is the same cost the code
 * has today and is why the residual step is bulk-only.
 *
 * NOT a versioned attribute. It is absent from Versioner::TABLES, and
 * Versioner::carryForward() unsets it explicitly so a run that died between setting
 * and clearing cannot preserve a stale stg_person_id through every future version.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasColumn('gp_identity', 'stg_seed_id')) {
            return;
        }

        DB::connection($this->connection)->statement(
            'ALTER TABLE `gp_identity` ADD COLUMN `stg_seed_id` BIGINT UNSIGNED NULL DEFAULT NULL,
             ALGORITHM=INSTANT'
        );
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('gp_identity', 'stg_seed_id')) {
            return;
        }

        DB::connection($this->connection)->statement(
            'ALTER TABLE `gp_identity` DROP COLUMN `stg_seed_id`'
        );
    }
};
```

- [ ] **Step 4: Swap the residual step onto it**

Replace `residualCreateAndLink()` in `app/GoldenProfile/SqlBackfill.php`:

```php
    /**
     * Any staged row still unlinked (no usable key) becomes its own identity.
     *
     * The 1:1 create-then-link stays fully set-based by carrying stg_person_id out
     * of the identity INSERT in gp_identity.stg_seed_id
     * (2026_09_04_000200_add_stg_seed_id_to_gp_identity) and joining back on it.
     * That used to be merged_into; 2026_09_04_000100_add_scd2_versioning made
     * merged_into a golden attribute, so borrowing it would write a stg_person_id
     * into a golden field and mint two versions per identity doing it — see the
     * migration's docblock.
     *
     * version_no and current are stated explicitly even though the column defaults
     * would produce them. insertGetId's per-row counterpart
     * (DeterministicResolver::createIdentity) does the same, for the same reason: a
     * reader of this statement should not have to open the migration to know which
     * version it produces.
     */
    private function residualCreateAndLink(): void
    {
        // This is the single biggest insert — one identity per still-unlinked staged
        // row (potentially millions). The key indexes are dropped for the duration
        // so it does not maintain five secondary indexes per row; the earlier key
        // tiers already finished (they needed them) and dedup, which needs them,
        // runs after. See withoutIdentityKeyIndexes() for why uq_identity_current
        // stays.
        $this->withoutIdentityKeyIndexes(function () {
            // Anti-join (LEFT JOIN … link_id IS NULL) instead of a correlated NOT EXISTS.
            $this->hub()->statement(
                "INSERT INTO gp_identity
                    (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                     ssn_hash, npi, upin, dea_number, confidence, record_count, status, stg_seed_id,
                     version_no, `current`, first_seen, last_updated)
                 SELECT UUID(), s.first_name, s.middle_name, s.last_name, s.date_of_birth,
                     s.ssn_hash, s.npi, s.upin, s.dea_number, 1.0, 0, 'active', s.stg_person_id,
                     1, 1, NOW(), NOW()
                 FROM stg_person s
                 LEFT JOIN gp_source_link l
                   ON l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id
                 WHERE s.system_id = ? AND l.link_id IS NULL",
                [$this->systemId]
            );

            // i.current = 1 is redundant on rows this method just minted, and kept
            // anyway: a re-run after a partial failure would otherwise be able to
            // join a superseded version that still carried a stale seed.
            $this->hub()->statement(
                "INSERT INTO gp_source_link
                    (identity_id, system_id, source_table, source_id, account_id, employeelist_id,
                     match_method, match_key, match_score, match_state, is_pinned, linked_at)
                 SELECT i.identity_id, s.system_id, s.source_table, s.source_id, s.account_id, s.employeelist_id,
                     'deterministic', 'new', 1.0, 'auto_match', 0, NOW()
                 FROM gp_identity i
                 JOIN stg_person s ON s.stg_person_id = i.stg_seed_id
                 WHERE i.stg_seed_id IS NOT NULL AND i.`current` = 1",
                []
            );

            $this->hub()->statement(
                'UPDATE gp_identity SET stg_seed_id = NULL WHERE stg_seed_id IS NOT NULL',
                []
            );
        });
    }
```

> The clear is a plain `UPDATE`, not a versioned write, and that is correct: `stg_seed_id` is not in
> `Versioner::TABLES`, so it is not a golden fact and changing it is not a change to the identity.
> That is the whole point of giving it its own column.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/SetResidualScratchTest.php`
Expected: PASS, 3 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 166 tests, 0 skipped.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_09_04_000200_add_stg_seed_id_to_gp_identity.php \
        app/GoldenProfile/SqlBackfill.php \
        tests/Feature/SetResidualScratchTest.php
git commit -m "fix(scd2): stop the residual tier borrowing merged_into as scratch"
```

---

## Task 6: Version the set-based resolve path — tier reads and `backfillIdentityKeys()`

3a's proposed five-task split for 3b did not name this task, and it has to exist: `SqlBackfill`'s
resolve half contains four `gp_identity` **reads** that would match superseded versions and one
`gp_identity` **write** — `backfillIdentityKeys()` — that overwrites golden facts in place. That write
is the set-based counterpart of `DeterministicResolver::backfillKeys()`, which 3a converted to
`Versioner::write()`. Leaving it unconverted would mean `gp:backfill` still overwrote where `gp:sync`
versioned, which is precisely the divergence the whole plan is about. Justified as a deviation in
Self-review.

The read filters are the more dangerous half. A tier's anti-join reads
`SELECT col FROM gp_identity WHERE status='active' AND col IS NOT NULL GROUP BY col`; an identity whose
`npi` was corrected has two versions with two different npis, and the anti-join sees the old one. So a
staged row carrying the corrected npi finds "no active identity has this key yet", mints a second
identity for the same person, and that is a **false split** the eval gate is there to catch.

**Files:**
- Modify: `app/GoldenProfile/SqlBackfill.php:425-448` (`backfillIdentityKeys`), `:450-479`
  (`tierCreate`), `:481-503` (`tierLink`), `:505-549` (`nameDobCreateAndLink`)
- Test: `tests/Feature/SetResolveVersioningTest.php`

**Interfaces:**
- Consumes: `SetVersionWriter::writeIdentities(string $proposals, array $columns, array $derived = []): int`.
- Produces: no signature change. `SqlBackfill::resolveDeterministic(?callable $log = null): void`
  unchanged; `backfillIdentityKeys()` stays private and keeps its `void` return.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SetResolveVersioningTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\SqlBackfill;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * The set-based resolve half, under versioning.
 *
 * The read filters matter more than the write here. A tier's anti-join asks "does
 * an active identity already hold this key?" against gp_identity; unfiltered it
 * sees SUPERSEDED versions, so an identity whose npi was corrected still answers
 * with its old npi and does not answer with its new one. A staged row carrying the
 * corrected value therefore concludes nobody holds it, mints a second identity for
 * the same person, and that is a false split — silent, and the exact failure the
 * eval gate exists to catch.
 *
 * backfillIdentityKeys() is the write: it fills an identity's null keys from its
 * linked staged rows. Supplying a key the identity did not have is a change to a
 * golden fact, so under the SCD-2 rule it is a new version, and supplying nothing
 * must be no version at all — which is what keeps a re-run of gp:backfill from
 * adding one gp_identity row per identity.
 */
class SetResolveVersioningTest extends HubTestCase
{
    private function backfillSystemId(): int
    {
        new SqlBackfill;

        return (int) $this->hub()->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');
    }

    /** Two versions of one identity: version 1 with $oldNpi retired, version 2 current with $newNpi. */
    private function seedCorrectedNpi(int $oldNpi, int $newNpi): int
    {
        $uuid = (string) Str::uuid();

        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => $uuid,
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'npi' => $oldNpi, 'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 0, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $identityId, 'identity_uuid' => $uuid,
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'npi' => $newNpi, 'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        return $identityId;
    }

    public function test_a_tier_does_not_mint_a_second_identity_for_a_corrected_key(): void
    {
        $systemId = $this->backfillSystemId();
        $identityId = $this->seedCorrectedNpi(1987654328, 1234567893);

        $this->stagePerson([
            'system_id' => $systemId, 'first_name' => 'Bob', 'last_name' => 'Smith',
            'date_of_birth' => null, 'npi' => 1234567893,
        ]);

        (new SqlBackfill)->resolveDeterministic();

        $this->assertSame(
            $identityId,
            (int) $this->hub()->table('gp_source_link')->where('system_id', $systemId)->value('identity_id'),
            'the npi tier could not see the CURRENT version and minted a new identity — a false split'
        );
        $this->assertSame(
            1, (int) $this->hub()->table('gp_identity')->distinct()->count('identity_id'),
            'a second identity was created for a person the hub already knows'
        );
    }

    public function test_a_tier_does_not_bind_to_a_superseded_key(): void
    {
        // The mirror image. The old npi is history: a staged row carrying it must
        // NOT be welded onto that identity, because the hub's current truth is that
        // the identity's npi is something else. Binding it would be a false merge.
        $systemId = $this->backfillSystemId();
        $identityId = $this->seedCorrectedNpi(1987654328, 1234567893);

        $this->stagePerson([
            'system_id' => $systemId, 'first_name' => 'Ada', 'last_name' => 'Nwosu',
            'date_of_birth' => null, 'npi' => 1987654328,
        ]);

        (new SqlBackfill)->resolveDeterministic();

        $this->assertNotSame(
            $identityId,
            (int) $this->hub()->table('gp_source_link')->where('system_id', $systemId)->value('identity_id'),
            'a staged row bound to a SUPERSEDED npi — a false merge'
        );
    }

    public function test_backfilling_a_missing_key_mints_one_version(): void
    {
        $systemId = $this->backfillSystemId();

        // An identity with no npi, and a staged row that has one. The name+dob tier
        // links them; backfillIdentityKeys then supplies the npi.
        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'npi' => null, 'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->stagePerson(['system_id' => $systemId, 'npi' => 1234567893]);

        (new SqlBackfill)->resolveDeterministic();

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $identityId)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows, 'supplying a key the identity lacked is a change to a golden fact');
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertNull($rows[0]->npi);
        $this->assertSame(1, (int) $rows[1]->current);
        $this->assertSame('1234567893', (string) $rows[1]->npi);
        $this->assertSame(
            'Robert', $rows[1]->canonical_first,
            'columns with nothing to add must carry forward, not be overwritten'
        );
    }

    public function test_re_resolving_the_same_rows_mints_no_further_version(): void
    {
        // This is the property that keeps a re-run of gp:backfill from adding one
        // gp_identity row per identity. It is also what makes the residual and tier
        // steps safe to re-run after an interrupted load.
        $systemId = $this->backfillSystemId();
        $this->stagePerson(['system_id' => $systemId, 'npi' => 1234567893]);

        $backfill = new SqlBackfill;
        $backfill->resolveDeterministic();
        $before = (int) $this->hub()->table('gp_identity')->count();

        $backfill->resolveDeterministic();

        $this->assertSame(
            $before, (int) $this->hub()->table('gp_identity')->count(),
            'the set-based resolve path is not idempotent under versioning'
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/SetResolveVersioningTest.php`

Expected: FAIL, 4 of 4.
- `test_a_tier_does_not_mint_a_second_identity_for_a_corrected_key` — the anti-join sees the
  superseded npi and misses the current one, so a second identity appears:
  `Failed asserting that 2 is identical to 1`.
- `test_a_tier_does_not_bind_to_a_superseded_key` — the link points at the identity via its retired
  npi.
- `test_backfilling_a_missing_key_mints_one_version` — `Failed asserting that actual size 1 matches
  expected size 2`: the `UPDATE … SET i.npi = COALESCE(...)` overwrote version 1 in place.
- `test_re_resolving_the_same_rows_mints_no_further_version` — passes only accidentally today
  (overwriting never adds rows); it must still pass after Step 4, which is the point of having it.

- [ ] **Step 3: Filter the four tier reads**

Four edits to `app/GoldenProfile/SqlBackfill.php`. Every one adds `AND \`current\` = 1` to a
`gp_identity` read and nothing else. `status = 'active'` stays alongside it in every case: `current`
is not `alive`, and a merged-away identity's latest version is `status = 'merged'` with
`current = 1`, so dropping either filter is a bug in a different direction.

(a) `tierCreate()`'s anti-join source:

```php
    /** Create one identity per distinct new value of $col among unlinked rows. */
    private function tierCreate(string $col): void
    {
        // ssn_hash only: skip rows whose hash is on the filler blocklist so they
        // fall through to the weaker-but-safe name+dob / residual tiers instead of
        // all collapsing onto one identity.
        $guard = $col === 'ssn_hash' ? $this->ssnGuard->exclusionSql('s.`ssn_hash`') : '';

        $this->hub()->statement(
            "INSERT INTO gp_identity
                (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                 ssn_hash, npi, upin, dea_number, confidence, record_count, status,
                 version_no, `current`, first_seen, last_updated)
             SELECT UUID(), r.first_name, r.middle_name, r.last_name, r.date_of_birth,
                 r.ssn_hash, r.npi, r.upin, r.dea_number, 1.0, 0, 'active',
                 1, 1, NOW(), NOW()
             FROM stg_person r
             JOIN (
                 SELECT MIN(s.stg_person_id) mid
                 FROM stg_person s
                 LEFT JOIN gp_source_link l
                   ON l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id
                 LEFT JOIN (SELECT `$col` k FROM gp_identity
                            WHERE status='active' AND `current` = 1 AND `$col` IS NOT NULL
                            GROUP BY `$col`) gi
                   ON gi.k = s.`$col`
                 WHERE s.system_id = ? AND s.`$col` IS NOT NULL
                   AND l.link_id IS NULL     -- not yet linked (anti-join)
                   AND gi.k IS NULL          -- no active identity has this key yet (anti-join)
                   $guard
                 GROUP BY s.`$col`
             ) f ON f.mid = r.stg_person_id",
            [$this->systemId]
        );
    }
```

(b) `tierLink()`'s identity source. Note what the filter fixes beyond the obvious: `MIN(identity_id)`
over unfiltered versions can pick a *retired* identity whose current version was merged away, and the
link would then point at an identity nothing reads:

```php
    /** Link every unlinked row whose $col matches an active identity. */
    private function tierLink(string $col, string $keyName): void
    {
        // Same filler screen as tierCreate — see there.
        $guard = $col === 'ssn_hash' ? $this->ssnGuard->exclusionSql('s.`ssn_hash`') : '';

        $this->hub()->statement(
            "INSERT INTO gp_source_link
                (identity_id, system_id, source_table, source_id, account_id, employeelist_id,
                 match_method, match_key, match_score, match_state, is_pinned, linked_at)
             SELECT i.identity_id, s.system_id, s.source_table, s.source_id, s.account_id, s.employeelist_id,
                 'deterministic', ?, 0.99, 'auto_match', 0, NOW()
             FROM stg_person s
             JOIN (SELECT `$col` k, MIN(identity_id) identity_id FROM gp_identity
                   WHERE status='active' AND `current` = 1 AND `$col` IS NOT NULL
                   GROUP BY `$col`) i ON i.k = s.`$col`
             WHERE s.system_id = ? AND s.`$col` IS NOT NULL
               $guard
               AND NOT EXISTS (SELECT 1 FROM gp_source_link l
                    WHERE l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id)",
            [$keyName, $this->systemId]
        );
    }
```

(c) and (d) `nameDobCreateAndLink()`'s two `gp_identity` reads, and its explicit version columns:

```php
    /** Name + DOB tier (lowest-confidence deterministic key). */
    private function nameDobCreateAndLink(): void
    {
        $this->hub()->statement(
            "INSERT INTO gp_identity
                (identity_uuid, canonical_first, canonical_middle, canonical_last, canonical_dob,
                 ssn_hash, npi, upin, dea_number, confidence, record_count, status,
                 version_no, `current`, first_seen, last_updated)
             SELECT UUID(), r.first_name, r.middle_name, r.last_name, r.date_of_birth,
                 r.ssn_hash, r.npi, r.upin, r.dea_number, 1.0, 0, 'active',
                 1, 1, NOW(), NOW()
             FROM stg_person r
             JOIN (
                 SELECT MIN(s.stg_person_id) mid
                 FROM stg_person s
                 LEFT JOIN gp_source_link l
                   ON l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id
                 LEFT JOIN (SELECT canonical_last l, canonical_first f, canonical_dob d
                            FROM gp_identity WHERE status='active' AND `current` = 1
                              AND canonical_last IS NOT NULL AND canonical_first IS NOT NULL AND canonical_dob IS NOT NULL
                            GROUP BY canonical_last, canonical_first, canonical_dob) gi
                   ON gi.l=s.last_name AND gi.f=s.first_name AND gi.d=s.date_of_birth
                 WHERE s.system_id = ? AND s.last_name IS NOT NULL AND s.first_name IS NOT NULL AND s.date_of_birth IS NOT NULL
                   AND l.link_id IS NULL     -- not yet linked (anti-join)
                   AND gi.l IS NULL          -- no active identity with this name+dob yet (anti-join)
                 GROUP BY s.last_name, s.first_name, s.date_of_birth
             ) f ON f.mid = r.stg_person_id",
            [$this->systemId]
        );

        $this->hub()->statement(
            "INSERT INTO gp_source_link
                (identity_id, system_id, source_table, source_id, account_id, employeelist_id,
                 match_method, match_key, match_score, match_state, is_pinned, linked_at)
             SELECT i.identity_id, s.system_id, s.source_table, s.source_id, s.account_id, s.employeelist_id,
                 'deterministic', 'name_dob', 0.95, 'auto_match', 0, NOW()
             FROM stg_person s
             JOIN (SELECT canonical_last l, canonical_first f, canonical_dob d, MIN(identity_id) identity_id
                   FROM gp_identity WHERE status='active' AND `current` = 1
                     AND canonical_last IS NOT NULL AND canonical_first IS NOT NULL AND canonical_dob IS NOT NULL
                   GROUP BY canonical_last, canonical_first, canonical_dob) i
                  ON i.l=s.last_name AND i.f=s.first_name AND i.d=s.date_of_birth
             WHERE s.system_id = ?
               AND NOT EXISTS (SELECT 1 FROM gp_source_link l
                    WHERE l.system_id=s.system_id AND l.source_table=s.source_table AND l.source_id=s.source_id)",
            [$this->systemId]
        );
    }
```

> **The five key indexes now carry the filter.** `idx_ssn`, `idx_npi`, `idx_upin`, `idx_dea` and
> `idx_name_dob` all end in `current` after `2026_09_04_000100_add_scd2_versioning`, so each of these
> `GROUP BY`s stays a covering index scan rather than degrading to the 6,475,711-row read
> `DeterministicResolver`'s docblock measured. Both `IDENTITY_KEY_INDEXES` constants were updated in
> 3a Task 10 and `IdentityKeyIndexParityTest` pins them; if a bulk run ever rebuilds the narrow
> versions, these four statements are the first thing that gets slow.

- [ ] **Step 4: Version `backfillIdentityKeys()`**

Replace it entirely:

```php
    /**
     * Populate each active identity's null keys from its linked staged rows.
     *
     * This used to be one UPDATE … JOIN with SET col = COALESCE(i.col, k.col).
     * Supplying a key an identity did not have is a change to a golden fact, so
     * under the SCD-2 rule it is a NEW VERSION (Data Flow by CAMI: "insert a new
     * row with current = 1, and set all preexisting rows to current = 0"), and
     * supplying nothing must be no version at all. The per-row counterpart,
     * DeterministicResolver::backfillKeys(), makes exactly that decision through
     * Versioner::write(); this makes it for the whole set through
     * SetVersionWriter::writeIdentities(), and SetBasedParityTest proves the two
     * agree.
     *
     * The proposals table holds NULL for "nothing to add", which is
     * VersionerSql::differsOnPresent()'s absent-column semantics and matches
     * backfillKeys() skipping a column it has nothing for. The IF(i.col IS NULL, …)
     * wrapper is what produces that NULL: a column the identity already has
     * proposes nothing, so it can never be a change and can never be overwritten.
     *
     * TWO DIVERGENCES FROM THE PER-ROW PATH ARE PRE-EXISTING AND LEFT ALONE, because
     * closing either would change which records match and this plan asserts the eval
     * gate does not move (see docs/SCD2.md):
     *
     *   - backfillKeys() tests emptiness with PHP empty(), so '' and '0' count as
     *     missing; COALESCE only treats NULL as missing.
     *   - backfillKeys() uses the value from the row being resolved; this uses
     *     MAX() across every linked row.
     *
     * A THIRD is owned by plan 2: backfillKeys() refuses to promote a filler
     * ssn_hash onto an identity that lacks one, and this has no blocklist screen at
     * all. Adding it is a matching change, and plan 2 deletes ssn_hash from both
     * paths, so it is recorded rather than fixed.
     */
    private function backfillIdentityKeys(): void
    {
        $hub = $this->hub();

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_backfill_keys');
        $hub->statement('CREATE TEMPORARY TABLE tmp_backfill_keys (INDEX idx_id (identity_id)) ENGINE=InnoDB AS
            SELECT i.identity_id,
                   IF(i.ssn_hash        IS NULL, k.ssn_hash,   NULL) AS ssn_hash,
                   IF(i.npi             IS NULL, k.npi,        NULL) AS npi,
                   IF(i.upin            IS NULL, k.upin,       NULL) AS upin,
                   IF(i.dea_number      IS NULL, k.dea_number, NULL) AS dea_number,
                   IF(i.canonical_dob   IS NULL, k.dob,        NULL) AS canonical_dob,
                   IF(i.canonical_first IS NULL, k.fn,         NULL) AS canonical_first,
                   IF(i.canonical_last  IS NULL, k.ln,         NULL) AS canonical_last,
                   IF(i.canonical_middle IS NULL, k.mn,        NULL) AS canonical_middle
            FROM gp_identity i
            JOIN (
                SELECT l.identity_id,
                       MAX(s.ssn_hash) ssn_hash, MAX(s.npi) npi, MAX(s.upin) upin, MAX(s.dea_number) dea_number,
                       MAX(s.date_of_birth) dob, MAX(s.first_name) fn, MAX(s.last_name) ln, MAX(s.middle_name) mn
                FROM gp_source_link l
                JOIN stg_person s ON s.system_id=l.system_id AND s.source_table=l.source_table AND s.source_id=l.source_id
                GROUP BY l.identity_id
            ) k ON k.identity_id = i.identity_id
            WHERE i.status = \'active\' AND i.`current` = 1');

        (new SetVersionWriter)->writeIdentities('tmp_backfill_keys', [
            'ssn_hash', 'npi', 'upin', 'dea_number',
            'canonical_dob', 'canonical_first', 'canonical_last', 'canonical_middle',
        ]);

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_backfill_keys');
    }
```

And add the import beside the existing ones at the top of `SqlBackfill.php`:

```php
use App\GoldenProfile\Support\SetVersionWriter;
```

> **Why `backfillIdentityKeys()` still runs between every tier.** Its own call site comment explains
> it: "After each tier we backfill identity keys from the just-linked rows so a later tier sees an
> earlier identity's secondary keys — without this, set-based tiers mint duplicate identities."
> Versioning it means each of those five calls can now mint a version, but only for identities that
> genuinely gained a key on that pass, and a key can only be gained once. So the worst case is one
> version per identity per key it was missing — bounded by the number of key columns, not by the
> number of source rows.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/SetResolveVersioningTest.php`
Expected: PASS, 4 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 170 tests, 0 skipped.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/SqlBackfill.php \
        tests/Feature/SetResolveVersioningTest.php
git commit -m "feat(scd2): version the set-based resolve tiers and key backfill"
```

---

## Task 7: Version the set-based `enrich()` and `rollup()`

Five `INSERT … ON DUPLICATE KEY UPDATE` statements become five `SetVersionWriter::write()` calls. The
`ON DUPLICATE KEY` target is the reason they cannot stay: the natural-key uniques now end in
`version_no` (`uq_lic`, `uq_addr`, `uq_identity_identifier`) or are the primary key with `version_no`
appended (`gp_identity_credential`, `gp_identity_exclusion`), so a re-observation no longer collides
with the existing row at all — it inserts a duplicate. And where it *did* still collide, on
`uq_*_current`, the `UPDATE` half would overwrite a golden fact in place, which is the thing the
programme is removing.

**Files:**
- Modify: `app/GoldenProfile/SqlBackfill.php:349-423` (`enrich`), `:620-668` (`rollup`)
- Test: `tests/Feature/SetEnrichRollupVersioningTest.php`

**Interfaces:**
- Consumes: `SetVersionWriter::write(string $table, string $incoming, array $compared): array{new_versions: int}`.
- Produces: no signature change. `SqlBackfill::enrich(): void` and `SqlBackfill::rollup(): void`
  unchanged.

### The five statements, and exactly which attributes each compares

Read off `Versioner::TABLES` and then narrowed to the attributes the **per-row** counterpart actually
passes, because an attribute the per-row path never supplies must carry forward rather than be reset:

| Target | Key (joined with `<=>`) | Compared | `onCreate` (carried) | Never passed → carries forward |
|---|---|---|---|---|
| `gp_license` | `identity_id`, `license_number`, `certification_state`, `certification_board` | `license_type`, `license_type_id`, `registry` | `source_link_id` | **`is_verified`** |
| `gp_address` | `identity_id`, `address1`, `city`, `state`, `zip` | `address2`, `is_primary` | `source_link_id` | — |
| `gp_identity_identifier` | `identity_id`, `id_type`, `id_value` | *(none — the key is the whole fact)* | `source_link_id` | — |
| `gp_identity_credential` | `system_id`, `credential_match_id` | `registry`, `match_summary_status`, `match_summary_status_code`, `match_is_valid`, `source_current`, `date_resolved`, `link_state` | `identity_id` | `link_confidence` |
| `gp_identity_exclusion` | `system_id`, `match_id` | `exclusion_record_id`, `registry`, `is_ssn_match`, `is_npi_match`, `is_canonical_name_match`, `is_upin_match`, `is_license_number_match`, `link_state` | `identity_id` | `link_confidence` |

Four consequences worth stating before writing any code:

- **`is_verified` comes out of the `gp_license` insert.** The current statement writes a literal `0`.
  Per-row `enrich()` does not pass it at all, so under versioning the literal would reset a verified
  licence to unverified *and* mint a version recording the reset, on every bulk run. Dropped from the
  insert, so a brand-new row takes the column default (`0`, the same value) and an existing one keeps
  what it has.
- **`source_link_id` stops being rewritten.** All three `enrich()` statements currently say
  `ON DUPLICATE KEY UPDATE source_link_id=VALUES(source_link_id)`. It is `onCreate`: it records which
  source row *established* the fact. Treating it as an attribute would mint a version every time a
  second account's employee row re-observed the same licence — thousands of identical versions on the
  pile-up identities, where identity 3 folds 12,463 source rows.
- **`identity_id` stops being repointed by the rollups.** Both currently say
  `ON DUPLICATE KEY UPDATE identity_id=VALUES(identity_id)`. 3a made it `onCreate` because repointing
  on a merge is a *grouping* change, recorded in `gp_resolution_log` and on the merged identity's own
  final version; versioning it would mint one row per credential per merge — 397,170 for identity 3
  alone. `Engine::applyMerge()` owns the repoint, with a bulk `UPDATE` across all versions where no
  unique can collide.
- **`gp_identity_identifier` has no attributes, so an existing current row is never superseded.**
  `differsOnAll([])` renders `FALSE`, so `write()` degenerates to "insert version 1 where no history
  exists" — which is exactly right: a DEA number being *withdrawn* is a fact, but it is recorded by
  retiring the row (`Versioner::retire()`), never by re-observing it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SetEnrichRollupVersioningTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\SqlBackfill;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * enrich() and rollup(), versioned.
 *
 * The per-row counterparts of both are Versioner::write() loops — 3a Task 6 for
 * enrich, 3a Task 8 for the two rollups — so the sharpest available parity check is
 * to run the set-based statement and a Versioner::write() loop over the SAME rows
 * and compare. That is what test_the_rollup_agrees_with_versioner_row_by_row does.
 *
 * A direct comparison against Engine::rollupCredentials() is NOT possible here and
 * that is a property of the code, not a shortcut: Engine::rollupCredentials() reads
 * credential_matches from the streamline_local connection, which phpunit.xml points
 * at a dead socket on purpose. Its WRITE half — the only half 3b changes — is
 * literally a Versioner::write() per row over the payload it built, so driving
 * Versioner::write() over src_credential_match reproduces it exactly.
 */
class SetEnrichRollupVersioningTest extends HubTestCase
{
    private int $identityId;

    private int $systemId2;

    protected function setUp(): void
    {
        parent::setUp();

        new SqlBackfill;
        $this->systemId2 = (int) $this->hub()->table('gp_source_system')
            ->where('system_code', SqlBackfill::SYSTEM_CODE)->value('system_id');

        $stg = $this->stagePerson(['system_id' => $this->systemId2, 'npi' => 1234567893]);
        $this->hub()->table('stg_person_license')->insert([
            'stg_person_id' => $stg, 'license_number' => 'L-77',
            'certification_state' => null, 'certification_board' => null,
            'license_type' => 'RN', 'license_type_id' => null, 'registry' => null, 'is_primary' => 1,
        ]);
        $this->hub()->table('stg_person_address')->insert([
            'stg_person_id' => $stg, 'address_type' => 'primary',
            'address1' => '1 Main St', 'address2' => 'Apt 1',
            'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701',
        ]);
        $this->hub()->table('stg_person_identifier')->insert([
            'stg_person_id' => $stg, 'id_type' => 'dea', 'id_value' => 'BX1234563',
        ]);

        (new SqlBackfill)->resolveDeterministic();

        $this->identityId = (int) $this->hub()->table('gp_source_link')
            ->where('system_id', $this->systemId2)->value('identity_id');
    }

    public function test_enrich_is_idempotent_and_does_not_reset_is_verified(): void
    {
        $backfill = new SqlBackfill;
        $backfill->enrich();

        // A steward (plan 6) or a re-verification marks the licence verified. A
        // second enrich must not undo it, and must not mint a version recording an
        // undo it did not make.
        $this->hub()->table('gp_license')->where('identity_id', $this->identityId)
            ->where('current', 1)->update(['is_verified' => 1]);

        $backfill->enrich();

        $rows = $this->hub()->table('gp_license')->where('identity_id', $this->identityId)->get();

        $this->assertCount(
            1, $rows,
            'enrich minted a version on re-observation — and note the licence has a NULL '.
            'certification_state, so the old ON DUPLICATE KEY UPDATE could not even find it'
        );
        $this->assertSame(1, (int) $rows[0]->is_verified, 'is_verified must not be reset to the literal 0');
        $this->assertSame(1, (int) $rows[0]->version_no);
    }

    public function test_a_changed_licence_attribute_supersedes(): void
    {
        $backfill = new SqlBackfill;
        $backfill->enrich();

        $this->hub()->table('stg_person_license')->update(['license_type' => 'LPN']);
        $backfill->enrich();

        $rows = $this->hub()->table('gp_license')->where('identity_id', $this->identityId)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertSame([0, 1], [(int) $rows[0]->current, (int) $rows[1]->current]);
        $this->assertSame('RN', $rows[0]->license_type);
        $this->assertSame('LPN', $rows[1]->license_type);
        $this->assertSame(
            (int) $rows[0]->source_link_id, (int) $rows[1]->source_link_id,
            'source_link_id is onCreate — it records which row established the fact'
        );
    }

    public function test_an_identifier_is_never_superseded_by_re_observation(): void
    {
        // gp_identity_identifier declares NO attributes: its key is the whole fact.
        $backfill = new SqlBackfill;
        $backfill->enrich();
        $backfill->enrich();

        $this->assertSame(
            1, (int) $this->hub()->table('gp_identity_identifier')
                ->where('identity_id', $this->identityId)->count()
        );
    }

    public function test_addresses_version_on_a_changed_non_key_field(): void
    {
        $backfill = new SqlBackfill;
        $backfill->enrich();

        $this->hub()->table('stg_person_address')->update(['address2' => 'Apt 2']);
        $backfill->enrich();

        $rows = $this->hub()->table('gp_address')->where('identity_id', $this->identityId)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Apt 1', $rows[0]->address2);
        $this->assertSame('Apt 2', $rows[1]->address2);
        $this->assertSame(1, (int) $rows[1]->current);
    }

    public function test_the_rollup_versions_and_is_idempotent(): void
    {
        $this->hub()->table('src_credential_match')->insert([
            'id' => 501, 'employee_id' => $this->hub()->table('gp_source_link')
                ->where('identity_id', $this->identityId)->value('source_id'),
            'registry' => 'CA-BRN', 'match_summary_status' => 'Verified',
            'match_summary_status_code' => 1, 'match_is_valid' => 1, 'current' => 1,
            'date_resolved' => null,
        ]);

        $backfill = new SqlBackfill;
        $backfill->rollup();
        $backfill->rollup();

        $this->assertSame(
            1, (int) $this->hub()->table('gp_identity_credential')->count(),
            'a second rollup over identical source rows must mint nothing'
        );

        $this->hub()->table('src_credential_match')->where('id', 501)
            ->update(['match_summary_status' => 'Expired', 'match_is_valid' => 0]);
        $backfill->rollup();

        $rows = $this->hub()->table('gp_identity_credential')->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Verified', $rows[0]->match_summary_status);
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('Expired', $rows[1]->match_summary_status);
        $this->assertSame(1, (int) $rows[1]->current);
        $this->assertSame(
            (int) $rows[0]->identity_id, (int) $rows[1]->identity_id,
            'identity_id is onCreate — a repoint is a grouping change, owned by Engine::applyMerge()'
        );
    }

    public function test_the_rollup_agrees_with_versioner_row_by_row(): void
    {
        // The per-row rollup IS a Versioner::write() per credential over the same
        // payload (3a Task 8). So this drives both over the same src rows and
        // compares the version census. If VersionerSql::same() and
        // Versioner::same() ever disagree on one of these columns, this is where it
        // shows up — with a diff naming the credential.
        $sourceId = (int) $this->hub()->table('gp_source_link')
            ->where('identity_id', $this->identityId)->value('source_id');

        foreach ([[501, 'Verified', 1, null], [502, 'Expired', 0, '2026-08-01 10:00:00']] as [$id, $status, $valid, $resolved]) {
            $this->hub()->table('src_credential_match')->insert([
                'id' => $id, 'employee_id' => $sourceId, 'registry' => 'CA-BRN',
                'match_summary_status' => $status, 'match_summary_status_code' => 1,
                'match_is_valid' => $valid, 'current' => 1, 'date_resolved' => $resolved,
            ]);
        }

        (new SqlBackfill)->rollup();
        $setBased = $this->credentialCensus();

        $this->hub()->table('gp_identity_credential')->delete();

        $versioner = new Versioner;
        foreach ($this->hub()->table('src_credential_match')->orderBy('id')->get() as $c) {
            $versioner->write(
                'gp_identity_credential',
                ['system_id' => $this->systemId2, 'credential_match_id' => (int) $c->id],
                [
                    'registry' => $c->registry,
                    'match_summary_status' => $c->match_summary_status,
                    'match_summary_status_code' => $c->match_summary_status_code,
                    'match_is_valid' => $c->match_is_valid,
                    'source_current' => $c->current,
                    'date_resolved' => $c->date_resolved,
                    'link_state' => 'confirmed',
                ],
                [],
                ['identity_id' => $this->identityId],
            );
        }
        $perRow = $this->credentialCensus();

        $this->assertSame($setBased, $perRow, 'the two rollup paths mint different versions');

        // And a second pass on each side must still mint nothing.
        (new SqlBackfill)->rollup();
        $this->assertSame($perRow, $this->credentialCensus(),
            'the set-based rollup is not idempotent against rows the per-row path wrote');
    }

    /** credential_match_id => [version_no, current, status, valid, resolved], ordered. */
    private function credentialCensus(): array
    {
        $census = [];

        foreach ($this->hub()->table('gp_identity_credential')
            ->orderBy('credential_match_id')->orderBy('version_no')->get() as $row) {
            $census[(int) $row->credential_match_id][] = [
                (int) $row->version_no, (int) $row->current,
                $row->match_summary_status, (int) $row->match_is_valid,
                (string) $row->date_resolved,
            ];
        }

        return $census;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/SetEnrichRollupVersioningTest.php`

Expected: FAIL, 6 of 6.
- `test_enrich_is_idempotent_and_does_not_reset_is_verified` — two `gp_license` rows, because
  `uq_lic` now ends in `version_no` *and* cannot match the NULL `certification_state` anyway. The
  message names both causes.
- `test_a_changed_licence_attribute_supersedes` — two rows, but both `current = 1` with `version_no`
  1, so the `[0, 1]` assertion fails.
- `test_an_identifier_is_never_superseded_by_re_observation` — `Failed asserting that 2 is identical
  to 1`.
- `test_addresses_version_on_a_changed_non_key_field` — same shape as the licence case.
- `test_the_rollup_versions_and_is_idempotent` — `Failed asserting that 2 is identical to 1`: the
  `ON DUPLICATE KEY` no longer matches, so the second rollup inserts a duplicate.
- `test_the_rollup_agrees_with_versioner_row_by_row` — the censuses differ: the set-based side has
  duplicate `version_no` 1 entries where the per-row side has one.

- [ ] **Step 3: Version `enrich()`**

Replace `enrich()` in `app/GoldenProfile/SqlBackfill.php`:

```php
    /**
     * Populate gp_license + gp_address + gp_identity_identifier from the staged
     * children (set-based).
     *
     * Each statement used to be INSERT … ON DUPLICATE KEY UPDATE
     * source_link_id=VALUES(source_link_id). Neither half of that survives SCD-2:
     *
     *   - the ON DUPLICATE KEY target is gone. uq_lic, uq_addr and
     *     uq_identity_identifier now END IN version_no
     *     (2026_09_04_000100_add_scd2_versioning), so a re-observation does not
     *     collide with the existing row — it inserts a duplicate.
     *   - the UPDATE half overwrote a golden fact in place, which is what this
     *     programme exists to stop, and it rewrote source_link_id, which is
     *     onCreate: it records which source row ESTABLISHED the fact, so treating
     *     it as an attribute would mint a version every time a second account's
     *     employee row re-observed the same licence (thousands of identical
     *     versions on identity 3, which folds 12,463 source rows).
     *
     * So each becomes: build the incoming set into a scratch table, hand it to
     * SetVersionWriter::write(), which compares it against the current version per
     * natural key and flips-and-inserts only what differs. The per-row counterpart
     * (DeterministicResolver::enrich(), 3a Task 6) makes the same decision through
     * Versioner::write().
     *
     * is_verified IS DELIBERATELY ABSENT from the licence statement, where it used
     * to be a literal 0. The per-row path never passes it, so under versioning the
     * literal would reset a verified licence AND mint a version recording the
     * reset, on every bulk run. Omitted, a new row takes the column default (0, the
     * same value) and an existing one keeps what it has.
     *
     * The GROUP BYs are unchanged, and so is the fact that they pick MAX() where the
     * per-row path takes the last observation. That divergence predates SCD-2 and is
     * left alone — see the divergence table in docs/SCD2.md.
     */
    public function enrich(): void
    {
        $hub = $this->hub();
        $writer = new SetVersionWriter;

        // gp_license
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_license');
        $hub->statement(
            'CREATE TEMPORARY TABLE tmp_enrich_license
                (INDEX idx_key (identity_id, license_number, certification_state, certification_board))
             ENGINE=InnoDB AS
             SELECT l.identity_id, spl.license_number, spl.certification_state, spl.certification_board,
                 MAX(spl.license_type) AS license_type, MAX(spl.license_type_id) AS license_type_id,
                 MAX(spl.registry) AS registry, MIN(l.link_id) AS source_link_id
             FROM stg_person_license spl
             JOIN stg_person sp ON sp.stg_person_id = spl.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spl.license_number, spl.certification_state, spl.certification_board'
        );
        $writer->write('gp_license', 'tmp_enrich_license', ['license_type', 'license_type_id', 'registry']);
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_license');

        // gp_address
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_address');
        $hub->statement(
            "CREATE TEMPORARY TABLE tmp_enrich_address
                (INDEX idx_key (identity_id, address1, city, state, zip))
             ENGINE=InnoDB AS
             SELECT l.identity_id, spa.address1, spa.city, spa.state, spa.zip,
                 MAX(spa.address2) AS address2,
                 MAX(spa.address_type='primary') AS is_primary,
                 MIN(l.link_id) AS source_link_id
             FROM stg_person_address spa
             JOIN stg_person sp ON sp.stg_person_id = spa.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spa.address1, spa.city, spa.state, spa.zip"
        );
        $writer->write('gp_address', 'tmp_enrich_address', ['address2', 'is_primary']);
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_address');

        // gp_identity_identifier. Multi-valued identifiers (DEA, MMIS); dedup then
        // merges identities that share one, which is how DEA/MMIS act as match keys.
        //
        // NO compared attributes: Versioner declares none for this table because the
        // key IS the whole fact, so differsOnAll([]) renders FALSE and an existing
        // current row is never superseded by a re-observation. A withdrawn DEA
        // number is recorded by retiring the row (Versioner::retire()), not by
        // re-observing it.
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_identifier');
        $hub->statement(
            'CREATE TEMPORARY TABLE tmp_enrich_identifier
                (INDEX idx_key (identity_id, id_type, id_value))
             ENGINE=InnoDB AS
             SELECT l.identity_id, spi.id_type, spi.id_value, MIN(l.link_id) AS source_link_id
             FROM stg_person_identifier spi
             JOIN stg_person sp ON sp.stg_person_id = spi.stg_person_id
             JOIN gp_source_link l ON l.system_id=sp.system_id AND l.source_table=sp.source_table AND l.source_id=sp.source_id
             GROUP BY l.identity_id, spi.id_type, spi.id_value'
        );
        $writer->write('gp_identity_identifier', 'tmp_enrich_identifier', []);
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_enrich_identifier');
    }
```

- [ ] **Step 4: Version `rollup()`**

Replace `rollup()`:

```php
    /**
     * credential_matches / matches -> gp_identity_credential + gp_identity_exclusion
     * (set-based, from the mirrored src_* transport buffers).
     *
     * Both statements used to end in ON DUPLICATE KEY UPDATE, including
     * identity_id=VALUES(identity_id). Two changes, for two different reasons:
     *
     *   - the upsert becomes a versioned write. Both tables' primary keys now end
     *     in version_no (2026_09_04_000100_add_scd2_versioning), so ON DUPLICATE
     *     KEY no longer matches an existing row at all — and where it still would,
     *     on uq_cred_current, the UPDATE half would overwrite a golden fact in
     *     place. A credential whose status, validity or CAMI currency flag moved is
     *     now superseded; one that came back identical produces nothing, which
     *     matters because sync re-reads every credential of every changed employee
     *     on every run.
     *   - identity_id STOPS BEING REPOINTED HERE. 3a made it onCreate: repointing on
     *     a merge is a GROUPING change, recorded in gp_resolution_log and on the
     *     merged identity's own final version, and versioning it would mint one row
     *     per credential per merge — 397,170 for identity 3 alone.
     *     Engine::applyMerge() owns the repoint, as a bulk UPDATE across all
     *     versions where no unique can collide.
     *
     * link_confidence is in Versioner's attribute list for both tables and is passed
     * by no path, per-row or set-based, so it carries forward. Do not "fix" that by
     * writing a float into it: a DECIMAL(5,4) round-trips as '0.9900' and
     * Versioner::same() would then report a change on every write forever.
     *
     * The status-code exclusion filter is unchanged, and so is where it is applied:
     * mirrorSource() already drops excluded codes at the source so they never cross
     * the wire, and this repeats the screen because src_credential_match can also
     * have been populated by an earlier run under a different config.
     */
    public function rollup(): void
    {
        $hub = $this->hub();
        $sys = $this->systemId;
        $writer = new SetVersionWriter;

        $exclude = config('golden_profile.credential_search.rollup_exclude_status_codes', []);
        $excludeSql = $exclude
            ? 'AND c.match_summary_status_code NOT IN ('.implode(',', array_map('intval', $exclude)).')'
            : '';

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_rollup_credential');
        $hub->statement(
            "CREATE TEMPORARY TABLE tmp_rollup_credential
                (INDEX idx_key (system_id, credential_match_id))
             ENGINE=InnoDB AS
             SELECT ? AS system_id, c.id AS credential_match_id, l.identity_id,
                 c.registry, c.match_summary_status, c.match_summary_status_code,
                 c.match_is_valid, c.`current` AS source_current, c.date_resolved,
                 'confirmed' AS link_state
             FROM src_credential_match c
             JOIN gp_source_link l ON l.system_id=? AND l.source_table='employees' AND l.source_id=c.employee_id
             WHERE 1=1 $excludeSql",
            [$sys, $sys]
        );
        $writer->write('gp_identity_credential', 'tmp_rollup_credential', [
            'registry', 'match_summary_status', 'match_summary_status_code',
            'match_is_valid', 'source_current', 'date_resolved', 'link_state',
        ]);
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_rollup_credential');

        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_rollup_exclusion');
        $hub->statement(
            "CREATE TEMPORARY TABLE tmp_rollup_exclusion
                (INDEX idx_key (system_id, match_id))
             ENGINE=InnoDB AS
             SELECT ? AS system_id, m.id AS match_id, l.identity_id,
                 m.exclusion_record_id, er.exclusion_list_prefix AS registry,
                 m.is_ssn_match, m.is_npi_match, m.is_canonical_name_match,
                 m.is_upin_match, m.is_license_number_match,
                 'candidate' AS link_state
             FROM src_match m
             JOIN gp_source_link l ON l.system_id=? AND l.source_table='employees' AND l.source_id=m.employee_id
             LEFT JOIN src_exclusion_record er ON er.id = m.exclusion_record_id",
            [$sys, $sys]
        );
        $writer->write('gp_identity_exclusion', 'tmp_rollup_exclusion', [
            'exclusion_record_id', 'registry', 'is_ssn_match', 'is_npi_match',
            'is_canonical_name_match', 'is_upin_match', 'is_license_number_match', 'link_state',
        ]);
        $hub->statement('DROP TEMPORARY TABLE IF EXISTS tmp_rollup_exclusion');
    }
```

> **One duplicate-row hazard the scratch tables introduce, and how it is handled.**
> `SetVersionWriter::write()` requires exactly one row per natural key in the incoming table. The
> credential and exclusion scratch builds satisfy that because `credential_match_id` / `match_id` is
> the source's primary key and `gp_source_link`'s `uq_source(system_id, source_table, source_id)`
> means the join to `l` cannot fan out. If a future change makes either fan out, the symptom is a
> duplicate-key error from `uq_cred_current` on the insert — loud, immediate, and the reason 3a built
> that index.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/SetEnrichRollupVersioningTest.php`
Expected: PASS, 6 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 176 tests, 0 skipped.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/SqlBackfill.php \
        tests/Feature/SetEnrichRollupVersioningTest.php
git commit -m "feat(scd2): version the set-based enrich and rollup writes"
```

---

## Task 8: Remove `SetBasedPathGuard`, prove parity, and run the eval gate through both paths

`SetBasedPathGuard`'s own docblock says "DELETING THIS FILE IS PART OF PLAN 3b. Remove the two
assertConverted() calls and this class together with the conversion, in the same commit." The parity
assertion is what earns that, so both land here.

**The eval gate.** SCD-2 is a change to *how facts are stored*, not to *who matches whom*. Every
deterministic tier, every Pass B signal and every threshold is untouched by 3b as it was by 3a. So the
gate must read **precision 1.0000 · recall 1.0000 · F1 1.0000 · 0 false merges · 0 false splits ·
9 true pairs** — identical to the measured baseline in `docs/EVALUATION.md`. This plan **re-baselines
nothing**; the two ratchet assertions stay exactly as they are. And it adds the cheapest parity check
available: the same fixture scored through **both** ladders, asserted to agree.

**If the numbers move, a read filter is wrong, and the direction says which:**

| Movement | What it means |
|---|---|
| `false_merges` > 0 | A tier's identity source is missing `current = 1` and a staged row bound to a superseded version. Task 6, `tierLink` or `nameDobCreateAndLink`. |
| `false_splits` > 0 | A tier's anti-join is missing `current = 1`, so it could not see a corrected key and minted a second identity — or a filter gained `current = 1` but *lost* `status = 'active'`. The two are not interchangeable; see `docs/SCD2.md`. Task 6, `tierCreate`. |
| `true_pairs` < 9 | Something removed fixture records. Never acceptable; `docs/EVALUATION.md` forbids it. |
| the two paths disagree | The set-based and per-row ladders no longer bind the same way. Read the failure message's cluster diff: a ref in a set-based singleton that the per-row path merged points at a missing `enrich()`/`dedup()` step, and the reverse points at a filter. |
| anything else | Stop. Do not adjust the gate. Find the read. |

**Files:**
- Modify: `app/GoldenProfile/Eval/EvalRunner.php`
- Modify: `app/GoldenProfile/Materialize/SetFinalizer.php` (`run()`)
- Modify: `app/GoldenProfile/SqlBackfill.php` (`transform()`)
- Modify: `tests/Support/SetBasedTestCase.php` (the parity snapshot helpers)
- Modify: `docs/SCD2.md`, `docs/EVALUATION.md`
- Delete: `app/GoldenProfile/Support/SetBasedPathGuard.php`
- Delete: `tests/Feature/SetBasedPathGuardTest.php`
- Test: `tests/Feature/EvalGateBothPathsTest.php`
- Test: `tests/Feature/SetBasedParityTest.php`

**Interfaces:**
- Consumes: `Tests\Support\SetBasedTestCase` (Task 1), everything Tasks 3–7 produced.
- Produces:
  - `EvalRunner::stage(EvalSet $set): array` — `ref => stg_person_id`
  - `EvalRunner::runSetBased(EvalSet $set): array{clusters: list<list<string>>, report: array<string,mixed>}`
  - `EvalRunner::run(EvalSet $set): array` — unchanged signature and behaviour
  - `SetBasedTestCase::clusterSnapshot(int $systemId): array`,
    `SetBasedTestCase::versionCensus(): array`, `SetBasedTestCase::profileCensus(): array`
- Removed: `SetBasedPathGuard::assertConverted()`, `SetBasedPathGuard::schemaIsVersioned()`.

### How the two runs are isolated — the mechanic, spelled out

`HubTestCase` runs `migrate:fresh` once per process and wraps each test in a transaction it rolls back.
Neither half of that gives two independent runs inside one test:

- **The transaction is unusable.** Every set-based entry point issues DDL, and MySQL implicitly commits
  on DDL. Task 1 made the index maintenance skip while a transaction is open — which makes the paths
  *safe* under `HubTestCase` but also makes them run in a shape production never uses. A parity test
  must exercise the real shape, so `SetBasedTestCase` gives the transaction up in `setUp()` and
  isolates with `TRUNCATE` in both `setUp()` and `tearDown()`.
- **`TRUNCATE`, not `DELETE`, and that choice does real work.** It resets `AUTO_INCREMENT`, so run A
  and run B over the same fixture mint the **same** `identity_id`s. That is what lets the profile
  comparison be column-by-column instead of a guess at a mapping. `DELETE` leaves the counter where it
  was, run B's ids start above run A's, and every assertion has to be rewritten around a signature
  join.
- **The fixture pins its own `source_id`s.** `HubTestCase::stagePerson()` uses a `static
  $nextSourceId` that advances across calls in a process, so re-staging "the same" rows would produce
  different `source_id`s. The parity fixture therefore passes `source_id` explicitly. `EvalRunner`
  already does this for free — it stages `source_id => crc32($ref)`, which is deterministic — and that
  is what makes the eval-set parity test possible at all.
- **Both runs use the same `system_id`.** `SetBasedTestCase::backfillSystemId()` returns
  `SqlBackfill`'s own, and the per-row run is driven under it too. Not cosmetic:
  `Survivorship::authorityRank()` looks the `system_code` up in
  `config('golden_profile.survivorship.field_authority.identity')`, where `streamline_local` ranks 2
  and `HubTestCase::seedSystem()`'s `uniqid()`-suffixed code falls to `100 - reliability = 50`. Running
  the two paths under different codes would compare two different authority orders.

### What each path is, exactly, and why the two lists differ

The per-row and set-based ladders are not the same statements in a different shape; they divide the
work differently, and a parity test that ignores that compares the wrong things:

| Per-row | Set-based | Why |
|---|---|---|
| `DeterministicResolver::resolve()` per staged row — five tiers **including a `license_registry` tier that reads `gp_license`**, then Pass B | `SqlBackfill::resolveDeterministic()` + `enrich()` + `Engine::dedup()` | The set-based path has **no** licence tier and **no** Pass B. Its own comment says so: licence resolution "is handled after enrich(), by dedup's mergeByLicense — it needs gp_license populated, which enrich() does", and "the probabilistic Pass B is intentionally skipped here". So `enrich()` and `dedup()` are not extras in the set-based run — they are where two of the per-row path's tiers live. |
| `Survivorship::recompute()` + `ProfileMaterializer::rebuild()` per identity (`Engine::finalizeAll()`) | `SetFinalizer::run()` | Same output, different shape. This is the pair the byte-identical invariant is about. |
| `Engine::rollupCredentials()` / `rollupExclusions()` | `SqlBackfill::rollup()` | Not directly comparable: the per-row versions read `credential_matches` from `streamline_local`, which `phpunit.xml` points at a dead socket. Their **write** halves are `Versioner::write()` loops, which Task 7's `test_the_rollup_agrees_with_versioner_row_by_row` compares against directly. |
| Pass B can bind a review-band pair the deterministic ladder missed | nothing equivalent | Contributes nothing on either fixture: the implemented probabilistic weights sum to exactly `auto_merge_at`, so Pass B never auto-merges, and both fixtures are chosen so the deterministic ladder resolves them. If a fixture is ever widened past that, the paths will legitimately diverge — that is plan 5's territory, not a parity bug. |

- [ ] **Step 1: Run the gate and record what it says**

Run: `vendor/bin/phpunit tests/Feature/EvalGateTest.php`
Expected: PASS, 1 test.

Run: `php artisan gp:eval`
Expected: precision 1.0000, recall 1.0000, f1 1.0000, false_merges 0, false_splits 0, true_pairs 9,
records 17, clusters 10 — byte-identical to the "Achieved" table in `docs/EVALUATION.md` and to what
3a Task 10 Step 1 recorded.

If any figure differs, **stop and fix the read path** using the movement table above. Do not continue,
do not edit `EvalGateTest`, and do not touch `min-precision` or `min-recall`.

- [ ] **Step 2: Give `EvalRunner` a set-based mode**

Replace `app/GoldenProfile/Eval/EvalRunner.php`:

```php
<?php

namespace App\GoldenProfile\Eval;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\SqlBackfill;
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
 *
 * TWO MODES, because the ladder is implemented twice. run() drives the per-row
 * resolver (gp:sync's path); runSetBased() drives SqlBackfill (gp:backfill's path).
 * They must score the eval set identically — that is the cheapest parity check the
 * programme has, and EvalGateBothPathsTest asserts it. If they ever diverge, the
 * gate becomes the second line of defence behind SetBasedParityTest rather than the
 * first sign of trouble.
 */
class EvalRunner
{
    public function __construct(private int $systemId) {}

    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /**
     * Stage every record and its licences. Returns ref => stg_person_id.
     *
     * source_id is crc32($ref), which is DETERMINISTIC — the same fixture staged
     * twice produces the same source ids, which is what lets a parity test run both
     * ladders over identical input and compare clusters by ref.
     *
     * @return array<string,int>
     */
    public function stage(EvalSet $set): array
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

        return $stgByRef;
    }

    /**
     * PER-ROW path: DeterministicResolver::resolve() once per staged row. Five
     * deterministic tiers plus Pass B, with enrich() inline, which is why no
     * separate enrich or dedup step appears here.
     *
     * @return array{clusters: list<list<string>>, report: array<string,mixed>}
     */
    public function run(EvalSet $set): array
    {
        $stgByRef = $this->stage($set);
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

    /**
     * SET-BASED path: SqlBackfill's tiers, then enrich(), then Engine::dedup().
     *
     * All three, and none of them optional. The set-based ladder has no licence
     * tier and no Pass B — resolveDeterministic()'s own comment records why:
     * "license resolution is handled after enrich(), by dedup's mergeByLicense — it
     * needs gp_license populated, which enrich() does", and "the probabilistic Pass
     * B is intentionally skipped here". So enrich() and dedup() are not extras
     * bolted on for the test; they are where two of the per-row path's tiers live,
     * and omitting them would compare a four-tier ladder against a five-tier one.
     *
     * $this->systemId must be SqlBackfill's own (SYSTEM_CODE = 'streamline_local'),
     * not a uniqid-suffixed test system: resolveDeterministic() filters
     * stg_person on its own systemId, and Survivorship's authority rank is looked
     * up by system_code.
     *
     * @return array{clusters: list<list<string>>, report: array<string,mixed>}
     */
    public function runSetBased(EvalSet $set): array
    {
        $stgByRef = $this->stage($set);

        $backfill = new SqlBackfill;
        $backfill->indexStaging();
        $backfill->resolveDeterministic();
        $backfill->enrich();
        (new Engine)->dedup();

        // gp_source_link is repointed onto the survivor by a merge, so grouping the
        // links by identity_id gives the post-dedup clustering directly.
        $refBySource = [];
        foreach (array_keys($stgByRef) as $ref) {
            $refBySource[crc32($ref)] = $ref;
        }

        $byIdentity = [];
        foreach ($this->hub()->table('gp_source_link')
            ->where('system_id', $this->systemId)
            ->orderBy('link_id')->get(['identity_id', 'source_id']) as $link) {
            $ref = $refBySource[(int) $link->source_id] ?? null;
            if ($ref !== null) {
                $byIdentity[(int) $link->identity_id][] = $ref;
            }
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

- [ ] **Step 3: Write the failing both-paths gate test**

Create `tests/Feature/EvalGateBothPathsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Eval\EvalRunner;
use App\GoldenProfile\Eval\EvalSet;
use Tests\Support\SetBasedTestCase;

/**
 * The eval set through the SET-BASED ladder, and the two ladders' scores compared.
 *
 * This is the cheapest parity check in the programme: 17 records and 10 truth
 * clusters that already have a measured, ratcheted answer, run through the path
 * plan 3b converted. It is also the second line of defence — SetBasedParityTest
 * compares the two paths structurally, and this compares what a human actually
 * cares about, which is whether they group the same people together.
 *
 * SetBasedTestCase, not HubTestCase: the set-based ladder issues DDL and must run in
 * the shape production uses, and the two runs need TRUNCATE between them. Each test
 * gets a clean schema, so the two runs live in separate tests where they can, and in
 * one test with an explicit wipe where the comparison requires it.
 */
class EvalGateBothPathsTest extends SetBasedTestCase
{
    public function test_the_set_based_ladder_clears_the_same_gate(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $report = (new EvalRunner($this->backfillSystemId()))->runSetBased($set)['report'];

        $message = sprintf(
            'set-based: precision %.4f recall %.4f f1 %.4f — %d false merge(s), %d false split(s)',
            $report['precision'], $report['recall'], $report['f1'],
            $report['false_merges'], $report['false_splits']
        );

        // The same assertions EvalGateTest makes of the per-row path, in the same
        // order and at the same values. Plan 3b re-baselines nothing: it changed how
        // facts are stored, not who matches whom.
        $this->assertGreaterThanOrEqual(9, $report['true_pairs'],
            'the eval set shrank — pairs were removed, not the matcher improved');
        $this->assertSame(0, $report['false_merges'],
            "a tier bound a staged row to a superseded version — $message");
        $this->assertGreaterThanOrEqual(0.99, $report['precision'], $message);
        $this->assertGreaterThanOrEqual(0.80, $report['recall'], $message);
        $this->assertSame(0, $report['false_splits'],
            "a tier anti-join could not see a current version — $message");
        $this->assertSame(1.0, $report['recall'], $message);
    }

    public function test_the_two_ladders_score_the_eval_set_identically(): void
    {
        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $systemId = $this->backfillSystemId();

        $perRow = (new EvalRunner($systemId))->run($set);

        // A full wipe between the runs. The transaction is gone by design (see
        // SetBasedTestCase), so this is the isolation, and TRUNCATE also resets
        // AUTO_INCREMENT so the second run's identity ids start from 1 again.
        $this->wipeHub();
        $this->systemId = $this->seedSystem();
        $setBased = (new EvalRunner($this->backfillSystemId()))->runSetBased($set);

        $this->assertSame(
            $this->canonical($perRow['clusters']),
            $this->canonical($setBased['clusters']),
            'the per-row and set-based ladders grouped the eval set differently'
        );

        foreach (['precision', 'recall', 'f1', 'false_merges', 'false_splits', 'true_pairs'] as $metric) {
            $this->assertSame(
                $perRow['report'][$metric], $setBased['report'][$metric],
                "the two ladders disagree on $metric"
            );
        }
    }

    /** Clusters as a sorted list of sorted ref lists — identity ids and order removed. */
    private function canonical(array $clusters): array
    {
        $out = array_map(function ($cluster) {
            sort($cluster);

            return $cluster;
        }, $clusters);
        sort($out);

        return $out;
    }
}
```

- [ ] **Step 4: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/EvalGateBothPathsTest.php`

Expected: FAIL, 2 of 2 — `Call to undefined method App\GoldenProfile\Eval\EvalRunner::runSetBased()`
before Step 2, and after Step 2 both pass. Run this step BEFORE applying Step 2 if you want to see the
red; if Step 2 is already in, the expected result is PASS, 2 tests, and the meaningful red is Step 6.

- [ ] **Step 5: Add the parity snapshot helpers to the harness**

Append to `tests/Support/SetBasedTestCase.php`:

```php
    /**
     * Clusters as a sorted list of sorted (source_table, source_id) lists.
     *
     * Deliberately identity-id-free. The two paths mint identities in different
     * ORDERS — the per-row path one staged row at a time, the set-based path tier by
     * tier — so even with AUTO_INCREMENT reset the ids are assigned to different
     * clusters. What has to match is WHICH SOURCE ROWS ended up together, which is
     * what a golden profile actually claims.
     *
     * @return list<list<string>>
     */
    protected function clusterSnapshot(int $systemId): array
    {
        $byIdentity = [];

        foreach ($this->hub()->table('gp_source_link')
            ->where('system_id', $systemId)
            ->orderBy('source_table')->orderBy('source_id')
            ->get(['identity_id', 'source_table', 'source_id']) as $link) {
            $byIdentity[(int) $link->identity_id][] = $link->source_table.'#'.$link->source_id;
        }

        $clusters = array_values($byIdentity);
        foreach ($clusters as &$cluster) {
            sort($cluster);
        }
        unset($cluster);
        sort($clusters);

        return $clusters;
    }

    /**
     * How many versions exist per versioned table, and how many are current.
     *
     * The count is the assertion, not the content: if VersionerSql::same() and
     * Versioner::same() disagree on one column type, the two paths mint a different
     * NUMBER of versions from identical input, and that is the failure this census
     * catches. Content parity is asserted separately by profileCensus(), which reads
     * the projection of all of it.
     *
     * @return array<string, array{rows: int, current: int, max_version: int}>
     */
    protected function versionCensus(): array
    {
        $census = [];

        foreach (array_keys(\App\GoldenProfile\Support\Versioner::TABLES) as $table) {
            $row = $this->hub()->selectOne(
                "SELECT COUNT(*) rows_total,
                        SUM(`current` = 1) current_total,
                        COALESCE(MAX(`version_no`), 0) max_version
                 FROM `$table`"
            );

            $census[$table] = [
                'rows' => (int) $row->rows_total,
                'current' => (int) $row->current_total,
                'max_version' => (int) $row->max_version,
            ];
        }

        return $census;
    }

    /**
     * Every gp_identity_profile row, keyed by its cluster signature, with the
     * columns neither path can match removed and the JSON arrays canonicalised.
     *
     * Keyed by cluster signature rather than identity_id for the same reason
     * clusterSnapshot() is: the two paths assign different ids to the same people.
     *
     * The four excluded columns are identity_uuid (UUID() on one path, Str::uuid()
     * on the other) and first_seen / last_updated / profile_built_at (all NOW()).
     * The JSON arrays are compared as MULTISETS because MySQL 8 has no ORDER BY
     * inside JSON_ARRAYAGG and neither path's element order is pinned — see the
     * "byte-identical profile" section of docs/SCD2.md.
     *
     * @return array<string, array<string,mixed>>
     */
    protected function profileCensus(): array
    {
        $volatile = ['identity_id', 'identity_uuid', 'first_seen', 'last_updated', 'profile_built_at'];
        $jsonArrays = [
            'identifiers', 'addresses', 'licenses', 'aliases', 'source_records',
            'accounts', 'credentials', 'exclusions', 'board_actions', 'resolutions',
        ];

        $signatures = [];
        foreach ($this->hub()->table('gp_source_link')
            ->orderBy('source_table')->orderBy('source_id')
            ->get(['identity_id', 'source_table', 'source_id']) as $link) {
            $signatures[(int) $link->identity_id][] = $link->source_table.'#'.$link->source_id;
        }

        $census = [];

        foreach ($this->hub()->table('gp_identity_profile')->get() as $profile) {
            $row = (array) $profile;
            $identityId = (int) $row['identity_id'];

            $signature = $signatures[$identityId] ?? [];
            sort($signature);

            foreach ($volatile as $column) {
                unset($row[$column]);
            }

            foreach ($jsonArrays as $column) {
                $decoded = json_decode((string) ($row[$column] ?? '[]'), true) ?? [];
                $encoded = array_map(fn ($e) => json_encode($e), $decoded);
                sort($encoded);
                $row[$column] = $encoded;
            }

            ksort($row);
            $census[implode('|', $signature)] = $row;
        }

        ksort($census);

        return $census;
    }
```

- [ ] **Step 6: Write the failing end-to-end parity test**

Create `tests/Feature/SetBasedParityTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\SqlBackfill;
use App\GoldenProfile\Support\SetVersionWriter;
use Tests\Support\SetBasedTestCase;

/**
 * The deliverable of plan 3b: one input set, resolved and finalized twice — once
 * per-row, once set-based — producing identical clusters, identical version counts
 * and identical profile rows. This is what earns the right to delete
 * SetBasedPathGuard.
 *
 * HOW THE TWO RUNS ARE ISOLATED. SetBasedTestCase gives up HubTestCase's per-test
 * transaction, because every set-based entry point issues DDL and MySQL implicitly
 * commits on DDL — the transaction would be committed out from under the test
 * mid-run. Isolation is TRUNCATE instead, in setUp, in tearDown, and once between
 * the two runs inside each test here. TRUNCATE rather than DELETE because it resets
 * AUTO_INCREMENT, so both runs mint the same identity_id range and every comparison
 * is about content rather than about mapping ids.
 *
 * THE FIXTURE PINS ITS OWN source_ids. HubTestCase::stagePerson() advances a static
 * counter, so "the same rows" staged twice would not be the same rows. Every stage
 * call below passes source_id explicitly.
 *
 * WHAT THE FIXTURE DELIBERATELY AVOIDS, and why that is not cheating. Two
 * per-row/set-based divergences predate this programme and closing either would
 * change which records match, which this plan asserts it does not do (see the
 * divergence table in docs/SCD2.md): the set-based enrich takes MAX() over a
 * repeated licence's or address's non-key attributes where the per-row path takes
 * the last observation, and the set-based key backfill uses MAX() across every
 * linked row where the per-row path uses the row being resolved. So repeated
 * licences and addresses in this fixture carry IDENTICAL non-key attributes, which
 * is the region where the two are documented to agree. Widening past it is plan 5's
 * job (match keys and data quality), not a parity bug to paper over here.
 */
class SetBasedParityTest extends SetBasedTestCase
{
    /**
     * Eight staged rows: an npi pair, a upin pair, a name+dob pair, one licence-only
     * pair that only dedup's mergeByLicense can join, and one true residual. Plus a
     * licence, an address and a DEA identifier on the npi pair, and two credential
     * rows in the transport buffer.
     *
     * @return list<int> the staged source ids, in fixture order
     */
    private function stageFixture(int $systemId): array
    {
        $rows = [
            // npi pair — resolves on the npi tier on both paths
            ['sid' => 9001, 'first' => 'Robert', 'last' => 'Smith', 'dob' => '1970-04-02',
                'npi' => 1234567893, 'upin' => null, 'lic' => 'L-77'],
            ['sid' => 9002, 'first' => 'Bob', 'last' => 'Smith', 'dob' => null,
                'npi' => 1234567893, 'upin' => null, 'lic' => 'L-77'],
            // upin pair
            ['sid' => 9003, 'first' => 'Grace', 'last' => 'Adeyemi', 'dob' => '1979-05-14',
                'npi' => null, 'upin' => 'U55501', 'lic' => null],
            ['sid' => 9004, 'first' => 'Gracie', 'last' => 'Adeyemi', 'dob' => null,
                'npi' => null, 'upin' => 'U55501', 'lic' => null],
            // name + dob pair
            ['sid' => 9005, 'first' => 'Anna', 'last' => 'Kowalski', 'dob' => '1984-01-09',
                'npi' => null, 'upin' => null, 'lic' => null],
            ['sid' => 9006, 'first' => 'Anna', 'last' => 'Kowalski', 'dob' => '1984-01-09',
                'npi' => null, 'upin' => null, 'lic' => null],
            // licence-only pair: nothing above the residual tier binds these, so the
            // per-row license_registry tier and the set-based dedup->mergeByLicense
            // are the two things that have to agree here.
            ['sid' => 9007, 'first' => 'Chen', 'last' => 'Watanabe', 'dob' => '1966-07-21',
                'npi' => null, 'upin' => null, 'lic' => 'L-901'],
            ['sid' => 9008, 'first' => 'Chien', 'last' => 'Watanabe', 'dob' => null,
                'npi' => null, 'upin' => null, 'lic' => 'L-901'],
            // true residual — no key at all
            ['sid' => 9009, 'first' => 'Bruno', 'last' => 'Kalinowski', 'dob' => null,
                'npi' => null, 'upin' => null, 'lic' => null],
        ];

        foreach ($rows as $i => $r) {
            $stg = $this->stagePerson([
                'system_id' => $systemId, 'source_id' => $r['sid'],
                'first_name' => $r['first'], 'last_name' => $r['last'],
                'date_of_birth' => $r['dob'], 'npi' => $r['npi'], 'upin' => $r['upin'],
                // Distinct source_modified values, ascending in fixture order, so the
                // survivorship recency tiebreak is decided by data rather than by
                // whichever row the server returned first.
                'source_modified' => now()->addMinutes($i)->toDateTimeString(),
            ]);

            if ($r['lic'] !== null) {
                // Identical non-key attributes on both rows of a pair: the region
                // where the MAX()-versus-last-observation divergence cannot bite.
                $this->hub()->table('stg_person_license')->insert([
                    'stg_person_id' => $stg, 'license_number' => $r['lic'],
                    'certification_state' => 'CA', 'certification_board' => 'BRN',
                    'license_type' => 'RN', 'license_type_id' => null, 'registry' => 'CA-BRN',
                    'is_primary' => 1,
                ]);
            }

            if ($r['sid'] === 9001) {
                $this->hub()->table('stg_person_address')->insert([
                    'stg_person_id' => $stg, 'address_type' => 'primary',
                    'address1' => '1 Main St', 'address2' => 'Apt 1',
                    'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701',
                ]);
                $this->hub()->table('stg_person_identifier')->insert([
                    'stg_person_id' => $stg, 'id_type' => 'dea', 'id_value' => 'BX1234563',
                ]);
                $this->hub()->table('stg_person_alias')->insert([
                    'stg_person_id' => $stg, 'alias_type' => 'alt',
                    'first_name' => 'Rob', 'last_name' => 'Smith',
                ]);
            }
        }

        $this->hub()->table('src_credential_match')->insert([
            ['id' => 501, 'employee_id' => 9001, 'registry' => 'CA-BRN',
                'match_summary_status' => 'Verified', 'match_summary_status_code' => 1,
                'match_is_valid' => 1, 'current' => 1, 'date_resolved' => null],
            ['id' => 502, 'employee_id' => 9003, 'registry' => 'CA-BRN',
                'match_summary_status' => 'Expired', 'match_summary_status_code' => 1,
                'match_is_valid' => 0, 'current' => 0, 'date_resolved' => '2026-08-01 10:00:00'],
        ]);

        return array_column($rows, 'sid');
    }

    /** Per-row: resolve each staged row, dedup, then finalize each identity. */
    private function runPerRow(int $systemId): void
    {
        $resolver = new DeterministicResolver($systemId);

        foreach ($this->hub()->table('stg_person')->orderBy('stg_person_id')
            ->pluck('stg_person_id') as $stgPersonId) {
            $resolver->resolve((int) $stgPersonId);
        }

        (new Engine)->dedup();

        // The per-row rollup reads credential_matches from streamline_local, which
        // phpunit.xml points at a dead socket. Its WRITE half is a Versioner::write()
        // per row over exactly this payload (3a Task 8), so it is reproduced here
        // rather than skipped — otherwise the profile's credential_count would
        // differ for a reason that has nothing to do with parity.
        $versioner = new \App\GoldenProfile\Support\Versioner;
        foreach ($this->hub()->table('src_credential_match')->orderBy('id')->get() as $c) {
            $identityId = $this->hub()->table('gp_source_link')
                ->where(['system_id' => $systemId, 'source_table' => 'employees', 'source_id' => $c->employee_id])
                ->value('identity_id');
            if (! $identityId) {
                continue;
            }

            $versioner->write(
                'gp_identity_credential',
                ['system_id' => $systemId, 'credential_match_id' => (int) $c->id],
                [
                    'registry' => $c->registry,
                    'match_summary_status' => $c->match_summary_status,
                    'match_summary_status_code' => $c->match_summary_status_code,
                    'match_is_valid' => $c->match_is_valid,
                    'source_current' => $c->current,
                    'date_resolved' => $c->date_resolved,
                    'link_state' => 'confirmed',
                ],
                [],
                ['identity_id' => (int) $identityId],
            );
        }

        (new Engine)->finalizeAll();
    }

    /** Set-based: tiers, enrich, dedup, rollup, then SetFinalizer. */
    private function runSetBased(): void
    {
        $backfill = new SqlBackfill;
        $backfill->indexStaging();
        $backfill->resolveDeterministic();
        $backfill->enrich();
        (new Engine)->dedup();
        $backfill->rollup();

        (new SetFinalizer)->run();
    }

    public function test_the_two_paths_produce_the_same_clusters(): void
    {
        $systemId = $this->backfillSystemId();
        $this->stageFixture($systemId);
        $this->runPerRow($systemId);
        $perRow = $this->clusterSnapshot($systemId);

        $this->wipeHub();
        $this->systemId = $this->seedSystem();
        $systemId = $this->backfillSystemId();
        $this->stageFixture($systemId);
        $this->runSetBased();
        $setBased = $this->clusterSnapshot($systemId);

        $this->assertSame($perRow, $setBased, 'the two paths grouped the source rows differently');

        // A guard against both paths collapsing everything into one identity, which
        // would make the comparison above vacuously true.
        $this->assertCount(5, $setBased,
            'four resolved pairs plus one residual — if this is 1, something merged everything');
    }

    public function test_the_two_paths_mint_the_same_number_of_versions(): void
    {
        // The sharpest signal that VersionerSql::same() and Versioner::same() agree.
        // If they disagree on any column type in any of the six tables, the counts
        // diverge here and the failure names the table.
        $systemId = $this->backfillSystemId();
        $this->stageFixture($systemId);
        $this->runPerRow($systemId);
        $perRow = $this->versionCensus();

        $this->wipeHub();
        $this->systemId = $this->seedSystem();
        $systemId = $this->backfillSystemId();
        $this->stageFixture($systemId);
        $this->runSetBased();
        $setBased = $this->versionCensus();

        $this->assertSame($perRow, $setBased, 'the two paths minted different versions');

        // And no table may hold two current versions of one natural key. The
        // database enforces this through uq_*_current EXCEPT where a key part is
        // NULL (current_key is CONCAT, which propagates NULL — 3a's deliberate
        // choice), so it is asserted here for the tables whose keys are nullable.
        foreach ($setBased as $table => $counts) {
            $this->assertSame(
                $counts['current'],
                (int) $this->hub()->table($table)->where('current', 1)->count(),
                "$table disagrees with its own census"
            );
        }
    }

    public function test_the_two_paths_produce_the_same_profile_rows(): void
    {
        // The documented invariant, end to end: "rebuild produces a byte-identical
        // profile". Scalars byte-compared, JSON arrays multiset-compared — see
        // docs/SCD2.md for why the JSON half cannot be byte-identical in MySQL 8.
        $systemId = $this->backfillSystemId();
        $this->stageFixture($systemId);
        $this->runPerRow($systemId);
        $perRow = $this->profileCensus();

        $this->wipeHub();
        $this->systemId = $this->seedSystem();
        $systemId = $this->backfillSystemId();
        $this->stageFixture($systemId);
        $this->runSetBased();
        $setBased = $this->profileCensus();

        $this->assertSame(array_keys($perRow), array_keys($setBased),
            'the two paths built profiles for different clusters');
        $this->assertSame($perRow, $setBased,
            'the two paths built different profile rows for the same cluster');
    }

    public function test_a_second_set_based_run_over_unchanged_input_mints_nothing(): void
    {
        // Idempotency is the single property that keeps versioning affordable:
        // Engine::finalizeAll() recomputes every identity, and without it a rebuild
        // adds one gp_identity row per identity — ~13.38M a run on the real hub.
        $systemId = $this->backfillSystemId();
        $this->stageFixture($systemId);
        $this->runSetBased();
        $first = $this->versionCensus();

        $this->runSetBased();

        $this->assertSame(
            $first, $this->versionCensus(),
            'a second set-based run over identical input minted versions'
        );
    }

    public function test_the_writer_and_the_guard_agree_that_the_paths_are_converted(): void
    {
        // A cheap structural check that the conversion is actually complete: no
        // set-based statement may still say ON DUPLICATE KEY UPDATE against a
        // versioned table, and SetBasedPathGuard must be gone.
        $this->assertFalse(
            class_exists(\App\GoldenProfile\Support\SetBasedPathGuard::class),
            'SetBasedPathGuard still exists — its own docblock says plan 3b deletes it'
        );

        foreach ([
            base_path('app/GoldenProfile/SqlBackfill.php'),
            base_path('app/GoldenProfile/Materialize/SetFinalizer.php'),
        ] as $file) {
            $this->assertStringNotContainsString(
                'ON DUPLICATE KEY UPDATE', file_get_contents($file),
                basename($file).' still upserts a versioned table in place'
            );
        }

        // And the writer is reachable, which is what the two classes now depend on.
        $this->assertTrue(method_exists(SetVersionWriter::class, 'write'));
        $this->assertTrue(method_exists(SetVersionWriter::class, 'writeIdentities'));
    }
}
```

- [ ] **Step 7: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/SetBasedParityTest.php`

Expected: FAIL, 2 of 5.
- `test_the_two_paths_produce_the_same_clusters` — PASSES: Tasks 5–7 already aligned the ladders.
- `test_the_two_paths_mint_the_same_number_of_versions` — PASSES for the same reason.
- `test_the_two_paths_produce_the_same_profile_rows` — PASSES, given Task 4.
- `test_a_second_set_based_run_over_unchanged_input_mints_nothing` — FAILS with
  `RuntimeException: SetFinalizer has not been converted to SCD-2 …`, thrown by the guard from
  `SetFinalizer::run()`. So does the first `runSetBased()` in every test above, actually — which is
  the point: **the guard has to come out before any of this can run.** Expect all five to fail with
  that exception until Step 8, then all five to pass.
- `test_the_writer_and_the_guard_agree_that_the_paths_are_converted` — FAILS,
  `SetBasedPathGuard still exists`.

> Read honestly: this test cannot go green in stages. `SetFinalizer::run()` and
> `SqlBackfill::transform()` are the guarded methods, and the parity test drives `run()`. So the red
> is "guard refuses", the fix is Step 8, and the assertions become meaningful in the same commit. That
> is exactly the coupling the guard's docblock describes — "remove the two assertConverted() calls and
> this class together with the conversion, in the same commit".

- [ ] **Step 8: Delete the guard and take the bulk lock instead**

Delete two files:

```bash
git rm app/GoldenProfile/Support/SetBasedPathGuard.php \
       tests/Feature/SetBasedPathGuardTest.php
```

In `app/GoldenProfile/Materialize/SetFinalizer.php`, remove the `use` line for the guard and replace
`run()`:

```php
    /**
     * Whole-hub finalize: survivorship, then materialize.
     *
     * EXCLUSIVE. Both halves are whole-table versioned writes: survivorship builds
     * "the identities whose winners differ from their current version", then flips
     * and inserts. Two concurrent runs would each build that set, each flip, and
     * each insert — and the loser gets a duplicate-key error from
     * uq_identity_current after doing hours of work. So the exclusivity is asserted
     * up front with a named advisory lock rather than discovered at the end.
     *
     * GET_LOCK with a zero timeout: fail immediately and say so, rather than block a
     * cron job behind a run that may take hours. It is session-scoped, released
     * explicitly below and automatically if the connection dies, and — unlike a
     * lock table — it is not DDL, so it does not implicitly commit a caller's
     * transaction.
     *
     * The shardable entry points are Engine::dedup() and Engine::finalizeAll(), not
     * this one. Nothing here is shardable: a chunked materialize is resumable (each
     * chunk is its own autocommitted DELETE+INSERT), which is a different property.
     */
    public function run(?callable $log = null): void
    {
        $lock = $this->hub()->selectOne("SELECT GET_LOCK('gp_scd2_bulk', 0) AS got");

        if ((int) ($lock->got ?? 0) !== 1) {
            throw new \RuntimeException(
                'another set-based bulk run holds gp_scd2_bulk. SetFinalizer::run() and '.
                'SqlBackfill::transform() are whole-hub versioned writes and must not overlap: '.
                'two concurrent runs would each flip the current versions and then collide on '.
                'uq_identity_current. Wait for the other run, or shard with '.
                'Engine::finalizeAll($progress, $shard, $shards) instead.'
            );
        }

        try {
            $log ??= fn ($p, $d) => null;
            $log('finalize', 'survivorship (set-based)');
            $this->survivorship();
            $log('finalize', 'materialize profiles (set-based)');
            $this->materialize();
        } finally {
            $this->hub()->statement("DO RELEASE_LOCK('gp_scd2_bulk')");
        }
    }
```

In `app/GoldenProfile/SqlBackfill.php`, remove the guard's `use` line and replace `transform()`:

```php
    /**
     * Post-staging transform: resolve → enrich → dedup → rollup.
     *
     * SINGLE PROCESS, and now enforced rather than documented. Every step from
     * resolve onwards is a whole-table versioned write, and two concurrent
     * transforms would each compute "what differs from the current version", each
     * flip, and each insert — the loser hitting a duplicate-key error from
     * uq_*_current after hours of work. Same lock as SetFinalizer::run(), because
     * the two must not overlap with each other either: run() calls stage() then
     * transform(), and a finalize running against a half-transformed graph would
     * version identities from an incomplete link set.
     *
     * stage() is deliberately OUTSIDE the lock. It writes only stg_* and src_*,
     * none of which are versioned, and it is the step designed for 16 parallel
     * workers (DEADLOCK_RETRIES was sized for their insert-intention gap-lock
     * deadlocks on stg_person). Locking it would serialise the one phase that
     * benefits from parallelism.
     */
    public function transform(?callable $log = null): void
    {
        $lock = $this->hub()->selectOne("SELECT GET_LOCK('gp_scd2_bulk', 0) AS got");

        if ((int) ($lock->got ?? 0) !== 1) {
            throw new \RuntimeException(
                'another set-based bulk run holds gp_scd2_bulk. SqlBackfill::transform() and '.
                'SetFinalizer::run() are whole-hub versioned writes and must not overlap. '.
                'Staging IS parallel-safe and unlocked — stage every stripe first, then run '.
                'transform() once.'
            );
        }

        try {
            $log ??= fn ($p, $d) => null;
            $this->indexStaging($log);
            $log('resolve', 'deterministic tiers');
            $this->resolveDeterministic($log);
            $log('enrich', 'licenses + addresses + identifiers');
            $this->enrich();
            $log('dedup', 'merge duplicate identities');
            (new Engine)->dedup();
            $log('rollup', 'credentials + exclusions');
            $this->rollup();
        } finally {
            $this->hub()->statement("DO RELEASE_LOCK('gp_scd2_bulk')");
        }
    }
```

> **Why the parity test can still call `SetFinalizer::run()` while `transform()` holds nothing.**
> `SetBasedParityTest::runSetBased()` calls the individual methods (`resolveDeterministic()`,
> `enrich()`, `rollup()`) and then `SetFinalizer::run()`, so exactly one lock is taken and released.
> A test that called `transform()` and then `run()` would work too — the lock is released in the
> `finally` — but it would hide which step failed, which is why the test drives the steps.

- [ ] **Step 9: Record the outcome in `docs/EVALUATION.md`**

Append to `docs/EVALUATION.md`, after the "SCD-2 versioning (plan 3a)" section:

```markdown
## SCD-2 set-based parity (plan 3b) — the gate held, on both paths

| Metric | Per-row (3a) | Per-row (3b) | Set-based (3b) |
|---|---|---|---|
| Precision | 1.0000 | 1.0000 | 1.0000 |
| Recall | 1.0000 | 1.0000 | 1.0000 |
| F1 | 1.0000 | 1.0000 | 1.0000 |
| False merges | 0 | 0 | 0 |
| False splits | 0 | 0 | 0 |
| True pairs | 9 | 9 | 9 |

**The third column is new, and it is the point.** Until plan 3b the eval set had only ever been scored
through `DeterministicResolver` — the per-row ladder `gp:sync` uses. `EvalRunner::runSetBased()` now
scores the same 17 records through `SqlBackfill`'s tiers plus `enrich()` plus `Engine::dedup()`, which
is where the set-based ladder keeps the licence tier the per-row one has inline. The two agree on every
metric and on the clustering itself, asserted by `EvalGateBothPathsTest`.

That agreement is a second line of defence, not the primary one. `SetBasedParityTest` compares the two
paths structurally — same clusters, same version counts per versioned table, same profile rows — over
a fixture built to exercise the residual tier and the licence-only join that the eval set does not.
The gate is what catches a regression cheaply; the parity test is what says why.

The ratchet assertions in `EvalGateTest` are **unchanged** by plan 3b, as they were by 3a. Plan 2,
which deliberately removes the `ssn_hash` tier, is still the one that has to re-baseline them — and
when it does, it must re-baseline `EvalGateBothPathsTest` in the same commit, because that file
asserts the same six numbers against the set-based path.
```

- [ ] **Step 10: Record the set-based register in `docs/SCD2.md`**

Append to `docs/SCD2.md`:

```markdown
## The set-based paths (plan 3b)

`SetFinalizer` and `SqlBackfill` reproduce `Support\Versioner`'s write rule in `INSERT … SELECT`
form. Two classes carry it so the seven write sites cannot drift:

| Class | Job |
|---|---|
| `Support\VersionerSql` | `Versioner::same()` and `Versioner::differs()` as SQL expressions. `same()` casts both sides to `BINARY` so the comparison is byte-exact like PHP `===` rather than case-insensitive like `utf8mb4_unicode_ci`; `LENGTH()`/`LEFT(CAST(x AS BINARY), 10)` keep the date-prefix rule byte-based like `strlen()`/`str_starts_with()`. `VersionerSqlTest` pins it against the PHP pair by pair. |
| `Support\SetVersionWriter` | The set-based twin of `Versioner::write()`. `write()` for the five child tables, `writeIdentities()` for `gp_identity`. Four statements: latest-version snapshot, the set of keys that differ, flip, insert. |

### Which statement versions what

| Statement | Target | Semantics |
|---|---|---|
| `SetFinalizer::survivorship()` | `gp_identity` | One pivoted winner row per identity → `writeIdentities()`. NULL means "no candidate", i.e. carry forward (`differsOnPresent`). `record_count` derived, written in place. `last_updated` stamped only on a version actually written. |
| `SqlBackfill::backfillIdentityKeys()` | `gp_identity` | `IF(i.col IS NULL, k.col, NULL)` proposals → `writeIdentities()`. Same `differsOnPresent` semantics. |
| `SqlBackfill::enrich()` ×3 | `gp_license`, `gp_address`, `gp_identity_identifier` | `differsOnAll` over the attributes the per-row path actually passes. `is_verified` and `source_link_id` carry forward. `gp_identity_identifier` compares nothing, so an existing current row is never superseded. |
| `SqlBackfill::rollup()` ×2 | `gp_identity_credential`, `gp_identity_exclusion` | `differsOnAll`. `identity_id` is onCreate — a merge repoint is a grouping change owned by `Engine::applyMerge()`. `link_confidence` carries forward. |
| `SetFinalizer::materializeRange()` | `gp_identity_profile` | Reads only. Five aggregates plus `$prim` and the outer identity read filter `current = 1`. `$term`/`$ssn4`/`$src`/`$acct`/`$alias` read unversioned tables and take no filter. |

### NULL key parts: the code enforces what the index cannot

`current_key` is `CONCAT(...)`, which propagates NULL, so `uq_*_current` does not constrain a row with
a NULL key part — a deliberate choice, because it reproduces the NULL-permissiveness of the
multi-column uniques it replaced. Every set-based key join therefore uses `<=>`, matching
`Versioner::current()`'s `->where($key)` (Laravel turns a null under `=` into `IS NULL`).

This **fixed a pre-existing defect**: `enrich()`'s old `ON DUPLICATE KEY UPDATE` could not match a
licence with a NULL `certification_state` either, so every run inserted a duplicate. `license_count`
and `address_count` will therefore *fall* for affected identities. That is a correction, and it does
not touch matching — `Engine::mergeByLicense()` groups on the same distinct values either way.

A future plan wanting the guarantee in the schema needs `current_key` built from
`COALESCE(part, CHAR(30 USING utf8mb4))` per part rather than raw `CONCAT`. That is a migration on the
largest tables in the hub and it reverses a deliberate 3a decision, so it is flagged for plan 5 rather
than done here.

### Per-row / set-based divergences that survive plan 3b

All three predate SCD-2 and closing any of them would change which records match, which plan 3b
asserts it does not do.

| Divergence | Per-row | Set-based | Owner |
|---|---|---|---|
| A repeated licence's or address's non-key attributes | last observation wins | `MAX()` over the group | plan 5 |
| Which linked row supplies a backfilled key | the row being resolved | `MAX()` across every linked row | plan 5 |
| "Missing" for a key backfill | PHP `empty()`, so `''` and `'0'` count as missing | `COALESCE`, so only NULL counts | plan 5 |
| Filler-SSN screen on a key backfill | `DeterministicResolver::backfillKeys()` skips blocked hashes | no blocklist check | **plan 2**, which deletes `ssn_hash` from both |

### Exclusivity

`SetFinalizer::run()` and `SqlBackfill::transform()` both take the MySQL advisory lock `gp_scd2_bulk`
with a zero timeout and fail fast if they cannot. Both are whole-hub versioned writes; two concurrent
runs would each flip the current versions and then collide on `uq_*_current` after hours of work.
`SqlBackfill::stage()` is deliberately outside the lock — it writes only `stg_*`/`src_*`, neither
versioned, and it is the phase designed for 16 parallel workers. The shardable entry points remain
`Engine::dedup()` and `Engine::finalizeAll()`.
```

- [ ] **Step 11: Run everything**

Run: `vendor/bin/phpunit tests/Feature/SetBasedParityTest.php tests/Feature/EvalGateBothPathsTest.php`
Expected: PASS, 7 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 180 tests, 0 skipped. (176 after Task 7, minus the 3 deleted
`SetBasedPathGuardTest` tests, plus 5 parity and 2 both-paths tests.)

Run: `vendor/bin/pint --test`
Expected: PASS, no style issues.

Run: `php artisan gp:eval`
Expected: unchanged from Step 1 — precision 1.0000, recall 1.0000, 0 false merges, 0 false splits,
9 true pairs.

Run: `grep -rn "SetBasedPathGuard" app/ tests/ docs/`
Expected: no matches outside `docs/superpowers/plans/`. If `app/` still mentions it, an
`assertConverted()` call survived.

- [ ] **Step 12: Commit**

```bash
vendor/bin/pint --dirty
git add -A app/GoldenProfile/Support/SetBasedPathGuard.php \
           tests/Feature/SetBasedPathGuardTest.php \
           app/GoldenProfile/Eval/EvalRunner.php \
           app/GoldenProfile/Materialize/SetFinalizer.php \
           app/GoldenProfile/SqlBackfill.php \
           tests/Support/SetBasedTestCase.php \
           tests/Feature/SetBasedParityTest.php \
           tests/Feature/EvalGateBothPathsTest.php \
           docs/SCD2.md docs/EVALUATION.md
git commit -m "feat(scd2): prove per-row/set-based parity and remove the bulk-path guard"
```

---

## Self-review

### Spec coverage

| Requirement from the brief | Where |
|---|---|
| Restructure `SetFinalizer::survivorship()` into one all-fields versioned pass — ranked CTE producing the complete winning row, comparison against the current version, two-statement flip-and-insert for only the identities that differ | Task 3. The ranked CTE is `rankedCandidatesSql()`; the complete winning row is the `tmp_surv_winner` pivot; the comparison and the flip-and-insert are `SetVersionWriter::writeIdentities()`, so the same code makes the same decision for `backfillIdentityKeys()`. |
| Understand why the existing method is index-free (commit `9c3f11c`) before restructuring | "Restructuring `SetFinalizer::survivorship()` — why the index-free design survives", with the commit's own measurement quoted, the reason the original argument no longer applies, the *new* reason the conclusion still holds (3a appended `current` to all five indexes, so the flip rewrites an entry in every one), and the explicit decision **not** to drop `uq_identity_current`. |
| Filter `materializeRange()`'s aggregates — five subqueries plus the `$prim`/`$ssn4`/`$term` window sources | Task 4, with a correction: only `$prim` needs it. `$ssn4` and `$term` read `gp_source_link → stg_person`, neither versioned, so `current = 1` there would be a fatal `Unknown column`. Stated with the reason, and the *real* problem with those two — no tiebreak on the per-row side — fixed instead. |
| Acceptance test = the documented byte-identical invariant | Task 4 Step 1 `test_the_two_materialize_paths_agree`, and Task 8 `test_the_two_paths_produce_the_same_profile_rows` end to end. Narrowed honestly: scalars byte-identical, JSON aggregates multiset-identical, four columns excluded, all recorded in `docs/SCD2.md`. Four order-dependent per-row picks fixed so the invariant is true rather than lucky. |
| Plan 2 / plan 3b ordering, and what changes if it flips | "Landing order against plan 2" — recommendation, three ranked reasons, and an edit-by-edit table of what changes in this document if plan 2 lands first. |
| `residualCreateAndLink()` gets its own scratch column | Task 5. `stg_seed_id`, `ALGORITHM=INSTANT`, deliberately unindexed with the cost stated, plus the argument for why a temporary table cannot do this job (no way to capture a batch's generated auto-increment values from the same statement). |
| Version the set-based `enrich()` and `rollup()`; the `ON DUPLICATE KEY` target changes | Task 7, with the per-target table of key / compared / onCreate / carries-forward, and the three consequences spelled out: `is_verified` out of the insert, `source_link_id` no longer rewritten, `identity_id` no longer repointed. |
| Remove `SetBasedPathGuard`, re-run the eval gate, assert parity end to end | Task 8. The guard's deletion and the parity assertion are in the same commit, which is what its docblock demands. |
| Reproducing `Versioner::same()` in SQL, specific per column type | "Reproducing `Versioner::same()` in SQL — the crux": four carried properties (NULL-strict, byte comparison, byte lengths, type rendering), a per-type audit table, the `DECIMAL` trap, where the date-prefix rule actually fires, and the absent-versus-NULL split into `differsOnPresent` / `differsOnAll`. Implemented in `VersionerSql` (Task 2) and pinned differentially against the PHP by `VersionerSqlTest`. |
| The parity proof's two-run isolation, concretely | Task 8's "How the two runs are isolated", and `Tests\Support\SetBasedTestCase` (Task 1). Transaction abandoned because DDL implicitly commits; `TRUNCATE` for isolation *and* to reset `AUTO_INCREMENT`; fixtures pin their own `source_id`s; both runs under `SqlBackfill`'s own `system_id` so the authority rank matches. |
| Reuse plan 2's mechanism for driving `SqlBackfill` in tests | `SetBasedTestCase::backfillSystemId()` is plan 2's `SetBackfillParityTest::backfillSystemId()`, promoted to the harness with its docblock extended to say why the `system_code` matters beyond the id. Task 1 Step 4 notes the one change plan 2's test needs. |
| Concurrency under the 16 parallel staging workers | "Concurrency under the 16 parallel staging workers": `stage()` unchanged and unlocked; `transform()` and `SetFinalizer::run()` take `GET_LOCK('gp_scd2_bulk', 0)`; each flip-and-insert pair inside `transaction($fn, DEADLOCK_RETRIES)` for the concurrent-`gp:sync` case; and why a crash between tables needs no repair step. |
| The eval gate, both directions of movement mapped to a specific missing filter | Task 8's movement table, extended with a fourth row for "the two paths disagree". Gate re-baselines nothing; the two ratchet assertions are untouched. |
| The eval set through both paths, asserted to agree | `EvalRunner::runSetBased()` and `EvalGateBothPathsTest` (Task 8), recorded in `docs/EVALUATION.md` as a three-column table. |
| New migrations only, `2026_09_*` | One migration, `2026_09_04_000200_add_stg_seed_id_to_gp_identity`. Nothing edits a migration that has run. |
| `vendor/bin/pint --dirty` before every commit | Every one of the eight commit steps. |
| Public repo — no keys, no real person's data | Every fixture is synthetic: Robert Smith, Grace Adeyemi, Anna Kowalski, Chen Watanabe, Bruno Kalinowski, Ada Nwosu. The DEA `BX1234563` and NPIs `1234567893` / `1987654328` are check-digit-valid synthetic values (`1987654328` is the corrected form plan 5 Task 2 owns). No credentials anywhere. |

### Deviations from the five-task shape 3a proposed, and why

3a proposed five tasks. This document has eight. Three deviations, each with a concrete reason:

1. **`VersionerSql` + `SetVersionWriter` are their own task (Task 2), split out of 3a's task 1.**
   3a's own framing is that "everything in 3b is set-based SQL that has to reproduce that primitive's
   semantics", and reproducing the semantics is separable from restructuring survivorship: it is
   consumed by four of the other tasks, and it is the only piece that can be pinned *differentially*
   against the PHP it mirrors. Bundling it into the survivorship restructure would have made the
   hardest task in the programme also the widest, and would have left the `same()` comparison
   unverifiable except through its effects.
2. **Task 1 (transaction-safe index maintenance and the `SetBasedTestCase` harness) is new, and it is
   a prerequisite rather than a nicety.** `ALTER TABLE` causes an implicit `COMMIT` in MySQL, and
   `SetFinalizer::survivorship()`, `SqlBackfill::residualCreateAndLink()`,
   `SqlBackfill::indexStaging()` and `SsnHashGuard::buildBlocklistTable()` all issue one. Without
   Task 1, every test in this plan silently commits its fixture into `gp_cami_test` and pollutes the
   rest of the process — and so does plan 2's `SetBackfillParityTest`, which is the cross-plan finding
   recorded in the landing-order section. There was no way to write a verifiable plan without fixing
   this first.
3. **Task 6 (the set-based resolve path) is new, and 3a's list missed it.** `SqlBackfill`'s resolve
   half holds four `gp_identity` reads that match superseded versions and one write —
   `backfillIdentityKeys()` — that overwrites golden facts in place. That write is the set-based twin
   of `DeterministicResolver::backfillKeys()`, which 3a converted; leaving it would mean `gp:backfill`
   overwrote where `gp:sync` versioned, which is the exact divergence the plan exists to close. The
   read filters are worse than the write: a tier anti-join that cannot see a corrected key mints a
   second identity for the same person, which is a **false split** and the eval gate's job to catch.

The remaining five map onto 3a's list one-to-one: Task 3 = its 1, Task 4 = its 2, Task 5 = its 3,
Task 7 = its 4, Task 8 = its 5. 3a asked whether the survivorship restructure needs to be two tasks;
it does not, once `SetVersionWriter` is extracted — the restructure itself is a pivot plus one call.

### Placeholder scan

No placeholders, no TBDs, no "similar to Task N". Every step that changes code shows the code in full.
Three items are deliberately deferred with a named owner rather than left vague:

- The four surviving per-row/set-based divergences, tabled in `docs/SCD2.md` with owners (three to
  plan 5, one to plan 2). Each is a *matching* change, and this plan asserts the gate does not move.
- A `COALESCE`-sentinel `current_key` that would let the database enforce single-current for
  NULL-keyed rows. Flagged for plan 5; it reverses a deliberate 3a decision and is a migration on the
  largest tables in the hub.
- Truly byte-identical JSON aggregates, which need an ordered JSON aggregate MySQL 8 does not have.
  Recorded in `docs/SCD2.md` with the two possible routes and why neither is worth it.

`docs/EVALUATION.md`'s production baseline table stays `PENDING` — it needs hub access nobody in this
environment has, and 3a already owns that placeholder. Nothing in this plan blocks on it.

### Type consistency

New signatures, and every consumer:

- `VersionerSql::same(string, string): string` — used by `differsOnAll`, `differsOnPresent`, and
  directly by `VersionerSqlTest`.
- `VersionerSql::differsOnAll(string, array<string,string>): string` — `SetVersionWriter::write()`.
- `VersionerSql::differsOnPresent(string, array<string,string>): string` —
  `SetVersionWriter::writeIdentities()`.
- `VersionerSql::keysEqual(string, string, list<string>): string` — `SetVersionWriter::write()`, five
  times per call.
- `SetVersionWriter::write(string, string, list<string>): array{new_versions:int}` — `enrich()` ×3,
  `rollup()` ×2. Every caller ignores the return except the tests, which is why it is a one-key array
  rather than an int: `retired` may be added by plan 6/7 without touching a call site.
- `SetVersionWriter::writeIdentities(string, list<string>, array<string,string>): int` —
  `SetFinalizer::survivorship()` (with `['record_count' => 'p.`c`']`) and
  `SqlBackfill::backfillIdentityKeys()` (with `[]`).
- `SetVersionWriter::realColumns(string): list<string>` — internal to both writer methods.
- `EvalRunner::stage(EvalSet): array<string,int>` — `run()`, `runSetBased()`, and no test directly.
- `EvalRunner::runSetBased(EvalSet): array{clusters:list<list<string>>, report:array<string,mixed>}` —
  the same shape `run()` returns, so `EvalGateBothPathsTest` can compare them field by field and
  `MatchScorer::score()` is called identically by both.
- `SetBasedTestCase::{hubTables,wipeHub,backfillSystemId,clusterSnapshot,versionCensus,profileCensus}` —
  used by `BulkPathHarnessTest`, `EvalGateBothPathsTest` and `SetBasedParityTest`.
- Changed: `Versioner::same()` from `private function` to `public static function`; its one internal
  call site becomes `self::same(...)`.
- Removed: `SetBasedPathGuard::assertConverted(string): void` and
  `SetBasedPathGuard::schemaIsVersioned(): bool`, with both call sites.

Unchanged, which is what keeps `Engine`, `GpBackfill`, `GpSync`, `GpRebuildProfile`, `GpEval` and
`GpRebuildAliases` working without edits: `SetFinalizer::run(?callable): void`,
`SetFinalizer::survivorship(): void`, `SetFinalizer::materialize(?callable): void`,
`SqlBackfill::run(array, ?callable): array`, `::stage(?int,?int,int,?callable,string): int`,
`::transform(?callable): void`, `::indexStaging(?callable): void`,
`::resolveDeterministic(?callable): void`, `::enrich(): void`, `::rollup(): void`,
`ProfileMaterializer::rebuild(int): void`, `EvalRunner::run(EvalSet): array`.

### Verified against the repo

- `SetFinalizer::survivorship()` runs nine per-field passes over `IDENTITY_FIELDS` (lines 29–39) with
  three statements each, then one `record_count`/`last_updated` `UPDATE` (128–132), wrapped in
  `dropIdentityKeyIndexes()` / `addIdentityKeyIndexes()` at 87 and 135.
- `materializeRange()`'s subqueries are at 258 (`$lic`), 264 (`$idt`), 271 (`$addr`), 277 (`$prim`),
  282 (`$cred`), 288 (`$excl`), 296 (`$board`), 303 (`$res`, already `is_current = 1`), 309 (`$src`),
  318 (`$acct`), 323 (`$alias`), 332 (`$term`), 337 (`$ssn4`).
- `SqlBackfill::residualCreateAndLink()` writes `s.stg_person_id` into `merged_into`, joins
  `stg_person s ON s.stg_person_id = i.merged_into`, then `UPDATE gp_identity SET merged_into = NULL`.
- The five `ON DUPLICATE KEY UPDATE` statements: three in `enrich()` (all
  `source_link_id=VALUES(source_link_id)`), two in `rollup()` (both starting
  `identity_id=VALUES(identity_id)`). `rollup()` already writes `source_current` after 3a Task 2.
- `uq_lic` is `(identity_id, license_number, certification_state, certification_board)` with
  `certification_state VARCHAR(65) NULL` and `certification_board VARCHAR(10) NULL`; `uq_addr` is
  `(identity_id, address1, city, state, zip)` with four nullable parts. `gp_license.is_verified` is
  `TINYINT DEFAULT 0` and `source_link_id` is NOT NULL.
- `Versioner::TABLES` lists `is_verified` under `gp_license`'s attributes and `link_confidence` under
  both rollup tables', and no path passes any of the three.
- `EvalRunner` stages `source_id => crc32($ref)` — deterministic, which is what makes
  `EvalGateBothPathsTest` possible.
- `HubTestCase::stagePerson()` uses `static $nextSourceId = 1000`, advancing per call in a process.
- `config('golden_profile.survivorship.field_authority.identity')` is
  `['verified', 'nppes', 'streamline_local', 'state_license', 'scraped_license']`, so
  `streamline_local` ranks 2 and an unlisted code falls to `100 - reliability`.
- `SqlBackfill::resolveDeterministic()`'s closing comment states that licence resolution is handled by
  `dedup`'s `mergeByLicense` after `enrich()`, and the class docblock states Pass B is intentionally
  skipped. `DeterministicResolver::matchDeterministic()` has a `license_registry` tier at line 161 and
  falls through to `ProbabilisticResolver` at 75.
- `SsnHashGuard::buildBlocklistTable()` runs `CREATE TABLE IF NOT EXISTS` then `->truncate()`.
- `AliasIndexer::rebuildAll($lo, $hi)` with both bounds takes the `delete()` branch, not
  `rebuildFromStaging()`'s `truncate()` — so `materialize()` does not implicitly commit through it.
- `commit 9c3f11c` is `perf(finalize): index-free survivorship + chunked resumable materialize`, and
  its message says the per-field updates' index maintenance was "the bottleneck (~tens of min per
  field)".

### Known risks carried into execution

1. **`VersionerSql::same()`'s `CAST(… AS BINARY)` makes the set-based path case-sensitive where the
   database is not, and that is deliberate.** It is what makes the two paths agree, but it means a
   source that changes only the *case* of a name now mints a version — where the pre-SCD-2 set-based
   `UPDATE` would have silently overwritten and the ci comparison would have called it no change.
   Expect a one-off wave of versions on the first bulk run after a source starts normalising case. The
   alternative — a ci comparison — freezes the canonical spelling at whatever landed first while the
   per-row path tracks the source, which is strictly worse.
2. **`CHAR` versus `BINARY` trailing spaces.** `upin` is `CHAR(50)` on both `gp_identity` and
   `stg_person`. MySQL strips trailing spaces from `CHAR` on retrieval and plain `CAST(… AS BINARY)`
   (no length) does not re-pad, so the comparison is on the trimmed bytes on both sides. If anyone
   ever writes `CAST(x AS BINARY(50))` in this expression it will pad, and every `upin` will read as
   changed on every run. `VersionerSqlTest`'s trailing-space pair is the tripwire.
3. **`SetBasedTestCase` trades transactional isolation for `TRUNCATE`, and the trade has a sharp
   edge.** A test that dies between wipes leaves the schema dirty for its successor, and the harness
   truncates tables any concurrently running process would be using. `phpunit` is single-process here
   and `wipeHub()` runs in both `setUp` and `tearDown`, so the exposure is a parallel test runner
   nobody has configured. If one is ever added, these tests need their own schema, not a wider guard.
4. **`hubTables()` is a list, not a discovery.** A table added by a later migration will not be wiped,
   and the symptom is a parity test failing because run B saw run A's rows. That is deliberate — a
   discovered list would silently truncate a table a future plan did not expect to lose — but it means
   plans 4, 6 and 7 must add their tables to it. The failure is loud and local, which is the point.
5. **The parity fixture sits inside the region where the two ladders are documented to agree.** Four
   divergences are tabled in `docs/SCD2.md` and the fixture avoids all four (identical non-key
   attributes on repeated licences and addresses, distinct `source_modified` values, no filler SSNs).
   Widening it will surface real disagreements, and they are plan 5's to resolve, not evidence that
   3b's conversion is wrong. Anyone who widens it should expect red and read the divergence table
   first.
6. **`GET_LOCK('gp_scd2_bulk', 0)` is session-scoped, and a killed process releases it — eventually.**
   A `SIGKILL`ed bulk run holds the lock until MySQL notices the connection is gone, which on a
   long-lived pooled connection can be minutes. The error message names the lock so an operator can
   check `SELECT IS_USED_LOCK('gp_scd2_bulk')` and release it deliberately; a `wait_timeout`-based
   guess would be worse. It also does not protect against a run against the same hub from a *different
   MySQL server* — there is only one, so that is theoretical.
7. **`SetVersionWriter::write()` requires exactly one row per natural key in the incoming table, and
   nothing enforces it at the boundary.** Today the five callers satisfy it structurally: three
   `GROUP BY` the whole key, and two join on the source's primary key through
   `gp_source_link.uq_source`, which cannot fan out. A future caller that fans out gets a duplicate-key
   error from `uq_*_current` on the insert — loud, but after the flip has already run in the same
   transaction, so it rolls back cleanly. A cheap `SELECT ... GROUP BY key HAVING COUNT(*) > 1` guard
   inside `write()` would catch it earlier at the cost of a full pass over the incoming set; it was
   left out because the incoming set is the size of the hub.
8. **Row growth on the first post-conversion bulk run is unmeasured.** Every `gp_identity` whose
   canonical winner differs from what the pre-SCD-2 `UPDATE` left behind gets a version, and on a
   13.38M-identity hub that is bounded above by 13.38M new rows — once. 3a's operational signal,
   `SELECT AVG(version_no) FROM gp_identity WHERE current = 1`, is the thing to watch, and
   `scripts/scd2-preflight.sql` should be re-run before the first `gp:backfill` after this lands. No
   production hub access exists in this environment to size it.
9. **`is_verified` now carries forward, which means nothing in the bulk path can ever set it.** It was
   written as a literal `0` by `enrich()`; now a licence's verified state can only be changed by
   something that writes it deliberately. Nothing does today — that is plan 6's steward writer layer
   and plan 7's exclusion lifecycle. Until then `is_verified` is `0` for every licence the bulk path
   created, which is what it was before, so this changes nothing observable. It is recorded because
   "the bulk path can no longer touch this column" is easy to forget.

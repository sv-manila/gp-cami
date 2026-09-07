# Pass B Blocking Widening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Widen Pass B's candidate discovery from the one blocking strategy gp-cami has today
(phonetic-last-name + birth-year) to the several the design set specifies — adding a name+state
leg and a phonetic-name/state/zip leg — without loosening the strict match gate that decides
whether a wider candidate pool actually binds, and without making the `block_size_cap` landmine
any harder to reason about than it already is.

**Architecture:** A new child table, `stg_person_block_key` (one row per person per leg), holds the
two new legs; the existing `stg_person.block_key` column is left untouched as the home of the
name+dob leg it already serves well. `ProbabilisticResolver::match()` unions candidate identities
across all three legs, checking `block_size_cap` **independently per leg** rather than once
globally, so a record oversized on one leg still gets a fair look through another. A new pure
`BlockKeyBuilder` class computes the two new legs' values, shared by the per-row connector, the
set-based bulk stager, and the eval-set stager — one computation, three staging call sites, the
same pattern `personRow()` already established for the name+dob leg. Nothing about the strict name
gate (`NameMatcher::compatible()`) or the hard-no safeguards changes: wider blocking can only
enlarge the candidate *pool* a record is compared against, never the rule that decides whether a
comparison succeeds.

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
- This plan's own migration is `2026_09_06_000000_create_stg_person_block_key` — dated after plan 5's `2026_09_05_*`
  migrations. If plan 5 has not merged first, run its migrations before this plan's; if the dates
  ever collide, this plan's migration must sort after plan 5's.

---

## Programme context — this is plan 5b of 8 (+1)

| # | Plan | Depends on | Status |
|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — | **DONE, merged into this branch** |
| 2 | SSN removal | 1 | to write |
| 3 | SCD-2 versioning | 1 | to write |
| 4 | Individual vs entity | 1, 3 | to write |
| 5 | Match keys & data quality | 1 | to write |
| **5b** | **Pass B blocking widening** | **1, 5** | **this document** |
| 6 | Steward writer layer | 1, 3 | to write |
| 7 | Exclusion lifecycle | 1, 3 | to write |
| 8 | Incremental profiling | 1, 5 | to write |

Plan 5's own Self-review recommended splitting this work out rather than squeezing it into an
already-10-task plan: multi-leg blocking is "a genuine schema/architecture change, not a wiring
change," and it "directly interacts with the `block_size_cap` silent-split landmine" plan 5 was
told not to make worse without naming. This plan is that split.

**What this plan assumes plan 5 has already landed**, because it builds directly on plan 5's final
shapes rather than the file states verified in the shared authoring brief:

- `StreamlineLocalConnector::personRow()` rejects a non-Luhn-valid NPI (plan 5 Task 3) — irrelevant
  to blocking directly, but confirms `personRow()` is still the one shared per-row/bulk choke point
  this plan follows the same pattern for.
- `StreamlineLocalConnector::rebuildChildren(int $stgId, object $emp, bool $isNew = false, ?array
  $children = null)` — plan 5 Task 7 added the `$isNew`/`$children` parameters and Task 8 extended
  the method to also stage `stg_person_identifier` rows (fixing the per-row path's pre-plan-5 gap
  where DEA/MMIS identifiers were staged only by the bulk backfill, never by `gp:sync`/
  `Engine::backfill()`). This plan's Task 3 adds one more child table to that same method, in the
  same shape.
- `StreamlineLocalConnector::additionalRows(iterable $aiRows, ?string $state = null)` and
  `ingest(): ?int` (nullable — plan 5 Task 7's quarantine gate) exist. This plan does not call
  either directly, but Task 3's edits sit inside the same method bodies plan 5 already changed, so
  its line-number references are approximate and keyed to method names, not exact lines — verify
  against the method name if a line has drifted.
- `tests/eval/identity-pairs.json` carries plan 5's corrected NPIs (Task 2) and its `mmis-*`/`dea-*`
  additions (Task 10), with `true_pairs` ratcheted to **11**. This plan's Task 6 builds on that
  number; if plan 5 lands with a different final count, substitute it — the fixture records this
  plan adds are singletons that do not change the count either way (see Task 6).

This plan depends on plan 1 (harness) and plan 5 (the shapes above). It does not depend on plans 2,
3, 4, 6, 7 or 8, and nothing in this plan touches SSN, SCD-2, entity/individual, or the steward
writer layer.

---

## Design decisions — read before Task 1

The task brief for this plan posed four design questions. Answering them up front, once, avoids
re-litigating them inside individual tasks.

### 1. Multi-leg blocking: one column, or a child table?

**A child table for the two new legs, added alongside the existing column rather than replacing
it.** `stg_person.block_key` stays exactly as it is — same column, same `idx_block` index, same
reader in `ProbabilisticResolver::match()` for that one leg — and a new `stg_person_block_key`
table (`stg_person_id`, `block_type`, `block_key`) holds `name_state` and `name_state_zip`.

This is a hybrid, not a full unification, and the reason is risk, not aesthetics. `block_key`'s
name+dob leg is the one part of Pass B blocking that is already correct, already tested, and
already load-bearing for the eval gate's 1.0 recall. Migrating it into the child table too would
mean rewriting a query path nothing in this plan needs to touch, for a purely cosmetic gain in
schema uniformity, with a real chance of a subtle regression (index-name churn, `ORDER BY`
tie-break drift, a missed call site) landing in code that today works. Keeping it as-is and adding
the new legs beside it is strictly additive: no migration touches an already-migrated table, no
existing query changes shape, and the blast radius of a mistake is confined to code this plan
writes fresh.

The child table's shape (`stg_person_id`, `block_type`, `block_key`) is deliberately the same shape
plan 8's own stated goal implies for its `block_key → {identity_id}` inverted index — plan 8 has
not been written yet (`docs/superpowers/plans/2026-09-03-gpp-conformance-incremental-profiling.md`
does not exist as of this writing), so this plan cannot read it, but it can leave a symmetrical
seam: plan 8's identity-side index is naturally `gp_identity_block_key(identity_id, block_type,
block_key)`, rolled up from this table's rows via `gp_source_link` the same way
`ProbabilisticResolver::candidateIdentityIds()` (Task 5) already joins them at query time. Plan 8
would need to read the name+dob leg from `stg_person.block_key` and the other two legs from this
table — two sources, not one — which is an honest cost of this plan's hybrid choice; it is stated
here rather than discovered later. `block_type` is a plain `VARCHAR(20)`, not an `ENUM`, specifically
so a future leg (plan 8's or anyone else's) never needs a `MODIFY COLUMN` migration to add a value.

### 2. Super-blocks: in scope, or not?

**Not in scope, and not approximated by anything in this plan.** The appendix's super-block step —
merge every block that shares a member into one connected-component "super-block," then profile
each super-block once — is a **batch** construct: it needs the whole block graph materialized before
any profiling starts, so that "does block A overlap block B" is answerable before either is
processed. gp-cami's per-row resolver (`DeterministicResolver::resolve()` → `ProbabilisticResolver
::match()`) has no equivalent execution model: it resolves one staged row against the identities
that already exist, one row at a time, as rows arrive from `gp:sync`. There is no point at which
"the whole block graph" exists to merge.

More importantly, gp-cami's other execution model — `SqlBackfill`, the set-based bulk path — is not
a candidate for super-blocks either, and not because of missing time: its own docblock states
outright that **"the probabilistic Pass B is intentionally skipped here"** for this single source
(Pass A resolves every non-distinct record deterministically; anything Pass B would have caught
becomes the residual "new identity" step's job instead). `SqlBackfill` never calls
`ProbabilisticResolver`. So the one batch code path that exists never performs blocked probabilistic
matching at all — there is no caller for a super-block step to serve, not just no convenient place
to add one. Building it anyway would be constructing infrastructure with zero callers, which is
exactly the kind of unmeasured, undriven work this programme's plans are asked to avoid.

What this plan does instead, and why it is not a lesser version of the same idea: querying three
legs and taking their **union** at match time (Task 5) gives each incoming record the same
practical membership a super-block would have given it — "compared against everyone reachable by
any leg" — without a materialization step, because the resolver only ever needs that answer for
one row at a time, which a union of three indexed lookups answers directly. It is a narrower
mechanism that happens to satisfy the one requirement (don't miss a match because only one leg
caught it) that motivates super-blocks in a batch system, not a stand-in for merging blocks that
overlap for records this plan is not currently resolving.

### 3. Candidate-set growth

Before this plan: `match()` issues one `COUNT` (cap check), one candidate-identity query (skipped
entirely if capped), and `ceil(N/1000)` `whereIn` batches to hydrate the `N` candidate identities
found. After this plan: up to three `COUNT`s (one per populated leg) and up to three candidate
queries — but the hydration step is **unchanged in shape**, because all three legs' candidate
identity IDs are merged and de-duplicated (`->unique()`) *before* the chunk-1000 hydration loop
runs. Overlap between legs (the common case — the same true match is often reachable by more than
one leg) costs nothing extra at the hydration stage; it only ever reduces or holds constant the
final identity count compared to widening the field with zero overlap.

The real cost is on the query-count side: at most 2 more `COUNT`s and 2 more `SELECT`s per resolved
row than before. Both are cheap and indexed (`(block_type, block_key)` is a composite index created
in the same migration that creates the table, not deferred — see Task 1's note on why, unlike the
bulk-only `stg_ssn`/`stg_npi`/`stg_namedob` indexes `SqlBackfill::indexStaging()` adds after the
fact, this one must exist from row one because the per-row path depends on it every time, not only
after a bulk backfill has run). This plan does not attempt to fold the three lookups into one `UNION`
statement — three separate, readable Eloquent queries are kept for this plan's scope, at the cost of
those 2-4 extra round trips per Pass-B-reached row; combining them is a reasonable follow-up if
`gp:sync` throughput measurements ever show it matters, not a decision to make blind here.

What this plan does **not** change: Pass A's `matchDeterministic()` never reaches blocking at all
(it queries `gp_identity` directly by key), and the great majority of `streamline_local` rows
resolve there — blocking only executes for the minority that miss every Pass A tier. The added
query cost lands only on that minority.

### 4. Recall vs. precision: does wider blocking risk a merge the current gate would reject?

**No — verified, not assumed.** `NameMatcher::compatible()` requires `first_name` and `last_name` to
be **exactly** equal (case/whitespace-normalized, not merely soundex-equal) before anything else is
checked, and `hardNo()` blocks on a conflicting birth **year** regardless of any other similarity.
Both gates read only from `$p` (the incoming record) and `$identity` (the candidate) — neither reads
which blocking leg produced the candidate. A wider candidate pool can only ever hand `compatible()`/
`hardNo()` **more pairs to evaluate**, never a looser rule to evaluate them by. Two people who share
a `name_state_zip` block by having the *same exact* last name (not merely a soundex collision) are
still rejected the instant their first names differ or their birth years conflict — proven directly
in this plan's Task 5 (`test_a_shared_widened_block_does_not_merge_a_different_person`) and exercised
again, at the standard eval-gate scale, in Task 6.

The one place recall genuinely could regress is the opposite direction: this plan does not raise
recall on its own, because of a ceiling this plan discovered while trying to build a fixture case
for it — see Task 5 and 6's introductions for the arithmetic (missing-DOB records cannot reach the
review band under today's implemented weights, wide blocking or not). Recall floors in
`EvalGateTest` are unaffected either way; Task 6 proves that by re-running the gate.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_06_000000_create_stg_person_block_key.php` *(create)* | New child table for the `name_state` / `name_state_zip` legs |
| `app/GoldenProfile/Support/BlockKeyBuilder.php` *(create)* | Pure computation of the two new legs' values |
| `tests/Unit/BlockKeyBuilderTest.php` *(create)* | Algorithm correctness, no DB |
| `app/GoldenProfile/Connectors/StreamlineLocalConnector.php` *(modify)* | `blockKeyRows()`; `rebuildChildren()` stages the new legs too |
| `tests/Unit/StreamlineLocalConnectorTest.php` *(modify)* | `blockKeyRows()` pure-function tests |
| `app/GoldenProfile/SqlBackfill.php` *(modify)* | Bulk `stage()` also stages the new legs, via the same `blockKeyRows()` |
| `tests/Support/HubTestCase.php` *(modify)* | `stagePerson()` also stages the new legs, so every test can exercise them |
| `app/GoldenProfile/Eval/EvalRunner.php` *(modify)* | Eval-fixture staging also populates the new legs |
| `app/GoldenProfile/Resolution/ProbabilisticResolver.php` *(modify)* | `candidateIdentityIds()` unions all three legs, cap enforced per leg; near-miss/decline logging |
| `config/golden_profile.php` *(modify)* | Comment update: `block_size_cap` is now enforced per leg |
| `tests/Feature/BlockingLegsTest.php` *(create)* | The three behaviours this plan exists to prove — see Task 5 |
| `tests/eval/identity-pairs.json` *(modify)* | Two singleton records proving no false merge from a shared widened block |
| `docs/EVALUATION.md` *(modify)* | Record the measured (unchanged) gate numbers and why `true_pairs` is not raised |

---

## Task 1: `stg_person_block_key` — the child table for the new legs

**Files:**
- Create: `database/migrations/2026_09_06_000000_create_stg_person_block_key.php`

**Interfaces:**
- Produces: table `stg_person_block_key(stg_person_id, block_type, block_key)`, indexed on
  `stg_person_id` (rebuild-time deletes) and on `(block_type, block_key)` (match-time lookups) —
  Tasks 3 and 5 depend on both indexes existing from the start.

Unlike the deferred indexes `SqlBackfill::indexStaging()` adds to `stg_person` after a bulk load
(`stg_ssn`, `stg_npi`, `stg_upin`, `stg_dea`, `stg_namedob` — added post-hoc because they serve only
`SqlBackfill`'s own SQL tier statements, which run once, after staging, not on every row), this
table's `(block_type, block_key)` index is created **in this migration, up front**. The reason is
which code path depends on it: `stg_ssn` etc. exist only for `SqlBackfill::resolveDeterministic()`'s
bulk `INSERT...SELECT` tiers, which never run outside a `gp:backfill` invocation. This table's
lookup index is read by `ProbabilisticResolver::candidateIdentityIds()` (Task 5) on **every**
per-row `gp:sync`/`Engine::backfill()` resolution that reaches Pass B — a hub that has never run
`gp:backfill` still needs this index from the first `gp:sync` row onward. `stg_person.block_key`'s
own `idx_block` was created the same way, in the original schema migration, for the identical
reason — this plan follows that precedent rather than the deferred-index one.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name+dob leg (stg_person.block_key / idx_block) stays exactly as it is —
 * this table holds the two ADDITIONAL blocking legs the GPP design set asks
 * for (name+state, phonetic-name/state/zip) rather than replacing it. See
 * "Design decisions" §1 in this plan for why a hybrid of one column plus one
 * child table was chosen over migrating everything into one shape.
 *
 * block_type is a plain VARCHAR, not an ENUM, so a future leg never needs a
 * MODIFY COLUMN migration to add a value — only an INSERT.
 *
 * The (block_type, block_key) index is created here, not deferred the way
 * SqlBackfill::indexStaging() defers stg_person's own bulk-only indexes: this
 * table's lookup index is read by ProbabilisticResolver on every per-row
 * gp:sync resolution that reaches Pass B, not only after a bulk gp:backfill
 * has run. See this plan's Task 1 for the full reasoning.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        Schema::connection($this->connection)->create('stg_person_block_key', function (Blueprint $t) {
            $t->unsignedBigInteger('stg_person_id');
            $t->string('block_type', 20);
            $t->string('block_key', 64);
            $t->index('stg_person_id');
            $t->index(['block_type', 'block_key'], 'idx_block_type_key');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('stg_person_block_key');
    }
};
```

- [ ] **Step 2: Verify it runs**

Run: `php artisan migrate --database=golden_profile --path=database/migrations
--pretend` (or, inside any test extending `HubTestCase`, migrations already run as part of
`migrate:fresh` in `setUp()` — confirmed by Task 2's tests passing, since `BlockKeyBuilderTest` does
not need the DB but every later `HubTestCase`-based test in this plan does).

Expected: no error; `stg_person_block_key` appears in `gp_cami_test` with the two indexes.

- [ ] **Step 3: Commit**
```bash
git add database/migrations/2026_09_06_000000_create_stg_person_block_key.php
git commit -m "feat(gp): add stg_person_block_key for the name_state/name_state_zip blocking legs"
```

---

## Task 2: `BlockKeyBuilder` — pure computation of the two new legs

**Files:**
- Create: `app/GoldenProfile/Support/BlockKeyBuilder.php`
- Create: `tests/Unit/BlockKeyBuilderTest.php`

**Interfaces:**
- Produces: `BlockKeyBuilder::nameState(?string $last, ?string $state): ?string`,
  `BlockKeyBuilder::nameStateZip(?string $last, ?string $state, ?string $zip): ?string` — Tasks 3,
  4, and 5 all call these; it is the single place either value is computed, exactly the role
  `personRow()`'s private `blockKey()` plays for the name+dob leg.

Both legs use the **phonetic last name only**, not the full name — consistent with the existing
name+dob leg (`soundex($last)`) and with the wiki's own worked example ("everyone with the same
phonetic last name in the same state and ZIP code"). This plan does not consolidate the existing
`blockKey()` (duplicated today across `StreamlineLocalConnector`, `EvalRunner`, and
`HubTestCase`, each carrying an identical "Same rule as..." comment) into this class — that
pre-existing duplication is a real, minor debt, but touching three already-correct call sites for a
name+dob leg this plan does not otherwise change is exactly the kind of blast-radius-for-no-reason
this plan's Design Decision §1 argues against for the same leg. `BlockKeyBuilder` is written so the
two NEW legs never repeat that duplication in the first place: `EvalRunner` and `HubTestCase`
(Task 4) call this class directly rather than re-implementing the phonetic-key logic a third time
each.

A leg is only ever computed when every field it needs is non-null and non-empty: a null-state
`name_state` bucket ("everyone named Whitmore, no state on file") would be exactly the kind of
unbounded bucket the `block_size_cap` landmine already punishes for missing DOB — this plan does
not introduce a second version of that problem by giving null-state records a nationwide bucket to
fall into. A record missing the leg's inputs simply does not get a row for that leg; it still gets
whichever other legs it has real values for.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\BlockKeyBuilder;
use Tests\TestCase;

/**
 * Pure functions, no DB — mirrors the existing (undertested) blockKey()
 * pattern but for the two new legs this plan adds. soundex() itself is
 * PHP's own function and is not re-verified here; these tests pin the
 * SHAPE of the combined key (soundex|STATE, soundex|STATE|ZIP5) and the
 * null-propagation rule (missing any required input means no key at all,
 * never a partial/degenerate one).
 */
class BlockKeyBuilderTest extends TestCase
{
    public function test_name_state_combines_soundex_and_uppercased_state(): void
    {
        $this->assertSame(soundex('Whitmore').'|OH', BlockKeyBuilder::nameState('Whitmore', 'oh'));
    }

    public function test_name_state_is_null_without_a_last_name(): void
    {
        $this->assertNull(BlockKeyBuilder::nameState(null, 'OH'));
        $this->assertNull(BlockKeyBuilder::nameState('', 'OH'));
    }

    public function test_name_state_is_null_without_a_state(): void
    {
        // A null-state bucket would be an unbounded "everyone with this last
        // name, nationally" pile-up — exactly the block_size_cap problem this
        // plan does not want a second copy of. See this task's docblock.
        $this->assertNull(BlockKeyBuilder::nameState('Whitmore', null));
        $this->assertNull(BlockKeyBuilder::nameState('Whitmore', ''));
    }

    public function test_name_state_zip_combines_all_three_and_truncates_zip_to_five(): void
    {
        $this->assertSame(
            soundex('Whitmore').'|OH|43215',
            BlockKeyBuilder::nameStateZip('Whitmore', 'oh', '43215-6789')
        );
    }

    public function test_name_state_zip_is_null_without_any_required_field(): void
    {
        $this->assertNull(BlockKeyBuilder::nameStateZip(null, 'OH', '43215'));
        $this->assertNull(BlockKeyBuilder::nameStateZip('Whitmore', null, '43215'));
        $this->assertNull(BlockKeyBuilder::nameStateZip('Whitmore', 'OH', null));
    }

    public function test_leading_and_trailing_whitespace_is_trimmed(): void
    {
        $this->assertSame(soundex('Whitmore').'|OH', BlockKeyBuilder::nameState('  Whitmore  ', ' OH '));
    }

    public function test_soundex_collision_on_different_spelling_still_yields_the_same_bucket(): void
    {
        // This is the whole point of the leg -- it groups by SOUND, not
        // spelling. Whether two such records actually merge is a separate
        // question decided by NameMatcher::compatible(), never by this class.
        $this->assertSame(
            BlockKeyBuilder::nameState('Whitmore', 'OH'),
            BlockKeyBuilder::nameState('Whitmoor', 'OH')
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/BlockKeyBuilderTest.php`
Expected: FAIL with `Class "App\GoldenProfile\Support\BlockKeyBuilder" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\GoldenProfile\Support;

/**
 * Computes the two blocking legs the GPP design set asks for beyond the
 * name+dob leg gp-cami already has (StreamlineLocalConnector's private
 * blockKey(), unchanged by this plan — see this plan's "Design decisions"
 * §1). Both new legs use the phonetic LAST NAME only, consistent with the
 * existing leg and with the wiki's own worked example ("everyone with the
 * same phonetic last name in the same state and ZIP code").
 *
 * Every leg here is all-or-nothing: if any required field is missing, the
 * leg returns null rather than degrading to a narrower key. A null-state
 * "name only" bucket would be an unbounded, nationwide pile-up -- exactly
 * the shape of bucket that already makes the null-DOB "D500|____" block
 * hold ~107k people. This class refuses to manufacture a second version of
 * that problem; a record missing a leg's inputs simply does not get a row
 * for that leg (see StreamlineLocalConnector::blockKeyRows(), Task 3).
 */
class BlockKeyBuilder
{
    public static function nameState(?string $last, ?string $state): ?string
    {
        $last = self::clean($last);
        $state = self::clean($state);
        if ($last === null || $state === null) {
            return null;
        }

        return soundex($last).'|'.strtoupper($state);
    }

    public static function nameStateZip(?string $last, ?string $state, ?string $zip): ?string
    {
        $last = self::clean($last);
        $state = self::clean($state);
        $zip = self::clean($zip);
        if ($last === null || $state === null || $zip === null) {
            return null;
        }

        // First 5 digits only: stg_person.zip carries zip+4 in places
        // ("43215-6789"), and the extra 4 digits are far too granular to
        // block on (they would fragment one neighborhood's worth of people
        // into dozens of one-member buckets, defeating the point of a block).
        return soundex($last).'|'.strtoupper($state).'|'.substr($zip, 0, 5);
    }

    private static function clean(?string $v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === '' || $v === null) ? null : $v;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/BlockKeyBuilderTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**
```bash
git add app/GoldenProfile/Support/BlockKeyBuilder.php tests/Unit/BlockKeyBuilderTest.php
git commit -m "feat(gp): add BlockKeyBuilder for the name_state/name_state_zip blocking legs"
```

---

## Task 3: Stage the new legs — per-row connector and bulk backfill

**Files:**
- Modify: `app/GoldenProfile/Connectors/StreamlineLocalConnector.php` (`rebuildChildren()`; new
  `blockKeyRows()`)
- Modify: `tests/Unit/StreamlineLocalConnectorTest.php` (add tests)
- Modify: `app/GoldenProfile/SqlBackfill.php` (`stage()`)

**Interfaces:**
- Consumes: `BlockKeyBuilder::nameState()`/`nameStateZip()` (Task 2).
- Produces: `StreamlineLocalConnector::blockKeyRows(object $emp): array` — a pure function
  (`['block_type' => ..., 'block_key' => ...]` rows, only for legs with a real value), called by
  both `rebuildChildren()` (per-row) and `SqlBackfill::stage()` (bulk). This is the same "one shared
  method, two callers" pattern `personRow()` already established for the name+dob leg and plan 5's
  Task 3/8 relied on for NPI validation and identifier staging — the reason it is trustworthy here
  is the same reason it was trustworthy there: there is exactly one place the computation happens,
  so there is nothing for the two paths to disagree about.

`blockKeyRows()` is deliberately **not** called from `personRow()` itself: `personRow()` returns
the single `stg_person` row (an `UPDATE`/`INSERT` target), while block keys are child rows against a
`stg_person_id` that does not exist until that row is inserted — exactly the same reason
`additionalRows()`'s identifier rows are attached in `rebuildChildren()`/`stage()`'s chunk loop
rather than inside `personRow()`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/StreamlineLocalConnectorTest.php`:

```php
    public function test_block_key_rows_includes_name_state_and_name_state_zip_when_both_present(): void
    {
        $rows = $this->connector()->blockKeyRows($this->employee(['last_name' => 'Whitmore', 'state' => 'OH', 'zip' => '43215']));

        $byType = collect($rows)->keyBy('block_type');
        $this->assertSame(soundex('Whitmore').'|OH', $byType['name_state']['block_key']);
        $this->assertSame(soundex('Whitmore').'|OH|43215', $byType['name_state_zip']['block_key']);
    }

    public function test_block_key_rows_omits_name_state_zip_without_a_zip(): void
    {
        $rows = $this->connector()->blockKeyRows($this->employee(['last_name' => 'Whitmore', 'state' => 'OH', 'zip' => null]));

        $byType = collect($rows)->keyBy('block_type');
        $this->assertArrayHasKey('name_state', $byType);
        $this->assertArrayNotHasKey('name_state_zip', $byType);
    }

    public function test_block_key_rows_is_empty_without_a_state(): void
    {
        $rows = $this->connector()->blockKeyRows($this->employee(['last_name' => 'Whitmore', 'state' => null, 'zip' => '43215']));

        $this->assertSame([], $rows);
    }
```

(`employee()` is the fixture helper plan 5's Task 3 already added to this file — it defaults
`state`/`zip` to `null`, so each test above only needs to override what it changes.)

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/StreamlineLocalConnectorTest.php --filter=test_block_key_rows_includes_name_state_and_name_state_zip_when_both_present`
Expected: FAIL — `Call to undefined method ...::blockKeyRows()`

- [ ] **Step 3: Add `blockKeyRows()` to `StreamlineLocalConnector`**

Add the import near the top of `app/GoldenProfile/Connectors/StreamlineLocalConnector.php`:

```php
use App\GoldenProfile\Support\BlockKeyBuilder;
```

Add the new public method, next to the existing private `blockKey()`:

```php
    /**
     * The two blocking legs this plan adds beyond the existing name+dob leg
     * (blockKey(), below, unchanged). Pure: no DB, no stg_person_id -- the
     * caller attaches that once the row exists (see rebuildChildren() and
     * SqlBackfill::stage(), the two places this is called from). Only legs
     * with every required field present are returned; see BlockKeyBuilder
     * for why a partial leg is refused rather than degraded.
     *
     * @return list<array{block_type:string,block_key:string}>
     */
    public function blockKeyRows(object $emp): array
    {
        $rows = [];
        if ($v = BlockKeyBuilder::nameState($emp->last_name ?? null, $emp->state ?? null)) {
            $rows[] = ['block_type' => 'name_state', 'block_key' => $v];
        }
        if ($v = BlockKeyBuilder::nameStateZip($emp->last_name ?? null, $emp->state ?? null, $emp->zip ?? null)) {
            $rows[] = ['block_type' => 'name_state_zip', 'block_key' => $v];
        }

        return $rows;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/StreamlineLocalConnectorTest.php`
Expected: PASS (all tests in the file, including plan 5's)

- [ ] **Step 5: Wire it into `rebuildChildren()` (per-row path)**

In `StreamlineLocalConnector::rebuildChildren()` (the shape plan 5's Task 7/8 leave it in — see
this plan's Programme context for the assumed final signature), add the delete for the new table
alongside the existing four, and add the new bucket to the insert loop:

```php
    private function rebuildChildren(int $stgId, object $emp, bool $isNew = false, ?array $children = null): void
    {
        $hub = $this->hub();
        if (! $isNew) {
            $hub->table('stg_person_alias')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_address')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_license')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_identifier')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_block_key')->where('stg_person_id', $stgId)->delete();
        }

        $c = $children ?? $this->childRows($emp);

        $aiRows = $this->src()->table('employee_additional_info')
            ->where('employee_id', $emp->id)->where('value', '<>', '')
            ->get(['name', 'value']);
        $extra = $this->additionalRows($aiRows, $emp->state ?? null);
        $c['aliases'] = array_merge($c['aliases'], $extra['aliases']);
        $c['licenses'] = array_merge($c['licenses'], $extra['licenses']);
        $c['identifiers'] = $extra['identifiers'];
        $c['block_keys'] = $this->blockKeyRows($emp);

        foreach (['stg_person_alias' => 'aliases', 'stg_person_address' => 'addresses',
            'stg_person_license' => 'licenses', 'stg_person_identifier' => 'identifiers',
            'stg_person_block_key' => 'block_keys'] as $table => $bucket) {
            if ($c[$bucket] ?? null) {
                $hub->table($table)->insert(array_map(
                    fn ($r) => $r + ['stg_person_id' => $stgId], $c[$bucket]
                ));
            }
        }
    }
```

Note on test coverage, same as plan 5's Task 8 noted for the identifier staging fix: `ingest()`/
`rebuildChildren()` remain untestable in isolation, because `phpunit.xml` deliberately points the
source connection at a dead socket (`SRC_DB_HOST=127.0.0.1:1`) so no test can reach a real
`streamline_local`. `blockKeyRows()` itself — the part of this change with actual logic — is fully
covered by Step 1's pure tests; the wiring into `rebuildChildren()` is verified by code review, the
same standard plan 5 applied to the identifier-staging fix in the same method.

- [ ] **Step 6: Wire it into `SqlBackfill::stage()` (bulk path)**

In `SqlBackfill::stage()`'s chunk closure, add a `$blockKeys` collector alongside the existing
`$aliases = $addresses = $licenses = $identifiers = [];` line:

```php
            $aliases = $addresses = $licenses = $identifiers = $blockKeys = [];
```

Inside the `foreach ($rows as $emp)` loop, after the block that appends to `$licenses`/`$aliases`/
`$identifiers` from `childRows()`/`additionalRows()`, add:

```php
                foreach ($this->connector->blockKeyRows($emp) as $bk) {
                    $blockKeys[] = $bk + ['stg_person_id' => $sid];
                }
```

And extend the per-chunk transaction to insert them:

```php
            $this->hub()->transaction(function () use ($aliases, $addresses, $licenses, $identifiers, $blockKeys) {
                $this->bulkInsert('stg_person_alias', $aliases);
                $this->bulkInsert('stg_person_address', $addresses);
                $this->bulkInsert('stg_person_license', $licenses);
                $this->bulkInsert('stg_person_identifier', $identifiers);
                $this->bulkInsert('stg_person_block_key', $blockKeys);
            }, self::DEADLOCK_RETRIES);
```

**On "the per-row and set-based path must agree":** they agree by construction, not by a
cross-checked integration test. `blockKeyRows()` is the one method both call; there is no second
implementation for the two to drift apart from. `SqlBackfill::stage()`'s own source connection is
the same dead-socket-in-tests dependency as `ingest()`'s, so — as with plan 5's identifier-staging
fix — the parity claim rests on "one shared pure function, two thin callers," verified by reading
both call sites side by side (Steps 5 and 6 above), not on a runtime test that cannot exist in this
environment. What Task 5's `BlockKeyBuilderTest` and this task's `StreamlineLocalConnectorTest`
additions verify directly is that `blockKeyRows()` itself is correct; nothing downstream can
disagree about a value neither path computes independently.

**One thing that is genuinely different between the two paths, stated plainly:** `SqlBackfill`
never calls `ProbabilisticResolver` (see this plan's Design Decision §2) — so staging these rows via
the bulk path has no matching-time effect for that path today. It exists so the staged data is
complete and consistent regardless of which path loaded a given row, and so a future batch-mode
Pass B (or plan 8's inverted index) has real data to build on rather than a gap that only the
per-row path ever filled in.

- [ ] **Step 7: Commit**
```bash
git add app/GoldenProfile/Connectors/StreamlineLocalConnector.php app/GoldenProfile/SqlBackfill.php tests/Unit/StreamlineLocalConnectorTest.php
git commit -m "feat(gp): stage the name_state/name_state_zip blocking legs in both ingestion paths"
```

---

## Task 4: Extend the test harness so fixtures can exercise the new legs

**Files:**
- Modify: `tests/Support/HubTestCase.php` (`stagePerson()`)
- Modify: `app/GoldenProfile/Eval/EvalRunner.php` (`run()`)

**Interfaces:**
- Consumes: `BlockKeyBuilder::nameState()`/`nameStateZip()` (Task 2).
- Produces: every `stagePerson()` call and every `EvalSet` record with a `state`/`zip` now also gets
  `stg_person_block_key` rows — Tasks 5 and 6's tests depend on this.

Neither `HubTestCase::stagePerson()` nor `EvalRunner::run()` populates `stg_person_address` (a
pre-existing gap the shared authoring brief already names — `EvalRunner` never stages it, so the
`address`/`zip` probabilistic score weights never fire in the eval today). This task does not fix
that; it only makes sure the **blocking legs** this plan adds are populated everywhere a person is
staged, since a test that stages a person without them cannot possibly exercise the new candidate
discovery this plan adds, regardless of what it later asserts.

- [ ] **Step 1: Update `HubTestCase::stagePerson()`**

Add the import at the top of `tests/Support/HubTestCase.php`:

```php
use App\GoldenProfile\Support\BlockKeyBuilder;
```

Change the end of `stagePerson()` from:

```php
        return (int) $this->hub()->table('stg_person')->insertGetId($row);
    }
```

to:

```php
        $stgId = (int) $this->hub()->table('stg_person')->insertGetId($row);

        foreach ([
            'name_state' => BlockKeyBuilder::nameState($row['last_name'], $row['state']),
            'name_state_zip' => BlockKeyBuilder::nameStateZip($row['last_name'], $row['state'], $row['zip']),
        ] as $type => $value) {
            if ($value !== null) {
                $this->hub()->table('stg_person_block_key')->insert([
                    'stg_person_id' => $stgId, 'block_type' => $type, 'block_key' => $value,
                ]);
            }
        }

        return $stgId;
    }
```

- [ ] **Step 2: Update `EvalRunner::run()`**

Add the import at the top of `app/GoldenProfile/Eval/EvalRunner.php`:

```php
use App\GoldenProfile\Support\BlockKeyBuilder;
```

In `run()`, right after the `stg_person` `insertGetId(...)` call (before the `foreach ($set->licenses($ref) as $lic)` loop), add:

```php
            foreach ([
                'name_state' => BlockKeyBuilder::nameState($r['last_name'] ?? null, $r['state'] ?? null),
                'name_state_zip' => BlockKeyBuilder::nameStateZip($r['last_name'] ?? null, $r['state'] ?? null, $r['zip'] ?? null),
            ] as $type => $value) {
                if ($value !== null) {
                    $this->hub()->table('stg_person_block_key')->insert([
                        'stg_person_id' => $stgByRef[$ref], 'block_type' => $type, 'block_key' => $value,
                    ]);
                }
            }
```

- [ ] **Step 3: Run the full existing suite — prove no regression**

Run: `vendor/bin/phpunit`
Expected: PASS, same counts as the shared authoring brief's baseline (92 tests, 261 assertions) plus
this plan's own new tests so far (`BlockKeyBuilderTest`'s 7, `StreamlineLocalConnectorTest`'s 3 new
ones) — 0 skipped, 0 failures. This step exists because `HubTestCase` is shared foundation infra
every other test in the suite depends on; a mistake here would silently break every
`HubTestCase`-based test in the repository, not just this plan's own.

- [ ] **Step 4: Commit**
```bash
git add tests/Support/HubTestCase.php app/GoldenProfile/Eval/EvalRunner.php
git commit -m "test(gp): stage the new blocking legs from HubTestCase and EvalRunner"
```

---

## Task 5: Multi-leg candidate discovery in `ProbabilisticResolver`, capped per leg

**Files:**
- Modify: `app/GoldenProfile/Resolution/ProbabilisticResolver.php` (`match()`; new
  `candidateIdentityIds()`)
- Modify: `config/golden_profile.php` (comment only)
- Test: `tests/Feature/BlockingLegsTest.php` *(create)*

**Interfaces:**
- Consumes: `BlockKeyBuilder` (Task 2), `stg_person_block_key` (Task 1), the staging from Task 3.
- Produces: `ProbabilisticResolver::match()`'s external contract (`[?int, float, string]`) is
  unchanged; its internals now union three legs instead of reading one column.

This is the core of the plan. Before writing it, two things this plan discovered while trying to
build a fixture for it, stated plainly because they shape what "value delivered" means here (see
this plan's Self-review for the fuller discussion):

1. **A record with no DOB at all cannot reach the review band today, regardless of which leg finds
   it.** The implemented probabilistic weights are `name` (0.45), `dob` (0.20), `address` (0.15),
   `zip` (0.05), `exclusion_share` (0.07) — summing to 0.92 (the shared authoring brief's own
   documented fact about why `auto_match` is unreachable). Without `dob` firing at all (which
   requires the incoming record to carry a DOB — `score()`'s dob branch is gated on `$p
   ->date_of_birth`, not on the candidate's), the maximum reachable score is 0.45+0.15+0.05+0.07 =
   **0.72**, structurally below the 0.75 `review_band_floor`. No combination of blocking legs
   changes this arithmetic — it is a scoring-weight fact, not a candidate-discovery fact. This plan
   does not attempt to close that gap (rebalancing weights or lowering `review_band_floor` is a
   Phase 3 calibration decision against labeled data, exactly per the existing config comment on
   `weights` — not something a blocking-scoped plan should decide unilaterally).
2. **Where wider blocking DOES change an outcome today, without touching any weight:** a record
   whose true match sits in an *oversized* name+dob block (a common surname + a common birth year —
   nothing to do with missing data) is declined by the single-leg design before this plan, even
   though a more specific leg (`name_state_zip`) for the same pair is nowhere near the cap. This is
   exactly the effect plan 5's Self-review predicted ("multiple independent legs, each capped
   separately, would likely reduce how often [an oversized decline] fires") and did not measure.
   This task measures it, with both DOB present (so the review floor IS reachable) and the name+dob
   leg deliberately oversized.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\ProbabilisticResolver;
use Illuminate\Support\Facades\DB;
use Tests\Support\HubTestCase;

/**
 * The three behaviours this plan exists to prove:
 *   1. a widened leg surfaces a true candidate the name+dob leg cannot reach
 *      (but see the class docblock in ProbabilisticResolver / this plan's
 *      Task 5 intro for why that candidate does not always bind);
 *   2. a widened leg lets a real match through when the name+dob leg is
 *      oversized -- the one case that changes an actual bind decision today;
 *   3. sharing a widened block never merges two different people -- the
 *      strict name+dob-conflict gate is blind to which leg found a candidate.
 */
class BlockingLegsTest extends HubTestCase
{
    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /** Insert a primary address for a staged person -- neither stagePerson() nor
     *  EvalRunner does this (a pre-existing gap the shared brief already names);
     *  these tests need it to reach the address/zip score weights, so they stage
     *  it directly rather than depend on a fix out of this plan's scope. */
    private function stageAddress(int $stgPersonId, string $address1, string $city, string $state, string $zip): void
    {
        $this->hub()->table('stg_person_address')->insert([
            'stg_person_id' => $stgPersonId, 'address_type' => 'primary',
            'address1' => $address1, 'address2' => null, 'city' => $city, 'state' => $state, 'zip' => $zip,
        ]);
    }

    public function test_a_missing_dob_record_is_found_via_name_state_but_stays_below_review_floor(): void
    {
        // Full-data anchor, resolved into an identity first.
        $anchorId = $this->stagePerson([
            'first_name' => 'Diane', 'last_name' => 'Whitmore', 'date_of_birth' => '1965-02-11',
            'state' => 'OH', 'zip' => '43215',
        ]);
        $this->stageAddress($anchorId, '100 Main St', 'Columbus', 'OH', '43215');
        $anchorIdentity = (new DeterministicResolver($this->systemId))->resolve($anchorId);

        // Same person, no DOB this time -- the name+dob leg buckets this under
        // "soundex(Whitmore)|____", a DIFFERENT bucket from the anchor's
        // "soundex(Whitmore)|1965", so before this plan the two would never
        // even be considered together. Same state/zip, so the new
        // name_state/name_state_zip legs DO put them in the same block.
        $noDobId = $this->stagePerson([
            'first_name' => 'Diane', 'last_name' => 'Whitmore', 'date_of_birth' => null,
            'state' => 'OH', 'zip' => '43215',
        ]);
        $this->stageAddress($noDobId, '100 Main St', 'Columbus', 'OH', '43215');

        $p = $this->hub()->table('stg_person')->find($noDobId);
        [$id, $score, $state] = (new ProbabilisticResolver($this->systemId))->match($p, collect());

        // The candidate WAS found and scored -- proving the widened leg
        // worked -- but 0.45 (name) + 0.15 (address) + 0.05 (zip) = 0.65 for
        // THIS pair (no exclusion-share signal staged), still short of the
        // 0.75 review floor, and no dob credit is possible with $p's DOB
        // null. See this task's intro for why 0.72 is the ceiling even with
        // every other implemented signal firing, and why closing that gap is
        // out of this plan's scope.
        $this->assertNull($id, 'below the review floor, so no bind -- this pins the CEILING, not a bug');
        $this->assertEqualsWithDelta(0.65, $score, 0.0001);
        $this->assertSame('no_match', $state);
    }

    public function test_an_oversized_name_dob_block_still_resolves_via_the_smaller_name_state_zip_leg(): void
    {
        // Lower the cap so a handful of fixture rows can simulate an
        // oversized block cheaply -- the real cap (2000) cannot be triggered
        // with a readable number of rows in a test.
        config()->set('golden_profile.probabilistic.block_size_cap', 3);

        // Anchor + target share last name AND birth YEAR (so the name+dob
        // leg buckets them together) but a different day (so Pass A's own
        // exact-date name+dob tier does NOT bind them first -- this pair
        // must actually reach Pass B to prove anything about it).
        $anchorId = $this->stagePerson([
            'first_name' => 'Diane', 'last_name' => 'Whitmore', 'date_of_birth' => '1980-03-01',
            'state' => 'OH', 'zip' => '43215',
        ]);
        $this->stageAddress($anchorId, '100 Main St', 'Columbus', 'OH', '43215');
        $anchorIdentity = (new DeterministicResolver($this->systemId))->resolve($anchorId);

        // Four unrelated fillers sharing the SAME name+dob block
        // (soundex(Whitmore)|1980) push that block's size to 6 -- over the
        // cap of 3 -- without sharing the anchor's state/zip at all.
        foreach (['Amy', 'Beth', 'Cara', 'Dina'] as $first) {
            $this->stagePerson(['first_name' => $first, 'last_name' => 'Whitmore', 'date_of_birth' => '1980-09-09']);
        }
        $blockSize = $this->hub()->table('stg_person')
            ->where('block_key', soundex('Whitmore').'|1980')->count();
        $this->assertGreaterThan(3, $blockSize, 'test setup: the name+dob block must actually be oversized');

        $targetId = $this->stagePerson([
            'first_name' => 'Diane', 'last_name' => 'Whitmore', 'date_of_birth' => '1980-03-15',
            'state' => 'OH', 'zip' => '43215',
        ]);
        $this->stageAddress($targetId, '100 Main St', 'Columbus', 'OH', '43215');

        $targetIdentity = (new DeterministicResolver($this->systemId))->resolve($targetId);

        // name (0.45, exact) + dob (0.10, same-year-only credit) + address
        // (0.15) + zip (0.05) = 0.75 -- clears review_band_floor exactly.
        // The name+dob leg alone (oversized, skipped) could never have found
        // this candidate before this plan; name_state_zip (2 members: anchor
        // + target) is nowhere near the cap.
        $this->assertSame($anchorIdentity, $targetIdentity, 'the oversized name+dob leg should not have blocked this real match');
    }

    public function test_a_shared_widened_block_does_not_merge_a_different_person(): void
    {
        $anchorId = $this->stagePerson([
            'first_name' => 'Diane', 'last_name' => 'Whitmore', 'date_of_birth' => '1980-03-01',
            'state' => 'OH', 'zip' => '43215',
        ]);
        $anchorIdentity = (new DeterministicResolver($this->systemId))->resolve($anchorId);

        // Same exact last name, same state AND zip (same name_state_zip
        // block as the anchor) -- but a different first name and a
        // conflicting birth year. hardNo's conflicting_dob rule must still
        // block this regardless of which leg surfaced the candidate.
        $strangerId = $this->stagePerson([
            'first_name' => 'Frank', 'last_name' => 'Whitmore', 'date_of_birth' => '1954-08-09',
            'state' => 'OH', 'zip' => '43215',
        ]);

        $strangerIdentity = (new DeterministicResolver($this->systemId))->resolve($strangerId);

        $this->assertNotSame($anchorIdentity, $strangerIdentity, 'sharing a widened block must never merge a different person');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/BlockingLegsTest.php`
Expected: FAIL on all three (or the first two returning wrong scores/no candidate, the third
trivially passing today since it can't merge anyway) — the new legs are staged (Task 3/4) but
`ProbabilisticResolver::match()` does not read `stg_person_block_key` yet.

- [ ] **Step 3: Rewrite `match()` and add `candidateIdentityIds()`**

Add the import at the top of `app/GoldenProfile/Resolution/ProbabilisticResolver.php`:

```php
use App\GoldenProfile\Support\BlockKeyBuilder;
use Illuminate\Support\Collection;
```

Replace the existing `match()` method body (everything from `if (! $p->block_key)` through the
`$identities` hydration loop) with:

```php
    /** @return array{0:?int,1:float,2:string} */
    public function match(object $p, $licenses): array
    {
        $candidateIds = $this->candidateIdentityIds($p);

        if ($candidateIds->isEmpty()) {
            return [null, 0.0, 'no_match'];
        }

        $best = null;
        $bestScore = 0.0;

        // Candidates are loaded in batches, not one query each -- unchanged
        // from before multi-leg blocking, and unaffected by it: the three
        // legs are unioned and de-duplicated (candidateIdentityIds()) BEFORE
        // this loop runs, so hydration cost depends only on the final
        // candidate-identity count, not on how many legs contributed to it.
        $identities = collect();
        foreach ($candidateIds->chunk(1000) as $batch) {
            $identities = $identities->merge(
                $this->hub()->table('gp_identity')->whereIn('identity_id', $batch->all())->get()
            );
        }

        foreach ($identities as $identity) {
            $cid = $identity->identity_id;
            if ($this->hardNo($p, $identity)) {
                continue;
            }
            // strict name prerequisite (first+last equal, middle/suffix/dob compatible) --
            // blind to which blocking leg produced this candidate. See this
            // plan's Design Decision §4.
            if (! NameMatcher::compatible($p, $this->asNameObj($identity))) {
                continue;
            }
            $score = $this->score($p, $identity);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int) $cid;
            }
        }

        if ($best === null) {
            return [null, 0.0, 'no_match'];
        }
        if ($bestScore >= $this->cfg['auto_merge_at']) {
            return [$best, $bestScore, 'auto_match'];
        }
        if ($bestScore >= $this->cfg['review_band_floor']) {
            return [$best, $bestScore, 'review'];
        }

        // A candidate WAS found -- blocking worked -- but it didn't clear the
        // review floor. This is common and structural for a record with no
        // DOB (see this plan's Task 5 intro: 0.72 is the ceiling without dob
        // credit, below the 0.75 floor), not a bug in this method. Today
        // that near-miss leaves no trace at all; this log line is the trace,
        // aimed at whoever eventually does the Phase 3 calibration pass the
        // 'weights' config comment already anticipates.
        Log::info('probabilistic near-miss: candidate found but below review floor', [
            'stg_person_id' => $p->stg_person_id ?? null,
            'best_identity_id' => $best,
            'best_score' => $bestScore,
            'review_band_floor' => $this->cfg['review_band_floor'],
        ]);

        return [null, $bestScore, 'no_match'];
    }

    /**
     * Union candidate identity_ids across every blocking leg with a usable
     * value on $p, checking block_size_cap INDEPENDENTLY per leg rather than
     * once globally -- a record oversized on the name+dob leg (a common
     * surname born in a common year, nothing to do with missing data) still
     * gets a fair look through name_state or name_state_zip, which are
     * typically far more selective. See this plan's Task 5 for a measured
     * case where this changes an actual bind decision.
     */
    private function candidateIdentityIds(object $p): Collection
    {
        $ids = collect();
        $cap = (int) ($this->cfg['block_size_cap'] ?? 0);
        $skipped = [];

        // Leg 1: name+dob. Same column, same query shape, same self-exclusion
        // predicate as before multi-leg blocking -- untouched on purpose
        // (see this plan's Design Decision §1).
        if ($p->block_key) {
            $size = (int) $this->hub()->table('stg_person')->where('block_key', $p->block_key)->count();
            if ($cap > 0 && $size > $cap) {
                $skipped[] = "name_dob($size)";
            } else {
                $ids = $ids->merge($this->hub()->table('gp_source_link as l')
                    ->join('stg_person as sp', function ($j) {
                        $j->on('sp.system_id', '=', 'l.system_id')
                            ->on('sp.source_table', '=', 'l.source_table')
                            ->on('sp.source_id', '=', 'l.source_id');
                    })
                    ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
                    ->where('sp.block_key', $p->block_key)
                    ->where('i.status', 'active')
                    ->where(fn ($q) => $q->where('sp.source_id', '!=', $p->source_id)->orWhere('sp.system_id', '!=', $this->systemId))
                    ->distinct()->pluck('l.identity_id'));
            }
        }

        // Legs 2-3: name_state and name_state_zip (stg_person_block_key).
        // Computed fresh from $p rather than re-reading $p's own stored rows
        // -- the value needed here IS the lookup key, not a fact about $p
        // worth a round trip to confirm.
        foreach ([
            'name_state' => BlockKeyBuilder::nameState($p->last_name, $p->state),
            'name_state_zip' => BlockKeyBuilder::nameStateZip($p->last_name, $p->state, $p->zip),
        ] as $type => $value) {
            if ($value === null) {
                continue;
            }
            $size = (int) $this->hub()->table('stg_person_block_key')
                ->where('block_type', $type)->where('block_key', $value)->count();
            if ($cap > 0 && $size > $cap) {
                $skipped[] = "$type($size)";
                continue;
            }
            $ids = $ids->merge($this->hub()->table('stg_person_block_key as bk')
                ->join('stg_person as sp', 'sp.stg_person_id', '=', 'bk.stg_person_id')
                ->join('gp_source_link as l', function ($j) {
                    $j->on('sp.system_id', '=', 'l.system_id')
                        ->on('sp.source_table', '=', 'l.source_table')
                        ->on('sp.source_id', '=', 'l.source_id');
                })
                ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
                ->where('bk.block_type', $type)
                ->where('bk.block_key', $value)
                ->where('i.status', 'active')
                ->where('bk.stg_person_id', '!=', $p->stg_person_id)
                ->distinct()->pluck('l.identity_id'));
        }

        // Every populated leg was oversized (or $p has no last name at all,
        // so no leg was even populated -- unchanged from before this plan).
        // Pass B declines the same way a single oversized block used to
        // decline it, but now it SAYS so instead of failing silently. This
        // does NOT flag anything for steward review or write a queryable
        // record of the decline -- that is plan 6's job (the steward writer
        // layer); this is only the trace that job will eventually consume.
        if ($skipped && $ids->isEmpty()) {
            Log::warning('probabilistic blocking declined: every populated leg was oversized', [
                'stg_person_id' => $p->stg_person_id ?? null,
                'skipped_legs' => $skipped,
                'block_size_cap' => $cap,
            ]);
        }

        return $ids->unique();
    }
```

- [ ] **Step 4: Update the `block_size_cap` config comment**

In `config/golden_profile.php`, change:

```php
        'block_size_cap' => 2000,   // oversized blocks flagged for steward, never truncated
```

to:

```php
        // Applied INDEPENDENTLY to every blocking leg (name+dob, name+state,
        // name+state+zip -- see ProbabilisticResolver::candidateIdentityIds()),
        // not once globally: a record oversized on one leg still gets a fair
        // look through another. "Flagged for steward" is aspirational, not
        // implemented -- an all-legs-oversized decline is logged
        // (Log::warning, 'probabilistic blocking declined') but writes no
        // queryable record; turning that into an actual steward-facing flag
        // is plan 6's job (the steward writer layer), not this config's.
        'block_size_cap' => 2000,
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/BlockingLegsTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS, 0 failures, 0 skipped (beyond the pre-existing environment-gated skips when
`GP_TEST_DB_*` is unset).

- [ ] **Step 7: Commit**
```bash
git add app/GoldenProfile/Resolution/ProbabilisticResolver.php config/golden_profile.php tests/Feature/BlockingLegsTest.php
git commit -m "feat(gp): union name_state/name_state_zip into Pass B candidate discovery, capped per leg"
```

---

## Task 6: Eval-gate coverage — prove no false merge, and record why recall does not move

**Files:**
- Modify: `tests/eval/identity-pairs.json`
- Modify: `docs/EVALUATION.md`

**Interfaces:**
- Consumes: `EvalRunner`'s Task 4 staging of the new legs.

This task adds fixture coverage for the widened legs at the standard eval-gate scale, and records,
in the one place the programme's other plans have recorded their own eval-gate deltas, why this
plan's fixture addition does **not** raise `true_pairs`.

**Why not:** Task 5's intro showed a record with no DOB caps out at 0.72, below the 0.75 review
floor, regardless of blocking — so the ONE class of pair the small, curated `identity-pairs.json`
fixture can represent (records distinguished only by presence/absence of a DOB) can never actually
bind under today's weights, and adding a `truth` pair the resolver cannot achieve would either
silently fail (`false_splits` regresses, which the gate treats as an absolute regression) or require
a scoring change this plan does not make. The genuinely valuable case this plan found — an oversized
name+dob leg resolved via a smaller `name_state_zip` leg — needs enough rows sharing one block to
exceed `block_size_cap` (2000 in production; even a lowered test-local cap needs several rows to
demonstrate cleanly), which is a reasonable ask of a `HubTestCase`-based Feature test (Task 5) but
not of a small, checked-in, human-readable JSON fixture. `true_pairs` therefore stays at whatever
plan 5 leaves it (11, per this plan's Programme context) — the capability this plan adds is proven
in Task 5's `BlockingLegsTest`, not here.

What this task DOES add to the small fixture: the one thing that IS representable at this scale and
belongs in the same file as every other precision-safety case — two different people who now share
a widened block and must stay apart.

- [ ] **Step 1: Write the failing test (implicitly — add the records first, run, confirm it already
      passes)**

In `tests/eval/identity-pairs.json`, add two new records to `"records"` (after the last existing
record, before the closing `]`):

```json
    { "ref": "block-widen-a", "first_name": "Diane", "last_name": "Whitmore", "date_of_birth": "1980-03-01", "state": "OH", "zip": "43215" },
    { "ref": "block-widen-b", "first_name": "Frank", "last_name": "Whitmore", "date_of_birth": "1954-08-09", "state": "OH", "zip": "43215" }
```

Add two new singleton clusters to `"truth"`:

```json
    ["block-widen-a"],
    ["block-widen-b"]
```

Append to the top-level `"notes"` string: `" block-widen-a and block-widen-b share an exact last
name, state, and zip (the same name_state_zip block a real match could use), but a different first
name and a conflicting birth year -- added by plan 5b to prove sharing a WIDENED block never merges
a different person inside the standard eval gate, not only in the dedicated BlockingLegsTest. They
are singletons on purpose and do not raise true_pairs -- see docs/EVALUATION.md for why no new true
pair could be added here."`

- [ ] **Step 2: Run the gate**

Run: `vendor/bin/phpunit --filter=EvalGateTest`
Expected: PASS, numbers unchanged from plan 5's final baseline — precision 1.0000, recall 1.0000,
f1 1.0000, `true_pairs` still >= whatever plan 5 leaves it (11 per this plan's assumption), 0 false
merges, 0 false splits. Both new records are singletons in `truth`, so they cannot change
`true_pairs`; if `false_merges` moves off 0, the two records were merged and this plan has a real
bug in `candidateIdentityIds()`/`hardNo()`/`compatible()` to fix before proceeding — do not adjust
the fixture or the gate to paper over it.

- [ ] **Step 3: Record the measured numbers**

Append a new subsection to the end of `docs/EVALUATION.md`:

```markdown
## Plan 5b (Pass B blocking widening) — eval fixture, local

Measured locally via `vendor/bin/phpunit --filter=EvalGateTest` after adding the `block-widen-a`/
`block-widen-b` false-merge-safety pair (see `tests/eval/identity-pairs.json`'s notes):

| Metric | Value |
|---|---|
| Precision | 1.0000 |
| Recall | 1.0000 |
| F1 | 1.0000 |
| False merges | 0 |
| False splits | 0 |
| True pairs | 11 (unchanged — see below) |

**`true_pairs` is deliberately NOT raised by this plan.** The two blocking legs this plan adds
(`name_state`, `name_state_zip`) are proven working and safe in `tests/Feature/BlockingLegsTest.php`
(`app/GoldenProfile/Resolution/ProbabilisticResolver.php`'s `candidateIdentityIds()`), not in this
JSON fixture. Reason: the only class of pair this small fixture can represent that the OLD single-leg
blocking would miss is a record with no DOB at all — and under today's implemented probabilistic
weights (`name` 0.45 + `address` 0.15 + `zip` 0.05 + `exclusion_share` 0.07 = 0.72), a record with no
DOB can never clear the 0.75 `review_band_floor`, regardless of which blocking leg finds it. Adding
such a pair to `truth` here would either regress `false_splits` (the gate's absolute floor) or
require a scoring-weight change out of this plan's scope (Phase 3 calibration, per
`config/golden_profile.php`'s own comment on `weights`). The one case this plan found that DOES
change a real bind decision today — an oversized `name+dob` block resolved via a smaller
`name_state_zip` leg — needs several rows sharing one block to demonstrate, which
`BlockingLegsTest`'s `HubTestCase` harness can do cheaply and this checked-in JSON fixture cannot.
```

- [ ] **Step 4: Commit**
```bash
git add tests/eval/identity-pairs.json docs/EVALUATION.md
git commit -m "test(eval): add a false-merge-safety pair for the widened blocking legs"
```

---

## Self-review

**Spec coverage against the task brief:**

- **"Block on NPI, (state, license), (state, provider#), (state, medicaid id), and name+state"** —
  NPI, license, and (state, MMIS-as-provider#/medicaid-id) are plan 5's job (already deterministic
  Pass A tiers or promoted to tiers there), explicitly out of this plan's scope. `name+state` is
  Task 5's `name_state` leg. `phonetic-name/state/zip` (the wiki's own worked example) is Task 5's
  `name_state_zip` leg.
- **"Merge overlapping blocks into super-blocks"** — argued out of scope in Design Decision §2:
  no execution model for it exists in gp-cami today (the per-row resolver has no batch-graph step,
  and the one batch path, `SqlBackfill`, never calls `ProbabilisticResolver` at all), and this plan
  does not manufacture one to serve zero current callers. The union-at-query-time mechanism this
  plan builds instead satisfies the one requirement super-blocks exist to serve (don't miss a match
  because only one leg caught it) for the one execution model gp-cami actually has.
- **Multi-leg architecture** — a `stg_person_block_key` child table for the two new legs, the
  existing `stg_person.block_key` column left untouched for the name+dob leg it already serves.
  Argued in Design Decision §1, including the explicit cost (two sources instead of one for a future
  reader like plan 8) rather than hiding it.
- **`block_size_cap` interaction** — made per-leg rather than global (Task 5), with the frequency
  claim plan 5 speculated about ("would likely reduce how often [oversized decline] fires") measured
  directly in `BlockingLegsTest::test_an_oversized_name_dob_block_still_resolves_via_the_smaller_name_state_zip_leg`.
  The flagging half of the landmine (a queryable steward record) is explicitly left to plan 6, named
  as such in both the code comment (Task 5, Step 4) and this Self-review, not silently skipped.
- **Recall vs. precision** — argued and tested in Design Decision §4 and
  `BlockingLegsTest::test_a_shared_widened_block_does_not_merge_a_different_person`: the strict gate
  (`NameMatcher::compatible()`, `hardNo()`) reads only `$p` and the candidate, never which leg
  produced the candidate, so a wider pool cannot loosen what is accepted.
- **The eval gate (brief §5)** — Task 6 adds fixture coverage and reruns the gate; `true_pairs` is
  explicitly NOT raised, with the arithmetic reasoning recorded in `docs/EVALUATION.md` rather than
  asserted without evidence. `false_merges` stays at the gate's absolute 0.

**What this plan explicitly does NOT do**, named here rather than discovered later:

- It does not raise measured recall on the eval gate. The genuine capability it adds (oversized-leg
  independence) is real and measured, but not representable at the eval-gate's fixture scale; the
  genuine gap it could theoretically help with (missing-DOB matches) is mathematically unreachable
  under today's scoring weights regardless of blocking. Both facts are stated in Task 5 and Task 6
  rather than papered over with a fixture that would either not prove anything or quietly break the
  false-splits ratchet.
- It does not flag oversized-block declines for steward review, write a queryable record of a
  decline, or build any UI/workflow around the near-miss/decline log lines it adds. Both are named,
  in the code comments and here, as plan 6's job.
- It does not consolidate the existing name+dob leg's triplicated `blockKey()` implementation
  (`StreamlineLocalConnector`, `EvalRunner`, `HubTestCase`) into `BlockKeyBuilder`. That is real,
  pre-existing, minor debt; touching three already-correct call sites for a leg this plan does not
  otherwise change was judged not worth the risk for a cosmetic gain (Task 2).
- It does not fold the three per-leg queries in `candidateIdentityIds()` into one `UNION` statement.
  Kept as three separate, readable Eloquent queries per Design Decision §3; a reasonable follow-up
  if `gp:sync` throughput measurements ever show the extra round trips matter.
- It does not stage `stg_person_address` for the eval fixture in general — only `BlockingLegsTest`
  (Task 5) stages it directly, scoped to the two tests that need it, rather than fixing the
  pre-existing `EvalRunner` gap the shared authoring brief already names. Fixing that gap generally
  is plan 8's likely territory (address/zip signals feeding incremental profiling), not restated
  here as a promise this plan does not keep.

**Placeholder scan:** no task says "add appropriate error handling," "similar to Task N," or "write
tests for the above." Every step that changes code shows the code inline, including the full
`BlockKeyBuilder` class, the full migration, and the full rewritten `ProbabilisticResolver` methods.

**Type consistency:** `candidateIdentityIds()` returns `Illuminate\Support\Collection` throughout —
declared via the `use` import in Task 5 rather than a fully-qualified name, matching the existing
file's style of importing collaborator classes. `blockKeyRows()` returns `list<array{block_type:
string,block_key:string}>`, consumed identically by `rebuildChildren()` and `SqlBackfill::stage()`
(Task 3) — both call sites are updated in the same task, so no caller is left holding the old
(nonexistent) shape.

**Known risks carried into execution:**

- **This plan is written against plan 5's *intended* final shape, not its executed one**, because
  plan 5 has not been run yet (see this plan's Programme context for exactly which method
  signatures and fixture state are assumed). If plan 5 lands with materially different shapes for
  `rebuildChildren()`, `additionalRows()`, or the eval fixture's final `true_pairs` count, Tasks 3
  and 6 need their line/count references reconciled against what actually landed — the logic itself
  (what to add, where) does not depend on plan 5's exact line numbers, only this plan's citations of
  them do.
- **The per-row/bulk staging parity claim (Task 3) is architectural, not test-verified**, for the
  same reason plan 5's identifier-staging fix accepted the same limitation: `SqlBackfill`'s and
  `StreamlineLocalConnector::ingest()`'s source connections are both dead sockets in this test
  environment. The shared `blockKeyRows()` method removes the opportunity for the two paths to
  compute different values, which is a weaker but real guarantee than a runtime cross-check would
  be — stated here rather than assumed silently.
- **`SqlBackfill` stages the new legs but never queries them.** Confirmed directly in Design
  Decision §2: `SqlBackfill` never calls `ProbabilisticResolver`. The staged rows exist for
  correctness/completeness and for a possible future batch-mode Pass B or plan 8's inverted index,
  not because this plan found a present-day bulk-path consumer for them. Anyone reading
  `SqlBackfill::stage()`'s diff without this context could reasonably ask why it stages data it
  never uses — this note is that answer.
- **The 0.72-versus-0.75 review-floor ceiling is a fact about the CURRENT config**, not a permanent
  property of the codebase. If a future plan (Phase 3 calibration, or a `provider_type` source
  column landing) changes the implemented weights or `review_band_floor`, the missing-DOB gap this
  plan's Task 5/6 describe as unreachable may become reachable — at which point the fixture-coverage
  reasoning in Task 6 should be revisited, not assumed to still hold.

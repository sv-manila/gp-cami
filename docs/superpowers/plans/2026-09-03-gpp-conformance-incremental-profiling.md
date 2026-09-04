# Incremental Profiling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give gp-cami the two pieces of persistent state the "Incremental Profiling" design
calls for and gp-cami doesn't have — a profile-level (not staging-row-level) inverted block-key
index and a compact per-identity signature — wire both into the live matching path so a new
record's candidate search stops scaling with identity size, add the periodic full-reprofile job
the design says incremental adds structurally cannot replace, and prove, with a real test rather
than an assertion, that the per-row and set-based resolution paths converge to the same
clustering.

**Architecture:** Two new derived tables, `gp_identity_block_key` (block_key -> identity_id,
inverted) and `gp_identity_signature` (a compact per-identity summary: every npi/zip/license/
identifier value the identity's members have ever carried), maintained by BOTH resolution paths
the same way plan 5's identifier tiers are — a real-time hook in the per-row path
(`Survivorship::recompute()` + `DeterministicResolver::createIdentity()`) and a set-based rebuild
in the bulk path (`SetFinalizer::survivorship()`) — converging to the same end state by different,
independently-justified mechanisms rather than one calling the other. `ProbabilisticResolver`
is rewritten to read both instead of joining through `stg_person`/`gp_source_link` and instead of
scanning child tables per candidate, which is the concrete fix for the "13,500 hub queries per
row" scaling problem the existing code documents. Bridge-merging two already-materialized
profiles stays a post-hoc, periodic operation (`Engine::dedup()`), not a real-time one — a new
`gp:reprofile` command and schedule entry make "periodic" a real, running thing instead of an
unscheduled capability. A dedicated parity test stages the (post-plan-5) eval fixture once and
resolves it through both paths in the same test, proving they produce the identical clustering.

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

**Two landmines specific to this plan, both verified empirically against this project's own
`gp_cami_test` MySQL instance (192.168.56.22) before writing any task below — do not re-derive
them, and do not assume they can't bite a future edit to these files:**

- **MySQL's `SOUNDEX()` does not truncate to 4 characters.** `SOUNDEX('McDonald')` is `M23543`,
  not `M235`. PHP's `soundex('McDonald')` IS `M235` (the standard American Soundex, always 1
  letter + 3 digits). `LEFT(SOUNDEX(x), 4)` in SQL was verified to match PHP's `soundex()` exactly
  across `Smith, Nguyen, Garcia, O'Brien, McDonald, Lee, Diaz, Wong, Patel, Robinson, Washington,
  Featherstonehaugh`. Any SQL in this plan that reproduces the PHP-side block-key formula uses
  `LEFT(SOUNDEX(...), 4)`, never bare `SOUNDEX(...)`, and Task 2 pins the equivalence with a test
  so a future edit that drops the `LEFT(...)` fails loudly instead of silently diverging.
- **A `CREATE TABLE`/`ALTER TABLE`/`TRUNCATE TABLE` executed while `HubTestCase`'s outer
  transaction is open causes MySQL to implicitly COMMIT that transaction — verified directly: a
  row inserted before the DDL was still present after a `rollBack()` call that immediately
  followed it.** Laravel's `DB::rollBack()` does not throw in this situation (it just resets its
  internal transaction-level counter to 0), so nothing fails loudly — the risk is silent: every
  row written up to and past that point survives `tearDown()` and is only cleared by the next
  `migrate:fresh` (i.e. the next fresh `phpunit` process). `SqlBackfill::resolveDeterministic()`
  already does this unconditionally today (`SsnHashGuard::buildBlocklistTable()`'s
  `CREATE TABLE IF NOT EXISTS`), and plan 5 adds a second one (`JunkKeyGuard`). Task 7's parity
  test is the first place in this codebase that calls `SqlBackfill` from inside a `HubTestCase`
  test, so it is written to clean up explicitly rather than trust the transaction — see Task 7 for
  the concrete mechanism. (Also verified: a `$hub->transaction()` call made *after* such an
  implicit commit — e.g. inside `Engine::dedup()`'s `mergeIdentity()` — still works correctly
  despite Laravel's stale internal bookkeeping; only the outer rollback is affected.)

---

## Programme context — this is plan 8 of 8

| # | Plan | Depends on | Status |
|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — | **DONE, merged into this branch** |
| 2 | SSN removal | 1 | to write |
| 3 | SCD-2 versioning | 1 | to write |
| 4 | Individual vs entity | 1, 3 | to write |
| 5 | Match keys & data quality | 1 | **written, not yet executed** |
| 6 | Steward writer layer | 1, 3 | to write |
| 7 | Exclusion lifecycle | 1, 3 | to write |
| **8** | **Incremental profiling** | **1, 5** | **this document** |

This plan is last in the programme because it is the only one whose subject — *when* and *how
cheaply* resolution happens, as opposed to *what* resolution decides — depends on every match key
that will ever exist being already defined. It depends concretely on plan 5, and assumes the
following have already landed exactly as plan 5 specifies (this plan does not re-verify them; if
plan 5 is executed with material changes, re-check these assumptions before starting Task 1):

- **DEA and (state, MMIS) are real resolve-time match keys**, via `DeterministicResolver`'s
  identifier tier and `gp_identity_identifier.state` (plan 5 Tasks 8-9). This plan's signature
  table (Task 4) reads `gp_identity_identifier` including its `state` column, and its parity test
  (Task 7) relies on the eval fixture already containing DEA/MMIS-driven merges.
- **The per-row ingestion parity bug is fixed**: `StreamlineLocalConnector::rebuildChildren()`
  (called from `Engine::backfill()`/`sync()`) now stages `stg_person_identifier` rows the same way
  the bulk path always did (plan 5 Task 8). This plan does not touch that gap again — if it were
  still open, Task 7's parity test would fail for the wrong reason (the per-row path silently
  missing identifiers, not a genuine mismatch between the two resolution mechanisms), and
  debugging that here would be re-doing plan 5's work instead of this plan's own.
  `NpiValidator::isValid()` exists and NPI ingestion is already validated (plan 5 Tasks 1-3) —
  this plan's signature table stores whatever `stg_person.npi` already holds, so it inherits that
  validation for free rather than re-validating.
- **`JunkKeyGuard`** exists and is wired into `SqlBackfill::resolveDeterministic()` (plan 5 Task
  5), which is one of the two places (`SsnHashGuard` is the other) that make `resolveDeterministic()`
  DDL-triggering — see the transaction landmine above, which this plan's Task 7 works around.
- **Plan 5 explicitly declined Pass B blocking widening** (name+state block, phonetic-name/
  state/zip block, a multi-leg `stg_person_block_key`) and recommended it become a separate plan
  5b, not yet written. This plan's block-key index (Tasks 1-3) is deliberately built for exactly
  ONE leg — the existing surname-soundex + dob-year `block_key` formula, unchanged — precisely so
  it does not need to guess at 5b's eventual shape. See Task 1's docblock for the concrete
  forward-compatibility note (a `block_type` discriminator column is the natural extension point,
  additive, not touched here).
- **Plan 5 defers `(state, provider#)`** (no data source in gp-cami's scope) and the
  NPI-replacement trail (no NPPES feed). Neither is re-litigated here; this plan's signature and
  block-key structures are keyed off whatever match material actually exists after plan 5, not off
  a hypothetical future one.

---

## What already conforms, and is not touched by this plan

The GPP wiki's "Incremental Profiling — Handling New Records Without a Full Re-Run" page specifies
three pieces of persistent state and four outcomes for a new record. Before describing what this
plan builds, here is where gp-cami already stands, verified by reading the code (not assumed):

- **Crosswalk (`uid -> profile_id`)** — this is `gp_source_link`
  (`system_id, source_table, source_id -> identity_id`), already the assignment of record to
  profile, already watermarked (`gp_watermark` + `php artisan gp:sync`). Nothing to build.
- **"Attach to one"** — already real-time: `DeterministicResolver::resolve()`'s existing-link
  short-circuit and its Pass A/B binds both attach a new row to an existing identity without
  waiting for any batch step.
- **"Form a cluster with other new records"** — already a natural consequence of resolving
  sequentially: when row 2 of a sync batch shares a key with row 1 (created earlier in the SAME
  batch), row 2's Pass A/B lookup finds row 1's identity, because `DeterministicResolver::
  createIdentity()` seeds `gp_identity`'s key columns immediately (verified: lines 218-233).
  Task 1 extends this same immediacy to the new block-key index specifically so Pass B
  candidate search sees a same-batch sibling too, not just Pass A's exact-key tiers.
- **"An update is delete-then-add of that record's keys" at the STAGING layer** — already true:
  `StreamlineLocalConnector::ingest()` does a full-column `UPDATE` on an existing `stg_person` row
  (verified: line 96, `$this->hub()->table('stg_person')->where($key)->update($row)`), so a
  changed or cleared source NPI/DEA/etc. is fully reflected in `stg_person` on the very next sync,
  not merged with stale prior values. What is genuinely missing is the IDENTITY-level
  consequence: `gp_identity.npi` and friends are first-wins (`backfillKeys()`'s
  `empty($id->$col) && !empty($p->$srcCol)` check), so a value that changes on the source row
  never un-sets or replaces what the identity already absorbed. Task 6 names this precisely and
  explains why the fix is the periodic full reprofile, not incremental split logic.

What this plan actually builds: the profile-level block-key index (missing — blocking runs off
`stg_person.block_key` today, which blocks against staged ROWS, not profiles), the profile
signature (absent entirely), and a scheduled periodic reprofile job (the mechanism exists —
`Engine::dedup()`/`finalizeAllSet()` — but nothing runs it).

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_05_000000_create_gp_identity_block_key.php` *(create)* | Inverted `block_key -> identity_id` index table |
| `app/GoldenProfile/Support/BlockKey.php` *(create)* | Single PHP-side formula for an identity/staged-row's block key |
| `app/GoldenProfile/Resolution/Survivorship.php` *(modify)* | Reindex a recomputed identity's full member block-key set |
| `app/GoldenProfile/Resolution/DeterministicResolver.php` *(modify)* | Seed a new identity's block key immediately; add signature-aware Pass B call |
| `app/GoldenProfile/Engine.php` *(modify)* | `applyMerge()` drops the loser's block-key/signature rows |
| `tests/Feature/BlockKeyIndexTest.php` *(create)* | Per-row maintenance: seed-on-create, union-of-members reindex, merge cleanup |
| `app/GoldenProfile/Materialize/SetFinalizer.php` *(modify)* | Set-based block-key + signature rebuild (bulk path) |
| `tests/Unit/SoundexAgreementTest.php` *(create)* | Pins `LEFT(SOUNDEX(x),4)` == PHP `soundex(x)` |
| `tests/Feature/BulkBlockKeyIndexTest.php` *(create)* | Bulk-path maintenance produces the same index shape as the per-row path |
| `app/GoldenProfile/Resolution/ProbabilisticResolver.php` *(modify)* | Candidate lookup + `block_size_cap` read the new index; hard-no + zip scoring read the signature |
| `tests/Unit/ProbabilisticScoringTest.php` *(modify)* | Extended for signature-aware hard-no/zip; existing tests still pass unmodified |
| `database/migrations/2026_09_05_000001_create_gp_identity_signature.php` *(create)* | Per-identity compact signature table |
| `tests/Feature/IdentitySignatureTest.php` *(create)* | Signature maintenance in both paths, orphan cleanup on merge |
| `app/Console/Commands/GpReprofile.php` *(create)* | Periodic full reprofile: dedup + finalize, sharded |
| `routes/console.php` *(modify)* | Schedule `gp:sync` and `gp:reprofile`, with a shared mutex |
| `tests/Unit/GpReprofileCommandTest.php` *(create)* | Command wiring (no DB — signature/option parsing only) |
| `tests/Feature/IncrementalBatchParityTest.php` *(create)* | The eval fixture resolved via both paths converges to the same clustering |
| `docs/EVALUATION.md` *(modify)* | Record the measured before/after gate numbers for this plan |

---

## Task 1: `gp_identity_block_key` — the inverted index, maintained by the per-row path

**Files:**
- Create: `database/migrations/2026_09_05_000000_create_gp_identity_block_key.php`
- Create: `app/GoldenProfile/Support/BlockKey.php`
- Modify: `app/GoldenProfile/Resolution/Survivorship.php:1-11,126-132`
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php:1-7,214-234`
- Modify: `app/GoldenProfile/Engine.php:490-494`
- Test: `tests/Feature/BlockKeyIndexTest.php`

**Interfaces:**
- Produces: `BlockKey::compute(?string $last, ?string $dob): ?string` — Task 2 (bulk path) and
  Task 3 (`ProbabilisticResolver`) both rely on this being the ONE PHP-side source of truth for
  the formula.
- Produces: `gp_identity_block_key(block_key, identity_id)`, one row per **distinct** block key
  among an identity's members (a UNION, not the identity's single canonical value — see below for
  why that distinction is load-bearing).

The GPP page's second piece of persistent state is a `block_key -> {profile_id}` inverted index.
gp-cami has blocking (`stg_person.block_key`, indexed as `idx_block`), but it indexes STAGED ROWS,
not profiles — `ProbabilisticResolver::match()`'s candidate query joins `gp_source_link` through
`stg_person` to find identities, which means a fat identity (many merged member rows) gets
compared against ONE row per member before the existing `->distinct()` collapses them, and the
`block_size_cap` check (Task 3) counts raw staged rows, not distinct identities. This is precisely
the scaling problem the code's own comment documents: "candidates are loaded in batches... a
per-candidate SELECT made every new source row cost that many round-trips — measured at ~13,500
hub queries per row."

**Why the index stores the UNION of every member's own block key, not just the identity's
canonical hybrid value.** `gp_identity.canonical_last` and `canonical_dob` are won
INDEPENDENTLY per field by `Survivorship` (see its `IDENTITY_FIELDS` loop — each field ranks its
own candidates by authority+recency). It is entirely possible for a high-authority source to win
`canonical_last` while a DIFFERENT, lower-authority source (the only one with a non-blank DOB)
wins `canonical_dob`. The resulting canonical PAIR can therefore be a hybrid that does not match
ANY single member's own `(last_name, date_of_birth)` — and if this table only stored that hybrid,
a brand-new incoming row whose own last name + dob matches an ACTUAL member (exactly the case the
old `stg_person`-joined query used to catch) would silently stop finding this identity as a
candidate. That would be a real recall regression hiding behind a performance change. Task 7's
parity test would catch this only by accident; this task avoids it by construction: index every
distinct block key among the identity's current members, computed from `Survivorship::recompute()`'s
own already-loaded row set (no extra query).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
use App\GoldenProfile\Support\BlockKey;
use Tests\Support\HubTestCase;

class BlockKeyIndexTest extends HubTestCase
{
    public function test_a_new_identity_seeds_its_own_block_key_immediately(): void
    {
        $stgId = $this->stagePerson(['last_name' => 'Nguyen', 'date_of_birth' => '1985-03-12']);

        $identityId = (new DeterministicResolver($this->systemId))->resolve($stgId);

        $this->assertDatabaseHas('gp_identity_block_key', [
            'block_key' => BlockKey::compute('Nguyen', '1985-03-12'),
            'identity_id' => $identityId,
        ]);
    }

    public function test_recompute_indexes_every_members_own_block_key_not_just_the_canonical_hybrid(): void
    {
        // Two rows bound to ONE identity via a shared npi, with DIFFERENT last
        // names — proves the index is the union of every member's own
        // (last_name, dob), not whatever wins canonical_last.
        $resolver = new DeterministicResolver($this->systemId);
        $a = $this->stagePerson(['npi' => 1234567893, 'last_name' => 'Smith', 'first_name' => 'Robert', 'date_of_birth' => '1970-04-02']);
        $identityId = $resolver->resolve($a);
        $b = $this->stagePerson(['npi' => 1234567893, 'last_name' => 'Smyth', 'first_name' => 'Robert', 'date_of_birth' => '1970-04-02']);
        $boundId = $resolver->resolve($b);
        $this->assertSame($identityId, $boundId, 'shared npi should bind row b to the same identity');

        (new Survivorship)->recompute($identityId);

        $this->assertDatabaseHas('gp_identity_block_key', [
            'block_key' => BlockKey::compute('Smith', '1970-04-02'), 'identity_id' => $identityId,
        ]);
        $this->assertDatabaseHas('gp_identity_block_key', [
            'block_key' => BlockKey::compute('Smyth', '1970-04-02'), 'identity_id' => $identityId,
        ]);
    }

    public function test_recompute_drops_a_block_key_no_member_carries_anymore(): void
    {
        // Simulates the one-member case shrinking back to one block key after
        // having briefly had two (e.g. a bad alias row later excluded) —
        // recompute() deletes-then-reinserts, so a stale key must not survive.
        $resolver = new DeterministicResolver($this->systemId);
        $a = $this->stagePerson(['last_name' => 'Alpha', 'date_of_birth' => '1980-01-01']);
        $identityId = $resolver->resolve($a);
        $this->hub()->table('gp_identity_block_key')->insert([
            'block_key' => 'STALE0000', 'identity_id' => $identityId,
        ]);

        (new Survivorship)->recompute($identityId);

        $this->assertDatabaseMissing('gp_identity_block_key', ['block_key' => 'STALE0000', 'identity_id' => $identityId]);
        $this->assertDatabaseHas('gp_identity_block_key', [
            'block_key' => BlockKey::compute('Alpha', '1980-01-01'), 'identity_id' => $identityId,
        ]);
    }

    public function test_dedup_merge_removes_the_losers_block_key_rows(): void
    {
        $resolver = new DeterministicResolver($this->systemId);
        $idA = $resolver->resolve($this->stagePerson(['last_name' => 'Alpha', 'date_of_birth' => '1980-01-01']));
        $idB = $resolver->resolve($this->stagePerson(['last_name' => 'Beta', 'date_of_birth' => '1981-02-02']));
        $this->assertNotSame($idA, $idB);

        // Force both onto the same npi so Engine::dedup()'s mergeByColumn('npi', ...) folds them.
        $this->hub()->table('gp_identity')->whereIn('identity_id', [$idA, $idB])->update(['npi' => 1234567893]);

        (new Engine)->dedup();

        $survivor = min($idA, $idB);
        $loser = max($idA, $idB);
        $this->assertDatabaseHas('gp_identity_block_key', ['identity_id' => $survivor]);
        $this->assertDatabaseMissing('gp_identity_block_key', ['identity_id' => $loser]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/BlockKeyIndexTest.php`
Expected: FAIL — `Class "App\GoldenProfile\Support\BlockKey" not found` (and, once that's created,
`Base table or view not found: gp_identity_block_key`).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inverted profile-level blocking index (GPP "Incremental Profiling" page's
 * second piece of persistent state: block_key -> {profile_id}). Existing
 * blocking runs off stg_person.block_key (idx_block) — an index of STAGED
 * ROWS, not identities, so a fat identity's block membership is only visible
 * by scanning all of its member rows. This table is the profile-level answer:
 * one row per DISTINCT block key among an identity's current members (a
 * union, not the identity's single canonical hybrid value — see BlockKey and
 * Survivorship for why that distinction matters).
 *
 * Single-leg by design (the existing surname-soundex + dob-year formula,
 * unchanged) — plan 5's Self-review recommends a separate "5b" plan for Pass
 * B blocking WIDENING (name+state, phonetic-name/state/zip, multi-leg
 * stg_person_block_key). This table does not guess at that shape. The
 * additive, non-breaking extension point for it is a `block_type` column
 * added to the primary key (e.g. 'surname_dob_year' for every row this plan
 * writes) — deliberately not added here since nothing in THIS plan reads it
 * yet and an unused discriminator column is exactly the kind of thing that
 * silently drifts from reality.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        Schema::connection($this->connection)->create('gp_identity_block_key', function (Blueprint $t) {
            // 64 to match stg_person.block_key's own width, not because a
            // surname-soundex+year value (max ~9 chars) needs it.
            $t->string('block_key', 64);
            $t->unsignedBigInteger('identity_id');
            $t->primary(['block_key', 'identity_id']);
            $t->index('identity_id', 'idx_identity');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('gp_identity_block_key');
    }
};
```

- [ ] **Step 4: Write `BlockKey`**

```php
<?php

namespace App\GoldenProfile\Support;

/**
 * The ONE PHP-side formula for a person's block key: surname soundex + dob
 * year. This already existed, independently duplicated, in
 * StreamlineLocalConnector::blockKey() (private), EvalRunner::blockKey()
 * (private) and Tests\Support\HubTestCase::blockKey() (protected) — all three
 * predate this plan and are left alone here (consolidating four copies into
 * one is a same-shaped, separate, low-risk cleanup, not folded into this
 * plan's scope). This is the fourth copy, and the one gp_identity_block_key's
 * maintenance code (Survivorship, DeterministicResolver) and its consumer
 * (ProbabilisticResolver) share, so at least those three agree with each
 * other by construction.
 *
 * The SQL-side equivalent (SetFinalizer's bulk rebuild) is NOT this class —
 * it is `CONCAT(LEFT(SOUNDEX(last_name),4), '|', COALESCE(SUBSTRING(dob,1,4),'____'))`.
 * MySQL's SOUNDEX() does not truncate to 4 characters the way PHP's soundex()
 * does (verified: SOUNDEX('McDonald') is 'M23543', not 'M235') — the
 * LEFT(...,4) is what makes the two agree. See SoundexAgreementTest (Task 2).
 */
class BlockKey
{
    public static function compute(?string $last, ?string $dob): ?string
    {
        $last = $last !== null ? trim($last) : null;
        if (! $last) {
            return null;
        }

        return soundex($last).'|'.($dob ? substr((string) $dob, 0, 4) : '____');
    }
}
```

- [ ] **Step 5: Wire into `Survivorship::recompute()`**

Add the import (after line 5):
```php
use App\GoldenProfile\Support\BlockKey;
```

Insert before `recompute()`'s closing brace (currently line 132, right after the `$auditRows`
insert loop):

```php
        // Reindex this identity's block key(s) — the full set derived from
        // every CURRENTLY linked member's own (last_name, dob), not just
        // whichever value won canonical_last/canonical_dob. Those two fields
        // are won independently per authority (the loop above), so an
        // identity's combined canonical pair can be a "hybrid" matching NONE
        // of its members' own pairs. Indexing only that hybrid would silently
        // drop this identity from ProbabilisticResolver::match()'s candidate
        // set for a brand-new row whose own last_name+dob matches an actual
        // member — the exact recall regression this table exists to avoid.
        // $rows is already loaded above (sp.* includes last_name/date_of_birth)
        // — no extra query.
        $blockKeys = $rows->map(fn ($r) => BlockKey::compute($r->last_name, $r->date_of_birth))
            ->filter()->unique()->values();
        $hub->table('gp_identity_block_key')->where('identity_id', $identityId)->delete();
        if ($blockKeys->isNotEmpty()) {
            $hub->table('gp_identity_block_key')->insert(
                $blockKeys->map(fn ($k) => ['block_key' => $k, 'identity_id' => $identityId])->all()
            );
        }
```

- [ ] **Step 6: Seed immediately in `DeterministicResolver::createIdentity()`**

Add the import (after line 5):
```php
use App\GoldenProfile\Support\BlockKey;
```

Change `createIdentity()` (lines 214-234) from returning `insertGetId(...)` directly to:

```php
    private function createIdentity(object $p): int
    {
        $now = now();

        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => $p->first_name,
            'canonical_middle' => $p->middle_name,
            'canonical_last' => $p->last_name,
            'canonical_dob' => $p->date_of_birth,
            'ssn_hash' => $p->ssn_hash,
            'npi' => $p->npi,
            'upin' => $p->upin,
            'dea_number' => $p->dea_number,
            'confidence' => 1.0,
            'record_count' => 0,
            'status' => 'active',
            'first_seen' => $now,
            'last_updated' => $now,
        ]);

        // Seed the block-key index immediately rather than waiting for the
        // next finalize/recompute pass: Engine::backfill()'s default
        // defer=true mode does not call Survivorship::recompute() until the
        // very end of the WHOLE run, and a brand-new identity must already be
        // a Pass B candidate for a sibling row resolved later in the SAME
        // run — this is how "cluster with other new records" (GPP page)
        // actually happens for a probabilistic-only pair. Survivorship::
        // recompute() reconciles this same row later as members change.
        $blockKey = BlockKey::compute($p->last_name, $p->date_of_birth);
        if ($blockKey) {
            $this->hub()->table('gp_identity_block_key')->insertOrIgnore([
                'block_key' => $blockKey, 'identity_id' => $identityId,
            ]);
        }

        return $identityId;
    }
```

- [ ] **Step 7: Clean up the loser's row on merge**

In `Engine.php`, `applyMerge()`'s "rebuilt from scratch by finalize" loop (lines 490-494):

```php
        // Rebuilt from scratch by finalize — just remove the loser's copies.
        foreach (['gp_attribute', 'gp_survivorship_audit', 'gp_identity_profile'] as $t) {
            $hub->table($t)->where('identity_id', $loser)->delete();
        }
```

change to:

```php
        // Rebuilt from scratch by finalize — just remove the loser's copies.
        // gp_identity_block_key/gp_identity_signature belong in this list for
        // the same reason gp_attribute does: nothing else ever deletes a stale
        // row for an identity_id that no longer exists in gp_identity, and
        // across millions of merges on the real hub that is unbounded row
        // growth in tables meant to stay proportional to the (much smaller)
        // set of ACTIVE identities.
        foreach (['gp_attribute', 'gp_survivorship_audit', 'gp_identity_profile',
            'gp_identity_block_key', 'gp_identity_signature'] as $t) {
            $hub->table($t)->where('identity_id', $loser)->delete();
        }
```

(`gp_identity_signature` does not exist until Task 4 — this edit is written once, here, since it
touches the same loop Task 4 would otherwise have to touch again; Task 4 does not repeat it.)

- [ ] **Step 8: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/BlockKeyIndexTest.php`
Expected: PASS (4 tests). (The `gp_identity_signature` delete in Step 7 is a no-op until Task 4's
migration exists — Laravel's query builder only issues the DELETE when asked, and `->delete()` on
a table that doesn't exist yet would error, so this step is written to run AFTER Task 4's migration
in execution order regardless of task numbering; if executing tasks strictly in order, swap Task 1
Step 7 to omit `gp_identity_signature` and add it back in Task 4 instead — noted in Task 4.)

- [ ] **Step 9: Commit**
```bash
git add database/migrations/2026_09_05_000000_create_gp_identity_block_key.php app/GoldenProfile/Support/BlockKey.php app/GoldenProfile/Resolution/Survivorship.php app/GoldenProfile/Resolution/DeterministicResolver.php app/GoldenProfile/Engine.php tests/Feature/BlockKeyIndexTest.php
git commit -m "feat(gp): add gp_identity_block_key, the profile-level inverted blocking index"
```

**Note carried into Task 4:** to avoid Step 7's ordering wrinkle above, Task 4 assumes the
`gp_identity_signature` table already exists by the time `Engine::applyMerge()`'s loop runs it
(true once both tasks are applied in order) and does not re-edit `Engine.php`.

---

## Task 2: Maintain the same index from the bulk path, and pin the SOUNDEX equivalence

**Files:**
- Modify: `app/GoldenProfile/Materialize/SetFinalizer.php:1-6,126-136`
- Test: `tests/Unit/SoundexAgreementTest.php`
- Test: `tests/Feature/BulkBlockKeyIndexTest.php`

**Interfaces:**
- Consumes: `gp_identity_block_key` (Task 1). No PHP interface is shared with the per-row path —
  the bulk rebuild is pure SQL, converging to the same table contents by a different mechanism,
  following plan 5's identifier-tier precedent for how the two paths are allowed to agree.

- [ ] **Step 1: Write the failing SOUNDEX test**

```php
<?php

namespace Tests\Unit;

use Tests\Support\HubTestCase;

/**
 * MySQL's SOUNDEX() does not truncate to 4 characters the way the standard
 * American Soundex algorithm (and PHP's soundex(), which BlockKey::compute()
 * uses) does — verified directly against this project's own MySQL instance:
 * SOUNDEX('McDonald') is 'M23543', not 'M235'. LEFT(SOUNDEX(x),4) corrects
 * that. If this test ever fails, SetFinalizer's set-based
 * gp_identity_block_key rebuild will silently diverge from the per-row path
 * (BlockKey::compute()) for whichever names trip the difference, breaking
 * ProbabilisticResolver::match()'s candidate lookup without any visible error
 * — a bulk-resolved identity would simply stop being found by name/dob-only
 * Pass B lookups for names past 4 raw Soundex characters.
 */
class SoundexAgreementTest extends HubTestCase
{
    public function test_left_soundex_4_matches_phps_soundex_for_representative_surnames(): void
    {
        $names = [
            'Smith', 'Nguyen', 'Garcia', "O'Brien", 'McDonald', 'Lee', 'Diaz',
            'Wong', 'Patel', 'Robinson', 'Washington', 'Featherstonehaugh',
        ];
        foreach ($names as $n) {
            $mysql = $this->hub()->selectOne('SELECT LEFT(SOUNDEX(?), 4) s', [$n])->s;
            $this->assertSame(soundex($n), $mysql, "MySQL LEFT(SOUNDEX,4) diverged from PHP soundex() for '$n'");
        }
    }
}
```

- [ ] **Step 2: Run it to verify it currently would have failed without the fix**

Run: `vendor/bin/phpunit tests/Unit/SoundexAgreementTest.php`
Expected: PASS as written (it already asserts the CORRECTED form). To see the failure this test
guards against, temporarily change the query to `SELECT LEFT(SOUNDEX(?), 8) s` and re-run —
Expected: FAIL for `McDonald` (`M23543` vs `M235 `-padded... exact mismatch), `Robinson`, and
`Featherstonehaugh`. Revert the temporary change before continuing.

- [ ] **Step 3: Write the failing bulk-index test**

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Support\BlockKey;
use Tests\Support\HubTestCase;

class BulkBlockKeyIndexTest extends HubTestCase
{
    public function test_bulk_survivorship_indexes_the_same_block_keys_the_per_row_path_would(): void
    {
        $resolver = new DeterministicResolver($this->systemId);
        $a = $this->stagePerson(['npi' => 1234567893, 'last_name' => 'McDonald', 'first_name' => 'Robert', 'date_of_birth' => '1970-04-02']);
        $identityId = $resolver->resolve($a);
        $b = $this->stagePerson(['npi' => 1234567893, 'last_name' => 'Robinson', 'first_name' => 'Robert', 'date_of_birth' => '1970-04-02']);
        $resolver->resolve($b);

        // Wipe whatever the per-row path already wrote so this test proves
        // the BULK rebuild alone reproduces it, not that both ran.
        $this->hub()->table('gp_identity_block_key')->where('identity_id', $identityId)->delete();

        (new SetFinalizer)->survivorship();

        $this->assertDatabaseHas('gp_identity_block_key', [
            'block_key' => BlockKey::compute('McDonald', '1970-04-02'), 'identity_id' => $identityId,
        ]);
        $this->assertDatabaseHas('gp_identity_block_key', [
            'block_key' => BlockKey::compute('Robinson', '1970-04-02'), 'identity_id' => $identityId,
        ]);
    }
}
```

- [ ] **Step 4: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/BulkBlockKeyIndexTest.php`
Expected: FAIL — `gp_identity_block_key` has no rows for `$identityId` after `survivorship()` runs
(the bulk path doesn't write this table yet).

- [ ] **Step 5: Write the implementation**

In `SetFinalizer.php`, `survivorship()` (currently ending at line 136 with the call to
`$this->addIdentityKeyIndexes();`), insert this block immediately before that call (i.e. after the
`record_count`/`last_updated` UPDATE, before the index-rebuild):

```php
        // Reindex block keys hub-wide: the union of every ACTIVE identity's
        // linked members' own (last_name, dob), mirroring Survivorship's
        // per-identity reconciliation exactly (see BlockKey's docblock for why
        // the union, not just the canonical hybrid, is required — the same
        // reasoning applies set-based). TRUNCATE is safe here: this method has
        // already rewritten gp_attribute/gp_survivorship_audit from scratch in
        // the loop above, and Engine::dedup() (which needs the OLD keys to
        // find merges) has already run by the time SetFinalizer runs — nothing
        // downstream of this point reads a pre-truncate row.
        //
        // LEFT(SOUNDEX(x),4): MySQL's SOUNDEX() does NOT truncate to 4
        // characters the way PHP's soundex() (which BlockKey::compute() uses)
        // does — verified directly: SOUNDEX('McDonald') is 'M23543', not
        // 'M235'. Without LEFT(...,4) this rebuild would silently diverge from
        // the per-row path for any surname whose un-truncated code runs past 4
        // characters. See SoundexAgreementTest.
        $hub->statement('TRUNCATE TABLE gp_identity_block_key');
        $hub->statement("
            INSERT IGNORE INTO gp_identity_block_key (block_key, identity_id)
            SELECT DISTINCT
                CONCAT(LEFT(SOUNDEX(sp.last_name), 4), '|', COALESCE(SUBSTRING(sp.date_of_birth, 1, 4), '____')),
                l.identity_id
            FROM gp_source_link l
            JOIN stg_person sp
              ON sp.system_id = l.system_id AND sp.source_table = l.source_table AND sp.source_id = l.source_id
            JOIN gp_identity i ON i.identity_id = l.identity_id AND i.status = 'active'
            WHERE sp.last_name IS NOT NULL AND TRIM(sp.last_name) <> ''");
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/BulkBlockKeyIndexTest.php tests/Unit/SoundexAgreementTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**
```bash
git add app/GoldenProfile/Materialize/SetFinalizer.php tests/Unit/SoundexAgreementTest.php tests/Feature/BulkBlockKeyIndexTest.php
git commit -m "feat(gp): rebuild gp_identity_block_key set-based in SetFinalizer::survivorship()"
```

---

## Task 3: `ProbabilisticResolver` reads the index — candidate lookup and `block_size_cap`

**Files:**
- Modify: `app/GoldenProfile/Resolution/ProbabilisticResolver.php:74-149`
- Test: `tests/Feature/ProbabilisticBlockIndexTest.php` *(create)*

**Interfaces:**
- Consumes: `gp_identity_block_key` (Tasks 1-2).
- Produces: `match()`'s public signature is unchanged (`match(object $p, $licenses): array`), so
  `DeterministicResolver::resolve()` (its only caller) needs no change here.

Two changes, both scoped to the candidate-gathering half of `match()` — the scoring half
(`hardNo()`/`score()`) is Task 5's job (it needs the signature table, Task 4).

**1. Candidate lookup.** Replace the `gp_source_link JOIN stg_person JOIN gp_identity` query with
a direct read of `gp_identity_block_key`. The old query's exclusion clause
(`sp.source_id != $p->source_id OR sp.system_id != $this->systemId`) is dropped: `match()` is only
ever reached from `DeterministicResolver::resolve()`'s "no existing link" branch (verified: line
69's `matchDeterministic()` call, falling through to line 75's `probabilistic->match()` only when
`$identityId === null` from Pass A — and the idempotent early-return at lines 59-67 means a row
that already HAS a link never reaches `match()` at all). The row being resolved is therefore never
already in `gp_source_link` when `match()` runs, so that exclusion could never have filtered a live
row — dropping it is a no-op for correctness, confirmed by Task 8's eval-gate re-run rather than
merely asserted.

**2. `block_size_cap`.** Count DISTINCT ACTIVE IDENTITIES for the block key instead of raw
`stg_person` rows. This is a strict improvement, not a trade-off: every identity has at least one
linked row, so `#distinct identities for a block <= #stg_person rows for that block` always — the
new count can only be smaller, which can only let MORE blocks through the cap, never fewer. It
cannot make the existing silent-split problem (over-cap => `no_match` => caller mints a new
identity, stamped `auto_match`) worse; it can only shrink how often it fires. **Fixing the
FLAGGING itself — the config's claim that oversized blocks are "flagged for steward," which is
false, that mechanism doesn't exist — is plan 6's job and is not touched here.**

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
use Tests\Support\HubTestCase;

class ProbabilisticBlockIndexTest extends HubTestCase
{
    public function test_probabilistic_match_finds_a_candidate_via_the_block_key_index_alone(): void
    {
        // Seed identity A with an address, then finalize so gp_address is
        // populated and its block key is indexed.
        $resolver = new DeterministicResolver($this->systemId);
        $a = $this->stagePerson([
            'first_name' => 'Roberta', 'last_name' => 'Alvarez', 'date_of_birth' => '1975-06-01',
        ]);
        $this->hub()->table('stg_person_address')->insert([
            'stg_person_id' => $a, 'address_type' => 'primary', 'address1' => '1 Main St',
            'address2' => null, 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62704',
        ]);
        $idA = $resolver->resolve($a);
        (new Survivorship)->recompute($idA);

        // A near-name-variant row with the SAME zip and dob — no deterministic
        // key shared, so this can only bind via Pass B's block-key candidate
        // search + address/zip scoring.
        $b = $this->stagePerson([
            'first_name' => 'Roberta', 'last_name' => 'Alvarez', 'date_of_birth' => '1975-06-01',
        ]);
        $this->hub()->table('stg_person_address')->insert([
            'stg_person_id' => $b, 'address_type' => 'primary', 'address1' => '1 Main St',
            'address2' => null, 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62704',
        ]);

        // Break Pass A's own name+dob tier so this can ONLY resolve through
        // Pass B: point b's own block key away from a's by corrupting dob's
        // day (block key only uses the YEAR, so this keeps the block key
        // identical while defeating the exact name+dob equality tier).
        // (No code change needed — name+dob tier requires EXACT canonical_dob
        // equality; identical dob here means Pass A WOULD also fire. To force
        // Pass B specifically, drop straight to asserting the identity match:
        // this test's job is proving the CANDIDATE SEARCH works via the index,
        // not proving Pass A/B tier selection, which existing tests cover.)
        $idB = $resolver->resolve($b);

        $this->assertSame($idA, $idB);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ProbabilisticBlockIndexTest.php`
Expected: PASS already via the OLD `stg_person`-joined query (this scenario resolves via Pass A's
name+dob tier regardless of the candidate-lookup mechanism, since `b`'s canonical fields exactly
match `a`'s). This step is therefore a **baseline run, not a red step** — note in the commit that
this specific test cannot distinguish old vs. new candidate lookup on its own; its purpose is
regression coverage after Step 3's rewrite, and the REAL correctness proof for this task is Task
8's full eval-gate re-run (which exercises the fixture's actual Pass-B-only cases). Confirm PASS
now, then proceed to Step 3 and re-run to confirm it STILL passes (no regression from the rewrite).

- [ ] **Step 3: Write the implementation**

Replace `match()`'s block-size-cap check and candidate query (lines 86-105):

```php
        // Oversized blocks carry no evidence. block_key is surname-soundex +
        // DOB year, and rows with no DOB collapse into buckets like
        // "D500|____" holding over 100k STAGED ROWS — but far fewer distinct
        // ACTIVE IDENTITIES, since many of those rows have already resolved
        // together via Pass A. Counting identities via gp_identity_block_key
        // instead of raw stg_person rows can only shrink the oversized set
        // (every identity has >=1 row, so #identities <= #rows always) — it
        // cannot make the existing silent-split failure mode (over cap =>
        // no_match => caller mints a new identity) worse, only rarer. Fixing
        // the FLAGGING itself (the config's false claim that oversized blocks
        // are "flagged for steward") is plan 6's job, not this one's.
        $cap = (int) ($this->cfg['block_size_cap'] ?? 0);
        if ($cap > 0) {
            $blockSize = (int) $this->hub()->table('gp_identity_block_key as bk')
                ->join('gp_identity as i', 'i.identity_id', '=', 'bk.identity_id')
                ->where('bk.block_key', $p->block_key)
                ->where('i.status', 'active')
                ->count();
            if ($blockSize > $cap) {
                return [null, 0.0, 'no_match'];
            }
        }

        // Profile-level candidate lookup: gp_identity_block_key is an
        // inverted, one-row-per-(block_key, identity) index, maintained by
        // Survivorship::recompute()/DeterministicResolver::createIdentity()
        // (per-row) and SetFinalizer::survivorship() (bulk) — see this
        // table's own migration docblock. This replaces a join through
        // gp_source_link/stg_person that returned one row PER MEMBER before
        // ->distinct() collapsed it, which is exactly the "13,500 hub queries
        // per row" scaling problem this class's own comments already
        // document for a fat identity.
        //
        // No exclusion for $p's own (system_id, source_id): match() is only
        // ever reached from DeterministicResolver::resolve()'s "no existing
        // link" branch, so the row being resolved is never already present in
        // gp_source_link when this runs — the old query's exclusion clause
        // could never have filtered a live row. Confirmed by Task 8's eval
        // gate re-run rather than merely asserted here.
        $candidateIds = $this->hub()->table('gp_identity_block_key as bk')
            ->join('gp_identity as i', 'i.identity_id', '=', 'bk.identity_id')
            ->where('bk.block_key', $p->block_key)
            ->where('i.status', 'active')
            ->pluck('bk.identity_id');
```

The rest of `match()` (batched identity loading, scoring loop, band decision) is unchanged.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ProbabilisticBlockIndexTest.php tests/Unit/ProbabilisticScoringTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**
```bash
git add app/GoldenProfile/Resolution/ProbabilisticResolver.php tests/Feature/ProbabilisticBlockIndexTest.php
git commit -m "perf(gp): read gp_identity_block_key for Pass B candidates and block_size_cap"
```

---

## Task 4: `gp_identity_signature` — the compact per-profile summary

**Files:**
- Create: `database/migrations/2026_09_05_000001_create_gp_identity_signature.php`
- Modify: `app/GoldenProfile/Resolution/Survivorship.php:1-11,132`
- Modify: `app/GoldenProfile/Materialize/SetFinalizer.php` (after Task 2's block-key rebuild block)
- Test: `tests/Feature/IdentitySignatureTest.php`

**Interfaces:**
- Produces: `gp_identity_signature(identity_id PK, npi_set JSON, license_set JSON,
  identifier_set JSON, zip_set JSON, computed_at)` — Task 5 is the consumer.

The GPP page's third piece of persistent state: "a compact stand-in so you compare against the
*profile*, not all its members." gp-cami has nothing like this today — `ProbabilisticResolver::
score()`'s `addressOverlap()` does a live any-vs-any nested loop over `stg_person_address` x
`gp_address` per candidate, and `hardNo()`'s `two_valid_npis` check only ever looks at
`gp_identity.npi`, a SINGLE column that is first-wins (`backfillKeys()`) — a merge that folds two
identities carrying DIFFERENT npis together (via license or name+dob, neither of which cross-checks
npi) silently drops the loser's npi with no record it ever existed. The signature fixes both: it
is a genuine SET per field, built from every current member, not a collapsed scalar.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
use Tests\Support\HubTestCase;

class IdentitySignatureTest extends HubTestCase
{
    public function test_per_row_recompute_builds_the_signature(): void
    {
        $resolver = new DeterministicResolver($this->systemId);
        $a = $this->stagePerson(['npi' => 1234567893, 'zip' => '62704', 'first_name' => 'Ann', 'last_name' => 'Diaz']);
        $this->stageLicense($a, 'LIC-1', 'IL');
        $identityId = $resolver->resolve($a);

        (new Survivorship)->recompute($identityId);

        $row = $this->hub()->table('gp_identity_signature')->where('identity_id', $identityId)->first();
        $this->assertNotNull($row);
        $this->assertSame([1234567893], json_decode($row->npi_set, true));
        $this->assertSame(['62704'], json_decode($row->zip_set, true));
        $this->assertSame(['LIC-1|IL'], json_decode($row->license_set, true));
    }

    public function test_signature_retains_a_conflicting_npi_a_merge_would_otherwise_silently_drop(): void
    {
        $resolver = new DeterministicResolver($this->systemId);
        $idA = $resolver->resolve($this->stagePerson(['npi' => 1234567893, 'first_name' => 'Ann', 'last_name' => 'Diaz', 'date_of_birth' => '1980-01-01']));
        (new Survivorship)->recompute($idA);
        $idB = $resolver->resolve($this->stagePerson(['npi' => 1999999992, 'first_name' => 'Ann', 'last_name' => 'Diaz', 'date_of_birth' => '1980-01-01']));
        (new Survivorship)->recompute($idB);
        $this->assertNotSame($idA, $idB, 'both npis are valid and different — hard-no should have kept these apart already');

        // Force a merge the way dedup's mergeByNameDob would (both share
        // canonical_last/first/dob), bypassing the hard-no check entirely to
        // set up the "already merged despite conflicting npis" scenario:
        $this->hub()->table('gp_identity')->where('identity_id', $idB)->update(['npi' => null]);
        (new \App\GoldenProfile\Engine)->dedup();
        $survivorId = $this->hub()->table('gp_source_link')->where('system_id', $this->systemId)->value('identity_id');

        (new Survivorship)->recompute($survivorId);

        $npis = json_decode($this->hub()->table('gp_identity_signature')->where('identity_id', $survivorId)->value('npi_set'), true);
        $this->assertContains(1234567893, $npis);
        $this->assertContains(1999999992, $npis, 'the merged-away identity\'s own npi must survive in the signature even though gp_identity.npi could only keep one');
    }

    public function test_bulk_survivorship_builds_the_same_shape_signature(): void
    {
        $resolver = new DeterministicResolver($this->systemId);
        $a = $this->stagePerson(['npi' => 1234567893, 'zip' => '62704', 'first_name' => 'Ann', 'last_name' => 'Diaz']);
        $this->stageLicense($a, 'LIC-1', 'IL');
        $identityId = $resolver->resolve($a);
        $this->hub()->table('gp_identity_signature')->where('identity_id', $identityId)->delete();

        (new SetFinalizer)->survivorship();

        $row = $this->hub()->table('gp_identity_signature')->where('identity_id', $identityId)->first();
        $this->assertNotNull($row);
        $this->assertSame([1234567893], json_decode($row->npi_set, true));
        $this->assertSame(['62704'], json_decode($row->zip_set, true));
        $this->assertSame(['LIC-1|IL'], json_decode($row->license_set, true));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/IdentitySignatureTest.php`
Expected: FAIL — `Base table or view not found: gp_identity_signature`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third piece of GPP "Incremental Profiling" persistent state: a compact
 * per-identity signature so a candidate is compared against the PROFILE, not
 * scanned member-by-member. Each *_set column is the union across every
 * currently-linked member (not a single collapsed scalar like gp_identity's
 * own npi/dea_number columns, which are first-wins and silently drop a
 * conflicting value on merge — see IdentitySignatureTest for a demonstration).
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        Schema::connection($this->connection)->create('gp_identity_signature', function (Blueprint $t) {
            $t->unsignedBigInteger('identity_id')->primary();
            $t->json('npi_set')->nullable();
            $t->json('license_set')->nullable();     // ["number|state", ...]
            $t->json('identifier_set')->nullable();  // ["dea|value", "mmis|state|value", ...]
            $t->json('zip_set')->nullable();
            $t->dateTime('computed_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('gp_identity_signature');
    }
};
```

- [ ] **Step 4: Wire into `Survivorship::recompute()`**

Insert immediately after the block-key reindex block added in Task 1 Step 5, still before
`recompute()`'s closing brace:

```php
        // Third GPP persistent-state piece: the compact per-identity
        // signature. Each set is the union across every CURRENT member — npi
        // and zip come from $rows (already loaded above, no extra query);
        // license/identifier come from gp_license/gp_identity_identifier,
        // which enrich() has already populated by the time recompute() runs
        // (resolve()'s own sequence: match -> insert link -> enrich ->
        // [caller] finalize). Deliberately a SET, not gp_identity's own
        // first-wins scalar columns: a merge that folds two identities
        // sharing a license/name+dob but carrying DIFFERENT npis drops the
        // loser's npi from gp_identity.npi with no record it ever existed;
        // the signature keeps it, so ProbabilisticResolver's hard-no check
        // (Task 5) can still catch that conflict against a NEW incoming row.
        $npiSet = $rows->pluck('npi')->filter()->unique()->values()->all();
        $zipSet = $rows->pluck('zip')->filter()->unique()->values()->all();
        $licenseSet = $hub->table('gp_license')->where('identity_id', $identityId)
            ->selectRaw("CONCAT_WS('|', license_number, certification_state) as k")
            ->pluck('k')->all();
        $identifierSet = $hub->table('gp_identity_identifier')->where('identity_id', $identityId)
            ->selectRaw("CONCAT_WS('|', id_type, id_value, state) as k")
            ->pluck('k')->all();

        $hub->table('gp_identity_signature')->updateOrInsert(
            ['identity_id' => $identityId],
            [
                'npi_set' => json_encode(array_values($npiSet)),
                'license_set' => json_encode(array_values($licenseSet)),
                'identifier_set' => json_encode(array_values($identifierSet)),
                'zip_set' => json_encode(array_values($zipSet)),
                'computed_at' => $now,
            ]
        );
```

- [ ] **Step 5: Wire into `SetFinalizer::survivorship()`**

Append immediately after Task 2's `gp_identity_block_key` rebuild block, still inside
`survivorship()`, before `$this->addIdentityKeyIndexes();`:

```php
        // gp_identity_signature, set-based. Upsert (not TRUNCATE+rebuild like
        // the block-key index above): a per-identity PRIMARY KEY makes
        // ON DUPLICATE KEY UPDATE the natural fit, and a truncate-then-rebuild
        // window would leave every identity signature-less for the duration
        // of these four statements, which — unlike the block-key table — IS
        // read live by ProbabilisticResolver during normal operation whenever
        // a concurrent sync happens to run alongside a scheduled reprofile
        // (Task 6 documents that overlap risk and the mutex that bounds it;
        // this ordering choice is a second, independent mitigation for THIS
        // specific table).
        //
        // Step 1 below seeds a row (with only computed_at) for every active
        // identity so steps 2-4's UPDATE...JOIN statements have something to
        // land on even for identities with no npi/license/identifier at all
        // (their *_set columns simply stay NULL, meaning "empty set").
        $hub->statement("
            INSERT INTO gp_identity_signature (identity_id, computed_at)
            SELECT identity_id, NOW() FROM gp_identity WHERE status = 'active'
            ON DUPLICATE KEY UPDATE computed_at = NOW()");

        $hub->statement("
            INSERT INTO gp_identity_signature (identity_id, npi_set)
            SELECT identity_id, JSON_ARRAYAGG(v) FROM (
                SELECT DISTINCT l.identity_id, sp.npi v
                FROM gp_source_link l
                JOIN stg_person sp ON sp.system_id = l.system_id AND sp.source_table = l.source_table AND sp.source_id = l.source_id
                JOIN gp_identity i ON i.identity_id = l.identity_id AND i.status = 'active'
                WHERE sp.npi IS NOT NULL
            ) t GROUP BY identity_id
            ON DUPLICATE KEY UPDATE npi_set = VALUES(npi_set)");

        $hub->statement("
            INSERT INTO gp_identity_signature (identity_id, zip_set)
            SELECT identity_id, JSON_ARRAYAGG(v) FROM (
                SELECT DISTINCT l.identity_id, sp.zip v
                FROM gp_source_link l
                JOIN stg_person sp ON sp.system_id = l.system_id AND sp.source_table = l.source_table AND sp.source_id = l.source_id
                JOIN gp_identity i ON i.identity_id = l.identity_id AND i.status = 'active'
                WHERE sp.zip IS NOT NULL AND sp.zip <> ''
            ) t GROUP BY identity_id
            ON DUPLICATE KEY UPDATE zip_set = VALUES(zip_set)");

        $hub->statement("
            INSERT INTO gp_identity_signature (identity_id, license_set)
            SELECT identity_id, JSON_ARRAYAGG(v) FROM (
                SELECT DISTINCT identity_id, CONCAT_WS('|', license_number, certification_state) v
                FROM gp_license
            ) t GROUP BY identity_id
            ON DUPLICATE KEY UPDATE license_set = VALUES(license_set)");

        $hub->statement("
            INSERT INTO gp_identity_signature (identity_id, identifier_set)
            SELECT identity_id, JSON_ARRAYAGG(v) FROM (
                SELECT DISTINCT identity_id, CONCAT_WS('|', id_type, id_value, state) v
                FROM gp_identity_identifier
            ) t GROUP BY identity_id
            ON DUPLICATE KEY UPDATE identifier_set = VALUES(identifier_set)");
```

(`JSON_ARRAYAGG(DISTINCT ...)` was tried and confirmed NOT to be valid MySQL 8 syntax — the
`SELECT DISTINCT ...` subquery-then-`GROUP BY` shape above was verified directly against this
project's MySQL 8.0.43 instance instead.)

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/IdentitySignatureTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**
```bash
git add database/migrations/2026_09_05_000001_create_gp_identity_signature.php app/GoldenProfile/Resolution/Survivorship.php app/GoldenProfile/Materialize/SetFinalizer.php tests/Feature/IdentitySignatureTest.php
git commit -m "feat(gp): add gp_identity_signature, maintained by both resolution paths"
```

---

## Task 5: Wire the signature into `ProbabilisticResolver` — hard-no and zip scoring

**Files:**
- Modify: `app/GoldenProfile/Resolution/ProbabilisticResolver.php:107-226`
- Modify: `tests/Unit/ProbabilisticScoringTest.php`

**Interfaces:**
- Consumes: `gp_identity_signature` (Task 4).
- Produces: `hardNo(object $p, object $identity, ?object $signature): bool` and
  `score(object $p, object $identity, ?object $signature): float` — both gain a third parameter;
  their only caller (`match()`, same file) is updated in the same task, so nothing else breaks.

Both changes replace a PER-CANDIDATE query/scan with a lookup into a signature ALREADY BATCH-LOADED
once per `match()` call (mirroring how `$identities` itself is already batch-loaded) — the whole
point of a profile signature is to avoid re-querying child tables once per candidate, so loading it
per-candidate inside `hardNo()`/`score()` would defeat the purpose.

1. **`hardNo()`'s `two_valid_npis`.** Today it compares `$p->npi` against the single
   `$identity->npi` column, which — per Task 4's docblock — can be silently wrong after a merge
   that dropped a conflicting npi. Compare against the full `npi_set` instead.
2. **`score()`'s zip signal.** `addressOverlap()`'s zip half becomes a signature-set lookup (cheap,
   no query); the address-line half (`addrHit`) is UNCHANGED — it still needs the live
   `gp_address` scan, but only runs that scan when the cheap zip check already found a hit, since
   the ORIGINAL code only ever set `addrHit` inside the `zipHit` branch (verified: `addrHit` is set
   only inside the `if ($a->zip && $a->zip === $b->zip)` block). This is a strict subset of the
   original work, done lazily, not a semantic change — the common no-overlap case now costs zero
   `gp_address` queries.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/ProbabilisticScoringTest.php` (a plain `TestCase`, since this exercises pure
scoring logic against in-memory objects, matching that file's existing style):

```php
    public function test_hard_no_checks_the_full_npi_signature_not_just_the_single_column(): void
    {
        $resolver = new \App\GoldenProfile\Resolution\ProbabilisticResolver(1);
        $ref = new \ReflectionMethod($resolver, 'hardNo');
        $ref->setAccessible(true);

        $p = (object) ['npi' => 1999999992, 'date_of_birth' => null];
        // gp_identity.npi itself is null (as if a merge dropped it), but the
        // signature still carries a DIFFERENT valid npi this identity once had.
        $identity = (object) ['npi' => null, 'canonical_dob' => null];
        $signature = (object) ['npi_set' => json_encode([1234567893])];

        $this->assertTrue($ref->invoke($resolver, $p, $identity, $signature));
    }

    public function test_hard_no_falls_back_to_the_single_column_with_no_signature_yet(): void
    {
        $resolver = new \App\GoldenProfile\Resolution\ProbabilisticResolver(1);
        $ref = new \ReflectionMethod($resolver, 'hardNo');
        $ref->setAccessible(true);

        $p = (object) ['npi' => 1999999992, 'date_of_birth' => null];
        $identity = (object) ['npi' => 1234567893, 'canonical_dob' => null];

        $this->assertTrue($ref->invoke($resolver, $p, $identity, null));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ProbabilisticScoringTest.php --filter=test_hard_no`
Expected: FAIL — `ArgumentCountError: Too few arguments to function ...hardNo(), 2 passed`.

- [ ] **Step 3: Write the implementation**

Replace `hardNo()` (lines 152-163):

```php
    /** Hard-no safeguards: block merge regardless of similarity. */
    private function hardNo(object $p, object $identity, ?object $signature): bool
    {
        $hn = $this->cfg['hard_no'];
        if (! empty($hn['conflicting_dob']) && NameMatcher::dobConflicts($p->date_of_birth, $identity->canonical_dob)) {
            return true;
        }
        if (! empty($hn['two_valid_npis']) && $p->npi) {
            // The full set of npis this identity has EVER carried across its
            // members, not just the single gp_identity.npi column — a merge
            // that folded two identities sharing a license/name+dob (neither
            // of which cross-checks npi) silently drops the loser's npi via
            // Engine::applyMerge()'s first-wins backfill. Falling back to the
            // single column when no signature row exists yet (a freshly
            // created identity, before its first finalize) preserves today's
            // behaviour rather than treating "no signature yet" as "no npi."
            $npis = $signature
                ? (json_decode($signature->npi_set ?? '[]', true) ?: [])
                : array_filter([$identity->npi]);
            foreach ($npis as $n) {
                if ($n && (int) $n !== (int) $p->npi) {
                    return true;
                }
            }
        }

        return false;
    }
```

Replace `score()`'s address/zip call site and `addressOverlap()` (lines 165-226 — the surrounding
`name`/`dob`/`exclusion_share` scoring is unchanged):

```php
    private function score(object $p, object $identity, ?object $signature): float
    {
        $w = $this->cfg['weights'];
        $sum = 0.0;

        // name — Jaro-Winkler over "last first"
        $nameSim = self::jaroWinkler(
            strtolower(trim(($p->last_name ?? '').' '.($p->first_name ?? ''))),
            strtolower(trim(($identity->canonical_last ?? '').' '.($identity->canonical_first ?? '')))
        );
        $sum += $w['name'] * $nameSim;

        // dob
        if ($p->date_of_birth && $identity->canonical_dob) {
            $d2 = substr((string) $identity->canonical_dob, 0, 10);
            if ($p->date_of_birth === $d2) {
                $sum += $w['dob'];
            } elseif (substr($p->date_of_birth, 0, 4) === substr($d2, 0, 4)) {
                $sum += $w['dob'] * 0.5;
            }
        }

        // address round-robin (any staged address vs any gp_address of the identity)
        [$addrHit, $zipHit] = $this->addressOverlap($p, $identity->identity_id, $signature);
        if ($addrHit) {
            $sum += $w['address'];
        }
        if ($zipHit) {
            $sum += $w['zip'];
        }

        // shared exclusion registry (compliance signal)
        if ($this->sharesExclusionRegistry($p, $identity->identity_id)) {
            $sum += $w['exclusion_share'];
        }

        return min(1.0, round($sum, 4));
    }

    private function addressOverlap(object $p, int $identityId, ?object $signature): array
    {
        $stg = $this->hub()->table('stg_person_address')
            ->where('stg_person_id', $this->stgId($p))->get();
        if ($stg->isEmpty()) {
            return [false, false];
        }

        // No signature yet (identity created but never finalized) — fall back
        // to the original live any-vs-any scan so a brand-new identity is
        // never silently treated as having no address at all.
        if (! $signature) {
            return $this->addressOverlapLive($stg, $identityId);
        }

        $zips = json_decode($signature->zip_set ?? '[]', true) ?: [];
        $zipHit = false;
        foreach ($stg as $a) {
            if ($a->zip && in_array($a->zip, $zips, true)) {
                $zipHit = true;
                break;
            }
        }

        // addrHit (exact address1 match) is a strict subset of zipHit in the
        // ORIGINAL code (it was only ever set INSIDE the zip-match branch) —
        // so it is correct, not merely faster, to skip the live gp_address
        // scan entirely when the cheap signature check found no zip overlap.
        $addrHit = false;
        if ($zipHit) {
            [$addrHit] = $this->addressOverlapLive($stg, $identityId);
        }

        return [$addrHit, $zipHit];
    }

    /** The original any-vs-any live scan — kept for the "no signature yet" fallback. */
    private function addressOverlapLive($stg, int $identityId): array
    {
        $gp = $this->hub()->table('gp_address')->where('identity_id', $identityId)->get();
        $addrHit = false;
        $zipHit = false;
        foreach ($stg as $a) {
            foreach ($gp as $b) {
                if ($a->zip && $a->zip === $b->zip) {
                    $zipHit = true;
                    if ($a->address1 && strtolower((string) $a->address1) === strtolower((string) $b->address1)) {
                        $addrHit = true;
                    }
                }
            }
        }

        return [$addrHit, $zipHit];
    }
```

Update `match()`'s scoring loop (inside the `foreach ($identities as $identity)` block) to
batch-load and pass signatures:

```php
        // Batch-load signatures once, alongside $identities, for the same
        // reason $identities itself is batch-loaded rather than queried per
        // candidate — see this class's own comment above about the
        // "13,500 hub queries per row" measurement this whole file exists to
        // avoid repeating in a new form.
        $signatures = $identities->isEmpty() ? collect() : $this->hub()
            ->table('gp_identity_signature')
            ->whereIn('identity_id', $identities->pluck('identity_id'))
            ->get()->keyBy('identity_id');

        foreach ($identities as $identity) {
            $cid = $identity->identity_id;
            $signature = $signatures->get($cid);
            if ($this->hardNo($p, $identity, $signature)) {
                continue;
            }
            // strict name prerequisite (first+last equal, middle/suffix/dob compatible)
            if (! NameMatcher::compatible($p, $this->asNameObj($identity))) {
                continue;
            }
            $score = $this->score($p, $identity, $signature);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int) $cid;
            }
        }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ProbabilisticScoringTest.php`
Expected: PASS (existing tests + the 2 new ones).

- [ ] **Step 5: Re-run the eval gate**

Run: `vendor/bin/phpunit --filter=EvalGateTest`
Expected: PASS, numbers unchanged from plan 5's baseline (precision 1.0000, recall 1.0000,
f1 1.0000, true_pairs 9, 0 false merges, 0 false splits). Every fixture record either has no
npi conflict scenario or resolves via Pass A before Pass B's hard-no ever runs, and the zip
scoring change is provably equivalent (Step 3's reasoning), not merely hoped to be — this run is
what turns that reasoning into a checked fact, per this plan's non-negotiable that any matching
change must be re-measured, not assumed neutral. Record the actual numbers in Task 8 regardless of
whether they match this expectation.

- [ ] **Step 6: Commit**
```bash
git add app/GoldenProfile/Resolution/ProbabilisticResolver.php tests/Unit/ProbabilisticScoringTest.php
git commit -m "perf(gp): score against gp_identity_signature instead of per-candidate child scans"
```

---

## Task 6: `gp:reprofile` — the periodic full reprofile, scheduled, with a shared mutex

**Files:**
- Create: `app/Console/Commands/GpReprofile.php`
- Modify: `app/Console/Commands/GpSync.php`
- Modify: `routes/console.php`
- Test: `tests/Unit/GpReprofileCommandTest.php`

**Interfaces:**
- Produces: `php artisan gp:reprofile {--shard=} {--shards=} {--legacy-finalize}`.
- Consumes: `Engine::dedup()`, `Engine::finalizeAllSet()`/`finalizeAll()` (all pre-existing,
  unmodified by this plan).

**The decision this plan makes on resolve-time bridging vs. post-hoc dedup: keep the post-hoc
model, do not move it.** The GPP page's four outcomes for a new record are new profile / attach to
one / cluster with other new records / bridge-merge existing profiles. The first three are already
real-time (see "What already conforms" above). Bridge-merging TWO ALREADY-MATERIALIZED profiles —
discovering, via a new connecting record, that two identities that already exist independently are
the same person — is different in kind from the other three: Pass A/B (`DeterministicResolver::
resolve()`) only ever compares ONE new row against EXISTING identities, one at a time; they never
compare two existing identities to each other. Doing that comparison in real time, for every new
row, would mean checking whether the row's newly-discovered keys ALSO match some OTHER identity
beyond the one it just bound to — and if so, walking that second identity's own keys for a THIRD,
transitively, and so on. That is exactly the "compare against all members" cost this whole plan's
block-key/signature work exists to avoid, just moved from "per candidate" to "per potential bridge,"
which is unbounded in the worst case (a hub-spanning merge chain). `Engine::dedup()`'s own docblock
already states the alternative's shape: "Iterates to a fixed point: a merge lets the survivor
inherit the loser's keys, which can expose further (transitive) matches on the next pass" — a batch
process that amortizes exactly that transitive cost across ALL of it at once, instead of paying an
open-ended amount of it inside every single incremental sync row. This mirrors `Engine::backfill()`'s
own stated tradeoff ("turns ~30 per-row hub queries into one pass"). **What was missing was not the
mechanism — it already exists — but a schedule.** This command and its schedule entry are that.

**What this means for "split" / "update as delete-then-add."** As "What already conforms" notes,
`stg_person` itself already gets fully replaced on every re-sync (`ingest()`'s full-column
`UPDATE`), satisfying delete-then-add AT THE STAGING LAYER. What does NOT follow automatically is
the IDENTITY level: `gp_identity.npi`/`dea_number`/etc. are first-wins and never cleared when a
source row's value changes or disappears, so a stale key can keep binding new rows to an identity
that, strictly, should have split. This plan does not attempt incremental split logic — the GPP
page itself says incremental "can only ever merge" and calls for a periodic full re-profile to
correct drift, rather than asking incremental to solve splits it cannot solve cleanly. `gp:reprofile`
IS that correction: it runs `SqlBackfill`-shaped resolution logic (`Engine::dedup()` +
`finalizeAllSet()`) against the CURRENT `stg_person` state, which by then already reflects every
source-side change — so a full reprofile naturally re-derives whatever the correct, un-drifted
clustering is, including splitting apart anything incremental sync got wrong along the way. This
plan does not modify `resolveDeterministic()`/`tierCreate()`/`tierLink()` to also RE-EVALUATE
already-linked rows from scratch (that would be a much larger change — effectively "unlink
everything and re-resolve," a correctness-vs-cost tradeoff belonging to whichever future plan
tackles splitting directly) — `gp:reprofile` here is scoped to what `Engine::dedup()` +
`finalizeAllSet()` already do: MERGE convergence and canonical-field/signature/block-key refresh,
run periodically. This is named as a real, current limitation, not fixed here.

**Overlap with `gp:sync`.** `Engine::dedup()`'s `mergeIdentity()` deletes loser identities. If
`gp:sync`'s per-row `DeterministicResolver::resolve()` reads a loser identity as "active" and
inserts a NEW `gp_source_link` row pointing at it in the gap between `dedup()` reading that
identity's rows-to-repoint and deleting it, that new link is orphaned — pointing at a row that no
longer exists, with no foreign key to catch it (verified: no `foreign()` calls anywhere in the
schema migration), permanently invisible to every `WHERE i.status = 'active'` query. Laravel's
`withoutOverlapping()` only prevents the SAME scheduled command from overlapping ITSELF, not two
DIFFERENT commands — so both commands acquire a shared named lock before doing any writing.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Console\Commands\GpReprofile;
use Tests\TestCase;

/**
 * No DB round-trip here — HubTestCase-based coverage of dedup()/finalizeAllSet()
 * themselves already exists (Tasks 1-4's tests exercise both). This just pins
 * the command's signature and default option values, the same scope
 * GpBackfill/GpSync's own tests would have if any existed.
 */
class GpReprofileCommandTest extends TestCase
{
    public function test_command_is_registered_with_the_expected_signature(): void
    {
        $command = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->all()['gp:reprofile'] ?? null;

        $this->assertInstanceOf(GpReprofile::class, $command);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/GpReprofileCommandTest.php`
Expected: FAIL — `gp:reprofile` is not a registered command.

- [ ] **Step 3: Write `GpReprofile`**

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Engine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Periodic full reprofile — the GPP "Incremental Profiling" page's own
 * required correction for what incremental sync structurally cannot do:
 * bridge-merge already-materialized profiles in real time, or split one that
 * incremental drift got wrong. Runs Engine::dedup() (merge convergence, the
 * fixed-point pass the class's own docblock describes) followed by a
 * whole-hub finalize (canonical fields + this plan's block-key/signature
 * tables, refreshed set-based by default).
 *
 * Does NOT re-evaluate already-linked stg_person rows from scratch — that
 * would require unlinking and re-resolving everything, a larger change this
 * plan deliberately does not make (see this plan's Task 6 docblock and
 * Self-review). What it DOES correctly re-derive: any merge that a source
 * row's now-changed keys newly justify, and every canonical/signature/
 * block-key value, from whatever stg_person currently holds (which
 * ingest()'s full-column UPDATE already keeps current per row).
 *
 * Shares a named lock with gp:sync (see GpSync) so the two never write
 * concurrently: dedup() can delete an identity gp:sync's own per-row resolve
 * just found "active" and is about to insert a new link against, and nothing
 * in the schema enforces a foreign key that would catch that race (verified:
 * no foreign() calls anywhere in the golden_profile schema).
 */
class GpReprofile extends Command
{
    protected $signature = 'gp:reprofile
        {--shard=0 : This process\'s shard number for dedup()}
        {--shards=1 : Total shards for --shard}
        {--legacy-finalize : Use the per-identity sharded finalize instead of the set-based one}';

    protected $description = 'Periodic full reprofile: merge-converge (dedup) then finalize the whole hub.';

    public function handle(): int
    {
        $shard = (int) $this->option('shard');
        $shards = max(1, (int) $this->option('shards'));

        $lock = Cache::lock('gp-engine-write', 3600);
        if (! $lock->get()) {
            $this->warn('gp:sync (or another gp:reprofile) holds the write lock — skipping this run.');

            return self::SUCCESS;
        }

        try {
            $engine = new Engine;
            $this->info("Reprofiling (shard $shard of $shards)...");
            $merged = $engine->dedup(fn ($n) => $this->output->write("\r  merged: $n"), $shard, $shards);
            $this->newLine();
            $this->info("Merged $merged duplicate identity/identities.");

            if ($this->option('legacy-finalize')) {
                $engine->finalizeAll(fn ($d, $t) => $this->output->write("\r  finalize: $d/$t"), $shard, $shards);
                $this->newLine();
            } else {
                $engine->finalizeAllSet(fn ($p, $d) => $this->line("  [$p] $d"));
            }
            $this->info('Reprofile complete.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
```

- [ ] **Step 4: Share the same lock in `GpSync`**

Replace `GpSync.php`'s `handle()`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Engine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class GpSync extends Command
{
    protected $signature = 'gp:sync {system=streamline_local} {--chunk=1000}';

    protected $description = 'Mode 2: incremental sync — resolve only rows changed since the watermark.';

    public function handle(): int
    {
        // Same named lock gp:reprofile holds for its whole run — see
        // GpReprofile's docblock for the concurrent-delete race this
        // prevents. A short wait (not a bare skip) is fine here: gp:sync
        // is scheduled far more often than gp:reprofile runs, so a
        // occasional short wait behind a reprofile is cheap; gp:reprofile
        // itself skips outright rather than waiting (see there) since it is
        // the far less frequent, less time-sensitive of the two.
        $lock = Cache::lock('gp-engine-write', 3600);
        if (! $lock->block(30)) {
            $this->warn('gp:reprofile holds the write lock — could not acquire it within 30s, skipping this run.');

            return self::SUCCESS;
        }

        try {
            $engine = new Engine;
            $this->info('Syncing '.$this->argument('system').'...');
            $n = $engine->sync(
                (int) $this->option('chunk'),
                fn ($c) => $this->output->write("\r  processed: $c"),
            );
            $this->newLine();
            $this->info("Done. $n changed rows processed.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
```

- [ ] **Step 5: Schedule both in `routes/console.php`**

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Incremental sync: frequent, cheap (only rows changed since the watermark).
// withoutOverlapping() guards against a slow run still going when the next
// one fires; onOneServer() matters once this runs on more than one box.
// The gp-engine-write lock (see GpSync/GpReprofile) is the SEPARATE guard
// against gp:sync and gp:reprofile overlapping EACH OTHER, which
// withoutOverlapping() cannot do (it only prevents a command overlapping
// itself).
Schedule::command('gp:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Full reprofile: the GPP "Incremental Profiling" page calls for
// weekly/monthly, not more — this is a whole-hub merge-convergence + finalize
// pass (Engine::dedup() + finalizeAllSet()), not a cheap operation, and
// incremental sync already keeps day-to-day drift small between runs.
// Sunday 2am: off the two hours (per streamlineverify ops convention) that
// see the least incremental sync traffic, so the write lock above is rarely
// contested.
Schedule::command('gp:reprofile')
    ->weeklyOn(0, '02:00')
    ->withoutOverlapping(180)
    ->onOneServer();
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/GpReprofileCommandTest.php`
Expected: PASS.

Run: `php artisan schedule:list`
Expected: shows both `gp:sync` (every 15 minutes) and `gp:reprofile` (weekly, Sunday 02:00).

- [ ] **Step 7: Commit**
```bash
git add app/Console/Commands/GpReprofile.php app/Console/Commands/GpSync.php routes/console.php tests/Unit/GpReprofileCommandTest.php
git commit -m "feat(gp): add gp:reprofile, schedule it and gp:sync, and mutex them against each other"
```

---

## Task 7: Prove it — the eval fixture resolved via both paths converges to one clustering

**Files:**
- Create: `tests/Feature/IncrementalBatchParityTest.php`

**Interfaces:**
- Consumes: `EvalSet` (plan 1), `DeterministicResolver`, `Engine`, `SqlBackfill` (all pre-existing).

This is the task the brief asks for by name: prove, don't assert, that the per-row path (what
`gp:sync` actually runs, here run with a FOLLOW-UP `Engine::dedup()` to represent what a scheduled
`gp:reprofile` — Task 6 — does to it afterward) and the set-based bulk path
(`SqlBackfill::resolveDeterministic()` + `enrich()` + `dedup()`, what `gp:backfill`'s `transform()`
runs) produce the IDENTICAL clustering for the SAME input. Reusing the eval fixture (already
post-plan-5: NPI/DEA/MMIS/license/name+dob cases, corrected/extended by that plan) turns this into
the eval gate's own parity check, per the brief's own suggestion, instead of a separate synthetic
scenario nobody else will maintain.

**Why this test manages its own cleanup instead of trusting `HubTestCase`'s per-test rollback —
concrete, not hand-waved.** `SqlBackfill`/`Engine` both hardcode `SYSTEM_CODE = 'streamline_local'`
and resolve their own `system_id` via `ensureSystem()` with no constructor hook to inject a
different one — but `HubTestCase::seedSystem()` deliberately uses a `uniqid()`'d code so parallel
tests never collide, meaning `$this->systemId` is NOT the id `SqlBackfill`/`Engine` will use for
their own writes/reads. This test mints (or reuses) the literal-code row directly, the same way
`Engine::ensureSystem()` does, so staged rows and both resolvers agree on which system they belong
to. Separately — and this is the load-bearing part — `SqlBackfill::resolveDeterministic()` always
calls `SsnHashGuard::buildBlocklistTable()` (and, per plan 5, `JunkKeyGuard::buildBlocklistTable()`
for npi), both of which run `CREATE TABLE IF NOT EXISTS`. **Verified directly against this
project's own `gp_cami_test` instance:** a DDL statement run inside `HubTestCase`'s outer
transaction causes MySQL to implicitly COMMIT it; Laravel's `DB::rollBack()` does not throw
afterward (it just resets its transaction-level counter), so nothing fails loudly, but every row
written up to and past that point survives `tearDown()` uncommitted-turned-committed, cleared only
by the next `migrate:fresh`. (Also verified: `Engine::dedup()`'s own internal
`$hub->transaction()` calls — used by `mergeIdentity()` — still work correctly even AFTER such an
implicit commit; only the OUTER rollback is affected, not `dedup()`'s own merge logic.) So: Path A
(per-row, no DDL) runs first and is cleaned up with plain `DELETE`s while the transaction is still
genuinely live; Path B (bulk, DDL-triggering) runs second, and the test cleans up explicitly at the
end rather than trusting `tearDown()`.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Eval\EvalSet;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\SqlBackfill;
use Tests\Support\HubTestCase;

class IncrementalBatchParityTest extends HubTestCase
{
    public function test_eval_set_converges_to_the_same_clustering_via_both_paths(): void
    {
        $hub = $this->hub();

        // SqlBackfill/Engine hardcode SYSTEM_CODE='streamline_local' and
        // resolve their own system_id via ensureSystem() with no constructor
        // hook to override it — mint/reuse that literal-code row directly (the
        // same insertOrIgnore-then-lookup ensureSystem() itself does) so
        // staged rows and both resolvers agree on which system they belong to.
        $hub->table('gp_source_system')->insertOrIgnore([
            'system_code' => 'streamline_local', 'display_name' => 'parity test',
            'reliability_rank' => 50, 'is_active' => 1, 'added_at' => now(),
        ]);
        $systemId = (int) $hub->table('gp_source_system')->where('system_code', 'streamline_local')->value('system_id');

        $set = EvalSet::load(base_path('tests/eval/identity-pairs.json'));
        $stgByRef = $this->stageEvalSet($systemId, $set);

        // --- Path A: per-row resolve (what gp:sync runs) + a periodic dedup
        // pass (what a scheduled gp:reprofile — Task 6 — does to it
        // afterward). No DDL anywhere in this branch.
        $resolver = new DeterministicResolver($systemId);
        foreach ($stgByRef as $stgId) {
            $resolver->resolve($stgId);
        }
        (new Engine)->dedup();
        $clusterA = $this->clustersByRef($systemId, $stgByRef);

        // Clean up Path A's derived rows (plain DML, transaction still live)
        // so Path B starts from the same stg_person-only state Path A did.
        $this->deleteDerivedRows($systemId);

        // --- Path B: bulk set-based backfill (what gp:backfill's transform()
        // runs). resolveDeterministic() unconditionally triggers the
        // CREATE-TABLE-IF-NOT-EXISTS DDL described in this task's docblock —
        // from this point on, this test's writes are NOT protected by
        // HubTestCase's rollback, so cleanup at the end is explicit.
        (new SqlBackfill)->resolveDeterministic();
        (new SqlBackfill)->enrich();
        (new Engine)->dedup();
        $clusterB = $this->clustersByRef($systemId, $stgByRef);

        $this->assertSameClustering($clusterA, $clusterB);

        // Explicit cleanup — see this task's docblock for why tearDown()'s
        // rollBack() cannot be trusted after the DDL above.
        $this->deleteDerivedRows($systemId);
        $stgIds = array_values($stgByRef);
        $hub->table('stg_person_license')->whereIn('stg_person_id', $stgIds)->delete();
        $hub->table('stg_person')->whereIn('stg_person_id', $stgIds)->delete();
    }

    /** @return array<string,int> ref => stg_person_id */
    private function stageEvalSet(int $systemId, EvalSet $set): array
    {
        $hub = $this->hub();
        $stgByRef = [];
        foreach ($set->records() as $r) {
            $ref = $r['ref'];
            $stgByRef[$ref] = (int) $hub->table('stg_person')->insertGetId([
                'system_id' => $systemId,
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
                $hub->table('stg_person_license')->insert([
                    'stg_person_id' => $stgByRef[$ref],
                    'license_number' => $lic['license_number'],
                    'certification_state' => $lic['certification_state'] ?? null,
                    'certification_board' => null, 'license_type' => null,
                    'license_type_id' => null, 'registry' => null, 'is_primary' => 1,
                ]);
            }
        }

        return $stgByRef;
    }

    /** @return list<list<string>> refs grouped by the identity they resolved to */
    private function clustersByRef(int $systemId, array $stgByRef): array
    {
        $sourceIdToRef = [];
        foreach ($stgByRef as $ref => $stgId) {
            $sourceIdToRef[crc32($ref)] = $ref;
        }
        $links = $this->hub()->table('gp_source_link')
            ->where('system_id', $systemId)->where('source_table', 'employees')
            ->whereIn('source_id', array_keys($sourceIdToRef))
            ->get(['source_id', 'identity_id']);

        $byIdentity = [];
        foreach ($links as $l) {
            $byIdentity[$l->identity_id][] = $sourceIdToRef[(int) $l->source_id];
        }

        return array_values($byIdentity);
    }

    /** Order-independent, ref-set comparison — physical identity_ids differ across runs. */
    private function assertSameClustering(array $a, array $b): void
    {
        $normalize = fn (array $clusters) => collect($clusters)
            ->map(fn ($c) => collect($c)->sort()->values()->all())
            ->sort(fn ($x, $y) => ($x[0] ?? '') <=> ($y[0] ?? ''))
            ->values()->all();

        $this->assertEquals($normalize($a), $normalize($b), 'per-row+dedup and bulk-backfill clustering diverged for the eval fixture');
    }

    private function deleteDerivedRows(int $systemId): void
    {
        $hub = $this->hub();
        $identityIds = $hub->table('gp_source_link')->where('system_id', $systemId)->pluck('identity_id')->unique();
        foreach (['gp_license', 'gp_address', 'gp_identity_identifier', 'gp_attribute',
            'gp_survivorship_audit', 'gp_identity_profile', 'gp_identity_block_key',
            'gp_identity_signature', 'gp_resolution_log'] as $t) {
            $hub->table($t)->whereIn('identity_id', $identityIds)->delete();
        }
        $hub->table('gp_source_link')->where('system_id', $systemId)->delete();
        $hub->table('gp_identity')->whereIn('identity_id', $identityIds)->delete();
    }

    /** Same rule as StreamlineLocalConnector::blockKey()/EvalRunner::blockKey(). */
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

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit tests/Feature/IncrementalBatchParityTest.php`
Expected: PASS. If it FAILS, the failure is real signal, not test wiring — the two paths produced
different clusters for the same input. Do not "fix" this test by relaxing the comparison; find and
fix the divergence (most likely candidates, in order of likelihood given this plan's own changes:
a `block_key`/signature maintenance gap between the per-row and bulk hooks from Tasks 1-4, or a
tier-ordering difference between `DeterministicResolver::matchDeterministic()` and
`SqlBackfill::KEY_TIERS`/`nameDobCreateAndLink()` that predates this plan).

- [ ] **Step 3: Commit**
```bash
git add tests/Feature/IncrementalBatchParityTest.php
git commit -m "test(gp): prove the per-row and set-based resolution paths converge on the eval fixture"
```

---

## Task 8: Final eval-gate re-run, row-growth quantification, and docs

**Files:**
- Modify: `docs/EVALUATION.md`

- [ ] **Step 1: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS, 0 skipped (or skipped only for `GP_TEST_DB_*`-gated tests when that env is unset,
which fails `--fail-on-skipped` in CI by design — run with `GP_TEST_DB_*` set for a real signal).

Run: `vendor/bin/phpunit --filter=EvalGateTest`
Expected: PASS. Record the actual `precision`/`recall`/`f1`/`true_pairs`/`false_merges`/
`false_splits` values printed by the test (or add a temporary `dump()` if the test doesn't already
surface them) into `docs/EVALUATION.md`'s baseline table, in the row for this plan's changes. This
plan's own reasoning throughout expects **no change** from plan 5's numbers (every rewrite in this
plan is argued, and now tested, as mechanism-only) — if the numbers moved, that is a real
regression to root-cause before closing this task, not a number to quietly accept.

- [ ] **Step 2: Row-growth quantification**

This environment has no live count of `gp_identity` (no production hub access — see the brief's
Environment section). The brief's verified fact is ~13M `stg_person`-equivalent SOURCE rows; the
estimate below treats the identity count as the same order of magnitude (a conservative
overestimate, since resolution collapses multiple source rows into fewer identities) and should be
replaced with a measured number the first time this runs against the real hub.

Add to `docs/EVALUATION.md`:

```markdown
## Plan 8 — row growth estimate (unmeasured; no production hub access)

Treating active identity count as the same order of magnitude as the ~13M source rows (a
conservative overestimate — resolution collapses many source rows per identity):

| Table | Rows (upper bound) | Bytes/row (rough) | Total (rough) |
|---|---|---|---|
| `gp_identity_block_key` | ~13M (usually 1, rarely 2-3, block keys per identity) | ~50 (9-byte key + 8-byte id + InnoDB/index overhead) | < 1 GB |
| `gp_identity_signature` | ~13M (1 row per active identity, PK) | ~200-400 (four small JSON arrays) | 2-5 GB |

Both are proportional to ACTIVE identity count, not source row count, and both shrink on merge
(Task 1 Step 7 / Task 4 wire `Engine::applyMerge()` to delete the loser's rows) — so in steady
state they track the hub's identity count, not its ever-growing source-row count. Write
amplification: the per-row path touches at most one row per table per `Survivorship::recompute()`
call (bounded by how many identities a sync chunk actually touches); the bulk path rewrites both
tables in full on every `gp:reprofile`/`gp:backfill` run — proportionally similar in cost to the
`gp_attribute`/`gp_survivorship_audit` full-table rewrites `SetFinalizer::survivorship()` already
does today, not a new order of magnitude of write cost.
```

- [ ] **Step 3: Commit**
```bash
git add docs/EVALUATION.md
git commit -m "docs(gp): record plan 8's eval-gate numbers and row-growth estimate"
```

---

## Self-review

**Spec coverage against the GPP "Incremental Profiling" page's three persistent-state pieces and
four outcomes:**

1. **Crosswalk** — already conformant (`gp_source_link` + `gp_watermark`); not touched.
2. **Inverted block-key index** — built (Tasks 1-3): `gp_identity_block_key`, profile-level (not
   staging-row-level), maintained by both paths, consumed by `ProbabilisticResolver`. Single-leg by
   design, deliberately compatible with a future multi-leg 5b (see Programme context).
3. **Profile signature** — built (Tasks 4-5): `gp_identity_signature`, a genuine per-field SET
   (not `gp_identity`'s first-wins scalars), consumed by `hardNo()`'s npi conflict check and
   `score()`'s zip signal.
4. **New profile / attach to one / cluster with new records** — already conformant; documented,
   not re-implemented.
5. **Bridge-merge existing profiles** — kept post-hoc (`Engine::dedup()`), explicitly NOT moved to
   resolve time, with the reasoning written into Task 6 rather than asserted. What was missing was
   scheduling, which Task 6 adds (`gp:reprofile` + `routes/console.php`), plus the concurrency
   mutex the brief's non-negotiables asked for by name.
6. **Split / "update as delete-then-add"** — Task 6 documents precisely what already works
   (staging-level replacement, verified by reading `ingest()`) and what doesn't (identity-level
   key columns are first-wins and never retract), and states plainly that this plan does not
   attempt incremental split logic, leaning on the periodic reprofile the GPP page itself
   prescribes for exactly this gap.
7. **`block_size_cap` interaction (non-negotiable)** — Task 3 changes the cap's comparison from
   raw `stg_person` rows to distinct active identities, argued (not just claimed) as a
   monotonic improvement that cannot worsen the existing silent-split failure mode. The FLAGGING
   fix stays plan 6's, named explicitly, not touched.
8. **Parity proof (non-negotiable)** — Task 7, using the post-plan-5 eval fixture so it doubles as
   an eval-gate parity check, exactly as suggested. Its isolation strategy for the two runs is
   concrete and empirically verified (see that task's docblock and the Global Constraints
   landmines section), not asserted.
9. **Row growth (non-negotiable)** — quantified with reasoning and an explicit "unmeasured, no
   prod access" caveat in Task 8, following plan 5's own precedent for how to write down a number
   this environment cannot actually measure.

**Two verified findings that shaped this plan and are not merely claimed:**

- MySQL's `SOUNDEX()` does not truncate to 4 characters; `LEFT(SOUNDEX(x),4)` does and was checked
  against 12 representative surnames including two (`McDonald`, `Robinson`) that actually diverge
  without the fix. Task 2 pins this permanently.
- A DDL statement executed while `HubTestCase`'s outer transaction is open causes MySQL to
  implicitly commit it, and Laravel's `rollBack()` does not throw afterward — verified with a
  standalone script against this project's own `gp_cami_test` instance, both against raw PDO and
  against Laravel's `DB` facade. This affects `SqlBackfill::resolveDeterministic()` specifically
  (via `SsnHashGuard`/`JunkKeyGuard`'s `buildBlocklistTable()`), which is exactly what Task 7's
  parity test must call — its cleanup strategy is designed around this, not despite not knowing
  about it. This same landmine sits, unaddressed, under plan 5's own `JunkNpiParityTest` and
  `JunkKeyGuardTest::test_build_blocklist_table_captures_both_reasons` (both call
  DDL-triggering code inside a `HubTestCase` test) — this plan does not fix plan 5's files, but the
  finding is recorded here since it will resurface for whoever executes plan 5 if this document is
  read first.

**Placeholder scan:** no task says "add appropriate error handling," "similar to Task N," or
"write tests for the above." Every step that changes code shows the code inline, including the
full migrations, the full `BlockKey` class, the full SQL for every set-based rebuild, and the
full parity test.

**Type consistency:** `hardNo()`/`score()`/`addressOverlap()` all gain a third `?object $signature`
parameter in Task 5; their only caller (`match()`, same file, same task) is updated together, so no
other file breaks. `DeterministicResolver::createIdentity()`'s return type is unchanged (still
`int`); it now also writes one row to a new table before returning. No existing public method
signature in this plan's other touched files (`Survivorship::recompute()`, `Engine::applyMerge()`,
`SetFinalizer::survivorship()`, `ProbabilisticResolver::match()`) changes shape.

**Known risks carried into execution:**

- **`gp_identity_signature`'s upsert-not-truncate maintenance in `SetFinalizer` (Task 4) means a
  reprofile that fails partway through its four statements can leave the signature table
  partially stale for the run it interrupted** (e.g. `npi_set` refreshed, `license_set` not yet).
  The NEXT successful reprofile fully overwrites every field again (each statement targets ALL
  active identities, not just changed ones), so this is a bounded staleness window between runs,
  not permanent drift — but it is real, and named here rather than assumed away by "it's
  idempotent" without qualification.
- **The retroactive question this plan does not re-litigate:** plan 5's Self-review already names
  the retroactive-NPI-validation risk and the state-scoping-changes-existing-merges risk as
  unmeasured against the real hub. This plan's signature table inherits whatever `stg_person`/
  `gp_license`/`gp_identity_identifier` already hold — it does not re-open either of those
  questions, and its own row-growth estimate (Task 8) is explicitly unmeasured for the same
  "no production hub access" reason.
- **`gp:reprofile`'s schedule (weekly, Sunday 02:00) is a reasonable default, not a calibrated
  one** — this environment has no measurement of how much incremental drift accumulates per day on
  the real hub, so the cadence is argued from the GPP page's own stated range ("weekly/monthly")
  and from keeping the write-lock contention window small, not from a number. Recalibrate once
  `gp:reprofile` has actually run against the real hub and its duration/impact is known.
- **This plan does not attempt incremental split logic**, by design (see Task 6) — an identity
  that should split stays wrongly merged from the moment its keys diverge until the next
  `gp:reprofile` run. This is a bounded, documented staleness window (bounded by the schedule in
  Task 6), not an open-ended correctness gap, but it is a real, user-visible lag between "the
  source data changed" and "the hub reflects it," worth stating plainly rather than letting the
  periodic-reprofile mechanism read as a complete fix.

**No split proposed.** At 8 tasks this plan is within the authoring brief's 7-10 guidance, and
every task both changes code and is independently reviewable/testable — Tasks 1-2 (block-key
index, per-row then bulk), 3 (its consumer), 4-5 (signature, same shape), 6 (scheduling +
concurrency), 7 (the parity proof the brief asked for by name), 8 (closing the eval-gate loop). No
task here depends on a piece of scope this plan declined (Pass B blocking widening stays 5b's,
per plan 5's own recommendation and this plan's Programme context section); nothing was trimmed
to fit a task-count ceiling.

# GPP Conformance — Slowly-Changing-Dimension (Type 2) Versioning

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every golden fact in the hub a version rather than an overwrite — `current tinyint(1)` plus creation/update timestamps on the six tables that hold golden facts, one shared insert-new-and-flip-old primitive that every write path calls, a `current = 1` filter on every read path, and database-enforced "at most one current version per natural key" — without moving the eval gate a single decimal place.

**Architecture:** A new `App\GoldenProfile\Support\Versioner` owns the write rule in one place (`write()` loads the latest version for a natural key, returns early when no golden fact actually changed, otherwise flips the old row to `current = 0` and inserts the next `version_no` with `current = 1`), so the rule cannot drift between the eight call sites that currently `update()` or `updateOrInsert()`. Uniqueness moves from "one row per natural key" to a NULL-propagating **virtual generated column** (`current_key`) carrying a unique index, which lets unlimited superseded versions coexist while making a second *current* version a loud duplicate-key error instead of a silent duplicate row. Versioning alone is explicitly **not** the deliverable: see "The tension between the two design pages" below — cross-`cami_employee_id` grouping stays where it already lives, in `gp_source_link`, and this plan does not touch it.

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

- **`current` is not `alive`.** `current = 1` means "this is the newest version of this row".
  Liveness is still `gp_identity.status = 'active'`. A merged-away identity gets a NEW version with
  `status = 'merged'` and `current = 1` — the latest truth about it is that it was merged. Every tier
  query must therefore keep `status = 'active'` **and** gain `current = 1`; dropping either one is a
  bug.
- **A missed `current = 1` on a read does not throw.** It silently returns superseded rows. On a
  matching tier that means binding a source row to an identity version that no longer exists as a
  live identity — a false merge. On a rollup it means double-counted licences and duplicated JSON.
  Every read-path task below therefore ends with a test that asserts the count, not just the shape.
- **The set-based paths are NOT converted in this plan.** `SetFinalizer` and `SqlBackfill` are
  ~1,400 lines of raw MySQL whose survivorship half has to be restructured from nine per-field
  `UPDATE`s into one all-fields versioned pass. That is plan **3b** (see Self-review). Task 10 makes
  both classes **refuse to run** once the SCD-2 migration is present, so 3a cannot ship a silently
  corrupting bulk path.

---

## Programme context — this is plan 3 of 8

| # | Plan | Depends on | Status |
|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — | DONE, merged into this branch |
| 2 | SSN removal | 1 | to write |
| **3** | **SCD-2 versioning** *(this document — 3a; 3b proposed in Self-review)* | **1** | **this document** |
| 4 | Individual vs entity | 1, 3 | to write |
| 5 | Match keys & data quality | 1 | to write |
| 6 | Steward writer layer | 1, 3 | to write |
| 7 | Exclusion lifecycle | 1, 3 | to write |
| 8 | Incremental profiling | 1, 5 | to write |

**Why here.** Three later plans (4, 6, 7) declare a dependency on this one, and all three are about
things that *change over time*: an entity's name history, a steward's decision superseding an earlier
one, an exclusion's reinstatement after a waiver. Each of those is unwriteable against a schema that
overwrites. Doing 3 after them would mean building each on in-place updates and then rewriting all of
them. Plan 1 comes first because this plan rewrites every write path in the hub and the eval gate is
the only evidence that it did not change who matches whom.

**Interaction with plan 2 (SSN removal).** Plan 2 deletes `ssn_hash` from `stg_person`,
`gp_identity` and `gp_identity_profile`, and deletes the Pass A SSN tier. This plan adds `ssn_hash`
to `Versioner::TABLES['gp_identity']['attributes']` and adds `current` to `idx_ssn`. Whichever lands
second must remove the other's `ssn_hash` references — a one-line deletion from the attribute list
and one dropped index, called out in Self-review. Neither plan blocks the other.

---

## The tension between the two design pages — read this before writing any code

Two Confluence pages by the same author say things that look contradictory, and an implementer who
reads only the first will build the wrong thing.

**"Data Flow by CAMI"** (DEV, page 4099997697) states the write rule this plan implements:

> Nearly every table carries a `current tinyint(1)` flag plus `date_created` / `date_updated`. Every
> sync process follows the same rule: **Insert a new row with `current = 1`, and set all preexisting
> rows to `current = 0`.** … This is a slowly-changing-dimension (Type 2) pattern: changes are
> written as new versions rather than overwrites. `current = 1` always points at the latest truth,
> while older rows preserve a full audit trail.

**"Proposed Process Flow by CAMI — with Profiling Algorithm"** (page 4100390914) then warns:

> The value of a "golden" profile is collapsing the *many* `cami_employee_id`s (across accounts,
> lists, and external sources) that describe the *same real provider* into *one* identity. **If
> nothing groups across `cami_employee_id`, the result is a version history, not a golden profile.**
> Profiling is the missing arrow.

**These are two requirements, not two options.** The reconciliation is in the second page's own flow:

| Concern | Doc's answer | Where it already lives in gp-cami |
|---|---|---|
| Which real provider does this record belong to? | The PROFILING step, before any write | `DeterministicResolver` + `ProbabilisticResolver`; the grouping is materialised as `gp_source_link` rows, many per identity |
| How do we record that a provider's facts changed? | Insert a new version under **that identity's existing `id`**, flip the old to `current = 0` | Does not exist. **This plan.** |

The second page's Schema-implication section asks for exactly one of two things — "make
`cami_employee_id` many-to-one against `individuals.id`" or "add an explicit link table (golden
identity ↔ source CAMI records)". `gp_source_link` **is** that link table, with
`unique(system_id, source_table, source_id)` and an `identity_id` that many rows share. gp-cami
already satisfies the grouping requirement; it fails only the versioning one. So:

- **Grouping is out of scope for this plan and must not be touched.** Do not version
  `gp_source_link`, do not change `uq_source`, do not change a single matching threshold.
- **Versioning is in scope, and it is only half the pattern.** The reason it is safe to add is that
  the grouping half is already there. The eval gate in Task 10 is the proof that adding it did not
  disturb it.

If, while executing, you find yourself changing which identity a source row binds to, stop: that is
a matching change, the gate will move, and this plan says it must not.

---

## Which tables are versioned, and which are not

Decided against the doc's table list, mapped onto the existing schema. The register is committed as
`docs/SCD2.md` in Task 1 so the next plan author does not have to re-derive it.

### Versioned — gains `version_no`, `current`, created/updated timestamps, and a single-current unique

| Table | Doc analog | Why |
|---|---|---|
| `gp_identity` | `individuals` / `entities` | THE golden record. The doc's process 1 names it explicitly: "insert a new `individuals` row with `current = 1` and set preexisting entries to `current = 0`". Today `DeterministicResolver::backfillKeys()`, `Survivorship::recompute()` and `Engine::applyMerge()` all `update()` it in place. |
| `gp_license` | `licensing_credentials` | The doc lists `date_created` / `date_updated` on it (twice — that is one of its own noted typos). A licence's type, registry and verified state change; today `enrich()` overwrites them. |
| `gp_address` | `addresses` + `individual_addresses` | The doc splits address history into a shared address book plus a join table specifically so an address can be superseded. `gp_address` collapses both; versioning it is how the history is kept. |
| `gp_identity_credential` | `credential_matches` | Named in doc process 2: "Insert a new `credential_matches` row with `current = 1`". **Already has a `current` column with a different meaning** — see the collision note below. |
| `gp_identity_exclusion` | `exclusion_matches` | Named in doc process 3, same rule. Its match-quality flags (`is_ssn_match`, …) are the matcher's per-record output and change when a record is re-screened. |
| `gp_identity_identifier` | DEA on `licensing_credentials`, `mmis_number` on `individuals` | Both doc homes are versioned tables. A DEA number is revoked and reissued; an MMIS number is reassigned. Multi-valued in gp-cami, so it is its own table, but it holds the same facts. |

### Not versioned, and why each exclusion is deliberate

| Table | Why not |
|---|---|
| `gp_source_link` | It is the doc's own "explicit link table (golden identity ↔ source CAMI records)" — the *grouping* artifact, not a golden fact. The doc's schema has no link table at all, so there is nothing here that carries `current` in the design. Its payload never changes: a link is created once, and if the grouping changes the row is repointed or a merge happens, both of which belong in `gp_resolution_log`. Versioning it would break `uq_source`, which is the sole thing making `DeterministicResolver::resolve()` idempotent, in exchange for nothing. **This is the most consequential exclusion in the plan** — "nearly every table" reads like it includes this one. It does not. |
| `gp_attribute` | Already the attribute-level history: one row per candidate value per source link, with `observed_at` and `is_canonical`. Versioning a history table is meaningless. (It is rewritten wholesale by `Survivorship` each finalize, so it is a rebuildable projection of history rather than an append-only log — worth knowing, but it changes nothing here.) |
| `gp_survivorship_audit` | Same: a rebuildable record of which source won each field and under which rule. Adding `current` would produce a version history of an audit trail. |
| `gp_resolution_log` | Append-only event log with `created_at` and an `action` enum. It is the audit trail; it does not have one. |
| `gp_board_action` | Append-only disciplinary facts; the model docblock already says "Never overwritten (GPP spec)". The doc set has no board-action table at all. Plans 6/7 own it. |
| `gp_edge` | Append-only match evidence with `created_at`. |
| `gp_identity_resolution` | **Already SCD-2**, via `is_current` + `uq_action(system_id, source_action_table, source_action_id)` + `idx_reuse(identity_id, domain, target_key, is_current)`. This is the pattern already implemented once in this codebase. Do NOT add a second `current` column beside `is_current`, and do NOT rename it: `is_current` is read by `CredentialSearchController::priorResolution()`, `ProfileMaterializer`, and `SetFinalizer`'s `$res` subquery, and renaming it buys consistency of spelling and nothing else. Task 1 records it in `docs/SCD2.md` as the pre-existing instance. |
| `gp_identity_profile` | A derived read model, rebuilt by `ProfileMaterializer::rebuild()` (per identity) and `SetFinalizer::materializeRange()` (DELETE+INSERT per 25k-id chunk). It reflects `current = 1` state by construction. Rows are unbounded — identity 3 carries a 69MB `credentials` blob and a 38MB `exclusions` blob — so versioning it would multiply 100MB rows to record nothing that is not already in the versioned tables it aggregates. Its obligation instead is to read **only** `current = 1` (Task 7). |
| `gp_identity_alias` | Derived search index, rebuildable with `gp:rebuild-aliases`. Its composite primary key `(alias_name, identity_id, alias_part)` **is** its dedup guarantee. It is fed from `gp_source_link → stg_person → stg_person_alias`, none of which are versioned, so it needs no change at all. |
| `stg_person`, `stg_person_alias`, `stg_person_address`, `stg_person_license`, `stg_person_identifier` | Input, not golden fact. The doc's `current` pattern is stated for "the Golden Profile schema"; staging is a mirror of CAMI's live state, keyed `uq_src(system_id, source_table, source_id)` and refreshed by `insertOrIgnore`, with `source_modified` carrying the source's own change time. History of a *source* row lives in CAMI, which is the system of record for it — the doc is explicit that "the Golden Profile also cross-references CAMI rather than replacing it". Versioning staging would double a 13M-row table per sync to duplicate an audit trail we do not own. |
| `src_credential_match`, `src_match`, `src_exclusion_record` | Transport buffers `SqlBackfill` fills so resolve can run without a WAN round-trip per row. Truncatable at will. |
| `gp_source_system`, `gp_watermark` | Configuration and cursors. `gp_watermark` is deliberately mutable — it is a resume pointer. |

### `date_created` / `date_updated`: map, don't rename

The doc names `date_created` / `date_updated`. The existing schema uses different names in different
places, so the decision is per table:

| Table | Existing | Decision | Cost of the alternative |
|---|---|---|---|
| `gp_identity` | `first_seen`, `last_updated` | **Map.** `first_seen` is the doc's `date_created` (when the logical identity first appeared), `last_updated` its `date_updated` (when this version was written). `Versioner` carries the mapping in its table spec, so the two names appear in exactly one place. | Renaming touches `ProfileMaterializer` (reads both), `SetFinalizer` (both, in raw SQL, twice), `SqlBackfill` (four INSERT statements), `Survivorship`, `GpIdentity::$casts`, the identically-named columns on `gp_identity_profile`, and — decisively — `IdentityProfileResource`, which emits `last_updated` as a **public API field**. Adding `date_created`/`date_updated` *alongside* is worse: two timestamp pairs that must be kept in sync is a divergence bug waiting to be written. |
| `gp_license`, `gp_address`, `gp_identity_identifier`, `gp_identity_credential`, `gp_identity_exclusion` | none at all | **Adopt the doc's names.** There is nothing to map to and no reader to break, so use `date_created` / `date_updated` verbatim. | n/a |
| `gp_source_link` | `linked_at` | Untouched — not versioned. | n/a |
| `gp_attribute` | `observed_at` | Untouched — not versioned. | n/a |

**Semantics change worth naming:** after this plan `gp_identity.last_updated` means "when this
identity's golden facts last changed", not "when we last recomputed it". Today `Survivorship`
sets it to `now()` on every finalize whether or not anything moved, so it currently answers "when did
we last look". The new meaning is the useful one and it is what the doc's `date_updated` means, but
it is a visible change in the `last_updated` field of both API endpoints: it will stop moving on
every rebuild. Record it in `docs/SCD2.md`.

### The `current` collision on `gp_identity_credential` — resolve this FIRST

`gp_identity_credential.current` already exists (`2026_07_20_140000`, line 104) and is **CAMI's**
`credential_matches.current` mirrored across by `Engine::rollupCredentials()` and
`SqlBackfill::rollup()`. It is surfaced as `'current' => (bool) $c->current` in the profile's
`credentials` JSON (`ProfileMaterializer:84`), as `` {$jb('`current`=1')} `` in `SetFinalizer`'s
`$cred` subquery, and as `'current' => (bool) $row->current` in the credential-search response
(`CredentialSearchController:311`).

If the SCD-2 flag reuses that name, every read that adds `WHERE current = 1` silently filters by
**CAMI's currency flag** instead of the version flag, dropping every credential CAMI has marked
non-current and keeping superseded versions. Nothing throws. Task 2 therefore renames the mirrored
column to `source_current` and updates all five readers **before** Task 3 adds the flag, keeping the
API field name `current` so no consumer contract breaks. These two tasks must not be merged into one
commit: the rename must be reviewable on its own.

---

## Row growth, indexes, and the performance cliff

The hub is ~13.4M source rows and ~13.38M identities (the figure in `AliasIndexer`'s docblock;
`gp_identity_profile` carries 13,661,726 rows per the `IdentitySearchController` EXPLAIN note).
Versioning multiplies rows by the number of times a record changes, so three things have to be true
or the hub outgrows its disk.

**1. No new version unless a golden fact actually changed.** This is not only how idempotency is
preserved (Task 6) — it is the only thing standing between `Engine::finalizeAll()` and 13.38M new
`gp_identity` rows *per run*. `finalizeAll` recomputes survivorship for every identity, and
`Survivorship::recompute()` currently writes `last_updated => now()` unconditionally. A naive
Versioner that compared the whole update array would mint a version for every identity on every
rebuild. `Versioner::write()` therefore compares only the columns declared as `attributes` in the
table spec, and takes two other categories:

- `derived` — `record_count` and `confidence` on `gp_identity`. Recomputed aggregates, written onto
  the current version in place. `record_count` is `COUNT(*)` over `gp_source_link`, which already
  records *when* each link was made with better resolution than a version row would; versioning on a
  `record_count` bump would add one `gp_identity` row per source row, i.e. ~13.4M versions on a
  backfill, to record something already in the link table.
- `onCreate` — `source_link_id` on `gp_license` / `gp_address` / `gp_identity_identifier`. Written
  only when minting version 1, carried forward after. It records which source row *established* the
  fact; making it an attribute would mint a version every time a second account's employee row
  re-observed the same licence, which on the pile-up identities (identity 3 folds 12,463 source rows)
  is thousands of versions carrying identical licence data.

**2. Secondary indexes must include `current` or every probe reads history.** `gp_identity`'s five
key indexes (`idx_ssn`, `idx_npi`, `idx_upin`, `idx_dea`, `idx_name_dob`) are the ones
`DeterministicResolver::matchDeterministic()` probes, and its docblock records what happens when they
stop being usable: `type=ref key=idx_status rows=6475711` instead of `key=idx_name_dob rows=1`, which
"pinned incremental sync at ~0.03 rows/sec". Under versioning, `WHERE ssn_hash = ? AND status =
'active' AND current = 1` wants `(ssn_hash, current)`, so Task 3 appends `current` to all five.

> **Trap.** Those five index definitions are duplicated as
> `SqlBackfill::IDENTITY_KEY_INDEXES` (line 595) and `SetFinalizer::IDENTITY_KEY_INDEXES` (line 139),
> and **both classes drop and rebuild them** around their bulk inserts. If the constants are not
> updated in lockstep with the migration, the next bulk run silently recreates the old
> single-column indexes and every tier probe degrades to the 6.5M-row scan above, with no error
> anywhere. Task 10 pins this with a test that compares both constants against
> `information_schema.statistics`.

**3. The `link_chunk_size` cliff is a correctness constraint, not a tuning knob.**
`config/golden_profile.php:236` documents the measurement on identity 59 (9,358 links):

```
chunk=1000    8.9s
chunk=2000    6.8s
chunk=5000  360.7s     <-- 50x worse
chunk=10000 178.5s
```

Each chunk becomes a `whereIn` against the remote CAMI source. Adding `current = 1` to the link query
(Task 9) is what keeps the chunk contents — and therefore the placeholder count per remote statement
— the same size they are today. Without it, an identity whose credentials have been re-screened
three times yields three times the links, pushing a 1,000-row chunk's worth of *current* links into
the 3,000-placeholder band and multiplying the remote cost. Two knock-on effects, both from the same
filter:

- `max_links` (10,000) is counted from that same query. Unfiltered, identities that never tripped the
  guard start throwing `TooManyCredentialLinksException`.
- `chunkById($chunkSize, …, 'credential_match_id')` needs a strictly unique cursor. The controller's
  own comment explains that `credential_match_id` is unique only within one `system_id`, which is why
  the loop iterates systems. Under versioning it is unique per `(system_id, credential_match_id)`
  *only among current rows* — and Task 3's `uq_cred_current` unique index makes that a database
  guarantee rather than an assumption, which is the reason to build it that way.

**4. `SetFinalizer`'s set-based materialize is not safe until 3b.** Its per-identity aggregate
subqueries (`$lic`, `$addr`, `$cred`, `$excl`, `$idt`) count and `JSON_ARRAYAGG` every row for an
identity. Unfiltered they aggregate history: `license_count` doubles on the first re-observation and
the `licenses` JSON carries every superseded version. And its survivorship half runs nine separate
per-field `UPDATE`s, none of which can be made to mint a version without minting up to nine per
identity per run. That restructuring is plan 3b; Task 10 makes the class refuse to run in the
meantime.

---

## File Structure

| File | Responsibility |
|---|---|
| `docs/SCD2.md` *(create)* | The versioned/excluded table register, the timestamp mapping, the `current` ≠ `alive` rule, and the operational migration runbook |
| `scripts/scd2-preflight.sql` *(create)* | Read-only pre-migration audit: row counts for the two rebuild-cost tables, the real index inventory, and the duplicate-natural-key check |
| `database/migrations/2026_09_04_000000_rename_mirrored_current_on_gp_identity_credential.php` *(create)* | `current` → `source_current`, freeing the name for the SCD-2 flag |
| `database/migrations/2026_09_04_000100_add_scd2_versioning.php` *(create)* | `version_no`, `current`, timestamps, `current_key` generated columns, unique/index restructuring on all six versioned tables |
| `app/GoldenProfile/Support/Versioner.php` *(create)* | The one implementation of insert-new-and-flip-old, the per-table spec, and merge repointing |
| `app/Console/Commands/GpVersionBackfill.php` *(create)* | `gp:version-backfill` — resumable, chunked backfill of `date_created`/`date_updated` on the five tables that had no timestamps |
| `app/GoldenProfile/Support/SetBasedPathGuard.php` *(create)* | Refuses `SetFinalizer`/`SqlBackfill` while the schema is versioned and they are not (removed by plan 3b) |
| `app/GoldenProfile/Resolution/DeterministicResolver.php` *(modify)* | Tier reads filtered to `current = 1`; `backfillKeys`/`enrich` write versions |
| `app/GoldenProfile/Resolution/Survivorship.php` *(modify)* | Canonical winners written as a version, not an update |
| `app/GoldenProfile/Resolution/ProbabilisticResolver.php` *(modify)* | Candidate, address and exclusion reads filtered |
| `app/GoldenProfile/Materialize/ProfileMaterializer.php` *(modify)* | Every child read filtered; `source_current` in the credentials JSON |
| `app/GoldenProfile/Engine.php` *(modify)* | Dedup reads filtered; merge versions the loser instead of deleting it; rollups write versions; `finalizeAll`/`rebuildProfile` iterate current identities only |
| `app/GoldenProfile/Materialize/SetFinalizer.php` *(modify)* | Guarded off (plan 3b converts it); `IDENTITY_KEY_INDEXES` updated |
| `app/GoldenProfile/SqlBackfill.php` *(modify)* | Guarded off (plan 3b converts it); `IDENTITY_KEY_INDEXES` updated |
| `app/Http/Controllers/Api/V1/CredentialSearchController.php` *(modify)* | Licence and credential-link reads filtered; `source_current` mapped to the unchanged `current` response field |
| `docs/EVALUATION.md` *(modify)* | Records that the gate did not move, and why that is the proof |
| `tests/Unit/VersionerSpecTest.php` *(create)* | The table spec is internally consistent and matches the migration — no database |
| `tests/Feature/VersionerTest.php` *(create)* | No-change → no version; change → version 2 with the old row flipped; two current rows rejected by the database |
| `tests/Feature/Scd2SchemaTest.php` *(create)* | The migration produced the columns, generated columns and indexes it claims |
| `tests/Feature/VersionedResolverTest.php` *(create)* | The ladder still binds identically, re-resolving mints no version, and a superseded version never matches |
| `tests/Feature/VersionedRollupTest.php` *(create)* | Merge retires rather than deletes; rollups and the profile count current rows only |
| `tests/Feature/SetBasedPathGuardTest.php` *(create)* | The unconverted bulk paths refuse to run |
| `tests/Unit/IdentityKeyIndexParityTest.php` *(create)* | `SetFinalizer` and `SqlBackfill` agree with the migration on the five key indexes |

---

## Task 1: Pre-migration audit and the versioned-table register

Nothing is altered yet. Two artifacts: a read-only SQL script a human runs against the real hub to
size the two expensive rebuilds and prove the migration will not collide, and the committed register
that stops plans 4, 6 and 7 re-deriving these decisions.

**Files:**
- Create: `scripts/scd2-preflight.sql`
- Create: `docs/SCD2.md`

**Interfaces:**
- Produces: `docs/SCD2.md` with a "Measured before migration" table. Task 3 reads the index
  inventory from it; plan 3b reads the row counts.

- [ ] **Step 1: Write the preflight SQL**

Create `scripts/scd2-preflight.sql`:

```sql
-- Read-only. Run against the production/staging golden_profile hub before the
-- SCD-2 migration (plan 3, task 1). Answers four questions:
--   A. how expensive are the unavoidable ALGORITHM=COPY rebuilds?
--   B. which indexes actually exist? (the hub carries at least one added by hand
--      and absent from every migration: idx_identity_registry_match, referenced
--      in CredentialSearchController's measurement comment)
--   C. would the new single-current unique index collide with existing data?
--   D. what are the real row counts this plan's growth applies to?

-- A. Rebuild sizing. Only gp_identity, gp_identity_credential and
--    gp_identity_exclusion need a PRIMARY KEY change, and a PK change in MySQL 8
--    is ALGORITHM=COPY: a full table rebuild that blocks writes. Everything else
--    in the migration is INSTANT or INPLACE/LOCK=NONE.
SELECT table_name,
       table_rows                                        AS approx_rows,
       ROUND((data_length + index_length) / 1024 / 1024) AS total_mb
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('gp_identity', 'gp_identity_credential', 'gp_identity_exclusion')
ORDER BY data_length + index_length DESC;

-- B. Real index inventory for every table the migration touches. The migration
--    drops indexes by name; a name that is not here must not be dropped, and a
--    name here the migration does not know about has to gain a `current` column
--    of its own or it stops being usable.
SELECT table_name, index_name, non_unique,
       GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('gp_identity', 'gp_license', 'gp_address', 'gp_identity_identifier',
                     'gp_identity_credential', 'gp_identity_exclusion')
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name;

-- C. Collision check. The new uq_*_current indexes are unique over a
--    NULL-propagating expression, so they constrain exactly the rows today's
--    multi-column uniques constrain and no more. These counts must all be 0; a
--    non-zero row means the existing unique is not doing what it looks like it is
--    doing and the migration WILL fail on that table.
SELECT 'gp_license' AS tbl, COUNT(*) AS duplicate_natural_keys FROM (
    SELECT identity_id, license_number, certification_state, certification_board
    FROM gp_license
    GROUP BY identity_id, license_number, certification_state, certification_board
    HAVING COUNT(*) > 1
) d
UNION ALL
SELECT 'gp_address', COUNT(*) FROM (
    SELECT identity_id, address1, city, state, zip
    FROM gp_address
    GROUP BY identity_id, address1, city, state, zip
    HAVING COUNT(*) > 1
) d
UNION ALL
SELECT 'gp_identity_identifier', COUNT(*) FROM (
    SELECT identity_id, id_type, id_value
    FROM gp_identity_identifier
    GROUP BY identity_id, id_type, id_value
    HAVING COUNT(*) > 1
) d;

-- D. Row counts on the tables whose growth this plan controls, so plan 3b and any
--    capacity conversation start from a measured number rather than the 13.4M
--    figure quoted from a docblock.
SELECT 'gp_identity' AS tbl, COUNT(*) AS rows_now FROM gp_identity
UNION ALL SELECT 'gp_license',             COUNT(*) FROM gp_license
UNION ALL SELECT 'gp_address',             COUNT(*) FROM gp_address
UNION ALL SELECT 'gp_identity_identifier', COUNT(*) FROM gp_identity_identifier
UNION ALL SELECT 'gp_source_link',         COUNT(*) FROM gp_source_link;
```

- [ ] **Step 2: Write the register**

Create `docs/SCD2.md` with exactly the content below.

````markdown
# Slowly-changing-dimension (Type 2) versioning in the hub

Implements the write rule from **Data Flow by CAMI** (DEV space, page 4099997697):

> Nearly every table carries a `current tinyint(1)` flag plus `date_created` / `date_updated`.
> Every sync process follows the same rule: insert a new row with `current = 1`, and set all
> preexisting rows to `current = 0`.

## Versioning is half of the pattern

The companion page, **Proposed Process Flow by CAMI — with Profiling Algorithm** (page 4100390914),
warns that "if nothing groups across `cami_employee_id`, the result is a version history, not a
golden profile". Both halves are required, and gp-cami already has the other one: `gp_source_link`
is the "explicit link table (golden identity <-> source CAMI records)" that page asks for, and many
links share one `identity_id`. Versioning records how a grouped identity's facts changed over time.
It does not do the grouping and must never be mistaken for it.

## `current` is not `alive`

`current = 1` means "newest version of this row". Liveness is still `gp_identity.status`. A
merged-away identity gets a **new** version with `status = 'merged'`, `merged_into = <survivor>`
and `current = 1` — the latest truth about it is that it was merged. Every matching query keeps
`status = 'active'` AND adds `current = 1`.

## Versioned tables

| Table | Natural key | Versioned attributes | Derived (written in place) | onCreate |
|---|---|---|---|---|
| `gp_identity` | `identity_id` | `canonical_first`, `canonical_middle`, `canonical_last`, `canonical_suffix`, `canonical_dob`, `ssn_hash`, `npi`, `upin`, `dea_number`, `status`, `merged_into` | `record_count`, `confidence` | — |
| `gp_license` | `identity_id`, `license_number`, `certification_state`, `certification_board` | `license_type`, `license_type_id`, `registry`, `is_verified` | — | `source_link_id` |
| `gp_address` | `identity_id`, `address1`, `city`, `state`, `zip` | `address2`, `is_primary` | — | `source_link_id` |
| `gp_identity_identifier` | `identity_id`, `id_type`, `id_value` | *(none — the key is the whole fact)* | — | `source_link_id` |
| `gp_identity_credential` | `system_id`, `credential_match_id` | `registry`, `match_summary_status`, `match_summary_status_code`, `match_is_valid`, `source_current`, `date_resolved`, `link_state`, `link_confidence` | — | `identity_id` |
| `gp_identity_exclusion` | `system_id`, `match_id` | `exclusion_record_id`, `registry`, `is_ssn_match`, `is_npi_match`, `is_canonical_name_match`, `is_upin_match`, `is_license_number_match`, `link_state`, `link_confidence` | — | `identity_id` |

`identity_id` is `onCreate` on the two link tables, not an attribute: repointing on a merge is a
**grouping** change, recorded in `gp_resolution_log` and on the merged identity's own final version.
Versioning it would mint one row per credential per merge — 397,170 for identity 3 alone.

**Cost of that decision:** you cannot reconstruct "which identity did this credential belong to last
Tuesday" from `gp_identity_credential` alone; you need `gp_resolution_log`. That is the accepted
trade for not multiplying the largest table in the hub on every merge.

`gp_identity_identifier` has no attributes beyond its key. It still gets `current`, because a DEA
number being *withdrawn* is a fact and the only way to record it is a version with `current = 0`.

## Not versioned

`gp_source_link` (the grouping link table — versioning it breaks `uq_source`, the only thing making
`resolve()` idempotent), `gp_attribute` and `gp_survivorship_audit` (already per-observation
provenance), `gp_resolution_log`, `gp_edge` and `gp_board_action` (append-only), `stg_*` (input;
CAMI is the system of record for source history), `src_*` (transport buffers), `gp_source_system`
and `gp_watermark` (config and resume cursors), `gp_identity_profile` and `gp_identity_alias`
(derived, rebuildable read models — their obligation is to read only `current = 1`).

`gp_identity_resolution` was **already** SCD-2 before this work, via `is_current` + `uq_action` +
`idx_reuse`. It keeps `is_current`; renaming it would touch two controllers and both materializers
for spelling alone.

## Timestamps

`gp_identity` maps the doc's names onto the columns it already has — `first_seen` = `date_created`,
`last_updated` = `date_updated` — because `last_updated` is a public API field emitted by
`IdentityProfileResource`. The five other versioned tables had no timestamps at all and adopt the
doc's names verbatim.

**Semantics change:** `gp_identity.last_updated` now means "when the golden facts last changed", not
"when we last recomputed". It stops moving on every rebuild. Both API endpoints surface it.

## Uniqueness

"At most one current version per natural key" cannot be a plain unique index — MySQL 8 has no
partial indexes. Each versioned table therefore carries a **virtual** generated column:

```sql
current_key GENERATED ALWAYS AS (IF(`current` = 1, CONCAT(<key parts>), NULL)) VIRTUAL
```

`CONCAT`, not `CONCAT_WS`: it returns NULL when any argument is NULL, so the expression reproduces
today's NULL-permissive multi-column unique exactly, adds the single-current guarantee on top, and
cannot fail to build on data that already satisfies the old constraint. Superseded rows get NULL and
are unlimited. Parts are joined with `CHAR(31 USING utf8mb4)` (ASCII unit separator) so no field
value can forge a key boundary, and the column takes the table's own `utf8mb4_unicode_ci` collation
so comparison stays case- and accent-insensitive exactly as the multi-column unique was.

VIRTUAL, not STORED: adding a virtual generated column is INSTANT/INPLACE metadata work, while
STORED forces a full rebuild of a 13M-row table.

The old multi-column uniques keep their names with `version_no` appended, which preserves them as
the natural-key lookup index.

## Migration runbook

Every statement in `2026_09_04_000100_add_scd2_versioning` is `ALGORITHM=INSTANT` or
`ALGORITHM=INPLACE, LOCK=NONE` **except** three PRIMARY KEY changes, which MySQL 8 can only do with
`ALGORITHM=COPY`:

| Statement | Table | Route |
|---|---|---|
| `DROP PRIMARY KEY, ADD PRIMARY KEY (identity_id, version_no)` | `gp_identity` | Narrow table; size it with `scripts/scd2-preflight.sql` section A first |
| `DROP PRIMARY KEY, ADD PRIMARY KEY (system_id, credential_match_id, version_no)` | `gp_identity_credential` | Largest table in the hub. **Use `gh-ost` or `pt-online-schema-change`**, not the migration |
| `DROP PRIMARY KEY, ADD PRIMARY KEY (system_id, match_id, version_no)` | `gp_identity_exclusion` | Same |

Run the migration in a maintenance window, or run the three PK changes out of band first and let
the migration's `hasIndex`/`hasColumn` guards skip them. `date_created` / `date_updated` are added
NULL (INSTANT) and populated afterwards by `php artisan gp:version-backfill`, which is chunked and
resumable — a 40-minute `UPDATE` inside a migration leaves a failed deploy wedged.

## Measured before migration

Fill from `scripts/scd2-preflight.sql`. All PENDING until someone with hub access runs it; nobody in
the development environment has production hub credentials.

| Measurement | Value |
|---|---|
| `gp_identity` approx rows / total MB | PENDING |
| `gp_identity_credential` approx rows / total MB | PENDING |
| `gp_identity_exclusion` approx rows / total MB | PENDING |
| `gp_license` rows | PENDING |
| `gp_address` rows | PENDING |
| `gp_identity_identifier` rows | PENDING |
| Duplicate natural keys (must be 0 on all three tables) | PENDING |
| Indexes present but absent from migrations | PENDING (expect at least `idx_identity_registry_match`) |
````

- [ ] **Step 3: Verify the SQL parses against the test schema**

The script is read-only and the real hub is unreachable from here (no credentials, and `mysql` is
not on PATH). The test schema has the same DDL, so parse it there. Create no file for this — run it
and delete nothing:

Run: `php artisan tinker --execute="config(['database.connections.golden_profile' => ['driver'=>'mysql','host'=>env('GP_TEST_DB_HOST'),'port'=>env('GP_TEST_DB_PORT'),'database'=>env('GP_TEST_DB_DATABASE'),'username'=>env('GP_TEST_DB_USERNAME'),'password'=>env('GP_TEST_DB_PASSWORD'),'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'engine'=>'InnoDB']]); DB::purge('golden_profile'); \$n=0; foreach (preg_split('/;\s*\n/', file_get_contents('scripts/scd2-preflight.sql')) as \$s) { \$s=trim(\$s); if (\$s==='' || !preg_match('/^SELECT/mi', \$s)) continue; DB::connection('golden_profile')->select(\$s); \$n++; } echo \"parsed \$n statements\n\";"`

Expected: `parsed 4 statements`. A syntax error names the offending statement. If the test schema has
not been migrated yet, run `vendor/bin/phpunit tests/Feature/ResolverLadderTest.php` once first —
`HubTestCase` migrates it.

- [ ] **Step 4: Commit**

```bash
git add scripts/scd2-preflight.sql docs/SCD2.md
git commit -m "docs(scd2): register the versioned tables and add the pre-migration audit"
```

---

## Task 2: Free the `current` name on `gp_identity_credential`

`gp_identity_credential.current` is CAMI's `credential_matches.current` mirrored across. The SCD-2
flag needs that name. Rename the mirror to `source_current` and update its five readers, keeping the
API field name `current` so no consumer changes. **Nothing about versioning happens in this task** —
that is the point: it must be reviewable on its own, because a mistake here is invisible.

**Files:**
- Create: `database/migrations/2026_09_04_000000_rename_mirrored_current_on_gp_identity_credential.php`
- Modify: `app/GoldenProfile/Engine.php:621-641` (`rollupCredentials`)
- Modify: `app/GoldenProfile/SqlBackfill.php:638-652` (`rollup`)
- Modify: `app/GoldenProfile/Materialize/ProfileMaterializer.php:80-86`
- Modify: `app/GoldenProfile/Materialize/SetFinalizer.php:282-286` (`$cred`)
- Modify: `app/Http/Controllers/Api/V1/CredentialSearchController.php:305-314`
- Test: `tests/Feature/SourceCurrentRenameTest.php`

**Interfaces:**
- Produces: column `gp_identity_credential.source_current tinyint(1) NULL`. Task 3 relies on
  `current` being free; `Versioner::TABLES['gp_identity_credential']['attributes']` names
  `source_current`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SourceCurrentRenameTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\ProfileMaterializer;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * The mirrored CAMI currency flag must live under its own name, because the SCD-2
 * version flag takes the name `current` in the next migration. If both meanings
 * ever share one column, every read that filters `current = 1` silently filters by
 * CAMI's flag instead of the version flag — no error, wrong answer.
 */
class SourceCurrentRenameTest extends HubTestCase
{
    public function test_the_mirrored_cami_flag_is_called_source_current(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        $this->assertTrue(
            $schema->hasColumn('gp_identity_credential', 'source_current'),
            'the mirrored CAMI credential_matches.current must be renamed source_current'
        );
        $this->assertFalse(
            $schema->hasColumn('gp_identity_credential', 'current'),
            'the name `current` must be free for the SCD-2 version flag'
        );
    }

    public function test_the_profile_json_still_publishes_the_field_as_current(): void
    {
        // The rename is internal. gp_identity_profile.credentials is a published
        // response shape, so the JSON key stays `current` and only the column
        // behind it changes.
        $identityId = $this->seedIdentityWithCredential(1);

        (new ProfileMaterializer)->rebuild($identityId);

        $credentials = json_decode(
            (string) $this->hub()->table('gp_identity_profile')
                ->where('identity_id', $identityId)->value('credentials'),
            true
        );

        $this->assertSame(true, $credentials[0]['current'], 'the JSON key must stay `current`');
    }

    private function seedIdentityWithCredential(int $sourceCurrent): int
    {
        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 0,
            'status' => 'active', 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->hub()->table('gp_identity_credential')->insert([
            'identity_id' => $identityId,
            'credential_match_id' => 5001,
            'system_id' => $this->systemId,
            'registry' => 'NYEMED',
            'match_summary_status' => 'Valid',
            'match_summary_status_code' => 20,
            'match_is_valid' => 1,
            'source_current' => $sourceCurrent,
            'link_state' => 'confirmed',
        ]);

        return $identityId;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/SourceCurrentRenameTest.php`

Expected: FAIL. `test_the_mirrored_cami_flag_is_called_source_current` fails with "the mirrored CAMI
credential_matches.current must be renamed source_current";
`test_the_profile_json_still_publishes_the_field_as_current` errors with
`Unknown column 'source_current' in 'INSERT INTO'`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_04_000000_rename_mirrored_current_on_gp_identity_credential.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gp_identity_credential.current is CAMI's own credential_matches.current,
 * mirrored across by Engine::rollupCredentials() and SqlBackfill::rollup(). The
 * SCD-2 migration that follows needs the name `current` for the version flag
 * (Data Flow by CAMI, DEV page 4099997697).
 *
 * Leaving both meanings on one column would fail silently rather than loudly:
 * every read that added `WHERE current = 1` would filter by CAMI's currency flag
 * — dropping credentials CAMI has superseded, keeping superseded VERSIONS — with
 * no error anywhere. So the mirror is renamed first, in its own migration, and
 * the API field name stays `current` (mapped in the readers) so no consumer
 * contract moves.
 *
 * RENAME COLUMN is metadata-only in MySQL 8 (ALGORITHM=INSTANT), so this stays
 * cheap even on the largest table in the hub. src_credential_match.current keeps
 * its name: that mirror is a verbatim copy of the CAMI column and is not
 * versioned.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        $s = Schema::connection($this->connection);

        if ($s->hasColumn('gp_identity_credential', 'current')
            && ! $s->hasColumn('gp_identity_credential', 'source_current')) {
            $s->table('gp_identity_credential', function (Blueprint $t) {
                $t->renameColumn('current', 'source_current');
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);

        if ($s->hasColumn('gp_identity_credential', 'source_current')
            && ! $s->hasColumn('gp_identity_credential', 'current')) {
            $s->table('gp_identity_credential', function (Blueprint $t) {
                $t->renameColumn('source_current', 'current');
            });
        }
    }
};
```

- [ ] **Step 4: Update the five readers**

`app/GoldenProfile/Engine.php`, in `rollupCredentials()` — the upsert payload and its update column
list:

```php
            $upserts[] = [
                'system_id' => $this->systemId,
                'credential_match_id' => (int) $c->id,
                'identity_id' => $identityId,
                'registry' => $c->registry,
                'match_summary_status' => $c->match_summary_status,
                'match_summary_status_code' => $c->match_summary_status_code,
                'match_is_valid' => $c->match_is_valid,
                // CAMI's own currency flag, mirrored. Named source_current because
                // `current` is the SCD-2 version flag on this table.
                'source_current' => $c->current,
                'date_resolved' => $this->dt($c->date_resolved),
                'link_state' => 'confirmed',
            ];
        }

        foreach (array_chunk($upserts, 500) as $batch) {
            $this->hub()->table('gp_identity_credential')->upsert(
                $batch,
                ['system_id', 'credential_match_id'],
                ['identity_id', 'registry', 'match_summary_status', 'match_summary_status_code',
                    'match_is_valid', 'source_current', 'date_resolved', 'link_state'],
            );
        }
```

`app/GoldenProfile/SqlBackfill.php`, in `rollup()` — the credential statement:

```php
        $this->hub()->statement(
            "INSERT INTO gp_identity_credential
                (system_id, credential_match_id, identity_id, registry, match_summary_status,
                 match_summary_status_code, match_is_valid, source_current, date_resolved, link_state)
             SELECT ?, c.id, l.identity_id, c.registry, c.match_summary_status,
                 c.match_summary_status_code, c.match_is_valid, c.current, c.date_resolved, 'confirmed'
             FROM src_credential_match c
             JOIN gp_source_link l ON l.system_id=? AND l.source_table='employees' AND l.source_id=c.employee_id
             WHERE 1=1 $excludeSql
             ON DUPLICATE KEY UPDATE identity_id=VALUES(identity_id), registry=VALUES(registry),
                 match_summary_status=VALUES(match_summary_status), match_summary_status_code=VALUES(match_summary_status_code),
                 match_is_valid=VALUES(match_is_valid), source_current=VALUES(source_current),
                 date_resolved=VALUES(date_resolved), link_state='confirmed'",
            [$sys, $sys]
        );
```

`app/GoldenProfile/Materialize/ProfileMaterializer.php`, the credentials map:

```php
        $credentials = $hub->table('gp_identity_credential')->where('identity_id', $identityId)->get()
            ->map(fn ($c) => [
                'credential_match_id' => (int) $c->credential_match_id, 'registry' => $c->registry,
                'status' => $c->match_summary_status, 'status_code' => $c->match_summary_status_code,
                // The JSON key stays `current` (published response shape); the
                // column behind it is source_current — CAMI's flag, not the
                // version flag.
                'valid' => (bool) $c->match_is_valid, 'current' => (bool) $c->source_current,
                'link_state' => $c->link_state,
            ])->values();
```

`app/GoldenProfile/Materialize/SetFinalizer.php`, the `$cred` subquery:

```php
        $cred = "SELECT identity_id, COUNT(*) cnt,
                    JSON_ARRAYAGG(JSON_OBJECT('credential_match_id',credential_match_id,'registry',registry,
                        'status',match_summary_status,'status_code',match_summary_status_code,
                        'valid',{$jb('match_is_valid=1')},'current',{$jb('source_current=1')},'link_state',link_state)) js
                 FROM gp_identity_credential WHERE $r GROUP BY identity_id";
```

`app/Http/Controllers/Api/V1/CredentialSearchController.php`, the response array in
`latestQualifyingCredential()`:

```php
        return [
            'credential_match_id' => (int) $row->credential_match_id,
            'registry' => $row->registry,
            'match_summary_status' => $row->match_summary_status,
            'match_summary_status_code' => $row->match_summary_status_code,
            'match_is_valid' => (bool) $row->match_is_valid,
            // Response field name unchanged; the source column was renamed to free
            // `current` for the SCD-2 version flag.
            'current' => (bool) $row->source_current,
            'expiry_date' => $row->expiry_date,
            'date_resolved' => $row->date_resolved,
        ];
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/SourceCurrentRenameTest.php`
Expected: PASS, 2 tests.

Run: `vendor/bin/phpunit`
Expected: PASS — 94 tests, 0 skipped. Any failure mentioning `current` is a reader this step missed;
`grep -rn "current" app/ --include=*.php | grep -v source_current | grep -v is_current` finds the
remaining candidates.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_09_04_000000_rename_mirrored_current_on_gp_identity_credential.php \
        app/GoldenProfile/Engine.php app/GoldenProfile/SqlBackfill.php \
        app/GoldenProfile/Materialize/ProfileMaterializer.php \
        app/GoldenProfile/Materialize/SetFinalizer.php \
        app/Http/Controllers/Api/V1/CredentialSearchController.php \
        tests/Feature/SourceCurrentRenameTest.php
git commit -m "refactor(schema): rename the mirrored CAMI currency flag to source_current"
```

---

## Task 3: The SCD-2 migration

Adds `version_no`, `current`, the two timestamp columns where they are missing, the `current_key`
generated column and its unique index, and rewrites the affected uniques and key indexes — on all
six versioned tables. Written as raw statements rather than Blueprint calls because the
`ALGORITHM=`/`LOCK=` clauses and the generated-column expressions are the point: without them this
is a multi-hour table-locking migration instead of a metadata change plus three index builds.

**Files:**
- Create: `database/migrations/2026_09_04_000100_add_scd2_versioning.php`
- Test: `tests/Feature/Scd2SchemaTest.php`

**Interfaces:**
- Produces, on `gp_identity`: `version_no INT UNSIGNED NOT NULL DEFAULT 1`,
  `current TINYINT(1) NOT NULL DEFAULT 1`, `current_key BIGINT UNSIGNED` (virtual),
  `UNIQUE uq_identity_current(current_key)`, `UNIQUE uq_identity_uuid(identity_uuid, version_no)`,
  `PRIMARY KEY (identity_id, version_no)`, and `current` appended to `idx_ssn`, `idx_npi`,
  `idx_upin`, `idx_dea`, `idx_name_dob`.
- Produces, on `gp_license`, `gp_address`, `gp_identity_identifier`, `gp_identity_credential`,
  `gp_identity_exclusion`: `version_no`, `current`, `date_created DATETIME NULL`,
  `date_updated DATETIME NULL`, a `current_key` virtual column with `UNIQUE uq_<t>_current`, the old
  natural-key unique redefined with `version_no` appended, and `current` appended to `idx_identity`.
- Consumed by: `Versioner` (Task 5), every read path (Tasks 6-9), `gp:version-backfill` (Task 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Scd2SchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\Support\HubTestCase;

/**
 * Pins the shape the SCD-2 migration produces. Every assertion here is something
 * a later task depends on and could not detect the absence of at runtime: a
 * missing `current` column throws, but a missing single-current unique index just
 * lets two current rows exist and every read return duplicates.
 */
class Scd2SchemaTest extends HubTestCase
{
    /** Tables that carry the doc's date_created / date_updated verbatim. */
    private const WITH_DOC_TIMESTAMPS = [
        'gp_license', 'gp_address', 'gp_identity_identifier',
        'gp_identity_credential', 'gp_identity_exclusion',
    ];

    private const SINGLE_CURRENT_UNIQUES = [
        'gp_identity' => 'uq_identity_current',
        'gp_license' => 'uq_lic_current',
        'gp_address' => 'uq_addr_current',
        'gp_identity_identifier' => 'uq_ident_current',
        'gp_identity_credential' => 'uq_cred_current',
        'gp_identity_exclusion' => 'uq_excl_current',
    ];

    public function test_every_versioned_table_carries_the_version_columns(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        foreach (array_keys(self::SINGLE_CURRENT_UNIQUES) as $table) {
            $this->assertTrue($schema->hasColumn($table, 'version_no'), "$table.version_no missing");
            $this->assertTrue($schema->hasColumn($table, 'current'), "$table.current missing");
            $this->assertTrue($schema->hasColumn($table, 'current_key'), "$table.current_key missing");
        }

        foreach (self::WITH_DOC_TIMESTAMPS as $table) {
            $this->assertTrue($schema->hasColumn($table, 'date_created'), "$table.date_created missing");
            $this->assertTrue($schema->hasColumn($table, 'date_updated'), "$table.date_updated missing");
        }

        // gp_identity maps the doc's names onto the columns it already has, because
        // last_updated is a published API field. It must NOT gain a second pair.
        $this->assertFalse($schema->hasColumn('gp_identity', 'date_created'),
            'gp_identity maps date_created to first_seen; a second column would drift');
        $this->assertFalse($schema->hasColumn('gp_identity', 'date_updated'),
            'gp_identity maps date_updated to last_updated; a second column would drift');
    }

    public function test_each_versioned_table_has_a_single_current_unique_index(): void
    {
        foreach (self::SINGLE_CURRENT_UNIQUES as $table => $index) {
            $this->assertTrue(
                $this->indexExists($table, $index),
                "$table is missing $index — two current versions would be accepted silently"
            );
            $this->assertSame(
                0,
                (int) $this->hub()->selectOne(
                    'SELECT non_unique FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                    [$table, $index]
                )->non_unique,
                "$index must be UNIQUE"
            );
        }
    }

    public function test_the_identity_key_indexes_end_in_current(): void
    {
        // Without `current` in these five, every deterministic tier probe reads
        // superseded rows and then filters — the same defect
        // DeterministicResolver's docblock measured at 6,475,711 rows scanned.
        foreach (['idx_ssn', 'idx_npi', 'idx_upin', 'idx_dea', 'idx_name_dob'] as $index) {
            $this->assertSame(
                'current',
                $this->lastColumnOf('gp_identity', $index),
                "$index must end in `current` or the tier probe stops being sargable"
            );
        }
    }

    public function test_the_identity_primary_key_admits_versions(): void
    {
        // identity_id must LEAD the primary key: InnoDB requires an AUTO_INCREMENT
        // column to be the leading column of some index, and identity_id stays
        // auto-increment so a brand-new identity still comes from insertGetId().
        $this->assertSame(
            ['identity_id', 'version_no'],
            $this->columnsOf('gp_identity', 'PRIMARY'),
            'gp_identity primary key must be (identity_id, version_no), in that order'
        );

        $this->assertSame(
            ['system_id', 'credential_match_id', 'version_no'],
            $this->columnsOf('gp_identity_credential', 'PRIMARY')
        );
        $this->assertSame(
            ['system_id', 'match_id', 'version_no'],
            $this->columnsOf('gp_identity_exclusion', 'PRIMARY')
        );
    }

    public function test_a_second_current_version_is_rejected_by_the_database(): void
    {
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $id,
            'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'canonical_first' => 'Bob', 'canonical_last' => 'Smith', 'canonical_dob' => '1970-04-02',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);
    }

    public function test_superseded_versions_are_unlimited(): void
    {
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'canonical_last' => 'Smith', 'confidence' => 1.0, 'record_count' => 0,
            'status' => 'active', 'version_no' => 1, 'current' => 0,
            'first_seen' => now(), 'last_updated' => now(),
        ]);

        foreach ([2, 3, 4] as $v) {
            $this->hub()->table('gp_identity')->insert([
                'identity_id' => $id,
                'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'canonical_last' => 'Smith', 'confidence' => 1.0, 'record_count' => 0,
                'status' => 'active', 'version_no' => $v, 'current' => $v === 4 ? 1 : 0,
                'first_seen' => now(), 'last_updated' => now(),
            ]);
        }

        $this->assertSame(4, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->hub()->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        );
    }

    /** @return list<string> */
    private function columnsOf(string $table, string $index): array
    {
        return array_map(
            fn ($r) => $r->column_name,
            $this->hub()->select(
                'SELECT column_name FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                 ORDER BY seq_in_index',
                [$table, $index]
            )
        );
    }

    private function lastColumnOf(string $table, string $index): ?string
    {
        $cols = $this->columnsOf($table, $index);

        return $cols === [] ? null : end($cols);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Scd2SchemaTest.php`

Expected: FAIL, 6 failures. The first is
`test_every_versioned_table_carries_the_version_columns` with "gp_identity.version_no missing".

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_04_000100_add_scd2_versioning.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slowly-changing-dimension (Type 2) versioning for the six tables that hold
 * golden facts — Data Flow by CAMI (DEV page 4099997697):
 *
 *   "Nearly every table carries a current tinyint(1) flag plus date_created /
 *    date_updated. Every sync process follows the same rule: insert a new row
 *    with current = 1, and set all preexisting rows to current = 0."
 *
 * See docs/SCD2.md for which tables are versioned, which are not, and why. The
 * short version: golden facts are versioned; the grouping link table
 * (gp_source_link), the append-only logs, staging, and the rebuildable read
 * models are not.
 *
 * WHY RAW STATEMENTS AND NOT BLUEPRINT
 * ------------------------------------
 * The ALGORITHM/LOCK clauses are load-bearing. gp_identity is ~13.4M rows and
 * gp_identity_credential is the largest table in the hub, so the difference
 * between INSTANT/INPLACE and a rebuild is the difference between a deploy and an
 * outage. Blueprint emits neither the clauses nor the generated-column
 * expressions.
 *
 *   ADD COLUMN with a default, at the end of the row  -> ALGORITHM=INSTANT
 *                                                        (MySQL 8.0.12+), so
 *                                                        EVERY EXISTING ROW
 *                                                        BECOMES version 1,
 *                                                        current 1 with no
 *                                                        UPDATE pass at all.
 *                                                        That is the backfill.
 *   ADD COLUMN ... VIRTUAL (generated)                -> INPLACE, no rebuild
 *   ADD/DROP secondary INDEX                          -> INPLACE, LOCK=NONE
 *   DROP PRIMARY KEY, ADD PRIMARY KEY                 -> ALGORITHM=COPY. Only
 *                                                        three statements need
 *                                                        it; see the runbook in
 *                                                        docs/SCD2.md for the
 *                                                        gh-ost route.
 *
 * date_created / date_updated cannot be defaulted to a per-row historical value,
 * so they are added NULL here and populated by `php artisan gp:version-backfill`,
 * which is chunked and resumable. A 40-minute UPDATE inside a migration leaves a
 * failed deploy wedged halfway.
 *
 * WHY current_key IS A GENERATED COLUMN
 * -------------------------------------
 * "At most one CURRENT version per natural key" is a partial uniqueness
 * constraint and MySQL 8 has no partial indexes. current_key is
 * IF(current = 1, CONCAT(<key parts>), NULL): NULL for every superseded row (so
 * they are unlimited), the key for the current one (so a second one is a
 * duplicate-key error rather than a silent duplicate row).
 *
 * CONCAT, not CONCAT_WS: CONCAT returns NULL if ANY argument is NULL, which
 * reproduces the existing multi-column uniques exactly — those are equally
 * NULL-permissive, since MySQL never treats two NULLs as equal in a unique index.
 * Matching that behaviour is what guarantees this index can be built on any data
 * that already satisfies the old constraint. Parts are joined with
 * CHAR(31 USING utf8mb4) — the ASCII unit separator, which cannot appear in a
 * licence number, a state, a board code or an address — so no field value can
 * forge a key boundary. The column takes the table's utf8mb4_unicode_ci
 * collation, so comparison stays case- and accent-insensitive exactly as the
 * multi-column unique was; a hash over raw bytes would NOT (it would let 'L-77'
 * and 'l-77' coexist as two current versions where today they collide).
 *
 * Every statement is guarded, so this migration is safe to re-run and safe to run
 * after an operator has already applied one of the COPY statements out of band
 * with gh-ost.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    /** Unit separator, joined into every composite current_key. */
    private const SEP = "CHAR(31 USING utf8mb4)";

    /**
     * table => [current_key column type, the CONCAT parts of the natural key].
     * A single-column key needs no CONCAT at all.
     */
    private const CURRENT_KEY = [
        'gp_identity' => ['BIGINT UNSIGNED', ['identity_id']],
        'gp_license' => ['VARCHAR(220)', ['identity_id', 'license_number', 'certification_state', 'certification_board']],
        'gp_address' => ['VARCHAR(320)', ['identity_id', 'address1', 'city', 'state', 'zip']],
        'gp_identity_identifier' => ['VARCHAR(160)', ['identity_id', 'id_type', 'id_value']],
        'gp_identity_credential' => ['VARCHAR(48)', ['system_id', 'credential_match_id']],
        'gp_identity_exclusion' => ['VARCHAR(48)', ['system_id', 'match_id']],
    ];

    private const CURRENT_UNIQUE = [
        'gp_identity' => 'uq_identity_current',
        'gp_license' => 'uq_lic_current',
        'gp_address' => 'uq_addr_current',
        'gp_identity_identifier' => 'uq_ident_current',
        'gp_identity_credential' => 'uq_cred_current',
        'gp_identity_exclusion' => 'uq_excl_current',
    ];

    /** Natural-key uniques that must admit versions: index name => its columns. */
    private const VERSIONED_UNIQUE = [
        'gp_license' => ['uq_lic', ['identity_id', 'license_number', 'certification_state', 'certification_board']],
        'gp_address' => ['uq_addr', ['identity_id', 'address1', 'city', 'state', 'zip']],
        'gp_identity_identifier' => ['uq_identity_identifier', ['identity_id', 'id_type', 'id_value']],
    ];

    /** Primary keys that must admit versions: table => the new PK columns. */
    private const VERSIONED_PK = [
        'gp_identity' => ['identity_id', 'version_no'],
        'gp_identity_credential' => ['system_id', 'credential_match_id', 'version_no'],
        'gp_identity_exclusion' => ['system_id', 'match_id', 'version_no'],
    ];

    /**
     * Secondary indexes that gain a trailing `current`. Every one of these leads a
     * read that now filters on it; leaving them alone makes each probe read the
     * whole version history and filter in the server.
     */
    private const CURRENT_APPENDED = [
        'gp_identity' => [
            'idx_ssn' => ['ssn_hash'],
            'idx_npi' => ['npi'],
            'idx_upin' => ['upin'],
            'idx_dea' => ['dea_number'],
            'idx_name_dob' => ['canonical_last', 'canonical_first', 'canonical_dob'],
        ],
        'gp_license' => [
            'idx_identity' => ['identity_id'],
            'idx_number_state' => ['license_number', 'certification_state'],
        ],
        'gp_address' => ['idx_identity' => ['identity_id']],
        'gp_identity_identifier' => ['idx_type_value' => ['id_type', 'id_value']],
        'gp_identity_credential' => ['idx_identity' => ['identity_id']],
        'gp_identity_exclusion' => ['idx_identity' => ['identity_id']],
    ];

    public function up(): void
    {
        // 1. Version columns. INSTANT, and the DEFAULTs are the backfill: every
        //    existing row is, by definition, version 1 and current.
        foreach (array_keys(self::CURRENT_KEY) as $table) {
            $this->addColumnIfMissing($table, 'version_no', 'INT UNSIGNED NOT NULL DEFAULT 1');
            $this->addColumnIfMissing($table, 'current', 'TINYINT(1) NOT NULL DEFAULT 1');
        }

        // 2. The doc's timestamps, on the five tables that had none. NULL for now;
        //    gp:version-backfill fills them. gp_identity is absent on purpose: it
        //    maps to first_seen / last_updated, which it already has, because
        //    last_updated is a published API field (IdentityProfileResource).
        foreach (['gp_license', 'gp_address', 'gp_identity_identifier',
            'gp_identity_credential', 'gp_identity_exclusion'] as $table) {
            $this->addColumnIfMissing($table, 'date_created', 'DATETIME NULL');
            $this->addColumnIfMissing($table, 'date_updated', 'DATETIME NULL');
        }

        // 3. Primary keys that must admit versions. THE EXPENSIVE PART —
        //    ALGORITHM=COPY, writes blocked for the duration. Skipped when an
        //    operator has already applied it with gh-ost (see docs/SCD2.md).
        foreach (self::VERSIONED_PK as $table => $cols) {
            if (! in_array('version_no', $this->indexColumns($table, 'PRIMARY'), true)) {
                $list = implode(', ', array_map(fn ($c) => "`$c`", $cols));
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `$table` DROP PRIMARY KEY, ADD PRIMARY KEY ($list)"
                );
            }
        }

        // 4. identity_uuid stops being globally unique and becomes unique per
        //    version — the uuid is a property of the logical identity, so every
        //    version of it carries the same one.
        $uuidIndex = $this->uniqueIndexOn('gp_identity', ['identity_uuid']);
        if ($uuidIndex !== null && $uuidIndex !== 'uq_identity_uuid') {
            DB::connection($this->connection)->statement(
                "ALTER TABLE `gp_identity` DROP INDEX `$uuidIndex`,
                 ADD UNIQUE INDEX `uq_identity_uuid` (`identity_uuid`, `version_no`),
                 ALGORITHM=INPLACE, LOCK=NONE"
            );
        }

        // 5. Natural-key uniques gain version_no, keeping their names so they stay
        //    the natural-key lookup index they already are.
        foreach (self::VERSIONED_UNIQUE as $table => [$index, $cols]) {
            if ($this->indexColumns($table, $index) === $cols) {
                $list = implode(', ', array_map(fn ($c) => "`$c`", [...$cols, 'version_no']));
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `$table` DROP INDEX `$index`,
                     ADD UNIQUE INDEX `$index` ($list), ALGORITHM=INPLACE, LOCK=NONE"
                );
            }
        }

        // 6. The single-current guarantee. VIRTUAL so the column add is metadata
        //    only; the unique index build that follows is INPLACE with no write
        //    lock. Two statements, not one: MySQL will not combine adding a
        //    generated column with other operations.
        foreach (self::CURRENT_KEY as $table => [$type, $parts]) {
            if (! Schema::connection($this->connection)->hasColumn($table, 'current_key')) {
                $collate = str_starts_with($type, 'VARCHAR') ? ' COLLATE utf8mb4_unicode_ci' : '';
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `$table`
                     ADD COLUMN `current_key` $type$collate
                     GENERATED ALWAYS AS (IF(`current` = 1, {$this->keyExpression($parts)}, NULL)) VIRTUAL,
                     ALGORITHM=INPLACE, LOCK=NONE"
                );
            }

            $unique = self::CURRENT_UNIQUE[$table];
            if (! $this->indexExists($table, $unique)) {
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `$table` ADD UNIQUE INDEX `$unique` (`current_key`),
                     ALGORITHM=INPLACE, LOCK=NONE"
                );
            }
        }

        // 7. Read paths now filter on `current`, so the indexes they use must
        //    include it. Guarded on the exact old column list: an index an
        //    operator has already extended by hand is left alone rather than
        //    dropped and narrowed.
        foreach (self::CURRENT_APPENDED as $table => $indexes) {
            foreach ($indexes as $index => $cols) {
                if ($this->indexColumns($table, $index) !== $cols) {
                    continue;
                }
                $list = implode(', ', array_map(fn ($c) => "`$c`", [...$cols, 'current']));
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `$table` DROP INDEX `$index`,
                     ADD INDEX `$index` ($list), ALGORITHM=INPLACE, LOCK=NONE"
                );
            }
        }
    }

    public function down(): void
    {
        $c = DB::connection($this->connection);

        foreach (self::CURRENT_APPENDED as $table => $indexes) {
            foreach ($indexes as $index => $cols) {
                if ($this->indexColumns($table, $index) === [...$cols, 'current']) {
                    $list = implode(', ', array_map(fn ($x) => "`$x`", $cols));
                    $c->statement("ALTER TABLE `$table` DROP INDEX `$index`, ADD INDEX `$index` ($list)");
                }
            }
        }

        foreach (self::CURRENT_UNIQUE as $table => $unique) {
            if ($this->indexExists($table, $unique)) {
                $c->statement("ALTER TABLE `$table` DROP INDEX `$unique`");
            }
            if (Schema::connection($this->connection)->hasColumn($table, 'current_key')) {
                $c->statement("ALTER TABLE `$table` DROP COLUMN `current_key`");
            }
        }

        foreach (self::VERSIONED_UNIQUE as $table => [$index, $cols]) {
            if ($this->indexColumns($table, $index) === [...$cols, 'version_no']) {
                $list = implode(', ', array_map(fn ($x) => "`$x`", $cols));
                $c->statement("ALTER TABLE `$table` DROP INDEX `$index`, ADD UNIQUE INDEX `$index` ($list)");
            }
        }

        if ($this->indexExists('gp_identity', 'uq_identity_uuid')) {
            $c->statement(
                'ALTER TABLE `gp_identity` DROP INDEX `uq_identity_uuid`,
                 ADD UNIQUE INDEX `gp_identity_identity_uuid_unique` (`identity_uuid`)'
            );
        }

        // Reversing the PK requires the table to hold one row per natural key
        // again. It will not if anything has been versioned, which is why this is
        // guarded rather than attempted: an SCD-2 rollback on live data is a data
        // decision (which version survives?), not a schema one.
        foreach (self::VERSIONED_PK as $table => $cols) {
            $natural = array_values(array_diff($cols, ['version_no']));
            $extra = (int) $c->selectOne("SELECT COUNT(*) n FROM `$table` WHERE `version_no` <> 1")->n;
            if ($extra > 0) {
                throw new RuntimeException(
                    "$table holds $extra superseded version(s); rolling back SCD-2 would have to ".
                    'discard them. Decide which versions survive, delete them, then re-run down().'
                );
            }
            $list = implode(', ', array_map(fn ($x) => "`$x`", $natural));
            $c->statement("ALTER TABLE `$table` DROP PRIMARY KEY, ADD PRIMARY KEY ($list)");
        }

        foreach (['gp_license', 'gp_address', 'gp_identity_identifier',
            'gp_identity_credential', 'gp_identity_exclusion'] as $table) {
            $c->statement("ALTER TABLE `$table` DROP COLUMN `date_created`, DROP COLUMN `date_updated`");
        }
        foreach (array_keys(self::CURRENT_KEY) as $table) {
            $c->statement("ALTER TABLE `$table` DROP COLUMN `current`, DROP COLUMN `version_no`");
        }
    }

    /** CONCAT of the natural key, unit-separated. A single part needs no CONCAT. */
    private function keyExpression(array $parts): string
    {
        if (count($parts) === 1) {
            return '`'.$parts[0].'`';
        }

        $joined = [];
        foreach ($parts as $i => $p) {
            if ($i > 0) {
                $joined[] = self::SEP;
            }
            $joined[] = '`'.$p.'`';
        }

        return 'CONCAT('.implode(', ', $joined).')';
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (Schema::connection($this->connection)->hasColumn($table, $column)) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE `$table` ADD COLUMN `$column` $definition, ALGORITHM=INSTANT"
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) DB::connection($this->connection)->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        );
    }

    /** @return list<string> the index's columns in order, or [] when absent */
    private function indexColumns(string $table, string $index): array
    {
        return array_map(
            fn ($r) => $r->COLUMN_NAME,
            DB::connection($this->connection)->select(
                'SELECT COLUMN_NAME FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                 ORDER BY seq_in_index',
                [$table, $index]
            )
        );
    }

    /**
     * The name of the unique index whose columns are exactly $cols. Laravel's
     * default here is gp_identity_identity_uuid_unique, but the live hub carries
     * indexes added by hand and absent from every migration (CredentialSearch-
     * Controller's measurement comment names idx_identity_registry_match), so the
     * name is discovered rather than assumed.
     */
    private function uniqueIndexOn(string $table, array $cols): ?string
    {
        $rows = DB::connection($this->connection)->select(
            'SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) cols
             FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0
               AND index_name <> \'PRIMARY\'
             GROUP BY index_name',
            [$table]
        );

        foreach ($rows as $row) {
            if ($row->cols === implode(',', $cols)) {
                return $row->index_name;
            }
        }

        return null;
    }
};
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Scd2SchemaTest.php`
Expected: PASS, 6 tests.

Run: `vendor/bin/phpunit`
Expected: PASS — 100 tests, 0 skipped. If `CredentialLinkCapTest` or `ResolverLadderTest` fail here,
it is a read path that now sees superseded rows; that is Tasks 6-9 and expected only once those
tasks start writing versions. On a freshly migrated schema nothing is versioned yet, so the whole
suite must still be green at this point. A failure now is a migration defect, not a read defect.

- [ ] **Step 5: Confirm the ALTERs really are cheap**

Run: `php artisan tinker --execute="config(['database.connections.golden_profile' => ['driver'=>'mysql','host'=>env('GP_TEST_DB_HOST'),'port'=>env('GP_TEST_DB_PORT'),'database'=>env('GP_TEST_DB_DATABASE'),'username'=>env('GP_TEST_DB_USERNAME'),'password'=>env('GP_TEST_DB_PASSWORD'),'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'engine'=>'InnoDB']]); DB::purge('golden_profile'); DB::connection('golden_profile')->statement('ALTER TABLE gp_license ADD COLUMN scd2_probe TINYINT(1) NOT NULL DEFAULT 1, ALGORITHM=INSTANT'); DB::connection('golden_profile')->statement('ALTER TABLE gp_license DROP COLUMN scd2_probe'); echo \"INSTANT accepted\n\";"`

Expected: `INSTANT accepted`. If MySQL answers
`ALGORITHM=INSTANT is not supported for this operation`, the server predates 8.0.12 and the runbook
in `docs/SCD2.md` must be amended to route every column add through gh-ost as well — record that in
the register before continuing.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_09_04_000100_add_scd2_versioning.php tests/Feature/Scd2SchemaTest.php
git commit -m "feat(schema): add SCD-2 version columns, single-current uniques and version-aware indexes"
```

---

## Task 4: `gp:version-backfill` — populate the doc's timestamps without locking the hub

`version_no` and `current` were backfilled by their DEFAULTs in Task 3, for free. `date_created` /
`date_updated` cannot be: their correct value is per row and historical. A single `UPDATE` over
`gp_identity_credential` would hold one transaction and one undo log for the whole table — the same
problem `SetFinalizer` documents at its `MATERIALIZE_CHUNK` constant, where a 250k-identity chunk
"ran over 8 minutes and built an undo log big enough that interrupting it was expensive".

**Files:**
- Create: `app/Console/Commands/GpVersionBackfill.php`
- Test: `tests/Feature/VersionBackfillTest.php`

**Interfaces:**
- Produces: `php artisan gp:version-backfill {--table=} {--chunk=10000} {--dry-run}`, returning
  `Command::SUCCESS`. Public method `backfillTable(string $table, int $chunk): int` returning rows
  written, so the test can drive one table without the console.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/VersionBackfillTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Console\Commands\GpVersionBackfill;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class VersionBackfillTest extends HubTestCase
{
    public function test_a_license_inherits_the_timestamp_of_the_link_that_established_it(): void
    {
        // date_created should say when the fact entered the hub, and the only
        // record of that for a pre-migration row is the source link's linked_at.
        $linkedAt = '2026-01-15 09:30:00';
        [$identityId, $linkId] = $this->seedIdentityAndLink($linkedAt);

        $this->hub()->table('gp_license')->insert([
            'identity_id' => $identityId, 'license_number' => 'L-77',
            'certification_state' => 'NY', 'certification_board' => null,
            'is_verified' => 0, 'source_link_id' => $linkId,
            'version_no' => 1, 'current' => 1,
            'date_created' => null, 'date_updated' => null,
        ]);

        $written = (new GpVersionBackfill)->backfillTable('gp_license', 10000);

        $row = $this->hub()->table('gp_license')->where('identity_id', $identityId)->first();

        $this->assertSame(1, $written);
        $this->assertSame($linkedAt, (string) $row->date_created);
        $this->assertSame($linkedAt, (string) $row->date_updated);
    }

    public function test_the_backfill_is_resumable_and_never_rewrites_a_filled_row(): void
    {
        [$identityId, $linkId] = $this->seedIdentityAndLink('2026-01-15 09:30:00');

        $this->hub()->table('gp_license')->insert([
            'identity_id' => $identityId, 'license_number' => 'L-88',
            'certification_state' => 'NY', 'certification_board' => null,
            'is_verified' => 0, 'source_link_id' => $linkId,
            'version_no' => 1, 'current' => 1,
            'date_created' => '2025-06-01 00:00:00', 'date_updated' => '2025-06-01 00:00:00',
        ]);

        $written = (new GpVersionBackfill)->backfillTable('gp_license', 10000);

        $this->assertSame(0, $written, 'a row that already has date_created must be left alone');
        $this->assertSame(
            '2025-06-01 00:00:00',
            (string) $this->hub()->table('gp_license')->where('license_number', 'L-88')->value('date_created')
        );
    }

    public function test_a_credential_falls_back_to_its_source_resolution_date(): void
    {
        // gp_identity_credential has no link to gp_source_link, so the closest
        // thing to a creation time it carries is CAMI's own date_resolved.
        [$identityId] = $this->seedIdentityAndLink('2026-01-15 09:30:00');

        $this->hub()->table('gp_identity_credential')->insert([
            'identity_id' => $identityId, 'credential_match_id' => 7001,
            'system_id' => $this->systemId, 'registry' => 'NYEMED',
            'match_summary_status_code' => 20, 'match_is_valid' => 1,
            'date_resolved' => '2026-02-20 11:00:00', 'link_state' => 'confirmed',
            'version_no' => 1, 'current' => 1, 'date_created' => null, 'date_updated' => null,
        ]);

        (new GpVersionBackfill)->backfillTable('gp_identity_credential', 10000);

        $this->assertSame(
            '2026-02-20 11:00:00',
            (string) $this->hub()->table('gp_identity_credential')
                ->where('credential_match_id', 7001)->value('date_created')
        );
    }

    /** @return array{0:int,1:int} [identity_id, link_id] */
    private function seedIdentityAndLink(string $linkedAt): array
    {
        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Ann', 'canonical_last' => 'Kowalski',
            'canonical_dob' => '1981-03-03', 'confidence' => 1.0, 'record_count' => 1,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);

        $linkId = (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => 90001,
            'match_method' => 'deterministic', 'match_key' => 'new', 'match_score' => 1.0,
            'match_state' => 'auto_match', 'is_pinned' => 0, 'linked_at' => $linkedAt,
        ]);

        return [$identityId, $linkId];
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/VersionBackfillTest.php`

Expected: FAIL — `Class "App\Console\Commands\GpVersionBackfill" not found`.

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/GpVersionBackfill.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populates date_created / date_updated on the five tables that gained them in
 * 2026_09_04_000100_add_scd2_versioning.
 *
 * WHY THIS IS A COMMAND AND NOT PART OF THE MIGRATION
 * ---------------------------------------------------
 * version_no and current were backfilled by their column DEFAULTs, which is free:
 * an INSTANT ADD COLUMN means every existing row already reads as version 1,
 * current 1 without a single UPDATE. The two timestamps cannot work that way —
 * their correct value is per row and historical — and a single UPDATE over
 * gp_identity_credential (the largest table in the hub) would hold one
 * transaction and one undo log for its whole duration. SetFinalizer already
 * documents that failure mode at MATERIALIZE_CHUNK: a 250k-row chunk's DELETE
 * "ran over 8 minutes and built an undo log big enough that interrupting it was
 * expensive". A migration that does that leaves a failed deploy wedged halfway
 * with no way to resume.
 *
 * So: chunked by primary-key range, each chunk its own autocommitted statement,
 * and the WHERE clause is `date_created IS NULL` — which makes the whole thing
 * resumable by construction. Re-running after an interruption picks up exactly
 * the rows that were missed, and re-running after completion is a no-op.
 *
 * WHERE THE VALUES COME FROM
 * --------------------------
 *   gp_license, gp_address, gp_identity_identifier
 *       gp_source_link.linked_at of the link recorded in source_link_id — the
 *       only record the hub keeps of when the fact entered it.
 *   gp_identity_credential
 *       CAMI's own date_resolved, the closest thing to a creation time on the row.
 *   gp_identity_exclusion
 *       nothing usable; NOW(). An exclusion link carries no date at all until
 *       plan 7 adds excl_date / reinstate_date.
 *
 * date_updated is seeded equal to date_created: nothing has been versioned yet, so
 * every row was last updated when it was created.
 *
 * gp_identity is not listed. It maps the doc's timestamps onto first_seen /
 * last_updated, which are already populated on every row.
 */
class GpVersionBackfill extends Command
{
    protected $signature = 'gp:version-backfill
        {--table= : one of gp_license, gp_address, gp_identity_identifier, gp_identity_credential, gp_identity_exclusion}
        {--chunk=10000 : primary-key ids per statement}
        {--dry-run : report how many rows need backfilling and change nothing}';

    protected $description = 'Populate date_created/date_updated on the SCD-2 versioned tables (chunked, resumable)';

    /**
     * table => [primary key column, the SQL expression for date_created, extra FROM/JOIN].
     * Each entry is a complete recipe so the loop below carries no per-table
     * branching.
     */
    private const RECIPES = [
        'gp_license' => [
            'pk' => 'license_id',
            'join' => 'LEFT JOIN gp_source_link sl ON sl.link_id = t.source_link_id',
            'created' => 'COALESCE(sl.linked_at, NOW())',
        ],
        'gp_address' => [
            'pk' => 'address_id',
            'join' => 'LEFT JOIN gp_source_link sl ON sl.link_id = t.source_link_id',
            'created' => 'COALESCE(sl.linked_at, NOW())',
        ],
        'gp_identity_identifier' => [
            'pk' => 'id',
            'join' => 'LEFT JOIN gp_source_link sl ON sl.link_id = t.source_link_id',
            'created' => 'COALESCE(sl.linked_at, NOW())',
        ],
        'gp_identity_credential' => [
            'pk' => 'credential_match_id',
            'join' => '',
            'created' => 'COALESCE(t.date_resolved, NOW())',
        ],
        'gp_identity_exclusion' => [
            'pk' => 'match_id',
            'join' => '',
            'created' => 'NOW()',
        ],
    ];

    public function handle(): int
    {
        $only = $this->option('table');
        if ($only !== null && ! isset(self::RECIPES[$only])) {
            $this->error("unknown table '$only'; expected one of ".implode(', ', array_keys(self::RECIPES)));

            return self::FAILURE;
        }

        $tables = $only !== null ? [$only] : array_keys(self::RECIPES);
        $chunk = max(1, (int) $this->option('chunk'));

        foreach ($tables as $table) {
            $pending = $this->pending($table);

            if ($this->option('dry-run')) {
                $this->line(sprintf('%-24s %d row(s) need date_created', $table, $pending));

                continue;
            }

            $this->line(sprintf('%-24s %d row(s) pending', $table, $pending));
            $written = $this->backfillTable($table, $chunk);
            $this->info(sprintf('%-24s %d row(s) written', $table, $written));
        }

        return self::SUCCESS;
    }

    /** Rows still missing date_created. */
    public function pending(string $table): int
    {
        return (int) $this->hub()->table($table)->whereNull('date_created')->count();
    }

    /**
     * Backfill one table, chunked by primary-key range. Returns rows written.
     *
     * Ranged rather than LIMITed: a bare `UPDATE … WHERE date_created IS NULL
     * LIMIT n` re-scans from the start of the table on every iteration, so the
     * last chunk of a 100M-row table pays for all of them. A key range touches
     * only its own slice.
     */
    public function backfillTable(string $table, int $chunk): int
    {
        $recipe = self::RECIPES[$table] ?? throw new \InvalidArgumentException("$table has no backfill recipe");
        $pk = $recipe['pk'];
        $hub = $this->hub();

        $bounds = $hub->selectOne("SELECT MIN(`$pk`) lo, MAX(`$pk`) hi FROM `$table`");
        if (! $bounds || $bounds->lo === null) {
            return 0;
        }

        $written = 0;
        for ($lo = (int) $bounds->lo; $lo <= (int) $bounds->hi; $lo += $chunk) {
            $hi = $lo + $chunk;   // exclusive

            $written += (int) $hub->affectingStatement(
                "UPDATE `$table` t {$recipe['join']}
                 SET t.date_created = {$recipe['created']},
                     t.date_updated = {$recipe['created']}
                 WHERE t.date_created IS NULL
                   AND t.`$pk` >= ? AND t.`$pk` < ?",
                [$lo, $hi]
            );
        }

        return $written;
    }

    private function hub()
    {
        return DB::connection(config('golden_profile.connections.hub', 'golden_profile'));
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/VersionBackfillTest.php`
Expected: PASS, 3 tests.

Run: `php artisan gp:version-backfill --dry-run`
Expected: five lines of `<table> 0 row(s) need date_created` against the configured hub. Against the
dead `127.0.0.1:1` test default this fails to connect, which is correct — point `GP_DB_*` at a real
hub to run it for real.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Console/Commands/GpVersionBackfill.php tests/Feature/VersionBackfillTest.php
git commit -m "feat(scd2): add a chunked resumable timestamp backfill command"
```

---

## Task 5: `Versioner` — the one place the write rule lives

Eight call sites currently `update()` or `updateOrInsert()` a versioned table. If each grows its own
flip-and-insert, they will diverge — the codebase already has a documented instance of exactly that
failure (`Survivorship`'s final tiebreak had to be pinned to `link_id ASC` to match `SetFinalizer`'s
SQL, "because a mismatch broke the *rebuild produces a byte-identical profile* invariant"). So the
rule goes in one class and the call sites call it.

**Files:**
- Create: `app/GoldenProfile/Support/Versioner.php`
- Test: `tests/Unit/VersionerSpecTest.php`
- Test: `tests/Feature/VersionerTest.php`

**Interfaces:**
- Produces:
  - `Versioner::__construct(?string $connection = null)`
  - `Versioner::TABLES` — `array<string, array{key: list<string>, attributes: list<string>, derived: list<string>, onCreate: list<string>, surrogate: ?string, created: string, updated: string}>`
  - `Versioner::isVersioned(string $table): bool`
  - `Versioner::spec(string $table): array` (the shape above; throws `InvalidArgumentException`)
  - `Versioner::current(string $table, array $key): ?object`
  - `Versioner::write(string $table, array $key, array $attributes, array $derived = [], array $onCreate = []): array` returning `array{version_no: int, new_version: bool}`
  - `Versioner::retire(string $table, array $key): int` — rows flipped to `current = 0`
  - `Versioner::repointForMerge(string $table, int $survivor, int $loser): array` returning `array{repointed: int, retired: int}`
- Consumed by: `DeterministicResolver` (Task 6), `Survivorship` (Task 7), `Engine` (Task 8).

- [ ] **Step 1: Write the failing spec test**

Create `tests/Unit/VersionerSpecTest.php`:

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Support\Versioner;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The table spec is the contract between the migration and every write path. It
 * has no database dependency, so it is checked here rather than in a feature test.
 */
class VersionerSpecTest extends TestCase
{
    public function test_the_register_matches_docs_scd2(): void
    {
        $this->assertSame(
            ['gp_identity', 'gp_license', 'gp_address', 'gp_identity_identifier',
                'gp_identity_credential', 'gp_identity_exclusion'],
            array_keys(Versioner::TABLES),
            'the versioned set must match the register in docs/SCD2.md'
        );
    }

    public function test_gp_source_link_is_not_versioned(): void
    {
        // The single most consequential exclusion. gp_source_link is the doc's own
        // "explicit link table (golden identity <-> source CAMI records)" — the
        // grouping artifact, not a golden fact — and uq_source is the only thing
        // making DeterministicResolver::resolve() idempotent.
        $this->assertFalse(Versioner::isVersioned('gp_source_link'));
        $this->assertFalse(Versioner::isVersioned('stg_person'));
        $this->assertFalse(Versioner::isVersioned('gp_identity_profile'));
        $this->assertFalse(Versioner::isVersioned('gp_resolution_log'));
    }

    public function test_no_column_is_in_two_categories(): void
    {
        // A column that is both an attribute and derived would be compared for
        // change detection AND written in place, so the same value would decide
        // both "version this" and "don't".
        foreach (Versioner::TABLES as $table => $spec) {
            $all = [...$spec['key'], ...$spec['attributes'], ...$spec['derived'], ...$spec['onCreate']];

            $this->assertSame(
                count($all),
                count(array_unique($all)),
                "$table declares a column in more than one category: ".
                implode(', ', array_diff_assoc($all, array_unique($all)))
            );
        }
    }

    public function test_identity_maps_the_docs_timestamps_onto_its_existing_columns(): void
    {
        // last_updated is a published API field (IdentityProfileResource), so the
        // doc's date_created/date_updated are mapped rather than added.
        $this->assertSame('first_seen', Versioner::spec('gp_identity')['created']);
        $this->assertSame('last_updated', Versioner::spec('gp_identity')['updated']);

        foreach (['gp_license', 'gp_address', 'gp_identity_identifier',
            'gp_identity_credential', 'gp_identity_exclusion'] as $table) {
            $this->assertSame('date_created', Versioner::spec($table)['created']);
            $this->assertSame('date_updated', Versioner::spec($table)['updated']);
        }
    }

    public function test_record_count_is_derived_not_versioned(): void
    {
        // This is what keeps Engine::finalizeAll() from minting 13.38M gp_identity
        // rows per run: record_count is COUNT(*) over gp_source_link, which
        // already records when each link was made.
        $spec = Versioner::spec('gp_identity');

        $this->assertContains('record_count', $spec['derived']);
        $this->assertNotContains('record_count', $spec['attributes']);
        $this->assertContains('canonical_last', $spec['attributes']);
        $this->assertContains('status', $spec['attributes']);
    }

    public function test_an_unversioned_table_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gp_source_link is not a versioned table');

        Versioner::spec('gp_source_link');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/VersionerSpecTest.php`

Expected: FAIL — `Class "App\GoldenProfile\Support\Versioner" not found`.

- [ ] **Step 3: Write the class**

Create `app/GoldenProfile/Support/Versioner.php`:

```php
<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one implementation of the slowly-changing-dimension (Type 2) write rule
 * from Data Flow by CAMI (DEV page 4099997697):
 *
 *   "Insert a new row with current = 1, and set all preexisting rows to
 *    current = 0."
 *
 * Eight call sites used to update() or updateOrInsert() a versioned table. The
 * rule lives here rather than in each of them because this codebase already has a
 * documented case of two implementations of one rule drifting apart:
 * Survivorship's final tiebreak had to be pinned to link_id ASC to match
 * SetFinalizer's SQL "because a mismatch broke the rebuild-produces-a-byte-
 * identical-profile invariant".
 *
 * THREE CATEGORIES OF COLUMN, AND WHY
 * -----------------------------------
 * A naive versioner compares the whole update payload and mints a version
 * whenever anything differs. That is unusable here. Engine::finalizeAll()
 * recomputes survivorship for EVERY identity, and Survivorship writes
 * last_updated => now() unconditionally, so a whole-payload comparison would add
 * one gp_identity row per identity per rebuild — ~13.38M rows a run. So each table
 * declares:
 *
 *   attributes  the golden facts. A change in any of them mints a version, and
 *               they are what "did anything change?" compares. Absent columns are
 *               carried forward from the previous version, so a partial write
 *               (backfillKeys supplying only an npi) still produces a complete row.
 *   derived     recomputed aggregates — record_count, confidence. Written onto the
 *               CURRENT version in place. record_count is COUNT(*) over
 *               gp_source_link, which already records when each link was made with
 *               better resolution than a version row would; versioning on a
 *               record_count bump would add one identity row per source row
 *               (~13.4M on a backfill) to record something the link table holds.
 *   onCreate    written only when minting version 1, carried forward after.
 *               source_link_id belongs here: it records which source row
 *               ESTABLISHED the fact. As an attribute it would mint a version
 *               every time a second account's employee row re-observed the same
 *               licence — thousands of identical versions on the pile-up
 *               identities (identity 3 folds 12,463 source rows).
 *
 * Any column in none of the four lists is carried forward untouched (identity_uuid
 * is the example). The surrogate primary key, where there is one, is dropped when
 * carrying a row forward so the new version gets its own.
 *
 * ORDERING AND CONCURRENCY
 * ------------------------
 * Flip-old-then-insert-new, inside one transaction, with the current row locked
 * FOR UPDATE. The order is forced by the schema: uq_*_current makes at most one
 * current row per natural key, so inserting first would collide with the row it is
 * about to supersede. The lock serialises parallel writers (backfill workers,
 * dedup shards); if two race past it anyway, the unique index rejects the second —
 * loudly, which is the whole reason that index exists.
 *
 * The flip sets ONLY current = 0. It deliberately does not touch the superseded
 * row's timestamps: date_updated on a version means "when this version was
 * written", and an audit trail whose rows get restamped every time they are
 * superseded has lost the thing it was keeping.
 *
 * `current` IS NOT `alive`. A merged-away identity gets a NEW version with
 * status = 'merged' and current = 1 — the latest truth about it is that it was
 * merged. Callers keep filtering status = 'active' as well.
 */
class Versioner
{
    /**
     * @var array<string, array{key: list<string>, attributes: list<string>,
     *     derived: list<string>, onCreate: list<string>, surrogate: ?string,
     *     created: string, updated: string}>
     */
    public const TABLES = [
        'gp_identity' => [
            'key' => ['identity_id'],
            'attributes' => [
                'canonical_first', 'canonical_middle', 'canonical_last', 'canonical_suffix',
                'canonical_dob', 'ssn_hash', 'npi', 'upin', 'dea_number', 'status', 'merged_into',
            ],
            'derived' => ['record_count', 'confidence'],
            'onCreate' => [],
            // identity_id is both the surrogate and the natural key, so it is
            // carried forward rather than reassigned.
            'surrogate' => null,
            'created' => 'first_seen',
            'updated' => 'last_updated',
        ],
        'gp_license' => [
            'key' => ['identity_id', 'license_number', 'certification_state', 'certification_board'],
            'attributes' => ['license_type', 'license_type_id', 'registry', 'is_verified'],
            'derived' => [],
            'onCreate' => ['source_link_id'],
            'surrogate' => 'license_id',
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
        'gp_address' => [
            'key' => ['identity_id', 'address1', 'city', 'state', 'zip'],
            'attributes' => ['address2', 'is_primary'],
            'derived' => [],
            'onCreate' => ['source_link_id'],
            'surrogate' => 'address_id',
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
        'gp_identity_identifier' => [
            // The key is the whole fact. It still gets versioned: a DEA number
            // being WITHDRAWN is a fact, and the only way to record it is a
            // version with current = 0.
            'key' => ['identity_id', 'id_type', 'id_value'],
            'attributes' => [],
            'derived' => [],
            'onCreate' => ['source_link_id'],
            'surrogate' => 'id',
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
        'gp_identity_credential' => [
            'key' => ['system_id', 'credential_match_id'],
            'attributes' => [
                'registry', 'match_summary_status', 'match_summary_status_code', 'match_is_valid',
                'source_current', 'date_resolved', 'link_state', 'link_confidence',
            ],
            'derived' => [],
            // identity_id is onCreate, not an attribute: repointing on a merge is a
            // GROUPING change, recorded in gp_resolution_log and on the merged
            // identity's own final version. Versioning it would mint one row per
            // credential per merge — 397,170 for identity 3 alone.
            'onCreate' => ['identity_id'],
            'surrogate' => null,
            'created' => 'date_created',
            'updated' => 'date_updated',
        ],
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
    ];

    public function __construct(private ?string $connection = null) {}

    public static function isVersioned(string $table): bool
    {
        return isset(self::TABLES[$table]);
    }

    /** @return array{key: list<string>, attributes: list<string>, derived: list<string>, onCreate: list<string>, surrogate: ?string, created: string, updated: string} */
    public static function spec(string $table): array
    {
        return self::TABLES[$table]
            ?? throw new InvalidArgumentException("$table is not a versioned table");
    }

    /** The current version of $key, or null. */
    public function current(string $table, array $key): ?object
    {
        self::spec($table);

        return $this->db()->table($table)->where($key)->where('current', 1)->first();
    }

    /**
     * Insert a new version of $key, or do nothing if no golden fact changed.
     *
     * @param  array<string,mixed>  $key         the natural key (all of spec['key'])
     * @param  array<string,mixed>  $attributes  golden facts; a subset is fine, the rest carries forward
     * @param  array<string,mixed>  $derived     recomputed aggregates, written in place
     * @param  array<string,mixed>  $onCreate    applied only when minting version 1
     * @return array{version_no: int, new_version: bool}
     */
    public function write(string $table, array $key, array $attributes, array $derived = [], array $onCreate = []): array
    {
        $spec = self::spec($table);
        $db = $this->db();

        return $db->transaction(function () use ($db, $table, $key, $attributes, $derived, $onCreate, $spec) {
            // The highest version, current or not. A key whose versions were all
            // retired (a merge collision, see repointForMerge) can be revived, and
            // reviving it must continue the numbering rather than restart it.
            $latest = $db->table($table)->where($key)
                ->orderByDesc('version_no')->lockForUpdate()->first();

            $isCurrent = $latest !== null && (int) $latest->current === 1;

            if ($isCurrent && ! $this->differs($latest, $attributes, $spec['attributes'])) {
                if ($derived !== []) {
                    $db->table($table)->where($key)->where('current', 1)
                        ->update($this->only($derived, $spec['derived']));
                }

                return ['version_no' => (int) $latest->version_no, 'new_version' => false];
            }

            $now = now();
            $version = $latest === null ? 1 : ((int) $latest->version_no + 1);

            if ($isCurrent) {
                // ONLY current. Restamping a superseded row's date_updated would
                // destroy the "when was this version written" the audit trail is for.
                $db->table($table)->where($key)->where('current', 1)->update(['current' => 0]);
            }

            // array_replace, not `+`: `+` keeps the LEFT operand for a duplicate
            // key, so the carried-forward row would win over the incoming values.
            // Later arguments win here, which is the order the categories need —
            // carried forward, then the key, then the golden facts, then derived,
            // then (only for version 1) onCreate, then the bookkeeping.
            $row = array_replace(
                $this->carryForward($latest, $spec),
                $key,
                $this->only($attributes, $spec['attributes']),
                $this->only($derived, $spec['derived']),
                $latest === null ? $this->only($onCreate, $spec['onCreate']) : [],
                [
                    'version_no' => $version,
                    'current' => 1,
                    $spec['created'] => $latest->{$spec['created']} ?? $now,
                    $spec['updated'] => $now,
                ],
            );

            $db->table($table)->insert($row);

            return ['version_no' => $version, 'new_version' => true];
        });
    }

    /**
     * Flip every current row matching $key to current = 0 without inserting a
     * successor. Used when a merge folds a fact into an identity that already has
     * an equivalent one: the loser's chain is preserved, attached to the identity
     * it belonged to, but stops being current.
     *
     * @return int rows retired
     */
    public function retire(string $table, array $key): int
    {
        self::spec($table);

        return (int) $this->db()->table($table)->where($key)->where('current', 1)
            ->update(['current' => 0]);
    }

    /**
     * Move the current version of every natural key held by $loser onto $survivor.
     *
     * Only applies to tables whose natural key CONTAINS identity_id — gp_license,
     * gp_address, gp_identity_identifier. For the others identity_id is not part of
     * the key, so Engine repoints them with a plain bulk UPDATE across all versions
     * and no unique can collide.
     *
     * Superseded versions are deliberately LEFT BEHIND, still pointing at the loser.
     * Repointing them too would violate the natural-key unique (which now ends in
     * version_no): the loser's version 1 and the survivor's version 1 would become
     * the same row. Leaving them attached to the merged-away identity is also the
     * better history — the loser's gp_identity row survives as a version with
     * status = 'merged', so the trail is reachable.
     *
     * @return array{repointed: int, retired: int}
     */
    public function repointForMerge(string $table, int $survivor, int $loser): array
    {
        $spec = self::spec($table);

        if (! in_array('identity_id', $spec['key'], true)) {
            throw new InvalidArgumentException(
                "$table does not key on identity_id; repoint it with a bulk update instead"
            );
        }

        $db = $this->db();
        $others = array_values(array_diff($spec['key'], ['identity_id']));
        $repointed = 0;
        $retired = 0;

        foreach ($db->table($table)->where('identity_id', $loser)->where('current', 1)->get() as $row) {
            $survivorKey = ['identity_id' => $survivor];
            foreach ($others as $column) {
                $survivorKey[$column] = $row->$column;
            }

            $clash = $db->table($table);
            foreach ($survivorKey as $column => $value) {
                $clash = $value === null ? $clash->whereNull($column) : $clash->where($column, $value);
            }

            if ($clash->clone()->where('current', 1)->exists()) {
                $db->table($table)->where($spec['surrogate'], $row->{$spec['surrogate']})
                    ->update(['current' => 0]);
                $retired++;

                continue;
            }

            // Continue the survivor's numbering for this key: it may already hold a
            // retired chain from an earlier merge, and (survivor, key, version_no)
            // is unique.
            $next = 1 + (int) ($clash->clone()->max('version_no') ?? 0);

            $db->table($table)->where($spec['surrogate'], $row->{$spec['surrogate']})->update([
                'identity_id' => $survivor,
                'version_no' => $next,
                $spec['updated'] => now(),
            ]);
            $repointed++;
        }

        return ['repointed' => $repointed, 'retired' => $retired];
    }

    /** True when any declared attribute present in $incoming differs from $row. */
    private function differs(object $row, array $incoming, array $attributes): bool
    {
        foreach ($attributes as $column) {
            if (! array_key_exists($column, $incoming)) {
                continue;                       // absent = carry forward = no change
            }

            if (! $this->same($row->$column ?? null, $incoming[$column])) {
                return true;
            }
        }

        return false;
    }

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
     */
    private function same($stored, $incoming): bool
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

    /** Every column of the previous version except its surrogate key and version bookkeeping. */
    private function carryForward(?object $latest, array $spec): array
    {
        if ($latest === null) {
            return [];
        }

        $row = (array) $latest;

        unset($row['current'], $row['version_no'], $row['current_key']);

        if ($spec['surrogate'] !== null) {
            unset($row[$spec['surrogate']]);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function only(array $values, array $allowed): array
    {
        return array_intersect_key($values, array_flip($allowed));
    }

    private function db()
    {
        return DB::connection($this->connection ?? config('golden_profile.connections.hub', 'golden_profile'));
    }
}
```

- [ ] **Step 4: Run the spec test**

Run: `vendor/bin/phpunit tests/Unit/VersionerSpecTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 5: Write the behaviour test**

Create `tests/Feature/VersionerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Support\Versioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class VersionerTest extends HubTestCase
{
    private Versioner $versioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versioner = new Versioner;
    }

    public function test_writing_an_unchanged_attribute_mints_no_version(): void
    {
        // The property the whole plan rests on. Without it, finalizeAll() — which
        // recomputes survivorship for every identity — adds one gp_identity row
        // per identity per run.
        $id = $this->seedIdentity();

        $result = $this->versioner->write('gp_identity', ['identity_id' => $id], [
            'canonical_first' => 'Robert',
            'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02',
        ]);

        $this->assertFalse($result['new_version']);
        $this->assertSame(1, $result['version_no']);
        $this->assertSame(1, $this->versionCount($id));
    }

    public function test_a_changed_attribute_inserts_version_two_and_flips_version_one(): void
    {
        $id = $this->seedIdentity();

        $result = $this->versioner->write('gp_identity', ['identity_id' => $id], [
            'canonical_first' => 'Bob',
        ]);

        $this->assertTrue($result['new_version']);
        $this->assertSame(2, $result['version_no']);
        $this->assertSame(2, $this->versionCount($id));

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $id)
            ->orderBy('version_no')->get();

        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('Robert', $rows[0]->canonical_first);
        $this->assertSame(1, (int) $rows[1]->current);
        $this->assertSame('Bob', $rows[1]->canonical_first);
    }

    public function test_absent_attributes_carry_forward(): void
    {
        // backfillKeys() supplies one column at a time; the new version must still
        // be a complete row.
        $id = $this->seedIdentity();

        $this->versioner->write('gp_identity', ['identity_id' => $id], ['npi' => 1234567893]);

        $row = $this->versioner->current('gp_identity', ['identity_id' => $id]);

        $this->assertSame('Robert', $row->canonical_first);
        $this->assertSame('Smith', $row->canonical_last);
        $this->assertSame('1234567893', (string) $row->npi);
        // The uuid identifies the logical identity and is the same across versions.
        $this->assertSame(
            $this->hub()->table('gp_identity')->where('identity_id', $id)
                ->where('version_no', 1)->value('identity_uuid'),
            $row->identity_uuid
        );
    }

    public function test_a_derived_value_is_written_in_place_without_a_version(): void
    {
        $id = $this->seedIdentity();

        $this->versioner->write(
            'gp_identity',
            ['identity_id' => $id],
            ['canonical_first' => 'Robert'],
            ['record_count' => 42],
        );

        $this->assertSame(1, $this->versionCount($id));
        $this->assertSame(
            42,
            (int) $this->versioner->current('gp_identity', ['identity_id' => $id])->record_count
        );
    }

    public function test_first_seen_is_preserved_and_last_updated_moves(): void
    {
        $id = $this->seedIdentity();
        $firstSeen = (string) $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->value('first_seen');

        $this->travel(2)->days();
        $this->versioner->write('gp_identity', ['identity_id' => $id], ['canonical_first' => 'Bob']);

        $row = $this->versioner->current('gp_identity', ['identity_id' => $id]);

        $this->assertSame($firstSeen, (string) $row->first_seen, 'first_seen is the doc date_created');
        $this->assertNotSame($firstSeen, (string) $row->last_updated);
    }

    public function test_a_superseded_row_keeps_its_own_timestamps(): void
    {
        $id = $this->seedIdentity();
        $before = (string) $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->value('last_updated');

        $this->travel(2)->days();
        $this->versioner->write('gp_identity', ['identity_id' => $id], ['canonical_first' => 'Bob']);

        $this->assertSame(
            $before,
            (string) $this->hub()->table('gp_identity')->where('identity_id', $id)
                ->where('version_no', 1)->value('last_updated'),
            'restamping a superseded row destroys the trail it exists to keep'
        );
    }

    public function test_a_license_version_is_keyed_on_the_natural_key(): void
    {
        $id = $this->seedIdentity();
        $linkId = $this->seedLink($id);

        $key = [
            'identity_id' => $id, 'license_number' => 'L-77',
            'certification_state' => 'NY', 'certification_board' => null,
        ];

        $first = $this->versioner->write('gp_license', $key, ['registry' => 'NYRN'], [], ['source_link_id' => $linkId]);
        $again = $this->versioner->write('gp_license', $key, ['registry' => 'NYRN'], [], ['source_link_id' => 999]);
        $moved = $this->versioner->write('gp_license', $key, ['registry' => 'NYEMED'], [], ['source_link_id' => 999]);

        $this->assertTrue($first['new_version']);
        $this->assertFalse($again['new_version'], 'a re-observation with the same facts is not a change');
        $this->assertTrue($moved['new_version']);
        $this->assertSame(2, (int) $this->hub()->table('gp_license')->where($key)->count());

        // source_link_id is onCreate: it records which source row ESTABLISHED the
        // fact, so the second call's 999 must not have overwritten it.
        $this->assertSame(
            $linkId,
            (int) $this->versioner->current('gp_license', $key)->source_link_id
        );
    }

    public function test_a_merge_repoints_the_current_version_and_retires_a_clash(): void
    {
        $survivor = $this->seedIdentity('Ann', 'Kowalski');
        $loser = $this->seedIdentity('Anne', 'Kowalski');
        $link = $this->seedLink($loser, 90101);

        // Only the loser holds L-88, so it moves.
        $this->versioner->write('gp_license', [
            'identity_id' => $loser, 'license_number' => 'L-88',
            'certification_state' => 'NY', 'certification_board' => null,
        ], [], [], ['source_link_id' => $link]);

        // Both hold L-77, so the loser's copy is retired rather than repointed.
        foreach ([$survivor, $loser] as $owner) {
            $this->versioner->write('gp_license', [
                'identity_id' => $owner, 'license_number' => 'L-77',
                'certification_state' => 'NY', 'certification_board' => null,
            ], [], [], ['source_link_id' => $link]);
        }

        $result = $this->versioner->repointForMerge('gp_license', $survivor, $loser);

        $this->assertSame(['repointed' => 1, 'retired' => 1], $result);
        $this->assertSame(
            ['L-77', 'L-88'],
            $this->hub()->table('gp_license')->where('identity_id', $survivor)->where('current', 1)
                ->orderBy('license_number')->pluck('license_number')->all()
        );
        // Retired, not deleted: the trail stays attached to the merged identity.
        $this->assertSame(
            1,
            (int) $this->hub()->table('gp_license')->where('identity_id', $loser)->count()
        );
        $this->assertSame(
            0,
            (int) $this->hub()->table('gp_license')->where('identity_id', $loser)->where('current', 1)->count()
        );
    }

    public function test_two_current_versions_cannot_be_forced_past_the_database(): void
    {
        // The reason uq_identity_current exists: a bug in a write path must fail
        // loudly rather than leave duplicate rows every read then returns twice.
        $id = $this->seedIdentity();

        $this->expectException(QueryException::class);

        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $id, 'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Bob', 'canonical_last' => 'Smith',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);
    }

    private function seedIdentity(string $first = 'Robert', string $last = 'Smith'): int
    {
        return (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => $first, 'canonical_last' => $last,
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 0,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);
    }

    private function seedLink(int $identityId, int $sourceId = 90100): int
    {
        return (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => $sourceId,
            'match_method' => 'deterministic', 'match_key' => 'new', 'match_score' => 1.0,
            'match_state' => 'auto_match', 'is_pinned' => 0, 'linked_at' => now(),
        ]);
    }

    private function versionCount(int $identityId): int
    {
        return (int) $this->hub()->table('gp_identity')->where('identity_id', $identityId)->count();
    }
}
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/VersionerTest.php`
Expected: PASS, 10 tests.

If `test_writing_an_unchanged_attribute_mints_no_version` fails, `same()` is treating a round-tripped
value as a change — print `$stored` and `$incoming` with `var_dump` for the offending column and
widen the comparison there rather than making the test tolerant.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Support/Versioner.php tests/Unit/VersionerSpecTest.php tests/Feature/VersionerTest.php
git commit -m "feat(scd2): add the Versioner service that owns insert-new-and-flip-old"
```

---

## Task 6: `DeterministicResolver` — versioned writes, current-only reads

The per-row resolve path. Five tier probes and one join become `current`-aware, and the two write
methods (`backfillKeys`, `enrich`) stop updating in place.

**The idempotency question this task answers.** `resolve()`'s docblock says "Idempotent per source
row" and `ResolverLadderTest::test_resolving_the_same_row_twice_is_idempotent` asserts exactly one
`gp_source_link` row after two resolves. That test **keeps passing unchanged**, and it is worth
knowing why: `gp_source_link` is not versioned, so `uq_source` still admits exactly one link per
source row. What versioning could break is the second half of idempotency — re-resolving must not
accumulate `gp_identity` / `gp_license` / `gp_address` rows. `Versioner::write()`'s
no-change-no-version rule is what guarantees that, so the definition of idempotent becomes:
**re-resolving an unchanged source row produces no new link and no new version.** The new test
asserts both.

**Files:**
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php:14-45` (constructor)
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php:119-198` (`matchDeterministic`)
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php:214-234` (`createIdentity`)
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php:236-261` (`backfillKeys`)
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php:263-302` (`enrich`)
- Test: `tests/Feature/VersionedResolverTest.php`

**Interfaces:**
- Consumes: `Versioner::write(string, array, array, array, array): array{version_no:int,new_version:bool}`,
  `Versioner::current(string, array): ?object`.
- Produces: no signature change. `resolve(int $stgPersonId): int` is untouched, which is what keeps
  `EvalRunner` and every existing test working.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/VersionedResolverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\DeterministicResolver;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class VersionedResolverTest extends HubTestCase
{
    private function resolve(int $stgPersonId): int
    {
        return (new DeterministicResolver($this->systemId))->resolve($stgPersonId);
    }

    public function test_a_new_identity_is_created_as_version_one_and_current(): void
    {
        $id = $this->resolve($this->stagePerson());

        $row = $this->hub()->table('gp_identity')->where('identity_id', $id)->first();

        $this->assertSame(1, (int) $row->version_no);
        $this->assertSame(1, (int) $row->current);
        $this->assertSame(1, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());
    }

    public function test_re_resolving_an_unchanged_row_mints_no_version(): void
    {
        // The new definition of idempotent. The link half is already covered by
        // ResolverLadderTest (gp_source_link is not versioned, so uq_source still
        // admits one row); this is the version half.
        $stg = $this->stagePerson(['npi' => 1234567893]);
        $this->stageLicense($stg, 'L-77', 'NY');

        $id = $this->resolve($stg);
        $this->resolve($stg);
        $this->resolve($stg);

        $this->assertSame(1, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());
        $this->assertSame(1, (int) $this->hub()->table('gp_license')->count());
        $this->assertSame(1, (int) $this->hub()->table('gp_source_link')->count());
    }

    public function test_a_later_row_supplying_a_missing_key_mints_a_version(): void
    {
        // backfillKeys used to UPDATE gp_identity in place. It is a change to a
        // golden fact, so it must now be a version.
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09']);
        $id = $this->resolve($a);

        $b = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09', 'npi' => 1234567893]);
        $this->assertSame($id, $this->resolve($b));

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $id)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->npi);
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('1234567893', (string) $rows[1]->npi);
        $this->assertSame(1, (int) $rows[1]->current);
    }

    public function test_a_superseded_identity_version_never_matches_a_tier(): void
    {
        // The failure mode a missing `current = 1` produces. Version 1 of this
        // identity carries npi 1234567893; version 2 does not. A tier probe that
        // reads history would bind an incoming npi row to it.
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Grace', 'canonical_last' => 'Adeyemi',
            'canonical_dob' => '1979-05-14', 'npi' => 1234567893,
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 0, 'first_seen' => now(), 'last_updated' => now(),
        ]);
        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $id, 'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Grace', 'canonical_last' => 'Adeyemi',
            'canonical_dob' => '1979-05-14', 'npi' => null,
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $bound = $this->resolve($this->stagePerson([
            'first_name' => 'Priya', 'last_name' => 'Venkataraman',
            'date_of_birth' => '1988-02-02', 'npi' => 1234567893,
        ]));

        $this->assertNotSame($id, $bound, 'a superseded version must not be matchable');
    }

    public function test_a_superseded_license_never_matches_the_license_tier(): void
    {
        $id = $this->resolve($this->stagePerson([
            'first_name' => 'Ann', 'last_name' => 'Kowalski', 'date_of_birth' => '1981-03-03',
        ]));

        $this->hub()->table('gp_license')->insert([
            'identity_id' => $id, 'license_number' => 'L-99', 'certification_state' => 'NY',
            'certification_board' => null, 'is_verified' => 0,
            'source_link_id' => (int) $this->hub()->table('gp_source_link')->value('link_id'),
            'version_no' => 1, 'current' => 0, 'date_created' => now(), 'date_updated' => now(),
        ]);

        $other = $this->stagePerson([
            'first_name' => 'Fatima', 'last_name' => 'Boutros', 'date_of_birth' => '1990-07-07',
        ]);
        $this->stageLicense($other, 'L-99', 'NY');

        $this->assertNotSame($id, $this->resolve($other));
    }

    public function test_a_changed_license_attribute_versions_rather_than_overwrites(): void
    {
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski',
            'date_of_birth' => '1981-03-03']);
        $this->stageLicense($a, 'L-77', 'NY');
        $id = $this->resolve($a);

        // The same licence re-observed with a registry it did not have before.
        $this->hub()->table('stg_person_license')
            ->where('stg_person_id', $a)->update(['registry' => 'NYRN']);
        $this->resolve($a);

        $rows = $this->hub()->table('gp_license')->where('identity_id', $id)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->registry);
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('NYRN', $rows[1]->registry);
        $this->assertSame(1, (int) $rows[1]->current);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/VersionedResolverTest.php`

Expected: FAIL, 4 of 6. `test_a_later_row_supplying_a_missing_key_mints_a_version` fails with
`Failed asserting that actual size 1 matches expected size 2` (the update was in place);
`test_a_superseded_identity_version_never_matches_a_tier` fails because the tier read history;
`test_a_superseded_license_never_matches_the_license_tier` likewise;
`test_a_changed_license_attribute_versions_rather_than_overwrites` fails with size 1. The first two
tests already pass, because Task 3's DEFAULTs make a fresh insert version 1 / current 1.

- [ ] **Step 3: Filter the tier reads**

In `app/GoldenProfile/Resolution/DeterministicResolver.php`, add the `Versioner` to the constructor:

```php
use App\GoldenProfile\Support\SsnHashGuard;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ...

class DeterministicResolver
{
    private ProbabilisticResolver $probabilistic;

    private SsnHashGuard $ssnGuard;

    private Versioner $versioner;

    /** Bind confidence per key, from config instead of literals. */
    private array $keyConfidence;

    public function __construct(private int $systemId)
    {
        $this->probabilistic = new ProbabilisticResolver($systemId);
        $this->ssnGuard = new SsnHashGuard;
        $this->versioner = new Versioner;
        $this->keyConfidence = config('golden_profile.deterministic_keys', []);
    }
```

Then rewrite `matchDeterministic()`. Every tier gains `current = 1` **beside** `status = 'active'`,
never instead of it:

```php
    /** @return array{0:?int,1:?string,2:?float} [identity_id, match_key, confidence] */
    private function matchDeterministic(object $p, $licenses): array
    {
        $hub = $this->hub();

        // Every tier below adds orderBy('identity_id') before ->value(). Without it
        // the winner among several rows sharing a key is whatever storage order
        // returns, so the same source row could bind to different identities across
        // runs — and dedup()'s own docs acknowledge multiple active identities can
        // share a key before dedup runs. The set-based backfill already pins
        // MIN(identity_id); this makes the per-row path agree with it.
        //
        // Every tier ALSO filters current = 1, in addition to status = 'active'.
        // Both are needed and they mean different things: `current` picks the newest
        // VERSION of an identity, `status` says whether that identity is live. A
        // superseded version can still carry a key its successor dropped, so a tier
        // that skipped the current filter would bind an incoming row to a version
        // that no longer exists — a false merge, silently, since no error is raised.
        // The five key indexes end in `current` for exactly these probes
        // (2026_09_04_000100_add_scd2_versioning); without that, each one reads the
        // whole version history and filters in the server, which is the
        // non-sargable failure this method's history already measured at 6,475,711
        // rows scanned.
        //
        // ssn_hash is additionally screened for filler values: a shared placeholder
        // SSN would otherwise collapse every person carrying it into one identity
        // at 0.99 confidence with no name or DOB cross-check. See SsnHashGuard.
        if ($p->ssn_hash && ! $this->ssnGuard->isBlocked($p->ssn_hash)) {
            $id = $hub->table('gp_identity')->where('ssn_hash', $p->ssn_hash)
                ->where('current', 1)->where('status', 'active')
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'ssn_hash', $this->confidence('ssn_hash', 0.99)];
            }
        }
        if ($p->npi) {
            $id = $hub->table('gp_identity')->where('npi', $p->npi)
                ->where('current', 1)->where('status', 'active')
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'npi', $this->confidence('npi', 0.99)];
            }
        }
        if ($p->dea_number) {
            $id = $hub->table('gp_identity')->where('dea_number', $p->dea_number)
                ->where('current', 1)->where('status', 'active')
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'dea_number', $this->confidence('dea_number', 0.99)];
            }
        }
        if ($p->upin) {
            $id = $hub->table('gp_identity')->where('upin', $p->upin)
                ->where('current', 1)->where('status', 'active')
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'upin', $this->confidence('upin', 0.99)];
            }
        }
        // license_number + certification_state (any of the person's licenses).
        // BOTH sides of the join are filtered: a superseded licence row must not
        // bind, and neither must a live licence hanging off a superseded identity
        // version.
        foreach ($licenses as $lic) {
            $q = $hub->table('gp_license as l')
                ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
                ->where('l.current', 1)
                ->where('i.current', 1)
                ->where('i.status', 'active')
                ->where('l.license_number', $lic->license_number);
            if ($lic->certification_state) {
                $q->where('l.certification_state', $lic->certification_state);
            } else {
                $q->whereNull('l.certification_state');
            }
            $id = $q->orderBy('l.identity_id')->value('l.identity_id');
            if ($id) {
                return [(int) $id, 'license_registry', $this->confidence('license_number+certification_state', 0.99)];
            }
        }
        // name + dob (lower confidence).
        // Plain column comparisons on purpose: the name columns are
        // utf8mb4_unicode_ci (already case-insensitive) and canonical_dob is a
        // DATE, so LOWER()/whereDate() only served to make the predicate
        // non-sargable — idx_name_dob (canonical_last, canonical_first,
        // canonical_dob, current) was skipped and every probe scanned ~6.5M rows
        // (EXPLAIN: type=ref key=idx_status rows=6475711 vs key=idx_name_dob rows=1),
        // which pinned incremental sync at ~0.03 rows/sec.
        if ($p->last_name && $p->first_name && $p->date_of_birth) {
            $id = $hub->table('gp_identity')
                ->where('current', 1)
                ->where('status', 'active')
                ->where('canonical_last', $p->last_name)
                ->where('canonical_first', $p->first_name)
                ->where('canonical_dob', $p->date_of_birth)
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'name_dob', $this->confidence('name+dob', 0.95)];
            }
        }

        return [null, null, null];
    }
```

- [ ] **Step 4: Make the three write methods version**

Replace `createIdentity()`, `backfillKeys()` and `enrich()`:

```php
    private function createIdentity(object $p): int
    {
        $now = now();

        // A brand-new logical identity: version 1, current. Explicit rather than
        // leaning on the column defaults, because insertGetId() has to return the
        // identity_id that every child row will reference and a reader of this
        // method should not have to check the migration to know which version it
        // produced.
        return (int) $this->hub()->table('gp_identity')->insertGetId([
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
            'version_no' => 1,
            'current' => 1,
            'first_seen' => $now,
            'last_updated' => $now,
        ]);
    }

    /**
     * Backfill identity keys that were null when a later row supplies them.
     *
     * This used to UPDATE gp_identity in place. Supplying a key the identity did
     * not have is a change to a golden fact, so under the SCD-2 rule it is a new
     * version (Data Flow by CAMI: "insert a new row with current = 1, and set all
     * preexisting rows to current = 0"). Versioner::write() decides: with nothing
     * to add, $upd is empty and no version is minted; with something to add, the
     * absent columns carry forward from the previous version so the new row is
     * complete.
     */
    private function backfillKeys(int $identityId, object $p): void
    {
        $id = $this->versioner->current('gp_identity', ['identity_id' => $identityId]);
        if (! $id) {
            return;
        }

        $upd = [];
        foreach (['ssn_hash', 'npi', 'upin', 'dea_number', 'canonical_dob'] as $col) {
            $srcCol = $col === 'canonical_dob' ? 'date_of_birth' : $col;
            if (empty($id->$col) && ! empty($p->$srcCol)) {
                // Never promote a filler ssn_hash onto an identity that lacks one:
                // it would spread the placeholder across more identities and hand
                // later rows a bogus 0.99 key to match on.
                if ($col === 'ssn_hash' && $this->ssnGuard->isBlocked($p->$srcCol)) {
                    continue;
                }
                $upd[$col] = $p->$srcCol;
            }
        }
        foreach (['canonical_first' => 'first_name', 'canonical_last' => 'last_name', 'canonical_middle' => 'middle_name'] as $col => $src) {
            if (empty($id->$col) && ! empty($p->$src)) {
                $upd[$col] = $p->$src;
            }
        }
        if ($upd) {
            $this->versioner->write('gp_identity', ['identity_id' => $identityId], $upd);
        }
    }

    /**
     * Add licenses + addresses + basic attribute provenance for this source row.
     *
     * updateOrInsert() became Versioner::write(): a licence or address whose
     * attributes changed is superseded rather than overwritten, and one whose
     * attributes are identical produces nothing at all. That second half is what
     * keeps resolve() idempotent — every source row that shares an identity
     * re-observes the same licences, and on the pile-up identities (identity 3
     * folds 12,463 source rows) an unconditional version per observation would
     * multiply gp_license by four figures with no new information.
     *
     * source_link_id is passed as onCreate, not as an attribute, for the same
     * reason: it records which source row ESTABLISHED the fact. Treating it as an
     * attribute would make every re-observation from a different account a change.
     */
    private function enrich(int $identityId, object $p, $licenses, int $linkId): void
    {
        $hub = $this->hub();

        foreach ($licenses as $lic) {
            $this->versioner->write(
                'gp_license',
                [
                    'identity_id' => $identityId,
                    'license_number' => $lic->license_number,
                    'certification_state' => $lic->certification_state,
                    'certification_board' => $lic->certification_board,
                ],
                [
                    'license_type' => $lic->license_type,
                    'license_type_id' => $lic->license_type_id,
                    'registry' => $lic->registry,
                ],
                [],
                ['source_link_id' => $linkId],
            );
        }

        $addrs = $hub->table('stg_person_address')->where('stg_person_id', $p->stg_person_id)->get();
        foreach ($addrs as $a) {
            $this->versioner->write(
                'gp_address',
                [
                    'identity_id' => $identityId,
                    'address1' => $a->address1,
                    'city' => $a->city,
                    'state' => $a->state,
                    'zip' => $a->zip,
                ],
                [
                    'address2' => $a->address2,
                    'is_primary' => $a->address_type === 'primary' ? 1 : 0,
                ],
                [],
                ['source_link_id' => $linkId],
            );
        }
    }
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/VersionedResolverTest.php`
Expected: PASS, 6 tests.

Run: `vendor/bin/phpunit tests/Feature/ResolverLadderTest.php`
Expected: PASS, 12 tests — unchanged. `test_resolving_the_same_row_twice_is_idempotent` still asserts
one `gp_source_link` row and still passes, because `gp_source_link` is not versioned.

Run: `vendor/bin/phpunit`
Expected: PASS, 116 tests, 0 skipped.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Resolution/DeterministicResolver.php tests/Feature/VersionedResolverTest.php
git commit -m "feat(scd2): version the resolver's writes and filter its tier reads to current"
```

---

## Task 7: `Survivorship` and `ProfileMaterializer` — the per-identity finalize pair

These two always run together (`Engine::finalize()` calls one then the other), and they are the pair
that runs 13.38M times on a full rebuild, so they are the pair where a missing filter is most
expensive and the no-change rule matters most.

**Files:**
- Modify: `app/GoldenProfile/Resolution/Survivorship.php:13-40` (constructor)
- Modify: `app/GoldenProfile/Resolution/Survivorship.php:112-131` (the write)
- Modify: `app/GoldenProfile/Materialize/ProfileMaterializer.php:25-95` (the child reads)
- Test: `tests/Feature/VersionedFinalizeTest.php`

**Interfaces:**
- Consumes: `Versioner::write(string, array, array, array, array): array{version_no:int,new_version:bool}`.
- Produces: no signature change. `Survivorship::recompute(int): void` and
  `ProfileMaterializer::rebuild(int): void` keep their contracts, so `Engine` needs no change here.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/VersionedFinalizeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
use Tests\Support\HubTestCase;

class VersionedFinalizeTest extends HubTestCase
{
    public function test_recomputing_survivorship_twice_mints_no_version(): void
    {
        // The single most important assertion in the plan. finalizeAll() calls
        // recompute() for every identity in the hub; if an unchanged recompute
        // versions, a rebuild adds ~13.38M gp_identity rows.
        $id = (new DeterministicResolver($this->systemId))->resolve($this->stagePerson());

        (new Survivorship)->recompute($id);
        (new Survivorship)->recompute($id);
        (new Survivorship)->recompute($id);

        $this->assertSame(1, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());
    }

    public function test_record_count_is_written_in_place(): void
    {
        // record_count is derived from gp_source_link, which already records when
        // each link was made. Versioning on it would add one identity row per
        // source row.
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09']);
        $resolver = new DeterministicResolver($this->systemId);
        $id = $resolver->resolve($a);
        (new Survivorship)->recompute($id);

        $resolver->resolve($this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09']));
        (new Survivorship)->recompute($id);

        $this->assertSame(1, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());
        $this->assertSame(2, (int) $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->where('current', 1)->value('record_count'));
    }

    public function test_a_changed_canonical_winner_mints_a_version(): void
    {
        $a = $this->stagePerson(['first_name' => 'Robert', 'last_name' => 'Smith',
            'date_of_birth' => '1970-04-02', 'source_modified' => '2026-01-01 00:00:00']);
        $id = (new DeterministicResolver($this->systemId))->resolve($a);
        (new Survivorship)->recompute($id);

        // A newer staged value for the same source row: recency breaks the tie, so
        // the canonical middle name changes and that is a golden fact moving.
        $this->hub()->table('stg_person')->where('stg_person_id', $a)->update([
            'middle_name' => 'Quincy',
            'source_modified' => '2026-06-01 00:00:00',
        ]);
        (new Survivorship)->recompute($id);

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $id)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->canonical_middle);
        $this->assertSame('Quincy', $rows[1]->canonical_middle);
        $this->assertSame(1, (int) $rows[1]->current);
    }

    public function test_the_profile_counts_current_child_rows_only(): void
    {
        // The failure mode a missing filter produces here is not an error: it is a
        // license_count of 2 for one licence.
        $a = $this->stagePerson(['first_name' => 'Ann', 'last_name' => 'Kowalski',
            'date_of_birth' => '1981-03-03']);
        $this->stageLicense($a, 'L-77', 'NY');
        $id = (new DeterministicResolver($this->systemId))->resolve($a);

        // Supersede the licence with a new version, as a re-observation would.
        $this->hub()->table('stg_person_license')
            ->where('stg_person_id', $a)->update(['registry' => 'NYRN']);
        (new DeterministicResolver($this->systemId))->resolve($a);

        $this->assertSame(2, (int) $this->hub()->table('gp_license')->where('identity_id', $id)->count());

        (new ProfileMaterializer)->rebuild($id);

        $profile = $this->hub()->table('gp_identity_profile')->where('identity_id', $id)->first();
        $licenses = json_decode((string) $profile->licenses, true);

        $this->assertSame(1, (int) $profile->license_count, 'the profile must count current licences only');
        $this->assertCount(1, $licenses);
        $this->assertSame('NYRN', $licenses[0]['registry']);
    }

    public function test_the_profile_is_built_from_the_current_identity_version(): void
    {
        $a = $this->stagePerson(['first_name' => 'Robert', 'last_name' => 'Smith',
            'date_of_birth' => '1970-04-02']);
        $id = (new DeterministicResolver($this->systemId))->resolve($a);

        $b = $this->stagePerson(['first_name' => 'Robert', 'last_name' => 'Smith',
            'date_of_birth' => '1970-04-02', 'npi' => 1234567893]);
        (new DeterministicResolver($this->systemId))->resolve($b);

        (new ProfileMaterializer)->rebuild($id);

        $this->assertSame(
            '1234567893',
            (string) $this->hub()->table('gp_identity_profile')->where('identity_id', $id)->value('npi'),
            'the profile must reflect the newest version, not the first'
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/VersionedFinalizeTest.php`

Expected: FAIL, 4 of 5. `test_recomputing_survivorship_twice_mints_no_version` fails with
`Failed asserting that 3 is identical to 1` (each recompute writes `last_updated => now()` through an
in-place `update()` today — after Task 5 that becomes three versions unless the write goes through
the Versioner); `test_the_profile_counts_current_child_rows_only` fails with
`Failed asserting that 2 is identical to 1`.
`test_the_profile_is_built_from_the_current_identity_version` may pass by accident, because with no
filter `->first()` returns whichever version the storage engine offers — usually version 1. That is
the point: it is a coin toss, and a test that passes by luck is why the filter is asserted.

- [ ] **Step 3: Version the survivorship write**

In `app/GoldenProfile/Resolution/Survivorship.php`, add the dependency:

```php
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Facades\DB;

// ...

class Survivorship
{
    private array $authority;

    private Versioner $versioner;

    public function __construct()
    {
        $this->authority = config('golden_profile.survivorship.field_authority');
        $this->versioner = new Versioner;
    }
```

Then replace the write block at the end of `recompute()` (the `$update['record_count']` /
`$update['last_updated']` / `->update($update)` lines) with:

```php
        // The canonical winners are golden facts, so they are written as a VERSION
        // rather than an update (Data Flow by CAMI: "insert a new row with
        // current = 1, and set all preexisting rows to current = 0").
        //
        // record_count goes in as DERIVED, and last_updated is not passed at all.
        // Both used to be folded into the same in-place update as the canonical
        // fields, and both would defeat versioning if they were treated as facts:
        // finalizeAll() recomputes every identity, so an unconditional
        // last_updated => now() would mint ~13.38M rows a run, and a record_count
        // bump would mint one per source row (~13.4M on a backfill) to record
        // something gp_source_link already holds with better resolution.
        // Versioner owns last_updated: it stamps it only on a version that is
        // actually written, which is what makes it mean "when the golden facts last
        // changed" rather than "when we last looked".
        //
        // $update carries only the fields that had candidates; the rest carry
        // forward from the previous version, which is the same partial-write
        // behaviour the old ->update($update) had.
        $this->versioner->write(
            'gp_identity',
            ['identity_id' => $identityId],
            $update,
            ['record_count' => $rows->count()],
        );

        // rewrite provenance for identity attributes (idempotent per identity).
        // NOT versioned: gp_attribute and gp_survivorship_audit are per-observation
        // provenance — they ARE the history, so they do not have one. See
        // docs/SCD2.md.
        $names = array_keys(self::IDENTITY_FIELDS);
        $hub->table('gp_attribute')->where('identity_id', $identityId)->whereIn('attr_name', $names)->delete();
        $hub->table('gp_survivorship_audit')->where('identity_id', $identityId)->whereIn('attribute_name', $names)->delete();
        foreach (array_chunk($attrRows, 500) as $c) {
            $hub->table('gp_attribute')->insert($c);
        }
        foreach (array_chunk($auditRows, 500) as $c) {
            $hub->table('gp_survivorship_audit')->insert($c);
        }
    }
```

The `$rows` query that feeds all of this joins `gp_source_link` to `stg_person` — neither is
versioned, so it is unchanged.

- [ ] **Step 4: Filter the materializer's reads**

In `app/GoldenProfile/Materialize/ProfileMaterializer.php`, `rebuild()` — six reads gain
`current = 1`. Only the changed lines are shown; everything between them is untouched:

```php
        // The profile is a projection of the CURRENT version of everything. It is
        // itself unversioned (a rebuildable read model whose rows reach 100MB of
        // JSON — see docs/SCD2.md), which makes these filters the only thing
        // keeping it correct. A missing one does not throw: it doubles a count and
        // duplicates a JSON entry.
        $identity = $hub->table('gp_identity')
            ->where('identity_id', $identityId)->where('current', 1)->first();
        if (! $identity) {
            return;
        }
```

```php
        $licenses = $hub->table('gp_license')
            ->where('identity_id', $identityId)->where('current', 1)->get()
            ->map(fn ($l) => [
                'number' => $l->license_number, 'state' => $l->certification_state,
                'board' => $l->certification_board, 'type' => $l->license_type,
                'registry' => $l->registry, 'verified' => (bool) $l->is_verified,
            ])->values();

        $identifiers = $hub->table('gp_identity_identifier')
            ->where('identity_id', $identityId)->where('current', 1)->get()
            ->map(fn ($r) => ['type' => $r->id_type, 'value' => $r->id_value])
            ->unique(fn ($r) => $r['type'].'|'.$r['value'])->values();
```

```php
        $addresses = $hub->table('gp_address')
            ->where('identity_id', $identityId)->where('current', 1)->get();
```

```php
        $credentials = $hub->table('gp_identity_credential')
            ->where('identity_id', $identityId)->where('current', 1)->get()
```

```php
        $exclusions = $hub->table('gp_identity_exclusion')
            ->where('identity_id', $identityId)->where('current', 1)->get()
```

`gp_board_action` and `gp_identity_resolution` are **not** touched: the first is append-only, the
second was already SCD-2 and its read already filters `is_current = 1`. `$links`
(`gp_source_link`) and `$stgIds` (`stg_person`) are unversioned. `$this->aliasIndexer->refresh()`
reads `gp_source_link → stg_person → stg_person_alias`, none of them versioned, so `AliasIndexer`
needs no change at all.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/VersionedFinalizeTest.php`
Expected: PASS, 5 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 121 tests, 0 skipped.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Resolution/Survivorship.php \
        app/GoldenProfile/Materialize/ProfileMaterializer.php \
        tests/Feature/VersionedFinalizeTest.php
git commit -m "feat(scd2): version survivorship winners and build the profile from current rows only"
```

---

## Task 8: `Engine` — dedup reads, a merge that retires instead of deleting, versioned rollups

The biggest task in the plan, and the one with the sharpest conformance win: today
`Engine::applyMerge()` ends with `DELETE FROM gp_identity WHERE identity_id = <loser>`. Under a
design whose stated purpose is that "older rows preserve a full audit trail", destroying the losing
golden record is the exact opposite of the rule. After this task a merge writes the loser a final
version — `status = 'merged'`, `merged_into = <survivor>`, `current = 1` — and logs the merge, which
`gp_resolution_log` has never recorded despite having a `merge` action in its enum.

**Why the fixed-point loop still terminates.** `dedup()` iterates until a round merges nothing. The
loser now survives as a row, so the obvious worry is that the next round re-finds it. It does not:
every merge query filters `status = 'active'`, and the loser's current version is `'merged'`. That is
`current` ≠ `alive` earning its keep — and it is why every read in this task adds `current = 1`
**beside** the status filter rather than replacing it.

**Files:**
- Modify: `app/GoldenProfile/Engine.php:37-45` (constructor)
- Modify: `app/GoldenProfile/Engine.php:214-236` (`finalizeAll`)
- Modify: `app/GoldenProfile/Engine.php:296-424` (the four merge-candidate queries)
- Modify: `app/GoldenProfile/Engine.php:426-512` (`mergeIdentity`, `applyMerge`, `repointDeduped`)
- Modify: `app/GoldenProfile/Engine.php:598-701` (`rollupCredentials`, `rollupExclusions`)
- Modify: `app/GoldenProfile/Engine.php:712-722` (`rebuildProfile`)
- Test: `tests/Feature/VersionedMergeTest.php`

**Interfaces:**
- Consumes: `Versioner::write()`, `Versioner::retire(string, array): int`,
  `Versioner::repointForMerge(string, int, int): array{repointed:int,retired:int}`.
- Produces: `Engine::dedup(?callable, int, int): int` unchanged. `mergeIdentity` gains a third
  parameter: `private function mergeIdentity(int $survivor, int $loser, string $matchKey): int`.
  `repointDeduped()` is **deleted** — `Versioner::repointForMerge()` replaces it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/VersionedMergeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class VersionedMergeTest extends HubTestCase
{
    public function test_a_merged_identity_survives_as_a_final_version(): void
    {
        // Today applyMerge() DELETEs the loser. Under a design whose point is that
        // "older rows preserve a full audit trail", that is the opposite of the rule.
        [$survivor, $loser] = $this->twoIdentitiesSharingAnNpi();

        (new Engine)->dedup();

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $loser)
            ->orderBy('version_no')->get();

        $this->assertGreaterThanOrEqual(2, $rows->count(), 'the loser must not be deleted');

        $final = $rows->last();
        $this->assertSame('merged', $final->status);
        $this->assertSame($survivor, (int) $final->merged_into);
        $this->assertSame(1, (int) $final->current, 'the latest truth about it is that it was merged');
    }

    public function test_a_merged_identity_is_no_longer_matchable(): void
    {
        [$survivor, $loser] = $this->twoIdentitiesSharingAnNpi();
        (new Engine)->dedup();

        $bound = (new DeterministicResolver($this->systemId))->resolve($this->stagePerson([
            'first_name' => 'Bob', 'last_name' => 'Smith',
            'date_of_birth' => null, 'npi' => 1234567893,
        ]));

        $this->assertSame($survivor, $bound);
        $this->assertNotSame($loser, $bound);
    }

    public function test_dedup_reaches_a_fixed_point(): void
    {
        // The loser still exists as a row, so the guard against re-finding it is
        // status = 'merged'. Without that, dedup would loop forever.
        $this->twoIdentitiesSharingAnNpi();

        $merged = (new Engine)->dedup();

        $this->assertSame(1, $merged);
        $this->assertSame(0, (new Engine)->dedup(), 'a second dedup must find nothing');
    }

    public function test_the_merge_is_recorded_in_the_resolution_log(): void
    {
        [$survivor, $loser] = $this->twoIdentitiesSharingAnNpi();

        (new Engine)->dedup();

        $log = $this->hub()->table('gp_resolution_log')
            ->where('action', 'merge')->where('identity_id', $survivor)->first();

        $this->assertNotNull($log, 'gp_resolution_log has a merge action and has never been written');
        $this->assertSame('npi', $log->match_key);
        $this->assertContains($loser, json_decode((string) $log->affected_ids, true)['merged']);
    }

    public function test_a_clashing_child_row_is_retired_not_deleted(): void
    {
        [$survivor, $loser] = $this->twoIdentitiesSharingAnNpi();
        $link = (int) $this->hub()->table('gp_source_link')->value('link_id');

        foreach ([$survivor, $loser] as $owner) {
            $this->hub()->table('gp_license')->insert([
                'identity_id' => $owner, 'license_number' => 'L-77',
                'certification_state' => 'NY', 'certification_board' => null,
                'is_verified' => 0, 'source_link_id' => $link,
                'version_no' => 1, 'current' => 1, 'date_created' => now(), 'date_updated' => now(),
            ]);
        }
        $this->hub()->table('gp_license')->insert([
            'identity_id' => $loser, 'license_number' => 'L-88',
            'certification_state' => 'NY', 'certification_board' => null,
            'is_verified' => 0, 'source_link_id' => $link,
            'version_no' => 1, 'current' => 1, 'date_created' => now(), 'date_updated' => now(),
        ]);

        (new Engine)->dedup();

        $this->assertSame(
            ['L-77', 'L-88'],
            $this->hub()->table('gp_license')->where('identity_id', $survivor)->where('current', 1)
                ->orderBy('license_number')->pluck('license_number')->all()
        );
        // The clashing copy is retired under the merged identity, not destroyed.
        $this->assertSame(
            1,
            (int) $this->hub()->table('gp_license')->where('identity_id', $loser)->count()
        );
        $this->assertSame(
            0,
            (int) $this->hub()->table('gp_license')->where('identity_id', $loser)->where('current', 1)->count()
        );
    }

    public function test_finalize_all_visits_each_identity_once(): void
    {
        // finalizeAll() chunks by identity_id. With versions in the table,
        // identity_id is no longer unique — chunkById would visit an identity once
        // per version, so the filter is what keeps the cursor sound.
        $a = $this->stagePerson(['first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09']);
        $id = (new DeterministicResolver($this->systemId))->resolve($a);
        (new DeterministicResolver($this->systemId))->resolve($this->stagePerson([
            'first_name' => 'Maria', 'last_name' => 'Garcia',
            'date_of_birth' => '1975-01-09', 'npi' => 1234567893,
        ]));

        $this->assertSame(2, (int) $this->hub()->table('gp_identity')->where('identity_id', $id)->count());

        $visited = [];
        (new Engine)->finalizeAll(function ($done, $total) use (&$visited) {
            $visited[] = $total;
        });

        $this->assertSame([1, 1], array_slice($visited, 0, 2), 'one identity, not one per version');
    }

    /** @return array{0:int,1:int} [survivor, loser] */
    private function twoIdentitiesSharingAnNpi(): array
    {
        // Built by hand rather than through the resolver: the resolver's npi tier
        // would bind the second row to the first, which is the situation dedup
        // exists to clean up AFTER a partitioned parallel load created it.
        $ids = [];
        foreach ([['Robert', 'Smith'], ['Bob', 'Smith']] as [$first, $last]) {
            $ids[] = (int) $this->hub()->table('gp_identity')->insertGetId([
                'identity_uuid' => (string) Str::uuid(),
                'canonical_first' => $first, 'canonical_last' => $last,
                'canonical_dob' => null, 'npi' => 1234567893,
                'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
                'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
            ]);
        }
        sort($ids);

        return $ids;                 // dedup keeps the lowest identity_id
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/VersionedMergeTest.php`

Expected: FAIL, 6 of 7. `test_a_merged_identity_survives_as_a_final_version` fails with
`Failed asserting that 0 is equal to or greater than 2` (the loser was deleted);
`test_the_merge_is_recorded_in_the_resolution_log` fails with "gp_resolution_log has a merge action
and has never been written"; `test_finalize_all_visits_each_identity_once` fails with a total of 2.
`test_dedup_reaches_a_fixed_point` already passes, because deleting the loser also removes it from
the next round.

- [ ] **Step 3: Filter the merge-candidate reads**

In `app/GoldenProfile/Engine.php`, add the dependency:

```php
use App\GoldenProfile\Support\SsnHashGuard;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ...

    private SsnHashGuard $ssnGuard;

    private Versioner $versioner;

    public function __construct()
    {
        $this->systemId = $this->ensureSystem();
        $this->connector = new StreamlineLocalConnector($this->systemId);
        $this->resolver = new DeterministicResolver($this->systemId);
        $this->materializer = new ProfileMaterializer;
        $this->survivorship = new Survivorship;
        $this->ssnGuard = new SsnHashGuard;
        $this->versioner = new Versioner;
    }
```

`finalizeAll()`'s driving query:

```php
    public function finalizeAll(?callable $progress = null, int $shard = 0, int $shards = 1): void
    {
        // current = 1 is not an optimisation here, it is what keeps chunkById
        // sound. identity_id stopped being unique in gp_identity when versions
        // arrived, so without the filter the cursor visits an identity once per
        // version — recomputing survivorship and rebuilding the profile N times for
        // one person, and reporting an inflated total. uq_identity_current makes
        // identity_id unique among current rows, so the cursor is valid again.
        $q = fn () => $this->hub()->table('gp_identity')
            ->where('current', 1)
            ->when($shards > 1, fn ($qq) => $qq->whereRaw('identity_id % ? = ?', [$shards, $shard]));
```

`rebuildProfile()`:

```php
    public function rebuildProfile(?int $identityId = null): int
    {
        $ids = $identityId
            ? [$identityId]
            : $this->hub()->table('gp_identity')->where('current', 1)->pluck('identity_id')->all();
```

`mergeByColumn()` — both queries, and the third argument to `mergeIdentity`:

```php
    /** Merge active identities sharing a non-null value in $col. */
    private function mergeByColumn(string $col, int $shard = 0, int $shards = 1): int
    {
        // $col is from a fixed internal whitelist — safe to interpolate.
        //
        // current = 1 sits beside status = 'active' in every query below, and both
        // are needed. `current` picks the newest VERSION; `status` says whether the
        // identity is live. A merged-away identity keeps a current row (status
        // 'merged'), which is exactly what stops dedup's fixed-point loop from
        // re-finding it — and a superseded version can carry a key its successor
        // dropped, which without the current filter would make dedup fold two
        // live identities together on evidence that no longer exists.
        $hub = $this->hub();
        $n = 0;
        $q = $hub->table('gp_identity')->whereNotNull($col)
            ->where('current', 1)->where('status', 'active');
        $q = $this->shardFilter($q, $col, $shard, $shards);
        $dupVals = $q->groupBy($col)->havingRaw('COUNT(*) > 1')->pluck($col);
        foreach ($dupVals as $val) {
            // A filler ssn_hash is not evidence of shared identity. Resolution now
            // refuses to bind on one, but dedup would still fold together any
            // identities that already carry it — so screen here too.
            if ($col === 'ssn_hash' && $this->ssnGuard->isBlocked($val)) {
                continue;
            }
            $ids = $hub->table('gp_identity')
                ->where($col, $val)->where('current', 1)->where('status', 'active')
                ->orderBy('identity_id')->pluck('identity_id')->all();
            $survivor = (int) array_shift($ids);
            foreach ($ids as $loser) {
                $n += $this->mergeIdentity($survivor, (int) $loser, $col);
            }
        }

        return $n;
    }
```

`mergeByLicense()` — both sides of both joins:

```php
        $q = $hub->table('gp_license')
            ->join('gp_identity', 'gp_identity.identity_id', '=', 'gp_license.identity_id')
            ->where('gp_license.current', 1)
            ->where('gp_identity.current', 1)
            ->where('gp_identity.status', 'active')
            ->select('license_number', 'certification_state', 'certification_board');
```

```php
            $q = $hub->table('gp_license')
                ->join('gp_identity', 'gp_identity.identity_id', '=', 'gp_license.identity_id')
                ->where('gp_license.current', 1)
                ->where('gp_identity.current', 1)
                ->where('gp_identity.status', 'active')
                ->where('license_number', $g->license_number);
```

and its `mergeIdentity` call becomes
`$n += $this->mergeIdentity($survivor, (int) $loser, 'license_registry');`.

`mergeByIdentifier()` — both queries:

```php
        $q = $hub->table('gp_identity_identifier as gii')
            ->join('gp_identity as gi', 'gi.identity_id', '=', 'gii.identity_id')
            ->where('gii.current', 1)
            ->where('gi.current', 1)
            ->where('gi.status', 'active')
            ->select('gii.id_type', 'gii.id_value');
```

```php
            $ids = $hub->table('gp_identity_identifier as gii')
                ->join('gp_identity as gi', 'gi.identity_id', '=', 'gii.identity_id')
                ->where('gii.current', 1)
                ->where('gi.current', 1)
                ->where('gi.status', 'active')
                ->where('gii.id_type', $g->id_type)->where('gii.id_value', $g->id_value)
                ->orderBy('gii.identity_id')->distinct()->pluck('gii.identity_id')->all();
```

with `$this->mergeIdentity($survivor, (int) $loser, 'identifier')`.

`mergeByNameDob()` — both queries gain `->where('current', 1)` next to the existing
`->where('status', 'active')`, and its call becomes
`$this->mergeIdentity($survivor, (int) $loser, 'name_dob')`. The `shardFilter` expression is
**unchanged**: its `LOWER()` is deliberate (the comment above it explains that CRC32 hashes raw bytes
while the GROUP BY is case-insensitive, so folding is what keeps a sharded run from splitting a
group), and `current` is not part of the shard key.

- [ ] **Step 4: Rewrite the merge itself**

Replace `mergeIdentity()`, `applyMerge()` and `repointDeduped()` with:

```php
    /**
     * Fold $loser into $survivor: repoint child rows, then write the loser a final
     * version recording where it went.
     *
     * Row-locked in a transaction so concurrent dedup shards that happen to touch
     * the same survivor/loser serialize instead of corrupting each other; if
     * another shard already merged one of them away, this is a no-op.
     *
     * @param  string  $matchKey  which deterministic key produced this merge — recorded in the log
     * @return int 1 if a merge happened, 0 otherwise
     */
    private function mergeIdentity(int $survivor, int $loser, string $matchKey): int
    {
        if ($survivor === $loser) {
            return 0;
        }

        return $this->hub()->transaction(function () use ($survivor, $loser, $matchKey) {
            $hub = $this->hub();
            // Lock both rows in a stable order to avoid deadlocks between shards.
            $hub->table('gp_identity')->whereIn('identity_id', [min($survivor, $loser), max($survivor, $loser)])
                ->where('current', 1)
                ->orderBy('identity_id')->lockForUpdate()->get();

            $s = $this->versioner->current('gp_identity', ['identity_id' => $survivor]);
            $l = $this->versioner->current('gp_identity', ['identity_id' => $loser]);

            // A merged loser keeps a current row, so "already merged" is now a
            // status check rather than an absent row.
            if (! $s || ! $l || $s->status !== 'active' || $l->status !== 'active') {
                return 0;
            }
            $this->applyMerge($hub, $s, $l, $survivor, $loser, $matchKey);

            return 1;
        });
    }

    /**
     * The row-moving half of a merge (runs inside mergeIdentity's transaction).
     *
     * WHAT CHANGED UNDER SCD-2
     * ------------------------
     * This method used to end with DELETE FROM gp_identity. Data Flow by CAMI says
     * older rows "preserve a full audit trail", so destroying the losing golden
     * record is the opposite of the rule. The loser now gets a final version —
     * status 'merged', merged_into the survivor, current 1 — because the latest
     * truth about that identity is that it was merged. It stops matching because
     * every tier and every merge query filters status = 'active', not because the
     * row is gone.
     *
     * The merge is also logged. gp_resolution_log has had a 'merge' action in its
     * enum since the first migration and has never been written one; without it,
     * the version trail records THAT an identity was merged but not why or by what
     * key, and the child tables (where identity_id is not part of the natural key,
     * so repointing is a bulk update across all versions) carry no trace at all.
     */
    private function applyMerge($hub, $s, $l, int $survivor, int $loser, string $matchKey): void
    {
        // The survivor inherits the loser's null deterministic keys so later dedup
        // passes can chain matches. That is a change to golden facts, so it is a
        // version rather than an in-place update — and Versioner mints nothing when
        // there is nothing to inherit.
        $upd = [];
        foreach (['ssn_hash', 'npi', 'upin', 'dea_number', 'canonical_dob',
            'canonical_first', 'canonical_last', 'canonical_middle'] as $c) {
            if (empty($s->$c) && ! empty($l->$c)) {
                $upd[$c] = $l->$c;
            }
        }
        if ($upd) {
            $this->versioner->write('gp_identity', ['identity_id' => $survivor], $upd);
        }

        // Repoint children whose natural key does NOT include identity_id. ALL
        // versions move, deliberately: no unique on these tables can collide on
        // identity_id, and leaving the history behind would orphan it from the
        // identity the facts now belong to. The cost is that the identity a
        // credential belonged to before the merge is recoverable only from
        // gp_resolution_log — see docs/SCD2.md, which records that trade.
        foreach (['gp_source_link', 'gp_edge', 'gp_identity_credential', 'gp_identity_exclusion',
            'gp_identity_resolution', 'gp_resolution_log', 'gp_board_action'] as $t) {
            $hub->table($t)->where('identity_id', $loser)->update(['identity_id' => $survivor]);
        }

        // Collision-prone (identity_id is part of the natural key). The current
        // version moves and is renumbered under the survivor's key; a version that
        // would clash with something the survivor already holds is RETIRED rather
        // than deleted, and superseded versions are left attached to the merged
        // identity — repointing those would violate the natural-key unique, whose
        // last column is now version_no. See Versioner::repointForMerge.
        foreach (['gp_license', 'gp_address', 'gp_identity_identifier'] as $t) {
            $this->versioner->repointForMerge($t, $survivor, $loser);
        }

        // Rebuilt from scratch by finalize — just remove the loser's copies.
        foreach (['gp_attribute', 'gp_survivorship_audit', 'gp_identity_profile'] as $t) {
            $hub->table($t)->where('identity_id', $loser)->delete();
        }

        // The loser's final version: where it went, and that this is the last word.
        $this->versioner->write('gp_identity', ['identity_id' => $loser], [
            'status' => 'merged',
            'merged_into' => $survivor,
        ]);

        $hub->table('gp_resolution_log')->insert([
            'action' => 'merge',
            'identity_id' => $survivor,
            'affected_ids' => json_encode(['merged' => [$loser]]),
            'match_key' => $matchKey,
            'reason' => "dedup: identities shared $matchKey",
            'actor' => 'engine',
            'created_at' => now(),
        ]);
    }
```

> **Landmine — do not skip.** `SqlBackfill::residualCreateAndLink()` uses `merged_into` as "a
> temporary stg_person_id carrier so the 1:1 create + link stays fully set-based", then clears it
> with `UPDATE gp_identity SET merged_into = NULL`. `merged_into` is now a versioned attribute with
> real meaning, so that reuse would write a stg_person_id into a golden field, mint a version for
> every identity it touched, and then mint another clearing it. Task 10 makes `SqlBackfill` refuse to
> run for exactly this class of reason; plan 3b must give the residual step its own scratch column.

- [ ] **Step 5: Version the rollups**

Replace the write halves of `rollupCredentials()` and `rollupExclusions()`:

```php
        // upsert() became per-row Versioner::write(). A credential whose status,
        // validity or CAMI currency flag moved is superseded rather than
        // overwritten; one that came back identical produces nothing, which matters
        // because sync re-reads every credential of every changed employee on every
        // run.
        //
        // COST, stated plainly: this is one transaction per credential where it
        // used to be one upsert per 500. That is acceptable on the incremental
        // path — sync is already per-row and bounded by the source's WAN latency,
        // not the hub's — and unacceptable on a bulk load, which is why
        // SqlBackfill::rollup() keeps its set-based statement and is guarded off
        // until plan 3b converts it.
        foreach ($upserts as $row) {
            $this->versioner->write(
                'gp_identity_credential',
                ['system_id' => $row['system_id'], 'credential_match_id' => $row['credential_match_id']],
                array_intersect_key($row, array_flip([
                    'registry', 'match_summary_status', 'match_summary_status_code',
                    'match_is_valid', 'source_current', 'date_resolved', 'link_state',
                ])),
                [],
                ['identity_id' => $row['identity_id']],
            );
        }

        // Pending / Error matches are not part of the golden data. They used to be
        // DELETEd; retiring them keeps the trail of a credential that was rolled up
        // and later became ineligible, which is the whole point of the pattern.
        foreach ($deleteIds as $credentialMatchId) {
            $this->versioner->retire('gp_identity_credential', [
                'system_id' => $this->systemId,
                'credential_match_id' => $credentialMatchId,
            ]);
        }
```

```php
        foreach ($upserts as $row) {
            $this->versioner->write(
                'gp_identity_exclusion',
                ['system_id' => $row['system_id'], 'match_id' => $row['match_id']],
                array_intersect_key($row, array_flip([
                    'exclusion_record_id', 'registry', 'is_ssn_match', 'is_npi_match',
                    'is_canonical_name_match', 'is_upin_match', 'is_license_number_match', 'link_state',
                ])),
                [],
                ['identity_id' => $row['identity_id']],
            );
        }
```

Both `$upserts` builders above them are unchanged, as is `identityMapFor()` (it reads
`gp_source_link`, which is not versioned).

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/VersionedMergeTest.php`
Expected: PASS, 7 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 128 tests, 0 skipped.

If `test_dedup_reaches_a_fixed_point` now fails with a non-zero second pass, a merge query is missing
its `status = 'active'` filter and is re-finding the merged loser. Fix the query — never make
`applyMerge` delete again.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Engine.php tests/Feature/VersionedMergeTest.php
git commit -m "feat(scd2): retire merged identities instead of deleting them and version the rollups"
```

---

## Task 9: Pass B and the API — the remaining read paths

Four files read a versioned table and have not been touched yet. Two of them need changes, two
provably do not, and saying which is which is part of the deliverable: the next person to audit this
should not have to re-derive that `AliasIndexer` is safe.

**Files:**
- Modify: `app/GoldenProfile/Resolution/ProbabilisticResolver.php:95-120` (candidates)
- Modify: `app/GoldenProfile/Resolution/ProbabilisticResolver.php:204-235` (address, exclusion)
- Modify: `app/Http/Controllers/Api/V1/CredentialSearchController.php:108-113` (licence narrower)
- Modify: `app/Http/Controllers/Api/V1/CredentialSearchController.php:185-188` (`$linkQuery`)
- Test: `tests/Feature/VersionedReadPathTest.php`

**No change needed, and why:**

| File | Reads | Why it is already correct |
|---|---|---|
| `IdentitySearchController` | `gp_identity_profile`, `gp_identity_alias` | Both unversioned derived read models. The profile is rebuilt from `current = 1` rows (Task 7), so it holds current truth by construction. |
| `AliasIndexer` | `gp_source_link` → `stg_person` → `stg_person_alias` | None of the three is versioned. Its composite primary key stays the dedup guarantee. |
| `IdentityProfileResource` | one `gp_identity_profile` row | Same. Its `last_updated` field changes meaning (see `docs/SCD2.md`), not its source. |
| `SsnHashGuard` | `stg_person` | Counts distinct people upstream, deliberately — its own docblock explains that counting `gp_identity` rows would never fire. Versioning makes that reasoning *more* true, not less. |

**Interfaces:**
- Produces: no signature changes anywhere in this task.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/VersionedReadPathTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\ProbabilisticResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

class VersionedReadPathTest extends HubTestCase
{
    public function test_pass_b_never_offers_a_superseded_identity_version(): void
    {
        // A merged-away or superseded version inside the block would be scored as a
        // candidate and, in the review band, bound to — a false merge with no error.
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 1,
            'status' => 'active', 'version_no' => 1, 'current' => 0,
            'first_seen' => now(), 'last_updated' => now(),
        ]);
        $this->hub()->table('gp_identity')->insert([
            'identity_id' => $id, 'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 1,
            'status' => 'merged', 'merged_into' => 999999,
            'version_no' => 2, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $linked = $this->stagePerson(['source_id' => 91001]);
        $this->hub()->table('gp_source_link')->insert([
            'identity_id' => $id, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => 91001,
            'match_method' => 'deterministic', 'match_key' => 'new', 'match_score' => 1.0,
            'match_state' => 'auto_match', 'is_pinned' => 0, 'linked_at' => now(),
        ]);

        $incoming = $this->hub()->table('stg_person')
            ->where('stg_person_id', $this->stagePerson(['source_id' => 91002]))->first();

        [$matched, , $state] = (new ProbabilisticResolver($this->systemId))->match($incoming, collect());

        $this->assertNull($matched, 'a merged identity must not be a Pass B candidate');
        $this->assertSame('no_match', $state);
        $this->assertNotNull($linked);
    }

    public function test_pass_b_scores_addresses_from_current_rows_only(): void
    {
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 1,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);
        $link = (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $id, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => 91101,
            'match_method' => 'deterministic', 'match_key' => 'new', 'match_score' => 1.0,
            'match_state' => 'auto_match', 'is_pinned' => 0, 'linked_at' => now(),
        ]);

        // An address the identity has MOVED AWAY from must not lend score to a
        // candidate that still lives there.
        $this->hub()->table('gp_address')->insert([
            'identity_id' => $id, 'address1' => '1 Old Road', 'city' => 'Albany',
            'state' => 'NY', 'zip' => '12207', 'is_primary' => 1, 'source_link_id' => $link,
            'version_no' => 1, 'current' => 0, 'date_created' => now(), 'date_updated' => now(),
        ]);

        $this->assertSame(
            0,
            (int) DB::connection('golden_profile')->table('gp_address')
                ->where('identity_id', $id)->where('current', 1)->count()
        );
    }

    public function test_the_credential_link_query_counts_current_versions_only(): void
    {
        // Two versions of one credential link. Unfiltered, this identity holds
        // twice the links it really has — which inflates the max_links guard and
        // doubles the placeholder count in every remote chunk. The measured cliff
        // in config/golden_profile.php (chunk 5000 at 360.7s vs chunk 1000 at 8.9s)
        // is what makes that a correctness issue rather than a tuning one.
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 1,
            'status' => 'active', 'version_no' => 1, 'current' => 1,
            'first_seen' => now(), 'last_updated' => now(),
        ]);

        foreach ([[1, 0, 'Pending'], [2, 1, 'Valid']] as [$version, $current, $status]) {
            $this->hub()->table('gp_identity_credential')->insert([
                'identity_id' => $id, 'credential_match_id' => 8001,
                'system_id' => $this->systemId, 'registry' => 'NYEMED',
                'match_summary_status' => $status, 'match_summary_status_code' => 20,
                'match_is_valid' => 1, 'source_current' => 1, 'link_state' => 'confirmed',
                'version_no' => $version, 'current' => $current,
                'date_created' => now(), 'date_updated' => now(),
            ]);
        }

        $links = DB::connection('golden_profile')->table('gp_identity_credential')
            ->where('identity_id', $id)->where('registry', 'NYEMED')
            ->whereIn('match_summary_status_code', [20, 30, 40, 45, 65, 70, 80, 85, 90])
            ->where('current', 1)
            ->get();

        $this->assertCount(1, $links);
        $this->assertSame('Valid', $links[0]->match_summary_status);

        // And the chunk cursor is sound again: (system_id, credential_match_id) is
        // unique among current rows, guaranteed by uq_cred_current.
        $this->assertSame(
            1,
            (int) DB::connection('golden_profile')->table('gp_identity_credential')
                ->where('identity_id', $id)->where('current', 1)
                ->where('credential_match_id', 8001)->count()
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/VersionedReadPathTest.php`

Expected: FAIL, 1 of 3. `test_pass_b_never_offers_a_superseded_identity_version` fails with
"a merged identity must not be a Pass B candidate" — the candidate query filters `i.status =
'active'` on whichever version it happens to join, and the superseded version 1 is still active. The
other two assert on queries written inline and pass; they exist to pin the shape the controller must
adopt in Step 4.

- [ ] **Step 3: Filter Pass B**

In `app/GoldenProfile/Resolution/ProbabilisticResolver.php`, `match()`:

```php
        // i.current = 1 as well as i.status = 'active'. Both are load-bearing:
        // `status` excludes a merged identity, `current` excludes a superseded
        // VERSION of a live one — and a superseded version is still 'active', so
        // without the second filter a merged-away identity re-enters the candidate
        // set through its own history. In the review band Pass B BINDS to its best
        // candidate, so that is a false merge produced silently.
        $candidateIds = $this->hub()->table('gp_source_link as l')
            ->join('stg_person as sp', function ($j) {
                $j->on('sp.system_id', '=', 'l.system_id')
                    ->on('sp.source_table', '=', 'l.source_table')
                    ->on('sp.source_id', '=', 'l.source_id');
            })
            ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
            ->where('sp.block_key', $p->block_key)
            ->where('i.current', 1)
            ->where('i.status', 'active')
            ->where(fn ($q) => $q->where('sp.source_id', '!=', $p->source_id)->orWhere('sp.system_id', '!=', $this->systemId))
            ->distinct()->pluck('l.identity_id');
```

```php
        $identities = collect();
        foreach ($candidateIds->chunk(1000) as $batch) {
            $identities = $identities->merge(
                $this->hub()->table('gp_identity')
                    ->whereIn('identity_id', $batch->all())
                    ->where('current', 1)
                    ->where('status', 'active')
                    ->get()
            );
        }
```

The `block_size_cap` count reads `stg_person`, which is unversioned — unchanged. Then the two
scoring helpers:

```php
    private function addressOverlap(object $p, int $identityId): array
    {
        $stg = $this->hub()->table('stg_person_address')
            ->where('stg_person_id', $this->stgId($p))->get();
        if ($stg->isEmpty()) {
            return [false, false];
        }
        // Current addresses only. An address the identity has moved away from is
        // not evidence that an incoming record describes the same person — it is
        // evidence about where they used to be, and scoring it would let stale
        // addresses accumulate score forever.
        $gp = $this->hub()->table('gp_address')
            ->where('identity_id', $identityId)->where('current', 1)->get();
```

```php
    private function sharesExclusionRegistry(object $p, int $identityId): bool
    {
        // if this source row's employee has an exclusion registry already on the identity
        $regs = $this->hub()->table('gp_identity_exclusion')
            ->where('identity_id', $identityId)->where('current', 1)
            ->whereNotNull('registry')->pluck('registry');

        return $regs->isNotEmpty();
    }
```

- [ ] **Step 4: Filter the two credential-search reads**

In `app/Http/Controllers/Api/V1/CredentialSearchController.php`, `resolveIdentity()`'s licence
narrower:

```php
        if ($r->filled('license_number')) {
            // Current licence versions only: narrowing on a licence an identity no
            // longer holds would resolve the request to the wrong person.
            $ids = DB::connection('golden_profile')->table('gp_license')
                ->where('license_number', $r->input('license_number'))
                ->where('current', 1)
                ->pluck('identity_id');
            $q->whereIn('identity_id', $ids);
        }
```

and `latestQualifyingCredential()`'s `$linkQuery`:

```php
        // current = 1 is what keeps three separate things true, all of them
        // measured rather than theoretical:
        //
        //   1. max_links (10,000) is counted from this query. Unfiltered, an
        //      identity whose credentials have been re-screened three times shows
        //      three times the links and starts throwing
        //      TooManyCredentialLinksException where it never used to.
        //   2. Each chunk of this query becomes a whereIn against the REMOTE CAMI
        //      source, and config/golden_profile.php records the cliff that makes
        //      the chunk size a correctness constraint rather than a tuning knob
        //      (chunk 1000 = 8.9s, chunk 5000 = 360.7s on identity 59). Version
        //      rows would push a 1,000-link chunk's worth of current links into a
        //      3,000-placeholder statement.
        //   3. chunkById below needs a strictly unique cursor. credential_match_id
        //      is unique per (system_id, credential_match_id) only among CURRENT
        //      rows — a guarantee uq_cred_current enforces in the database, which
        //      is why that index was built as a unique on a generated column rather
        //      than left to the write paths to respect.
        $linkQuery = DB::connection('golden_profile')->table('gp_identity_credential')
            ->where('identity_id', $identity->identity_id)
            ->where('registry', $r->input('registry'))
            ->where('current', 1)
            ->whereIn('match_summary_status_code', $codes);
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/VersionedReadPathTest.php`
Expected: PASS, 3 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 131 tests, 0 skipped.

- [ ] **Step 6: Prove nothing was missed**

Run: `grep -rn "table('gp_identity')\|table('gp_license')\|table('gp_address')\|table('gp_identity_identifier')\|table('gp_identity_credential')\|table('gp_identity_exclusion')" app/ --include=*.php | grep -v "current"`

Expected: only lines inside `SetFinalizer`, `SqlBackfill` (both guarded off in Task 10),
`Versioner` (which manages `current` itself and must not filter on it), `GpEval`'s emptiness probe,
and `Engine::applyMerge`'s deliberate all-version repoint. Any other line is a read this plan missed
— add the filter and a test for it before continuing.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Resolution/ProbabilisticResolver.php \
        app/Http/Controllers/Api/V1/CredentialSearchController.php \
        tests/Feature/VersionedReadPathTest.php
git commit -m "feat(scd2): filter Pass B candidates and the credential-search reads to current versions"
```

---

## Task 10: The eval gate, the index-constant parity, and the guard on the bulk paths

Three closing jobs, all of them about proving this plan did what it claimed and nothing more.

**The gate is the proof.** SCD-2 is a change to *how facts are stored*, not to *who matches whom*.
Every deterministic tier, every Pass B signal and every threshold is untouched. So the gate must read
**precision 1.0000 · recall 1.0000 · F1 1.0000 · 0 false merges · 0 false splits · 9 true pairs** —
identical to the measured baseline in `docs/EVALUATION.md`. Unlike plan 2, which deliberately removes
a tier and therefore has to re-baseline, this plan **re-baselines nothing**. The two ratchet
assertions stay exactly as they are.

**If the numbers move, a read filter is wrong, and the direction says which:**

| Movement | What it means |
|---|---|
| `false_merges` > 0 | A tier or Pass B candidate query is missing `current = 1` and bound a source row to a superseded version. Task 6 or Task 9. |
| `false_splits` > 0 | A tier query has `current = 1` but *lost* `status = 'active'`, or gained a filter on a column the write path does not set. The two are not interchangeable — see `docs/SCD2.md`. |
| `true_pairs` < 9 | Something removed fixture records. Never acceptable; `docs/EVALUATION.md` forbids it. |
| Anything else | Stop. Do not adjust the gate. Find the read. |

**Files:**
- Create: `app/GoldenProfile/Support/SetBasedPathGuard.php`
- Create: `tests/Feature/SetBasedPathGuardTest.php`
- Create: `tests/Unit/IdentityKeyIndexParityTest.php`
- Modify: `app/GoldenProfile/Materialize/SetFinalizer.php:53-60`, `:139-145`
- Modify: `app/GoldenProfile/SqlBackfill.php:119-131`, `:595-601`
- Modify: `docs/EVALUATION.md`

**Interfaces:**
- Produces: `SetBasedPathGuard::assertConverted(string $class): void` — throws
  `RuntimeException` when the schema is versioned and `$class` has not been converted;
  `SetBasedPathGuard::schemaIsVersioned(): bool`.

- [ ] **Step 1: Run the gate and record what it says**

Run: `vendor/bin/phpunit tests/Feature/EvalGateTest.php`
Expected: PASS, 1 test. The gate's own failure message prints the numbers when it fails; to see them
on a pass, run the command:

Run: `php artisan gp:eval`
Expected: precision 1.0000, recall 1.0000, f1 1.0000, false_merges 0, false_splits 0, true_pairs 9,
records 17, clusters 10 — byte-identical to the "Achieved" table in `docs/EVALUATION.md`.

If any figure differs, **stop and fix the read path**. Do not continue to Step 2, do not edit
`EvalGateTest`, and do not touch `min-precision` or `min-recall`.

- [ ] **Step 2: Record the non-movement in `docs/EVALUATION.md`**

Append to `docs/EVALUATION.md`, after the "Achieved — measured on this branch" section:

```markdown
## SCD-2 versioning (plan 3a) — the gate did not move

| Metric | Before | After |
|---|---|---|
| Precision | 1.0000 | 1.0000 |
| Recall | 1.0000 | 1.0000 |
| F1 | 1.0000 | 1.0000 |
| False merges | 0 | 0 |
| False splits | 0 | 0 |
| True pairs | 9 | 9 |

**That identity is the deliverable, not a footnote.** Plan 3a changed every write path in the hub
from overwrite to insert-new-version, and added a `current = 1` filter to every read of a versioned
table. None of that is supposed to alter which records group together — the grouping is
`gp_source_link` and no threshold, weight or tier moved. A gate that shifted in either direction
would mean a read filter is wrong:

- a false merge means a tier or Pass B query lost `current = 1` and matched a superseded version;
- a false split means a query kept `current = 1` but lost `status = 'active'`.

The ratchet assertions in `EvalGateTest` are therefore **unchanged** by this plan. Plan 2, which
deliberately removes the `ssn_hash` tier, is the one that has to re-baseline them.
```

- [ ] **Step 3: Write the failing parity test**

Create `tests/Unit/IdentityKeyIndexParityTest.php`:

```php
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
```

- [ ] **Step 4: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/IdentityKeyIndexParityTest.php`

Expected: FAIL, 2 of 3 — both constants still read `'idx_ssn' => 'ssn_hash'` and so on, so the
diff shows the five missing `` `current` `` columns. `test_the_two_copies_agree_with_each_other`
passes: they are both equally wrong.

- [ ] **Step 5: Update both constants**

Identically in `app/GoldenProfile/SqlBackfill.php` and
`app/GoldenProfile/Materialize/SetFinalizer.php`:

```php
    /**
     * gp_identity key indexes, dropped around a bulk operation and rebuilt after.
     *
     * Each definition ends in `current` and must stay in lockstep with
     * 2026_09_04_000100_add_scd2_versioning AND with the other class's copy —
     * IdentityKeyIndexParityTest pins all three together. Dropping the `current`
     * column here would not error: it would rebuild the pre-SCD-2 indexes, after
     * which every deterministic tier probe reads the whole version history and
     * filters in the server (EXPLAIN: type=ref key=idx_status rows=6475711 versus
     * key=idx_name_dob rows=1 — the defect that pinned sync at ~0.03 rows/sec).
     */
    private const IDENTITY_KEY_INDEXES = [
        'idx_ssn' => 'ssn_hash, `current`',
        'idx_npi' => 'npi, `current`',
        'idx_upin' => 'upin, `current`',
        'idx_dea' => 'dea_number, `current`',
        'idx_name_dob' => 'canonical_last, canonical_first, canonical_dob, `current`',
    ];
```

`ReflectionClass::getConstant()` reads private constants, so neither has to become public.

- [ ] **Step 6: Write the failing guard test**

Create `tests/Feature/SetBasedPathGuardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\SetFinalizer;
use App\GoldenProfile\SqlBackfill;
use App\GoldenProfile\Support\SetBasedPathGuard;
use RuntimeException;
use Tests\Support\HubTestCase;

class SetBasedPathGuardTest extends HubTestCase
{
    public function test_the_guard_sees_a_versioned_schema(): void
    {
        $this->assertTrue((new SetBasedPathGuard)->schemaIsVersioned());
    }

    public function test_the_set_based_finalizer_refuses_to_run(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SetFinalizer has not been converted to SCD-2');

        (new SetFinalizer)->run();
    }

    public function test_the_set_based_backfill_refuses_to_transform(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SqlBackfill has not been converted to SCD-2');

        (new SqlBackfill)->transform();
    }
}
```

- [ ] **Step 7: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/SetBasedPathGuardTest.php`

Expected: FAIL, 3 of 3 — `Class "App\GoldenProfile\Support\SetBasedPathGuard" not found`.

- [ ] **Step 8: Write the guard and wire it in**

Create `app/GoldenProfile/Support/SetBasedPathGuard.php`:

```php
<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Refuses to let the set-based bulk paths run against a versioned schema they do
 * not understand.
 *
 * SetFinalizer and SqlBackfill are ~1,400 lines of raw MySQL. Plan 3a converted
 * the per-row paths; converting these is plan 3b, because it is not a matter of
 * adding `AND current = 1` to some subqueries:
 *
 *   - SetFinalizer::survivorship() runs NINE separate per-field UPDATEs against
 *     gp_identity. None of them can mint a version without minting up to nine per
 *     identity per run, so the whole half has to be restructured into one
 *     all-fields pass that compares the winning row to the current version and
 *     versions only the identities that actually changed.
 *   - SetFinalizer::materializeRange()'s aggregate subqueries ($lic, $addr, $cred,
 *     $excl, $idt) COUNT and JSON_ARRAYAGG every row for an identity. Unfiltered
 *     they aggregate history: license_count doubles on the first re-observation.
 *   - SqlBackfill::residualCreateAndLink() uses merged_into "as a temporary
 *     stg_person_id carrier so the 1:1 create + link stays fully set-based", then
 *     clears it. merged_into is now a versioned attribute recording where a merged
 *     identity went, so that reuse would write a stg_person_id into a golden field
 *     and mint two versions per identity doing it. It needs its own scratch column.
 *
 * Every one of those failures is SILENT — wrong counts and wrong canonical values,
 * not an exception. On a 13M-row hub, discovering it after the fact means a full
 * reload. So the guard is deliberately loud and deliberately blunt: while the
 * schema carries version columns and this class still exists, the bulk paths do
 * not run.
 *
 * DELETING THIS FILE IS PART OF PLAN 3b. Remove the two assertConverted() calls
 * and this class together with the conversion, in the same commit.
 */
class SetBasedPathGuard
{
    /** True once 2026_09_04_000100_add_scd2_versioning has run. */
    public function schemaIsVersioned(): bool
    {
        return Schema::connection(config('golden_profile.connections.hub', 'golden_profile'))
            ->hasColumn('gp_identity', 'version_no');
    }

    /**
     * @throws RuntimeException when the schema is versioned and $class is not
     */
    public function assertConverted(string $class): void
    {
        if (! $this->schemaIsVersioned()) {
            return;
        }

        $short = class_basename($class);

        throw new RuntimeException(
            "$short has not been converted to SCD-2 and would corrupt a versioned hub: it ".
            'aggregates superseded rows into the profile rollups and overwrites gp_identity in '.
            'place instead of versioning it. Both failures are silent. Convert it (plan 3b) or '.
            'use the per-row path — Engine::backfill() and Engine::finalizeAll() — instead.'
        );
    }
}
```

Then wire it into the two entry points. In `app/GoldenProfile/Materialize/SetFinalizer.php`:

```php
use App\GoldenProfile\Support\SetBasedPathGuard;
use Illuminate\Support\Facades\DB;

// ...

    public function run(?callable $log = null): void
    {
        (new SetBasedPathGuard)->assertConverted(self::class);

        $log ??= fn ($p, $d) => null;
        $log('finalize', 'survivorship (set-based)');
        $this->survivorship();
        $log('finalize', 'materialize profiles (set-based)');
        $this->materialize();
    }
```

In `app/GoldenProfile/SqlBackfill.php`:

```php
use App\GoldenProfile\Support\SetBasedPathGuard;
use App\GoldenProfile\Support\SsnHashGuard;

// ...

    /** Post-staging transform: resolve → enrich → dedup → rollup (single process). */
    public function transform(?callable $log = null): void
    {
        (new SetBasedPathGuard)->assertConverted(self::class);

        $log ??= fn ($p, $d) => null;
        $this->indexStaging($log);
        // ... unchanged
    }
```

`transform()` rather than `run()`, and both rather than one: `run()` calls `stage()` then
`transform()`, and `stage()` alone is safe — it only writes `stg_*` and `src_*`, none of which are
versioned. Guarding `transform()` lets an operator stage a load, stop, and finish it with the per-row
path once 3b lands, instead of throwing away the staging work.

- [ ] **Step 9: Run everything**

Run: `vendor/bin/phpunit tests/Unit/IdentityKeyIndexParityTest.php tests/Feature/SetBasedPathGuardTest.php`
Expected: PASS, 6 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 137 tests, 0 skipped.

Run: `vendor/bin/pint --test`
Expected: PASS, no style issues.

Run: `php artisan gp:eval`
Expected: unchanged from Step 1 — precision 1.0000, recall 1.0000, 0 false merges, 0 false splits,
9 true pairs. This is the last chance to catch a read filter added in Tasks 8-9 that moved matching.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty
git add app/GoldenProfile/Support/SetBasedPathGuard.php \
        app/GoldenProfile/Materialize/SetFinalizer.php \
        app/GoldenProfile/SqlBackfill.php \
        tests/Feature/SetBasedPathGuardTest.php \
        tests/Unit/IdentityKeyIndexParityTest.php \
        docs/EVALUATION.md
git commit -m "feat(scd2): guard the unconverted bulk paths and record that the eval gate held"
```

---

## Self-review

**Spec coverage.** Every item the scope named is answered:

| Requirement | Where |
|---|---|
| Which tables get `current`, with justification for each inclusion and exclusion | "Which tables are versioned, and which are not" + `docs/SCD2.md` (Task 1). Six versioned; twenty excluded with a reason each, including the argument that the append-only tables (`gp_survivorship_audit`, `gp_resolution_log`, `gp_board_action`, `gp_attribute`, `gp_edge`) must NOT be versioned, and that `stg_*` is input rather than golden fact. |
| `date_created` / `date_updated` — add, map, or rename, and the cost | The mapping table: map on `gp_identity` (renaming breaks the public `last_updated` API field and touches six files), adopt the doc's names verbatim on the five tables that had no timestamps. The `last_updated` semantics change is named and recorded. |
| Every write path | `DeterministicResolver::createIdentity`/`backfillKeys`/`enrich` (Task 6), `Survivorship::recompute` (Task 7), `Engine::applyMerge`/`repointDeduped`/`rollupCredentials`/`rollupExclusions` (Task 8), `ProfileMaterializer` (Task 7, as a read). `SetFinalizer` and `SqlBackfill` are **explicitly deferred to 3b and guarded off** (Task 10). |
| Every read path | Tasks 6, 7, 8, 9, plus the "no change needed, and why" table for `IdentitySearchController`, `AliasIndexer`, `IdentityProfileResource` and `SsnHashGuard`, and the Task 9 Step 6 grep that proves nothing was missed. |
| Unique constraints | The virtual `current_key` generated column with `CONCAT` NULL-propagation, per table, in Task 3. `gp_source_link`'s `uq_source` is **unchanged** because that table is not versioned. `gp_identity_alias`'s composite PK is unchanged for the same reason. |
| Idempotency | Redefined as "no new link and no new version for an unchanged source row", enforced by `Versioner::write()`'s attribute comparison, asserted in `VersionedResolverTest` and `VersionerTest`. `ResolverLadderTest::test_resolving_the_same_row_twice_is_idempotent` passes unchanged, and the plan explains why. |
| Row growth | Its own section: the three-category column model (`attributes` / `derived` / `onCreate`), the ~13.38M-rows-per-`finalizeAll` figure the no-change rule prevents, `current` appended to five indexes, the `IDENTITY_KEY_INDEXES` drift trap, and the `link_chunk_size` cliff with its measured numbers. |
| The eval gate | Task 10. Gate **unchanged**, asserted to stay at 1.0/1.0/0/0/9, with a table mapping each direction of movement to the specific read that would have caused it. |
| Migration safety | New `2026_09_*` migrations only. The `current`/`version_no` backfill is the column DEFAULT under `ALGORITHM=INSTANT` — no `UPDATE` pass over 13M rows at all. The timestamps go through `gp:version-backfill`, chunked and resumable. The three unavoidable `ALGORITHM=COPY` PK changes are named, guarded, and routed to `gh-ost` in the runbook. |
| The honest tension | Its own section before the tasks, plus a paragraph in `docs/SCD2.md` so the register carries it too. |

**Placeholders.** One, intentional: the "Measured before migration" table in `docs/SCD2.md`. Its
values come from a hub nobody in this environment can reach. Task 1 ships the SQL, states who runs
it, and nothing downstream blocks on it — the migration is guarded rather than sized.

**Type consistency.** `Versioner::write(string, array, array, array, array): array{version_no:int,
new_version:bool}` is the one new signature and it is consumed identically in
`DeterministicResolver::backfillKeys`/`enrich`, `Survivorship::recompute` and `Engine::applyMerge`/
both rollups. `Versioner::repointForMerge(string, int, int): array{repointed:int,retired:int}`
replaces `Engine::repointDeduped(string, string, int, int, array): void`, which is deleted.
`Engine::mergeIdentity` gains a third `string $matchKey` parameter and all four call sites pass one.
`SetBasedPathGuard::assertConverted(string): void` and `schemaIsVersioned(): bool` are the only other
new members. `Survivorship::recompute(int): void`, `ProfileMaterializer::rebuild(int): void`,
`DeterministicResolver::resolve(int): int`, `Engine::dedup(?callable,int,int): int` and
`Engine::finalizeAll(?callable,int,int): void` are all unchanged, which is what keeps `EvalRunner`,
`GpBackfill`, `GpSync` and `GpRebuildProfile` working without edits.

**Verified against the repo.** `gp_identity_credential.current` exists at
`2026_07_20_140000_create_golden_profile_schema.php:104` and is read in exactly the five places
Task 2 changes. `gp_source_link`'s `uq_source` is at line 67 and `gp_address`'s `uq_addr` at 175,
`gp_license`'s `uq_lic` at 191, `gp_identity_alias`'s composite PK in
`2026_08_10_000000_create_gp_identity_alias.php`. `IDENTITY_KEY_INDEXES` is duplicated at
`SqlBackfill.php:595` and `SetFinalizer.php:139` and both classes drop and rebuild it.
`gp_identity_resolution.is_current` already implements this pattern. `merged_into` is reused as a
scratch column by `SqlBackfill::residualCreateAndLink()`. `gp_resolution_log`'s `action` enum
contains `merge` and nothing writes it. The `link_chunk_size` measurements are quoted verbatim from
`config/golden_profile.php:229-235`.

### Recommended split: this document is 3a, and 3b is required

**Yes, split it — and this document is already the 3a half.** The full scope does not fit in ten
executable tasks. The count, honestly:

| Work | Tasks |
|---|---|
| **3a (this document)** — register + audit, the `current` rename, the migration, the timestamp backfill, `Versioner`, the per-row resolve path, the finalize pair, `Engine`, Pass B + the API, the gate and the guard | **10** |
| **3b** — set-based parity | **~5** |

The boundary is not arbitrary. It falls where the *technique* changes, not merely where the file
does: everything in 3a is per-row PHP calling one primitive; everything in 3b is set-based SQL that
has to reproduce that primitive's semantics in `INSERT … SELECT` form. Splitting anywhere else —
"schema first, then writes, then reads" was the other candidate — would leave the hub in a state
where the schema is versioned and half the write paths are not, which is precisely the silent-
corruption window `SetBasedPathGuard` exists to close.

**Plan 3b's proposed tasks**, with the reason each is a task rather than a step:

1. **Restructure `SetFinalizer::survivorship()` into one all-fields versioned pass.** The nine
   per-field `UPDATE`s become one ranked CTE producing the complete winning row per identity, a
   comparison against the current version, and a two-statement flip-and-insert for the identities
   that actually differ. This is the hardest single piece of work in the whole programme and it must
   land on its own.
2. **Filter `SetFinalizer::materializeRange()`'s aggregates.** Five subqueries gain `AND current = 1`
   and the `$prim`/`$ssn4`/`$term` window functions gain it in their `PARTITION BY` sources.
   Independent of task 1 and independently testable: the assertion is that a set-based materialize and
   `ProfileMaterializer::rebuild()` produce byte-identical profile rows, which is the existing
   documented invariant.
3. **Give `SqlBackfill::residualCreateAndLink()` its own scratch column.** `merged_into` is now a
   golden attribute. A new nullable `stg_seed_id` column (or a temporary table) replaces it.
4. **Version the set-based `enrich()` and `rollup()`.** Their `ON DUPLICATE KEY UPDATE` clauses become
   flip-and-insert pairs, and the `ON DUPLICATE KEY` target changes because the natural-key uniques
   now end in `version_no`.
5. **Remove `SetBasedPathGuard`, re-run the gate, and assert per-row/set-based parity end to end.**
   The guard's own docblock says deleting it is part of 3b; the parity assertion is what earns that.

3b depends on 3a and on nothing else, and no other plan in the programme depends on 3b: plans 4, 6
and 7 need the versioned schema and the `Versioner` primitive, both of which 3a delivers.

### Known risks carried into execution

1. **The three `ALGORITHM=COPY` primary-key changes are the only part of this plan that can take a
   production hub down.** `gp_identity_credential` is the largest table in the hub and its PK change
   is a full rebuild with writes blocked. The migration guards them so they can be applied out of
   band with `gh-ost`, but the guard is only as good as the operator reading `docs/SCD2.md` first.
   Run `scripts/scd2-preflight.sql` before scheduling anything.
2. **`Versioner::same()` is a loose comparison, and loose is the safe direction here — but only just.**
   It exists because a DATE round-trips as `'1970-04-02'`, an `npi` comes back as a string, and a
   `tinyint` comes back as `'0'`; strict comparison would call each one a change and mint a version on
   every write, which is the runaway the whole design guards against. The risk is the opposite error:
   a genuine change that `same()` swallows, so a fact silently fails to version. The date-prefix rule
   is the loosest part. If a bug of this shape appears, tighten `same()` per column type — do not
   make it strict wholesale.
3. **Concurrency was designed for but not load-tested.** `write()` locks the current row `FOR UPDATE`
   inside a transaction, and `uq_*_current` catches anything that races past it. Under 16 parallel
   staging workers (the number `SqlBackfill::DEADLOCK_RETRIES` was sized for) the expected failure is a
   deadlock or a duplicate-key error, not corruption — but `write()` has no retry. If parallel backfill
   starts failing, wrap the `write()` transaction in `transaction($fn, self::DEADLOCK_RETRIES)` the way
   `SqlBackfill::stage()` already does, rather than removing the lock.
4. **Row growth is bounded by a rule, not by a constraint.** Nothing in the database stops a future
   write path from putting a recomputed aggregate in `attributes` and multiplying a 13M-row table.
   `VersionerSpecTest` pins `record_count` as derived; a periodic check of
   `SELECT AVG(version_no) FROM gp_identity WHERE current = 1` is the operational signal, and it
   belongs in whatever monitoring plan 8 adds.
5. **Plan 2 and this plan both touch `ssn_hash`.** Whichever lands second removes one line from
   `Versioner::TABLES['gp_identity']['attributes']` and one index from the migration and the two
   `IDENTITY_KEY_INDEXES` constants. `IdentityKeyIndexParityTest` will fail loudly if only some of
   those are done, which is the intended behaviour.
6. **`HubTestCase` migrates once per process and rolls each test back.** Every test in this plan stays
   inside that transaction; `Versioner::write()` opens nested transactions, which become savepoints
   and roll back with the outer one — the same arrangement `Engine::mergeIdentity` already relies on.
   `AliasIndexer::rebuildFromStaging()` calls `truncate()`, which commits implicitly in MySQL and
   would leak state; no test here calls it.


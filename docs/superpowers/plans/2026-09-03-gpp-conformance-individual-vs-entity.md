# Individual vs Entity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the hub a first-class distinction between an individual provider and an organization — one `gp_identity.entity_type` discriminator plus `org_name`, a type inferred at the single ingestion choke point, a type-scoped Pass A ladder with TIN/EIN/UEI keys for entities, an entity blocking key and entity Pass B scoring, entity survivorship, an entity-aware API, and a measured, reviewable path for reclassifying the ~107,882 organization names that currently ride through the hub inside a person structure.

**Architecture:** One golden table with a discriminator, not two golden tables — the two design pages disagree, and this plan follows plan 1's precedent of conforming to *semantics* while keeping the existing `gp_*` names (justified at length in "One table or two" below). `entity_type VARCHAR(12) NOT NULL DEFAULT 'individual'` is adopted verbatim from the Data Model page; the default is what makes the migration a metadata change that classifies all 13.38M existing rows as the status quo, so the migration itself cannot move a single match. Every deterministic tier gains an `entity_type` predicate, which strictly narrows each tier and is therefore incapable of creating a false merge; the entity ladder reuses plan 5's `gp_identity_identifier` mechanism for TIN/EIN/UEI rather than inventing a parallel one; and reclassification of existing rows happens in a separate, read-only-first pair of artisan commands that write through plan 3's `Versioner`, never inside a migration.

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

- **`entity_type` is a classification, not a survivable fact.** It is decided once, at identity
  creation, from the inference rule below, and changed afterwards only by an explicit
  reclassification that writes a new version and a `gp_resolution_log` row. `Survivorship` must
  never write it: authority-plus-recency across several source rows would let an identity's type
  oscillate between rebuilds, and every downstream tier predicate would flip with it.
- **Type scoping only ever removes candidates.** Adding `entity_type = ?` to a tier probe strictly
  narrows its result set, so it can never create a false merge. It can create a false *split*, and
  only where the classification itself is wrong — which is why the inference rule's false-positive
  cost is named explicitly (design question 2 below) and why `mixed` identities are reported and
  left alone rather than reclassified.
- **`TRIM(x) <> ''`, never `x IS NOT NULL`.** Measured 2026-09-04 on `streamline_local.employees`
  (n=109): `business` is NULL on 10 rows and the **empty string on 96**, and `first_name` /
  `last_name` are `NOT NULL char(100)` so a blank name is `''`, never NULL. A null-check-based
  inference rule classifies 96 of 109 local rows as organizations. Every predicate in this plan that
  reads a source name or business column trims first.

---

## Programme context — this is plan 4 of 8

| # | Plan | Depends on | Status |
|---|---|---|---|
| 1 | Foundation — eval set, scorer, resolver harness, CI | — | DONE, merged into this branch |
| 2 | SSN removal | 1 | written, not executed |
| 3 | SCD-2 versioning | 1 | written (3a), not executed |
| **4** | **Individual vs entity** *(this document)* | **1, 3, 5** | **this document** |
| 5 | Match keys & data quality | 1 | written, not executed |
| 6 | Steward writer layer | 1, 3 | to write |
| 7 | Exclusion lifecycle | 1, 3 | to write |
| 8 | Incremental profiling | 1, 5 | to write |

**Why here.** Entity handling touches every layer at once — schema, ingestion, both resolver passes,
survivorship, the read model, and both endpoints — so it has to come after the plans that rewrite
those layers wholesale, or it gets built twice. Plan 3 is a hard prerequisite because an
organization's name history is the doc's `entity_names` table, and a name history is unwriteable
against a schema that overwrites; this plan keeps that history by versioning `org_name` on
`gp_identity` rather than adding a table.

**This plan adds a dependency the programme table did not have: plan 5.** The brief scopes plan 4 to
"1, 3". Executing it against 1+3 alone would mean re-implementing a multi-valued identifier match
tier on `gp_identity_identifier` in order to key entities on TIN/EIN/UEI — precisely the parallel
mechanism plan 5 exists to prevent. Plan 5 Task 9 adds the `foreach ($identifiers as $ident)` tier to
`DeterministicResolver::matchDeterministic()`, adds the `gp_identity_identifier` upsert loop to the
per-row `enrich()`, and threads a `state` column through both; plan 5 Task 10 adds
`EvalSet::identifiers()` and identifier staging to `EvalRunner`. This plan consumes all four and
extends them by three `id_type` values. Reordering 4 before 5 is possible but costs a throwaway
implementation of that tier; declaring the dependency is cheaper and more honest.

**Exactly what this plan assumes has landed.** State this back to the reviewer before starting
Task 2; Task 1 verifies it with a command.

*From plan 1:* `Tests\Support\HubTestCase` (MySQL-backed; `stagePerson()`, `stageLicense()`,
`blockKey()`), `App\GoldenProfile\Eval\{EvalSet,EvalRunner,MatchScorer}`,
`tests/eval/identity-pairs.json`, `tests/Feature/EvalGateTest.php`, `docs/EVALUATION.md`.

*From plan 3 (3a):*
- `gp_identity`, `gp_license`, `gp_address`, `gp_identity_credential`, `gp_identity_exclusion` and
  `gp_identity_identifier` each carry `version_no`, `current`, created/updated timestamps, a
  `current_key` virtual generated column, and a single-current unique index.
- `gp_source_link` is **not** versioned, `uq_source` is unchanged, and no matching threshold moved.
- `gp_identity_resolution` keeps its pre-existing `is_current`; nothing was renamed.
- `gp_identity` maps the doc's `date_created` / `date_updated` onto its existing `first_seen` /
  `last_updated`; the five other versioned tables adopt `date_created` / `date_updated` verbatim.
- `App\GoldenProfile\Support\Versioner` exists, with
  `write(string $table, array $key, array $attributes, array $derived = [], array $onCreate = []): array{version_no:int,new_version:bool}`,
  `current(string $table, array $key): ?object`, `retire()`, `repointForMerge()`, `isVersioned()`,
  `spec()`, and the `TABLES` register.
- `DeterministicResolver`, `Survivorship`, `ProbabilisticResolver`, `ProfileMaterializer`, `Engine`
  and `CredentialSearchController` filter `current = 1` on every read and write through `Versioner`.
- `SetFinalizer` and `SqlBackfill` **refuse to run** (plan 3 Task 10's `SetBasedPathGuard`) until
  plan 3b converts them. `docs/SCD2.md` holds the register.
- `gp_identity_credential.current` was renamed `source_current`; the API field name `current` is
  unchanged.

*From plan 5:* NPI check-digit validation at the connector choke point; `stg_person_identifier.state`
and `gp_identity_identifier.state`; the `dea_multi` and `mmis+state` deterministic tiers; the per-row
`enrich()` writing `gp_identity_identifier`; `EvalSet::identifiers()`; `EvalRunner` identifier
staging; `true_pairs >= 11` in `EvalGateTest`; and the corrected fixture NPIs (`1987654328`,
`1112223338`).

**Any table this plan creates must go through `Versioner`.** It creates none — see "One table or
two". The two columns it adds to `gp_identity` join that table's existing `attributes` list, so they
are versioned by the machinery already there, and Task 2 updates `Versioner::TABLES` and
`docs/SCD2.md` in lockstep.

**Interaction with plan 2 (SSN removal).** Plan 2 deletes `ssn_hash` from `stg_person`,
`gp_identity` and `gp_identity_profile`, removes it from `Survivorship::IDENTITY_FIELDS`, and deletes
the Pass A SSN tier. This plan's Task 4 marks that tier individual-only; whichever lands second
deletes the other's reference — one branch removed from `matchDeterministic()` and one line from the
entity-shape assertion in `EntitySchemaTest`. Neither plan blocks the other.

---

## The two design pages disagree, and this section is the ruling

Three Confluence pages require this feature, and two of them ask for different schemas.

**"Data Model (What We Store)"** asks for one table: `golden_provider.entity_type VARCHAR(12) NOT
NULL`, plus `org_name`, and `provider_identifier.id_type` including `EIN` and `UEI`.

**"Data Flow by CAMI"** (DEV page 4099997697) asks for two: separate `individuals` and `entities`
golden tables, each with its own name history (`individual_names`, `entity_names`) and address join
(`individual_addresses`, `entity_addresses`), a shared `addresses` book, and `licensing_credentials`
hanging off individuals only. `entities` carries UPIN, TIN + hash + last four, NPI. Its process 1
branches on **"Employee Type?"** before writing.

**"Golden Profiles — Delivery Plan & Checklist"** makes it a discovery obligation rather than a
schema: §0 *"Decide record types in scope: individual vs organization (entity) — they need different
rules"*, §3 *"Plan individual vs entity profiling differences (rules, keys, survivorship)"*.

### One table or two

**Decision: one table, `gp_identity`, with an `entity_type` discriminator and an `org_name` column.**

This is the same reconciliation plan 1 made and recorded: keep the existing `gp_*` names and conform
to the *semantics* of the design set rather than to either page's literal schema. Here that choice
also satisfies one of the two pages literally — the Data Model page's `entity_type VARCHAR(12) NOT
NULL` and `org_name` are adopted verbatim, column type included.

**How the two-table page's requirements are met on one table.** Its schema is five requirements
wearing a schema's clothes:

| "Data Flow by CAMI" asks for | Satisfied by |
|---|---|
| `individuals` and `entities` distinguishable | `gp_identity.entity_type`, with a named CHECK constraint so the domain is enforced in the DDL and nameable by `gp:entity-audit` |
| `individual_names` / `entity_names` — a name history per type | Plan 3. `canonical_*` and `org_name` are in `Versioner::TABLES['gp_identity']['attributes']`, so a rename is a new version with the old one preserved at `current = 0`. That *is* the name-history table, split by type through the same discriminator |
| `addresses` shared book plus `individual_addresses` / `entity_addresses` joins | `gp_address` already collapses book and join, and plan 3's register says versioning it is how address history is kept. A per-type join table adds nothing an `entity_type` predicate on the parent does not already give |
| `licensing_credentials` on individuals only | Task 4 makes the licence tier individual-only for **matching**, which is the part that changes behaviour. `gp_license` rows are still written for an entity rather than dropped — see "What this plan deliberately does not touch" |
| `entities` carries UPIN, TIN + hash + last four, NPI | `upin` and `npi` are already columns on `gp_identity`; TIN joins `gp_identity_identifier` as `id_type = 'tin'` (plan 5's mechanism), alongside `ein` and `uei` |

**What two tables would actually cost.** Fourteen tables carry a bare `identity_id` today:
`gp_source_link`, `gp_edge`, `gp_attribute`, `gp_identity_credential`, `gp_identity_exclusion`,
`gp_identity_resolution`, `gp_resolution_log`, `gp_board_action`, `gp_survivorship_audit`,
`gp_license`, `gp_address`, `gp_identity_identifier`, `gp_identity_profile` and
`gp_identity_alias`. Splitting the golden table forces one of two things on every one of them:

- **Two id spaces.** Each of the fourteen needs its own type column to say which space its
  `identity_id` points into — the discriminator returns, spread over fourteen tables instead of
  living in one, and now unenforceable, because no single foreign key can span two parents.
- **One shared id sequence.** A discriminator by another name, with nothing in the database
  stopping a row from claiming the wrong type.

Three consequences are worth naming because they are not merely tedious:

1. **`gp_source_link`.** `uq_source(system_id, source_table, source_id)` is, in plan 3's words,
   "the sole thing making `DeterministicResolver::resolve()` idempotent". Two golden tables mean
   either two link tables — and then `resolve()`'s very first query, the idempotency check, must
   know the record's type *before* it can look up whether the row is already linked, a
   chicken-and-egg with the inference step that runs at staging — or one link table with a type
   column, which is the discriminator again.
2. **`gp_identity_profile` and both endpoints.** The profile is a one-row-per-identity read model
   that `IdentitySearchController` pages in two phases specifically because a row can carry >100MB
   of JSON (identity 3's `credentials` blob is 69MB). Two profiles means either a UNION inside that
   two-phase paging — whose phase-1 narrow sort would then be sorting a derived table and lose
   `idx_name_dob` — or a second endpoint, i.e. a public API split CAMI would have to adopt.
3. **`Engine::mergeIdentity()` / `applyMerge()`.** It repoints seven child tables by `identity_id`
   and calls `repointDeduped()` on three more. Across two id spaces each of those ten statements
   must know which parent it is repointing into, and a cross-type merge becomes
   representable-but-meaningless rather than simply impossible.

**What the discriminator costs, stated plainly.** On an `entity` row six columns are permanently
NULL: `canonical_first`, `canonical_middle`, `canonical_suffix`, `canonical_dob`, `ssn_hash` and
`dea_number`. On the ~13.38M existing individual rows `org_name` is permanently NULL. `idx_name_dob`
holds an all-NULL tuple for every entity row. And nothing at the row level stops a bad write putting
a DOB on an entity.

The mitigations, and the one deliberate gap:

- The value domain **is** enforced: `ck_identity_entity_type CHECK (entity_type IN ('individual',
  'entity'))`, a named constraint visible in the DDL.
- The row *shape* is **not** enforced by a CHECK, deliberately. A shape constraint
  (`entity_type = 'individual' OR (canonical_dob IS NULL AND …)`) would reject exactly the `mixed`
  identities this plan decides to preserve (design question 6), which would make
  `gp:entity-reclassify` undeployable against the real hub — the constraint would fail validation on
  data the plan intends to keep. Shape is enforced one level up instead, in
  `EntitySchemaTest::test_an_entity_identity_carries_no_person_only_fact` against the resolver, and
  reported as a count by `gp:entity-audit`. That is a real weakening versus a database constraint,
  and it is the price of not destroying the ambiguous rows.
- `VARCHAR(12)` rather than an `ENUM`, per the doc. An ENUM would enforce the domain for free with
  no validation scan, which is a genuine advantage; it was passed over because the Data Model page
  is explicit about the type and this programme's purpose is conformance, and because a named CHECK
  gives the same enforcement in a form `gp:entity-audit` can cite in its output.

### `org_name` is its own column, not a reuse of `canonical_last`

Putting the organization name into `canonical_last` is the cheapest-looking option and it is wrong
for a mechanical reason: `Survivorship::IDENTITY_FIELDS` maps `canonical_last` from
`stg_person.last_name`, which is NULL on every entity row. `recompute()` would find no candidate,
`continue`, and leave `canonical_last` out of `$update` — so the value would survive the first
finalize, but every path that *does* write `canonical_last` (`backfillKeys()`, `applyMerge()`'s
null-fill) would then be writing a surname into an org-name column. Worse,
`Engine::mergeByNameDob()` and `CredentialSearchController::resolveIdentity()` both read
`canonical_last` / `profile.last_name` as a surname. A separate column costs one `ALTER` and removes
all of it.

### What this plan deliberately does not touch

- **`gp_identity_alias`.** `stg_person_alias.alias_type` is `enum('maiden','alt','business')` — the
  business discriminator exists in staging — while `gp_identity_alias.alias_part` is
  `enum('last','first')`, so the label is lost downstream (both enums verified from DDL on the live
  test schema, 2026-09-04). Adding a `'business'` member is tempting and is **not** done:
  `gp_identity_alias` is the only entity lookup path that works today (see the API section), its
  composite primary key `(alias_name, identity_id, alias_part)` is its dedup guarantee, and
  `AliasIndexer::insertSql()` hardcodes the two parts. Changing the enum means rewriting the indexer
  and running a full `gp:rebuild-aliases` over 108k staged alias rows, to duplicate a distinction
  that `gp_identity.org_name` now carries as a first-class golden fact. Business aliases keep
  flowing into `gp_identity_alias` exactly as they do today, so entity search keeps working
  unchanged.
- **`gp_license` for entities.** The doc puts `licensing_credentials` under individuals only. This
  plan conforms where it changes behaviour — the licence *tier* is individual-only, and an
  individual can no longer bind to an entity on a shared licence — but keeps writing `gp_license`
  rows for an entity rather than dropping them. Under a discriminator, "which table holds it" is
  moot, and refusing the write would silently discard facility licence numbers whose prevalence
  nobody here can measure (the local dataset's three entity rows carry no licence at all, n=3).
  Naming what we do not know beats deleting on a guess.
- **The set-based paths.** `SqlBackfill` and `SetFinalizer` refuse to run under plan 3a's
  `SetBasedPathGuard`, so there is no live divergence to fix. Their entity obligations are
  enumerated for plan 3b in Task 10 and in `docs/ENTITY-TYPES.md` rather than half-implemented here.
  The one exception is `IDENTITY_KEY_INDEXES`, which is a schema mirror rather than logic and is
  updated in lockstep with the migration in Task 2 — the trap the authoring brief flags.

---

## Design question 2: how is the type decided?

**Measured answer: nothing in `streamline_local.employees` carries it.**

Verified 2026-09-04 against the live source schema (`SHOW FULL COLUMNS FROM employees`, 70 columns).
There is no `employee_type`, `entity_type`, `provider_type`, `is_individual`, `is_entity`,
`is_business`, `org_name`, `organization` or `record_type` column. The only columns whose names
contain "type" are `license_type`, `license_type_id`, `alt_license_type` and `alt_license_type_id` —
a clinical credential type, not a record type. Two near-misses, both ruled out:

- `record_status varchar(255)` — plausible by name; in the local dataset it holds only NULL (98 rows)
  and `''` (11 rows). Not a type.
- `facility_id varchar(65) NOT NULL` — a free-text, client-supplied identifier, not a flag.

There is also **no entity/organization table** in `streamline_local`: `SHOW TABLES LIKE` on
`%entity%`, `%business%`, `%organization%`, `%facility%`, `%company%` and `%org%` all return nothing;
the `%provider%` hits are settings tables (`provider_identifier_settings`,
`account_provider_identifier_settings`). Organizations live inside `employees`, as rows.

So the doc's `Employee Type?` branch has no persisted source column. It is a decision point in
CAMI's own import/UI flow that never reaches the row. **The type must therefore be inferred, and this
plan says so rather than inventing a column.**

### The inference rule

```
entity      iff  TRIM(business) <> ''  AND  TRIM(first_name) = ''  AND  TRIM(last_name) = ''
individual  otherwise
```

`business` is the primary organization-name column; `alt_business1` / `alt_business2` are additional
names for the same organization, and `alt_business3..9` arrive through `employee_additional_info`.
All of them continue to be staged as `alias_type = 'business'`. `org_name` is the first non-blank of
`business`, `alt_business1`, `alt_business2`.

The rule lives in exactly one place, `StreamlineLocalConnector::personRow()`, because that is the
choke point both ingestion paths share: `Engine::backfill()` and `Engine::sync()` reach it through
`ingest()`, and `SqlBackfill::stage()` calls `personRow()` directly. Same single-choke-point
placement plan 5 uses for NPI validation, and for the same reason.

### Measured state of the signal

`streamline_local` (n=109 rows across 17 employee lists), 2026-09-04:

| Measure | Count |
|---|---|
| total rows | 109 |
| `business` non-blank | 3 |
| `business` non-blank AND both names blank (→ **entity**) | 3 |
| `business` non-blank AND a name present (the ambiguous case) | **0** |
| `business` blank AND a name present (→ individual) | 106 |
| no name and no business at all | 0 |
| `alt_business1` / `alt_business2` non-blank | 0 / 0 |

`business` is NULL on 10 rows and `''` on **96**. All three entity rows carry the same literal value,
`ABC Medical Supply` (ids 102, 107, 114), with `first_name = ''`, `last_name = ''`, `npi = NULL` —
hand-seeded smoke-test fixtures.

**What these numbers do and do not establish.** They establish *shape*: organizations exist as
business-populated / name-blank rows, and the empty-string trap is real and would misclassify 96 of
109 rows. They establish nothing about production prevalence, and in particular 0 ambiguous rows out
of 3 entity rows cannot rule out mixed business-plus-person rows in production. That is one of the
numbers `gp:entity-audit` exists to measure, and it is written as a human task in Task 9.

### The false-positive cost, and the false-negative cost

**False positive** (a person classified as an organization): someone imported with both name columns
blank and their practice name in `business`. Consequence: `entity_type = 'entity'` suppresses the
SSN, DEA, licence and name+dob tiers for that row and gives it an entity block key, so it can never
bind to its own correctly-named records. That is a **permanent, silent false split** — nothing
downstream reconsiders a classification. It is also why design question 6 refuses to reclassify
ambiguous history automatically.

**False negative** (an organization classified as a person): someone typed the org name into
`last_name`. Consequence: `org_name` stays NULL and the row stays on the individual ladder. The
name+dob tier needs first *and* last *and* DOB, so it will not fire on a bare org name; the realistic
collision is the licence tier, which is exactly the cross-type false merge Task 4 closes for
correctly classified rows and cannot close for misclassified ones.

**NPI is not a type signal, and its validation does not differ.** A type-1 (individual) and a type-2
(organizational) NPI are indistinguishable from the number: both are ten digits with the same Luhn
check digit computed over the nine-digit base prefixed with `80840`, and NPPES carries the
distinction in a separate *Entity Type Code* field that `streamline_local` does not mirror. So plan
5's check-digit validation is **identical for both types and needs no change** — only the
interpretation differs — and NPI cannot be used to infer the type. Both facts are asserted in Task 3.

---

## Design question 3: the entity match ladder

Individuals today (after plans 3 and 5): `ssn_hash` 0.99, `npi` 0.99, `dea_number` 0.99, `upin` 0.99,
`dea_multi` 0.99, `mmis+state` 0.99, `license_number+certification_state` 0.99, `name+dob` 0.95.

None of `date_of_birth`, `dea_number` or `license+state` means anything for an organization;
conversely TIN/EIN and UEI mean nothing for a person. The entity ladder is:

| Tier | Confidence | Mechanism |
|---|---|---|
| `npi` | 0.99 | `gp_identity.npi`, type-scoped. A type-2 organizational NPI |
| `upin` | 0.99 | `gp_identity.upin`, type-scoped. The doc lists UPIN on `entities` |
| `tin` | 0.99 | `gp_identity_identifier`, `id_type = 'tin'`. Federal, so **not** state-scoped |
| `ein` | 0.99 | Same, `id_type = 'ein'`. EIN and TIN are the same nine digits in this domain; both `id_type`s are accepted because the Data Model page names `EIN` while CAMI data uses both words |
| `uei` | 0.99 | Same, `id_type = 'uei'`. SAM.gov Unique Entity ID, twelve alphanumerics |

**There is deliberately no soft Pass A tier for entities.** An `org_name + zip` deterministic key was
designed and then dropped, because it makes Pass B structurally dead for entities:
`ProbabilisticResolver::addressOverlap()` sets its zip flag only when the two zips are equal, and its
address flag only inside that, so an entity pair can score above the review floor *only* when their
zips match — and if the zips match and the names are equal, an `org_name + zip` Pass A tier would
already have bound them at 0.99 with no review record. Dropping that tier moves every soft entity
decision into Pass B, where it lands in the review band, binds, and writes a `gp_resolution_log` row
through the existing `logReview()`. That is what `tracks.identity = precision_first` asks for, and it
is why Task 6 is load-bearing rather than decorative.

**Reuse, not a parallel mechanism.** `gp_identity_identifier.id_type` is `varchar(16)` (verified),
so `tin`, `ein` and `uei` need no schema change at all. Plan 5's tier loop already probes
`(id_type, id_value)` and state-scopes only `mmis`; Task 5 extends the state-scoping decision and
the `deterministic_keys` config rather than adding a second loop.

**TIN hashing and last four.** The doc asks `entities` to carry "TIN + hash + last four". This plan
stores the TIN in `gp_identity_identifier.id_value` in the clear and does **not** add a hash column,
because plan 2 is in the middle of removing exactly that pattern for SSN, and adding a second
plaintext-plus-hash pair while the first is being deleted would be building the thing the programme
is dismantling. A TIN is also not an SSN — it is issued to organizations and appears on public
filings — so the confidentiality argument driving plan 2 does not apply. Recorded as a deliberate
deviation in Self-review.

---

## Design question 4: entity blocking and Pass B

**The reading is verified.** `ProbabilisticResolver::match()` opens with

```php
if (! $p->block_key) {
    return [null, 0.0, 'no_match'];
}
```

and `StreamlineLocalConnector::blockKey()` returns `null` whenever the trimmed `last_name` is empty.
An entity row has a blank `last_name`, so its `block_key` is NULL, so **Pass B never runs for an
organization at all** — not "runs badly", never runs. Confirmed by reading both methods.

Two further things break the moment an entity is given a block key, and both must be fixed in the
same task or the block key is worse than useless:

1. **`NameMatcher::compatible()` rejects every entity pair.** `eq()` is
   `return $a !== null && $a === $b;`, and `norm('')` returns `null`, so with both first and last
   names blank the gate returns false for every candidate. Pass B would block, score nothing, and
   return `no_match`.
2. **If the gate were bypassed, the name weight would fire at full strength on two nameless rows.**
   `score()` builds `strtolower(trim(($p->last_name ?? '').' '.($p->first_name ?? '')))` — `''` for
   an entity — and `jaroWinkler('', '')` returns `1.0` from its first line (`if ($s1 === $s2) return
   1.0;`), *before* the zero-length check. So the 0.45 name weight would be awarded in full for
   agreeing on nothing. Latent today because entities never reach `score()`; a live false-merge
   generator the instant they do.

### The entity blocking key

```
'E:' . soundex(<first alphabetic token of org_name>) . '|' . <2-letter state, or '__'>
```

- The `E:` prefix makes an entity key structurally incapable of colliding with an individual key
  (`soundex(last)|year`), so an organization and a person are never in one candidate block — the
  blocking-level counterpart of Task 4's tier scoping.
- **First token, not the whole string.** `soundex()` consumes every letter it is given, so
  `soundex('Ashgrove Dialysis Center')` is dominated by accidents of the tail, and two records of one
  organization differing only in a trailing `LLC` would land in different blocks. The first
  alphabetic token is the discriminating part of an organization name and is stable across
  legal-form variants.
- **State, not zip.** A zip is too narrow: a multi-site organization's records carry different zips,
  so blocking on zip would keep the two records of one organization out of each other's candidate
  set — a false split created by the blocking key itself. State is the jurisdiction the licensing and
  exclusion registries key on, and it survives a branch address change.
- **Named cost.** `employees.state` is `varchar(65)`, so a spelled-out state has to be truncated to
  two letters, and that truncation collides: `Michigan`, `Missouri` and `Mississippi` all become
  `MI`. This is harmless to correctness, and only to correctness — blocking is a recall device that
  widens a candidate set, never a decision; every candidate still has to pass the org-name gate and
  score above the floor. The cost is `block_size_cap` pressure, which is the next point.
- `block_size_cap` is 2000 and is unchanged. Over the cap, `match()` returns `no_match` and the
  caller **mints a new identity stamped `auto_match`** — the silent false split the config comment
  mislabels as "flagged for steward". This plan does not fix that (plan 6 owns the steward surface)
  and must not make it worse, so Task 9's audit reports the entity block-size distribution as a
  measured number and Task 6's docblock names the risk at the site.

The key is computed in one new place, `App\GoldenProfile\Support\BlockKey`, because the rule is
currently written out three times — `StreamlineLocalConnector::blockKey()`, `HubTestCase::blockKey()`
and `EvalRunner::blockKey()`, the latter two carrying the comment "Same rule as
StreamlineLocalConnector::blockKey()". Adding a second branch to three copies of a rule is how the
`Survivorship` / `SetFinalizer` tiebreak divergence happened; Task 3 collapses them.

### Entity Pass B scoring

A separate weight set, `probabilistic.entity_weights`:

| Signal | Weight |
|---|---|
| `name` (normalized `org_name`, Jaro-Winkler) | 0.50 |
| `address` | 0.25 |
| `exclusion_share` | 0.15 |
| `zip` | 0.10 |

**Why adding a weight block here is safe when rebalancing the individual weights is not.** The config
comment on the individual weights says rebalancing "changes merge behaviour across the whole hub, so
it is deliberately left alone here: it is a Phase 3 calibration decision against labeled data, not a
code fix." That is right, and it is about the *individual* weights, which govern 13.38M live rows.
`entity_weights` governs zero existing rows — every row in the hub is `entity_type = 'individual'`
after Task 2's migration — so it has no blast radius. The individual weights are not touched.

The entity set sums to exactly 1.00 and **every member is implemented**, so unlike the individual set
(which tops out at exactly `auto_merge_at` because `provider_type` has no source column)
`auto_match` is genuinely reachable for an entity. What it takes, in one sentence: *an entity
auto-merges under Pass B only on an exact normalized org-name match plus the same street address, the
same zip, and a shared exclusion registry.* The review band (0.75) is reached by name plus address
plus zip, or name plus zip plus exclusion. Name alone is 0.50 — below the floor, so an organization
is never bound on its name alone, the same policy the `nodob-a` / `nodob-b` fixture pair asserts for
people.

**The org-name gate.** `NameMatcher::orgCompatible()` requires *equality* of the normalized names
before any scoring, mirroring the individual rule where first and last must be equal and only middle,
suffix and DOB may be merely compatible. Normalization lowercases, strips punctuation, collapses
whitespace and drops one trailing legal-form token from a fixed list. Without this gate,
`Ashgrove Dental Care` and `Ashgrove Dialysis Center` at the same address and zip score
`0.50 x JW + 0.25 + 0.10`, which clears the review floor — a false merge. The `org-similar-name`
fixture record in Task 10 exists to prove the gate holds.

**Entity hard-no: conflicting TIN/EIN.** The analog of `two_valid_npis`. Two organizations at one
address trading under one name but holding different federal tax IDs are two legal entities — a
management company and its clinic is the common shape — and no amount of address agreement should
merge them. `conflicting_dob` can never fire for an entity (both NULL); `two_valid_npis` still
applies. This requires `match()` to receive the staged identifiers, so its signature grows a third
parameter; `resolve()` already has them in scope after plan 5.

**Cross-type candidates are refused outright,** before the gate and before scoring — Task 4's rule
applied to Pass B.

---

## Design question 5: entity survivorship

The brief describes `config golden_profile.survivorship.field_authority` as having "an `identity`
list keyed on person fields". One correction, because it changes what has to be edited:
`field_authority.identity` is an **authority order over source `system_code`s**
(`['verified', 'nppes', 'streamline_local', 'state_license', 'scraped_license']`), not a field list.
The person-field list is `Survivorship::IDENTITY_FIELDS`, a nine-entry map from `canonical_*` /
`npi` / `upin` / `dea_number` / `ssn_hash` to their `stg_person` source columns.

What survivorship means for an organization, and what changes:

1. **`org_name` gains an `IDENTITY_FIELDS` entry** (`'org_name' => 'org_name'`). Without it, an
   identity folding several source rows never gets a canonical winner for its own name, and the
   value written at creation persists regardless of a newer, more authoritative row. It also gains
   full `gp_attribute` / `gp_survivorship_audit` provenance for free — which is exactly the
   `entity_names` history the doc asks for, at the attribute level.
2. **The six person-only fields are skipped, not blanked.** `recompute()` already filters candidates
   with `isBlank()` and `continue`s when none remain, so `canonical_first`, `canonical_middle`,
   `canonical_suffix`, `canonical_dob`, `ssn_hash` and `dea_number` are simply absent from `$update`
   for an entity. That is correct and pre-existing; Task 7 pins it with a test rather than changing
   it, because it is load-bearing and easy to break.
3. **A separate authority order, `field_authority.entity`.** The delivery checklist §3 asks for
   survivorship differences explicitly, and there is a real one: for an organization the
   authoritative sources are the NPPES type-2 registry and SAM.gov, whereas a state licensing board
   is authoritative about a *person's* credential and says little about an organization's legal name.
   `['verified', 'nppes', 'sam', 'streamline_local', 'state_license', 'scraped_license']`.
   `Survivorship::recompute()` selects the group by the identity's `entity_type`.
4. **`entity_type` is never survived.** It is not in `IDENTITY_FIELDS`. Letting
   authority-plus-recency decide it would make an identity's type oscillate between rebuilds and flip
   every downstream tier predicate with it.
5. **A disagreement among an identity's linked rows is an alarm, not a value.** If one linked
   `stg_person` is entity-shaped and another is person-shaped, the resolver bound across types —
   which Task 4's scoping is meant to prevent, so it means either a pre-existing merge or a
   classification error. `recompute()` writes a `gp_resolution_log` row (`action = 'override'`,
   `actor = 'survivorship'`) and leaves the stored `entity_type` alone.
6. **Plan 2 interaction.** Plan 2 removes `ssn_hash` from `IDENTITY_FIELDS`. That deletion and this
   plan's `org_name` addition touch the same array and will conflict textually; resolving it is one
   line either way.

---

## Design question 6: the ~107,882 existing alias rows

### What is actually known, and by whom

The `2026_08_10_000000_create_gp_identity_alias` docblock records, measured against the real hub:

> 107,882 of the 107,888 rows carrying aliases include a null surname … `stg_person_alias` holds
> 108,527 rows of which only 17 have a non-blank `last_name` — almost every alias is an
> entity/business name carried in `first_name` (`{"last": null, "first": "Acme LLC"}`).

**Verified here versus taken from the docblock.** Checked against the live test server
(192.168.56.22, MySQL 8.0.43) on 2026-09-04:

| Claim | Status |
|---|---|
| 107,882 / 107,888 `gp_identity_alias` rows with a null surname | **From the docblock. Not verifiable here.** |
| 108,527 `stg_person_alias` rows, 17 with a non-blank `last_name` | **From the docblock. Not verifiable here.** |
| `gp_cami_test` is the only gp/stg schema on the server, and it is empty | **Verified.** All 34 non-`migrations` tables at exactly 0 rows |
| No other `gp_cami` / `golden_profile` schema exists on this server | **Verified** (`SHOW DATABASES`) |
| `stg_person_alias.alias_type` is `enum('maiden','alt','business')` | **Verified from DDL** |
| `gp_identity_alias.alias_part` is `enum('last','first')` — no `business` member | **Verified from DDL** |
| `gp_identity` has no `entity_type`, `org_name` or `is_individual` column | **Verified from DDL** |
| `stg_person` has no business/org column at all | **Verified from DDL** |
| `streamline_local.employees` has no type discriminator (70 columns enumerated) | **Verified from DDL** |
| `gp_identity_identifier.id_type` is `varchar(16)`, so `tin`/`ein`/`uei` fit | **Verified from DDL** |

So the two headline counts stand as docblock evidence and cannot be reproduced in this environment;
every schema-shaped claim this plan rests on is verified.

**One verified fact materially improves the brief's framing.** The brief describes these rows as
organization names that have to be identified by shape. They do not need to be:
`alias_type = 'business'` already labels them, set by `StreamlineLocalConnector::childRows()` for
`business` / `alt_business1` / `alt_business2` and by `additionalRows()` for `alt_business3..9`. The
classification signal for existing history is a stored label, not an inference — the inference in
design question 2 is needed only at the `stg_person` level, to decide whether the *person row* is an
organization or a person who also has a practice name.

**One correction to the risk as posed.** The brief asks what happens to identities "which may have
been merged with real people on a business-name match". **There is no business-name match path in
gp-cami.** `gp_identity_alias` feeds `IdentitySearchController` only; nothing in
`DeterministicResolver`, `ProbabilisticResolver`, `Survivorship` or `Engine::dedup()` reads an alias,
and `Engine::mergeByNameDob()` requires `canonical_dob IS NOT NULL`, which an entity never has. The
real entity-to-person merge paths are the ones Task 4 closes: a shared licence number and state (the
licence tier and `Engine::mergeByLicense()`, neither type-aware today), a shared `upin`, and a shared
`npi` (the tier and `Engine::mergeByColumn()`). That is a narrower and more tractable risk than a
name-based merge, and it is verified from code rather than assumed.

### The decision

**This plan reclassifies existing rows only where the evidence is unanimous, through a
read-only-first pair of commands, and it runs neither of them from a migration.** Three stages:

**Stage 1 — the migration classifies nothing.** `entity_type NOT NULL DEFAULT 'individual'` makes
every existing row an individual. That is deliberately the status quo, not a guess: because it
changes no row's classification, it cannot change a single tier result, so the migration itself has
zero matching blast radius. It is also what makes the `ADD COLUMN` a metadata-only change on a
13.38M-row table.

**Stage 2 — `gp:entity-audit`, read-only, writes nothing.** Buckets every active identity by the
shape of its linked staged rows:

| Bucket | Definition | Disposition |
|---|---|---|
| `unanimous_entity` | every linked `stg_person` is entity-shaped (a `business` alias, no name) | reclassifiable |
| `unanimous_individual` | every linked `stg_person` is person-shaped | leave |
| `mixed` | both shapes on one identity | **report only, never touch** |
| `no_signal` | no name and no business alias | report; plan 5 Task 7's quarantine owns these |

It also reports the two numbers that size the risk and cannot be obtained any other way: the
cross-type key collisions (identities of differing inferred shape sharing an `npi`, a `upin`, or a
licence number and state — the merges Task 4 will start refusing) and the entity block-size
distribution against `block_size_cap`. **This is the measurement step, and it is written as a human
task with the exact command, because nobody in this environment has production hub credentials.**

**Stage 3 — `gp:entity-reclassify`, `--dry-run` by default.** Touches `unanimous_entity` identities
only. For each it writes `entity_type` and `org_name` through
`Versioner::write('gp_identity', ['identity_id' => $id], [...])`, so the change is a new version with
the previous one preserved at `current = 0` and therefore fully reversible by reading it back; it
writes a `gp_resolution_log` row per identity (`action = 'override'`,
`actor = 'gp:entity-reclassify'`); and it updates the identity's linked `stg_person` rows'
`entity_type`, `org_name` and `block_key` with a plain `UPDATE`, because staging is deliberately not
versioned (plan 3's register) and its history belongs to CAMI.

### What happens to the identities, precisely

For a reclassified `unanimous_entity` identity: nothing moves. It keeps its `identity_id`, its
`identity_uuid`, every `gp_source_link`, `gp_license`, `gp_address`, `gp_identity_credential`,
`gp_identity_exclusion`, `gp_identity_identifier` and `gp_board_action` row, and its
`gp_identity_profile` row is rebuilt by the next finalize. It gains a type and a name and loses
nothing.

Its *future* matching changes, and this is the behaviour change to state: an incoming person row can
no longer bind to it. That can only reduce merges, never create one, because type scoping strictly
narrows a tier's result set. The failure mode it can create is a false split, and only where the
classification is wrong — which is the whole reason `mixed` is left alone.

### Why `mixed` is not reclassified, and not split

Splitting a merged identity is not something this plan can do safely. `gp_identity.status` has a
`'split'` value that **nothing in the codebase ever writes**; there is no split machinery, no way to
decide which of an identity's links belong to the organization and which to the person, and no undo.
Guessing at scale would manufacture false splits with no recovery path. Reporting the count is worth
more than acting on it, and plan 6 owns the steward surface where a human can act on the list.

---

## File Structure

| File | Responsibility |
|---|---|
| `docs/ENTITY-TYPES.md` *(create)* | The one-table-versus-two ruling, the inference rule and its measured trap, the entity ladder, the entity blocking key, the survivorship differences, the reclassification runbook, and the plan-3b obligations |
| `scripts/entity-preflight.sql` *(create)* | Read-only pre-migration audit: source-side shape counts and the cross-type key-collision probe, runnable against the real hub by a human |
| `database/migrations/2026_09_07_000000_add_entity_type_and_org_name.php` *(create)* | `entity_type` + `org_name` on `gp_identity`, `stg_person` and `gp_identity_profile`; `ck_identity_entity_type`; `idx_org_name` |
| `app/GoldenProfile/Support/BlockKey.php` *(create)* | The one implementation of the blocking-key rule, individual and entity |
| `app/GoldenProfile/Connectors/StreamlineLocalConnector.php` *(modify)* | Infers `entity_type` / `org_name` at the shared choke point; delegates the block key |
| `app/GoldenProfile/Support/Versioner.php` *(modify)* | `entity_type` and `org_name` join `gp_identity`'s `attributes` |
| `app/GoldenProfile/Resolution/DeterministicResolver.php` *(modify)* | Type-scoped tiers; the entity ladder; `entity_type` / `org_name` on create and backfill |
| `app/GoldenProfile/Support/NameMatcher.php` *(modify)* | `orgCompatible()` — normalized org-name equality |
| `app/GoldenProfile/Resolution/ProbabilisticResolver.php` *(modify)* | Cross-type refusal, the org-name gate, entity weights, the conflicting-TIN hard-no |
| `app/GoldenProfile/Resolution/Survivorship.php` *(modify)* | `org_name` survives; the entity authority order; type disagreement logged |
| `app/GoldenProfile/Materialize/ProfileMaterializer.php` *(modify)* | `entity_type` / `org_name` into the read model |
| `app/GoldenProfile/Engine.php` *(modify)* | `mergeByColumn` / `mergeByLicense` type-scoped; `applyMerge` refuses a cross-type merge |
| `app/GoldenProfile/SqlBackfill.php` *(modify)* | `idx_org_name` into `IDENTITY_KEY_INDEXES` (lockstep with the migration) |
| `app/GoldenProfile/Materialize/SetFinalizer.php` *(modify)* | Same constant, same reason |
| `app/Http/Resources/IdentityProfileResource.php` *(modify)* | Emits `entity_type` and `org_name` |
| `app/Http/Requests/IdentitySearchRequest.php` *(modify)* | `org_name`, and `last_name` becomes `required_without:org_name` |
| `app/Http/Requests/CredentialSearchRequest.php` *(modify)* | Same, for the credential contract |
| `app/Http/Controllers/Api/V1/IdentitySearchController.php` *(modify)* | An `org_name` search leg beside the canonical and alias legs |
| `app/Http/Controllers/Api/V1/CredentialSearchController.php` *(modify)* | An entity branch in `resolveIdentity()`; `org_name` / `entity_type` in the response |
| `app/Console/Commands/GpEntityAudit.php` *(create)* | `gp:entity-audit` — read-only classification and collision report |
| `app/Console/Commands/GpEntityReclassify.php` *(create)* | `gp:entity-reclassify` — `--dry-run` by default, `Versioner`-written, logged |
| `config/golden_profile.php` *(modify)* | Entity deterministic keys, `entity_weights`, `field_authority.entity`, the entity hard-no |
| `app/GoldenProfile/Eval/EvalSet.php` *(modify)* | `entity_type` / `org_name` validation; `addresses()` |
| `app/GoldenProfile/Eval/EvalRunner.php` *(modify)* | Stages `entity_type`, `org_name`, addresses, and the type-aware block key |
| `tests/eval/identity-pairs.json` *(modify)* | Eight synthetic entity records exercising the entity ladder, Pass B, and the cross-type licence foil |
| `tests/Support/HubTestCase.php` *(modify)* | `stagePerson()` defaults, `stageEntity()`, `stageAddress()` |
| `tests/Feature/EntitySchemaTest.php` *(create)* | The migration produced the columns, the CHECK and the index it claims |
| `tests/Unit/EntityInferenceTest.php` *(create)* | The inference rule, the empty-string trap, and the block key |
| `tests/Feature/EntityLadderTest.php` *(create)* | Type scoping and the TIN/EIN/UEI tiers, end to end |
| `tests/Feature/EntityPassBTest.php` *(create)* | Entity blocking, the org-name gate, the weights, the hard-no |
| `tests/Feature/EntitySurvivorshipTest.php` *(create)* | `org_name` survives; person fields are skipped; type never flips |
| `tests/Feature/EntityApiTest.php` *(create)* | Both endpoints resolve and shape an entity |
| `tests/Feature/EntityReclassifyTest.php` *(create)* | Audit buckets; reclassify touches only unanimous entities and versions the change |
| `tests/Unit/ProbabilisticScoringTest.php` *(modify)* | The entity weight set is complete and reconciled |
| `tests/Unit/DeterministicKeyConfigTest.php` *(modify)* | The new tiers have configured confidences |
| `tests/Unit/VersionerSpecTest.php` *(modify)* | `entity_type` / `org_name` are attributes, not derived |
| `tests/Unit/IdentityKeyIndexParityTest.php` *(modify)* | `idx_org_name` is in both constants and in the schema |
| `tests/Feature/EvalGateTest.php` *(modify)* | `true_pairs` ratchet 11 → 13 |
| `docs/EVALUATION.md` *(modify)* | The measured post-entity numbers |
| `docs/SCD2.md` *(modify)* | `gp_identity`'s attribute list gains the two columns |

---

## Task 1: Confirm the prerequisites, and commit the decision record

Nothing in this task changes behaviour. It exists because the next nine tasks assume plans 3 and 5
have landed, and finding that out in Task 4 costs a rollback; and because the one-table ruling is the
most consequential decision in the plan and deserves its own reviewable commit.

**Files:**
- Create: `docs/ENTITY-TYPES.md`
- Create: `scripts/entity-preflight.sql`

**Interfaces:**
- Produces: `docs/ENTITY-TYPES.md` — the register every later task and plan 6/7 cites.
- Consumes: nothing.

- [ ] **Step 1: Verify plans 3 and 5 have landed**

Run:
```bash
cd /c/projects/dramiel/gp-cami
test -f app/GoldenProfile/Support/Versioner.php && echo "plan 3: Versioner present" || echo "plan 3: MISSING"
grep -c "dea_multi" config/golden_profile.php
grep -c "public function identifiers" app/GoldenProfile/Eval/EvalSet.php
grep -c "true_pairs" tests/Feature/EvalGateTest.php
test -f app/GoldenProfile/Support/SetBasedPathGuard.php \
  && echo "bulk paths: GUARDED (plan 3a, 3b not yet landed)" \
  || echo "bulk paths: LIVE (plan 3b has landed — see below)"
```

Expected:
```
plan 3: Versioner present
2
1
1
bulk paths: GUARDED (plan 3a, 3b not yet landed)
```

`plan 3: MISSING` or any `0` means **stop**. This plan is not executable yet; report which
prerequisite is absent and which plan owns it. Do not "work around" a missing `Versioner` by writing
`update()` calls — that reintroduces the divergence plan 3 exists to prevent.

**`bulk paths: LIVE` is not a failure, it is a scope change.** Plan 3b
(`2026-09-03-gpp-conformance-scd2-set-based-parity.md`) deletes `SetBasedPathGuard`, so its absence
means `SqlBackfill` and `SetFinalizer` are reachable again. Task 4's justification for scoping only
the per-row path is that the bulk paths cannot run; with the guard gone,
`SqlBackfill::residualCreateAndLink()` would mint every organization as an individual while the
per-row path classified it correctly — a divergence with no error anywhere. **In that case, fold the
six plan-3b obligations from `docs/ENTITY-TYPES.md` into Task 4 instead of deferring them**, and say
so in Task 4's commit message. Do not proceed with the guarded-path assumption while the guard is
gone.

- [ ] **Step 2: Confirm the source schema still lacks a type column**

Run:
```bash
cd /c/projects/dramiel/gp-cami
php -r '$p=new PDO("mysql:host=192.168.56.22;dbname=streamline_local","root","root");
foreach($p->query("SHOW COLUMNS FROM employees") as $r){
  if(preg_match("/type|entity|individual|business|org|company|record/i",$r["Field"])) echo $r["Field"]," ",$r["Type"],"\n";
}'
```

Expected — exactly these nine, and no `employee_type` / `entity_type` / `is_individual`:
```
business varchar(255)
alt_business1 varchar(255)
alt_business2 varchar(255)
license_type_id varchar(100)
license_type varchar(100)
alt_license_type varchar(100)
alt_license_type_id varchar(100)
record_status varchar(255)
```
(`certification_board` and friends do not match the pattern; the count is eight lines plus whatever
a newer source migration has added. If a genuine type discriminator has appeared since 2026-09-04,
**stop and re-open design question 2** — the inference rule exists only because no such column
does.)

- [ ] **Step 3: Write the read-only preflight script**

Create `scripts/entity-preflight.sql`:

```sql
-- Read-only pre-migration audit for plan 4 (individual vs entity).
-- Nothing here writes. Run the SOURCE half against streamline_local and the HUB
-- half against the golden_profile hub, and paste the numbers into
-- docs/ENTITY-TYPES.md. Nobody in the dev environment has hub credentials, so
-- the hub half is a human task (see docs/ENTITY-TYPES.md "Measured before
-- reclassification").
--
-- TRIM(...) <> '' everywhere, never IS NOT NULL. employees.first_name and
-- employees.last_name are NOT NULL char(100) so a blank name is '', and
-- measured on the dev source (n=109) `business` is NULL on 10 rows and '' on
-- 96 -- an IS NOT NULL rule classifies 96 of 109 rows as organizations.

-- ============ SOURCE: streamline_local ============

-- 1. The inference rule's own numbers.
SELECT
    COUNT(*)                                                               AS total_rows,
    SUM(TRIM(COALESCE(business,'')) <> '')                                 AS business_present,
    SUM(TRIM(COALESCE(business,'')) <> ''
        AND TRIM(first_name) = '' AND TRIM(last_name) = '')                AS inferred_entity,
    SUM(TRIM(COALESCE(business,'')) <> ''
        AND (TRIM(first_name) <> '' OR TRIM(last_name) <> ''))             AS ambiguous_both,
    SUM(TRIM(COALESCE(business,'')) = ''
        AND (TRIM(first_name) <> '' OR TRIM(last_name) <> ''))             AS inferred_individual,
    SUM(TRIM(COALESCE(business,'')) = ''
        AND TRIM(first_name) = '' AND TRIM(last_name) = '')                AS no_signal
FROM employees;

-- 2. Do inferred entities carry facility licences? This decides whether keeping
--    gp_license writes for entities matters in practice (n=3 locally: no).
SELECT
    SUM(TRIM(COALESCE(certification_number,'')) <> '')                     AS entity_rows_with_licence,
    SUM(TRIM(COALESCE(npi,'')) <> '' AND npi > 0)                          AS entity_rows_with_npi,
    SUM(TRIM(upin) <> '')                                                  AS entity_rows_with_upin
FROM employees
WHERE TRIM(COALESCE(business,'')) <> ''
  AND TRIM(first_name) = '' AND TRIM(last_name) = '';

-- 3. How wide are entity blocking buckets going to be? block_size_cap is 2000,
--    and over it ProbabilisticResolver declines and the caller mints a new
--    identity stamped auto_match -- a silent false split.
SELECT CONCAT('E:', SOUNDEX(SUBSTRING_INDEX(TRIM(business),' ',1)), '|',
              UPPER(LEFT(NULLIF(TRIM(state),''),2)))                       AS entity_block_key,
       COUNT(*)                                                            AS members
FROM employees
WHERE TRIM(COALESCE(business,'')) <> ''
  AND TRIM(first_name) = '' AND TRIM(last_name) = ''
GROUP BY entity_block_key
ORDER BY members DESC
LIMIT 20;

-- ============ HUB: golden_profile ============

-- 4. The docblock numbers, re-measured. The 2026_08_10 migration recorded
--    107,882 of 107,888 alias rows with a null surname and 17 of 108,527
--    stg_person_alias rows with a non-blank last_name. Neither is reproducible
--    in the dev environment (gp_cami_test is empty). Confirm or correct them.
SELECT COUNT(*) AS alias_rows,
       SUM(alias_part = 'first') AS from_first_name,
       SUM(alias_part = 'last')  AS from_last_name
FROM gp_identity_alias;

SELECT COUNT(*) AS staged_alias_rows,
       SUM(alias_type = 'business')                        AS business_aliases,
       SUM(TRIM(COALESCE(last_name,'')) <> '')             AS with_surname
FROM stg_person_alias;

-- 5. Cross-type key collisions -- the merges Task 4 will START REFUSING.
--    Identities of differing inferred shape that share a hard key. Each row is
--    a merge that happens today and will not after this plan.
--    "Shape" is taken from the identity's linked staged rows: an identity is
--    entity-shaped when EVERY linked stg_person has a business alias and no name.
CREATE TEMPORARY TABLE tmp_shape AS
SELECT l.identity_id,
       MIN(CASE WHEN a.stg_person_id IS NOT NULL
                 AND TRIM(COALESCE(sp.first_name,'')) = ''
                 AND TRIM(COALESCE(sp.last_name,''))  = '' THEN 1 ELSE 0 END) AS all_entity,
       MAX(CASE WHEN a.stg_person_id IS NOT NULL
                 AND TRIM(COALESCE(sp.first_name,'')) = ''
                 AND TRIM(COALESCE(sp.last_name,''))  = '' THEN 1 ELSE 0 END) AS any_entity
FROM gp_source_link l
JOIN stg_person sp
  ON sp.system_id = l.system_id AND sp.source_table = l.source_table
 AND sp.source_id = l.source_id
LEFT JOIN (SELECT DISTINCT stg_person_id FROM stg_person_alias WHERE alias_type = 'business') a
  ON a.stg_person_id = sp.stg_person_id
GROUP BY l.identity_id;

SELECT 'npi' AS shared_key, COUNT(*) AS colliding_groups FROM (
    SELECT i.npi FROM gp_identity i JOIN tmp_shape s ON s.identity_id = i.identity_id
    WHERE i.npi IS NOT NULL AND i.status = 'active' AND i.current = 1
    GROUP BY i.npi HAVING COUNT(DISTINCT s.all_entity) > 1
) x
UNION ALL
SELECT 'upin', COUNT(*) FROM (
    SELECT i.upin FROM gp_identity i JOIN tmp_shape s ON s.identity_id = i.identity_id
    WHERE TRIM(COALESCE(i.upin,'')) <> '' AND i.status = 'active' AND i.current = 1
    GROUP BY i.upin HAVING COUNT(DISTINCT s.all_entity) > 1
) y
UNION ALL
SELECT 'license+state', COUNT(*) FROM (
    SELECT gl.license_number, gl.certification_state
    FROM gp_license gl JOIN tmp_shape s ON s.identity_id = gl.identity_id
    WHERE gl.current = 1
    GROUP BY gl.license_number, gl.certification_state
    HAVING COUNT(DISTINCT s.all_entity) > 1
) z;

-- 6. The reclassification buckets.
SELECT CASE WHEN all_entity = 1 THEN 'unanimous_entity'
            WHEN any_entity  = 0 THEN 'unanimous_individual'
            ELSE 'mixed' END AS bucket,
       COUNT(*) AS identities
FROM tmp_shape
GROUP BY bucket;

DROP TEMPORARY TABLE tmp_shape;
```

- [ ] **Step 4: Run the source half**

Run:
```bash
cd /c/projects/dramiel/gp-cami
php -r '$p=new PDO("mysql:host=192.168.56.22;dbname=streamline_local","root","root");
$sql=file_get_contents("scripts/entity-preflight.sql");
$q=trim(explode(";",substr($sql,strpos($sql,"SELECT\n")))[0]);
foreach($p->query($q) as $r){ print_r(array_filter($r,"is_string",ARRAY_FILTER_USE_KEY)); }'
```

Expected on the dev source (n=109): `total_rows 109`, `business_present 3`, `inferred_entity 3`,
`ambiguous_both 0`, `inferred_individual 106`, `no_signal 0`. A different `total_rows` is fine — the
source is a live dev database. **`ambiguous_both` greater than zero is the interesting outcome**: it
means the inference rule has real false-positive exposure locally and the test in Task 3 should be
extended with a row of that shape before proceeding.

- [ ] **Step 5: Write the decision record**

Create `docs/ENTITY-TYPES.md`:

```markdown
# Individuals and entities in the hub

## One table, not two

The design set contradicts itself. "Data Model (What We Store)" specifies one
`golden_provider` table with `entity_type VARCHAR(12) NOT NULL` plus `org_name`;
"Data Flow by CAMI" (DEV 4099997697) specifies separate `individuals` and
`entities` tables with per-type name-history and address-join tables.

gp-cami implements **one table with a discriminator**: `gp_identity.entity_type`
and `gp_identity.org_name`. This follows the precedent set when plan 1 reconciled
the same kind of disagreement — keep the existing `gp_*` names, conform to the
design set's *semantics* rather than to either page's literal DDL — and it
satisfies the Data Model page literally, column type included.

The two-table page's requirements map onto one table like this:

| Doc asks for | Here |
|---|---|
| `individuals` / `entities` | `gp_identity.entity_type`, domain enforced by `ck_identity_entity_type` |
| `individual_names` / `entity_names` | SCD-2 versions of `canonical_*` / `org_name` (docs/SCD2.md). A rename is a new version; the old one survives at `current = 0` |
| `addresses` + per-type joins | `gp_address`, versioned. A per-type join adds nothing an `entity_type` predicate on the parent does not give |
| `licensing_credentials` on individuals only | The licence match tier is individual-only. `gp_license` rows are still WRITTEN for entities — see "What we deliberately did not do" |
| `entities` carry UPIN, TIN, NPI | `gp_identity.upin` / `.npi`; TIN/EIN/UEI as `gp_identity_identifier` rows |

### What it costs

Six columns are permanently NULL on an entity row — `canonical_first`,
`canonical_middle`, `canonical_suffix`, `canonical_dob`, `ssn_hash`,
`dea_number` — and `org_name` is permanently NULL on the ~13.38M individual
rows. `idx_name_dob` carries an all-NULL tuple per entity row.

The value domain is enforced (`ck_identity_entity_type`). The row *shape* is
not, deliberately: a shape CHECK would reject the `mixed` identities we chose to
preserve, which would make `gp:entity-reclassify` undeployable. Shape is
asserted in `EntitySchemaTest` against the resolver and counted by
`gp:entity-audit`.

## The type is inferred, because the source has no column for it

`streamline_local.employees` has 70 columns and none of them is a record type
(verified 2026-09-04). No `employee_type`, `entity_type`, `provider_type`,
`is_individual`, `is_business` or `record_type`. `record_status` holds only NULL
and `''`; `facility_id` is free-text client data. There is no organization table
anywhere in `streamline_local` either. The doc's "Employee Type?" branch is a
decision in CAMI's import flow that is never persisted on the row.

    entity      iff  TRIM(business) <> '' AND TRIM(first_name) = '' AND TRIM(last_name) = ''
    individual  otherwise

`org_name` = the first non-blank of `business`, `alt_business1`, `alt_business2`.

**`TRIM(x) <> ''`, never `x IS NOT NULL`.** `first_name` and `last_name` are
`NOT NULL char(100)`, so a blank name is `''`. Measured on the dev source
(n=109), `business` is NULL on 10 rows and the empty string on **96** — a
null-check rule classifies 96 of 109 rows as organizations.

The rule lives in exactly one place, `StreamlineLocalConnector::personRow()`,
which is the choke point `Engine::backfill()`, `Engine::sync()` (via `ingest()`)
and `SqlBackfill::stage()` all share.

### The false-positive cost

A person imported with both name columns blank and their practice name in
`business` is classified as an organization. That suppresses the SSN, DEA,
licence and name+dob tiers for the row and gives it an entity block key, so it
can never bind to its own correctly-named records: a permanent, silent false
split. Nothing downstream reconsiders a classification. This is why
`gp:entity-reclassify` refuses to touch `mixed` identities.

### NPI is not a type signal

A type-1 and a type-2 NPI are indistinguishable from the number — same ten
digits, same Luhn check digit over the nine-digit base prefixed with `80840`.
NPPES carries the distinction in a separate Entity Type Code field that
`streamline_local` does not mirror. Plan 5's check-digit validation is therefore
identical for both types and needed no change.

## The entity match ladder

| Tier | Confidence | Where |
|---|---|---|
| `npi` | 0.99 | `gp_identity.npi`, type-scoped |
| `upin` | 0.99 | `gp_identity.upin`, type-scoped |
| `tin` | 0.99 | `gp_identity_identifier`, federal (not state-scoped) |
| `ein` | 0.99 | Same. EIN and TIN are the same nine digits here; both `id_type`s are accepted |
| `uei` | 0.99 | Same. SAM.gov Unique Entity ID |

Every individual tier is type-scoped too, which is what stops an individual
binding to an entity on a shared licence number, `upin` or `npi` — the three
real cross-type merge paths (there is no business-NAME match path; aliases feed
identity-search only).

**Type scoping only ever removes candidates**, so it cannot create a false
merge. It can create a false split, and only where the classification is wrong.

**There is deliberately no soft Pass A tier for entities.** An `org_name + zip`
key would make Pass B dead for entities — `addressOverlap()` requires equal zips
before it awards anything, so an entity pair can only score above the review
floor when zips match, which such a tier would already have caught at 0.99 with
no review record. Every soft entity decision goes to Pass B instead, lands in
the review band, binds, and writes a `gp_resolution_log` row.

## Entity blocking and Pass B

`ProbabilisticResolver::match()` returns `no_match` immediately on a null
`block_key`, and `blockKey()` returned null for any blank surname — so before
this plan **Pass B never ran for an organization at all.**

    'E:' . soundex(<first alphabetic token of org_name>) . '|' . <2-letter state or '__'>

`E:` makes an entity key structurally unable to collide with an individual key.
First token rather than the whole string, because `soundex()` consumes every
letter and a trailing `LLC` would otherwise move a record to a different block.
State rather than zip, because a multi-site organization's records carry
different zips and blocking on zip would split them.

**Named cost:** a spelled-out state truncated to two letters collides —
`Michigan`, `Missouri` and `Mississippi` all become `MI`. Harmless to
correctness: blocking widens a candidate set, it never decides. The cost is
`block_size_cap` (2000) pressure, and over the cap the caller mints a new
identity stamped `auto_match` — a silent false split that plan 6 owns.

Entity weights (`probabilistic.entity_weights`) sum to exactly 1.00 with every
member implemented: `name` 0.50, `address` 0.25, `exclusion_share` 0.15,
`zip` 0.10. So an entity auto-merges under Pass B only on an exact normalized
org-name match plus the same street address, the same zip, AND a shared
exclusion registry. Name alone is 0.50, below the review floor — an organization
is never bound on its name alone.

Adding this block was safe in a way that rebalancing the individual weights is
not: it governs zero existing rows.

`NameMatcher::orgCompatible()` gates every candidate on *equality* of the
normalized name, mirroring the individual rule (first and last must be equal;
only middle, suffix and DOB may be merely compatible). Without it,
"Ashgrove Dental Care" and "Ashgrove Dialysis Center" at one address score above
the review floor.

Entity hard-no: **conflicting TIN/EIN**. Two organizations at one address under
one trading name with different federal tax IDs are two legal entities.

## Survivorship differences

- `org_name` is an `IDENTITY_FIELDS` entry, so it gets a canonical winner and
  full `gp_attribute` / `gp_survivorship_audit` provenance — the doc's
  `entity_names` history at the attribute level.
- The six person-only fields are **skipped, not blanked**, by the pre-existing
  `isBlank()`-then-`continue` path. Pinned by a test because it is easy to break.
- `field_authority.entity` is a separate authority order: NPPES type-2 and
  SAM.gov outrank a state licensing board, which is authoritative about a
  *person's* credential and says little about an organization's legal name.
- **`entity_type` is never survived.** Authority-plus-recency across several
  source rows would make an identity's type oscillate between rebuilds and flip
  every tier predicate with it.
- A shape disagreement among an identity's linked rows is logged to
  `gp_resolution_log` as an alarm; the stored type is left alone.

## Reclassifying existing history

Three stages, and the migration is not one of them.

1. **The migration classifies nothing.** `entity_type NOT NULL DEFAULT
   'individual'` makes every existing row an individual — the status quo, not a
   guess — so the migration has zero matching blast radius. It is also what
   makes the `ADD COLUMN` a metadata-only change on a 13.38M-row table.
2. **`php artisan gp:entity-audit`** — read-only, writes nothing. Buckets active
   identities into `unanimous_entity`, `unanimous_individual`, `mixed` and
   `no_signal`, and reports the cross-type key collisions and the entity
   block-size distribution.
3. **`php artisan gp:entity-reclassify --apply`** — `unanimous_entity` only.
   Writes through `Versioner`, so every change is a new version with the previous
   one preserved at `current = 0` and is reversible by reading it back, plus one
   `gp_resolution_log` row per identity. Staged rows get a plain `UPDATE` because
   staging is not versioned (docs/SCD2.md).

**`mixed` identities are reported and never touched.** `gp_identity.status` has a
`'split'` value that nothing in this codebase ever writes: there is no split
machinery, no way to decide which of an identity's links belong to the
organization, and no undo. Reporting the count is worth more than guessing at
scale. Plan 6 owns the steward surface.

### Measured before reclassification

Needs production hub credentials, which the development environment does not
have. **HUMAN TASK.** Run the hub half of `scripts/entity-preflight.sql` against
the `golden_profile` hub and fill this in.

| Measure | Value |
|---|---|
| `gp_identity_alias` rows | PENDING (docblock: 107,888) |
| … of which from `first_name` | PENDING (docblock: 107,882) |
| `stg_person_alias` rows | PENDING (docblock: 108,527) |
| … with a non-blank `last_name` | PENDING (docblock: 17) |
| … with `alias_type = 'business'` | PENDING |
| Identities: `unanimous_entity` | PENDING |
| Identities: `unanimous_individual` | PENDING |
| Identities: **`mixed`** | PENDING |
| Identities: `no_signal` | PENDING |
| Cross-type `npi` collisions | PENDING |
| Cross-type `upin` collisions | PENDING |
| Cross-type `license+state` collisions | PENDING |
| Largest entity block vs cap 2000 | PENDING |

The three collision rows size what Task 4 stops doing; the `mixed` row sizes
what nobody can safely automate. **Do not run `gp:entity-reclassify --apply`
against the real hub until this table is filled in.**

Verified locally instead (2026-09-04, 192.168.56.22): the source has no type
column; `streamline_local.employees` n=109 splits 3 entity / 106 individual / 0
ambiguous; `business` is `''` on 96 rows; `gp_cami_test` is empty in all 34
tables, so no hub-side number is reproducible here.

## What we deliberately did not do

- **`gp_identity_alias` was not changed.** `stg_person_alias.alias_type` is
  `enum('maiden','alt','business')` — staging already labels business names —
  while `gp_identity_alias.alias_part` is `enum('last','first')`, so the label is
  lost downstream. Adding a `'business'` member would mean rewriting
  `AliasIndexer::insertSql()` and a full `gp:rebuild-aliases` over 108k rows, to
  duplicate a distinction `gp_identity.org_name` now carries directly — and
  `gp_identity_alias` is the ONLY entity lookup path that works today. Business
  aliases keep flowing into it unchanged.
- **`gp_license` rows are still written for entities.** The doc puts
  `licensing_credentials` under individuals only; we conformed where it changes
  behaviour (the match tier) and kept the write, because refusing it would
  discard facility licence numbers whose prevalence nobody has measured — the dev
  source's three entity rows carry none, n=3.
- **No TIN hash column.** The doc asks for "TIN + hash + last four". Plan 2 is
  removing exactly that pattern for SSN; adding a second plaintext-plus-hash pair
  while the first is being deleted would build the thing the programme is
  dismantling. A TIN is issued to organizations and appears on public filings, so
  plan 2's confidentiality argument does not transfer.

## Obligations handed to plan 3b

`SqlBackfill` and `SetFinalizer` refuse to run under plan 3a's
`SetBasedPathGuard`, so nothing below is a live defect — but 3b must not lift
that guard without doing these:

1. `SqlBackfill::residualCreateAndLink()` and `nameDobCreateAndLink()` list
   `gp_identity` columns explicitly and omit `entity_type` / `org_name`. The
   `DEFAULT 'individual'` means the insert still succeeds and produces
   individuals — wrong for entity staged rows. Both statements need
   `s.entity_type, s.org_name` added.
2. `SqlBackfill::tierCreate()` / `tierLink()` and
   `SqlBackfill::resolveDeterministic()` need `AND i.entity_type = s.entity_type`
   in every tier join, matching the per-row scoping.
3. `SqlBackfill::enrich()`'s `gp_identity_identifier` INSERT already carries
   `id_type`, so TIN/EIN/UEI flow through unchanged.
4. `SetFinalizer::survivorship()`'s per-field `UPDATE`s must gain `org_name` and
   must select the authority order by `entity_type`, matching
   `Survivorship::recompute()` — the two paths already have a documented
   divergence history (`link_id ASC`).
5. `SetFinalizer::materializeRange()`'s `gp_identity_profile` INSERT must carry
   `i.entity_type` and `i.org_name`.
6. `Engine::mergeByOrgName()` does not exist and should not be added without a
   Pass-A-tier decision: this plan deliberately routes soft entity matching
   through Pass B, and a set-based org-name merge in `dedup()` would be a
   deterministic org-name key by another name.
```

- [ ] **Step 6: Commit**
```bash
git add docs/ENTITY-TYPES.md scripts/entity-preflight.sql
git commit -m "docs(entity): record the one-table ruling and the entity preflight audit"
```

---

## Task 2: The migration — `entity_type` and `org_name`

Adds the two columns to the three tables that need them, the value-domain CHECK, and
`idx_org_name (org_name, current)`, then updates `Versioner::TABLES`, `docs/SCD2.md` and both
`IDENTITY_KEY_INDEXES` constants in lockstep. Written as raw statements rather than Blueprint calls
for the same reason plan 3's migration is: the `ALGORITHM` clauses and the guards are the point.

**Files:**
- Create: `database/migrations/2026_09_07_000000_add_entity_type_and_org_name.php`
- Modify: `app/GoldenProfile/Support/Versioner.php` (the `gp_identity` spec)
- Modify: `app/GoldenProfile/SqlBackfill.php` (`IDENTITY_KEY_INDEXES`)
- Modify: `app/GoldenProfile/Materialize/SetFinalizer.php` (`IDENTITY_KEY_INDEXES`)
- Modify: `docs/SCD2.md`
- Modify: `tests/Unit/VersionerSpecTest.php`
- Modify: `tests/Unit/IdentityKeyIndexParityTest.php`
- Test: `tests/Feature/EntitySchemaTest.php`

**Interfaces:**
- Produces, on `gp_identity`: `entity_type VARCHAR(12) NOT NULL DEFAULT 'individual'`,
  `org_name VARCHAR(255) NULL`, `CONSTRAINT ck_identity_entity_type CHECK (entity_type IN
  ('individual','entity'))`, `INDEX idx_org_name (org_name, current)`.
- Produces, on `stg_person`: `entity_type VARCHAR(12) NOT NULL DEFAULT 'individual'`,
  `org_name VARCHAR(255) NULL`.
- Produces, on `gp_identity_profile`: the same two columns plus `INDEX idx_org_name (org_name)`.
- Produces: `Versioner::TABLES['gp_identity']['attributes']` now contains `entity_type` and
  `org_name`.
- Consumed by: every remaining task.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EntitySchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * Pins the shape the entity migration produces.
 *
 * Every assertion here is something a later task depends on and could not
 * detect the absence of at runtime. A missing entity_type column throws on the
 * first insert; a missing CHECK just lets 'entty' into the column and every
 * tier predicate then silently matches nothing.
 */
class EntitySchemaTest extends HubTestCase
{
    public function test_the_three_tables_carry_entity_type_and_org_name(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        foreach (['gp_identity', 'stg_person', 'gp_identity_profile'] as $table) {
            $this->assertTrue($schema->hasColumn($table, 'entity_type'), "$table.entity_type missing");
            $this->assertTrue($schema->hasColumn($table, 'org_name'), "$table.org_name missing");
        }
    }

    public function test_entity_type_defaults_to_individual(): void
    {
        // This default IS the backfill for the 13.38M existing rows, and it is
        // what gives the migration zero matching blast radius: nothing is
        // reclassified, so no tier result can move.
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->assertSame(
            'individual',
            $this->hub()->table('gp_identity')->where('identity_id', $id)->value('entity_type')
        );
    }

    public function test_the_check_constraint_rejects_an_unknown_type(): void
    {
        $this->expectException(QueryException::class);

        $this->hub()->table('gp_identity')->insert([
            'identity_uuid' => (string) Str::uuid(),
            'entity_type' => 'organisation',          // not in the domain
            'org_name' => 'Ashgrove Dialysis Center',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);
    }

    public function test_an_entity_identity_stores_its_name_in_org_name(): void
    {
        // org_name is its own column rather than a reuse of canonical_last
        // because Survivorship maps canonical_last from stg_person.last_name,
        // which is NULL on every entity row -- so every writer of canonical_last
        // (backfillKeys, applyMerge's null-fill) would be writing a surname into
        // an org-name column.
        $id = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'entity_type' => 'entity',
            'org_name' => 'Ashgrove Dialysis Center',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $row = $this->hub()->table('gp_identity')->where('identity_id', $id)->first();

        $this->assertSame('entity', $row->entity_type);
        $this->assertSame('Ashgrove Dialysis Center', $row->org_name);
        $this->assertNull($row->canonical_last, 'an org name must not land in canonical_last');
    }

    public function test_org_name_is_indexed_together_with_current(): void
    {
        // identity-search gains an org_name leg and gp:entity-audit groups by it.
        // Without `current` the probe reads the whole version history and filters
        // in the server -- the non-sargable failure DeterministicResolver's
        // docblock measured at 6,475,711 rows scanned.
        $this->assertSame(
            ['org_name', 'current'],
            $this->columnsOf('gp_identity', 'idx_org_name'),
            'idx_org_name on gp_identity must be (org_name, current)'
        );

        // The profile table is not versioned, so it carries org_name alone.
        $this->assertSame(['org_name'], $this->columnsOf('gp_identity_profile', 'idx_org_name'));
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
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/EntitySchemaTest.php`

Expected: FAIL, 5 failures. The first is
`test_the_three_tables_carry_entity_type_and_org_name` with
`gp_identity.entity_type missing`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_07_000000_add_entity_type_and_org_name.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The individual-versus-entity discriminator, from "Data Model (What We Store)":
 *
 *   golden_provider.entity_type VARCHAR(12) NOT NULL, plus org_name
 *
 * "Data Flow by CAMI" asks instead for separate `individuals` and `entities`
 * tables. gp-cami implements one table with a discriminator; the full ruling,
 * including what two tables would have cost, is in docs/ENTITY-TYPES.md.
 *
 * THE DEFAULT IS THE POINT
 * ------------------------
 * entity_type is NOT NULL DEFAULT 'individual'. Two consequences, both
 * deliberate:
 *
 *   1. ADD COLUMN with a default, at the end of the row, is ALGORITHM=INSTANT
 *      on MySQL 8.0.12+. gp_identity is ~13.4M rows and stg_person ~13M, so the
 *      difference between INSTANT and a table rebuild is the difference between
 *      a deploy and an outage. The default IS the backfill -- there is no UPDATE
 *      pass at all.
 *   2. Every existing row becomes an INDIVIDUAL, which is the status quo rather
 *      than a guess. Because no row's classification changes, no deterministic
 *      tier result can change, so this migration cannot cause a false merge or a
 *      false split. Reclassifying existing history is a separate, measured,
 *      reviewable step -- see gp:entity-audit and gp:entity-reclassify.
 *
 * VARCHAR(12) AND A CHECK, NOT AN ENUM
 * ------------------------------------
 * An ENUM would enforce the domain with no validation scan, which is a genuine
 * advantage. VARCHAR(12) + a named CHECK is used anyway because the Data Model
 * page is explicit about the type and conformance is this programme's purpose,
 * and because a named constraint is something gp:entity-audit can cite by name
 * in its output. The CHECK does read every row to validate -- but every row was
 * just created with DEFAULT 'individual' and nothing has written the column yet,
 * so validation CANNOT fail; it only costs time. Run it in the same maintenance
 * window as plan 3's primary-key rebuilds.
 *
 * NO ROW-SHAPE CHECK, DELIBERATELY
 * --------------------------------
 * A constraint like
 *   entity_type = 'individual' OR (canonical_dob IS NULL AND ssn_hash IS NULL ...)
 * would be stronger and is NOT added: it would reject exactly the `mixed`
 * identities (an organization already merged with a person) that
 * gp:entity-reclassify deliberately preserves, which would make that command
 * undeployable against the real hub. Shape is asserted one level up, in
 * EntitySchemaTest against the resolver, and counted by gp:entity-audit.
 *
 * WHY org_name IS NOT A REUSE OF canonical_last
 * ---------------------------------------------
 * Survivorship::IDENTITY_FIELDS maps canonical_last from stg_person.last_name,
 * which is NULL on every entity row. So every path that DOES write
 * canonical_last -- DeterministicResolver::backfillKeys(), Engine::applyMerge()'s
 * null-fill -- would be writing a surname into an org-name column, and
 * Engine::mergeByNameDob() plus CredentialSearchController::resolveIdentity()
 * both read it as a surname.
 *
 * Every statement is guarded, so this migration is safe to re-run and safe to
 * run after an operator has applied part of it out of band.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    /** table => [does it carry the versioning `current` column?] */
    private const TABLES = [
        'gp_identity' => true,
        'stg_person' => false,
        'gp_identity_profile' => false,
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $this->addColumnIfMissing(
                $table, 'entity_type', "VARCHAR(12) NOT NULL DEFAULT 'individual'"
            );
            $this->addColumnIfMissing($table, 'org_name', 'VARCHAR(255) NULL');
        }

        // Value domain. gp_identity only: stg_person mirrors the source and must
        // stay able to represent whatever the inference produced, and
        // gp_identity_profile is a rebuildable projection of gp_identity, so a
        // second constraint there would only be able to fail on a materializer
        // bug that the parent constraint has already prevented.
        if (! $this->constraintExists('gp_identity', 'ck_identity_entity_type')) {
            DB::connection($this->connection)->statement(
                "ALTER TABLE `gp_identity`
                 ADD CONSTRAINT `ck_identity_entity_type`
                 CHECK (`entity_type` IN ('individual','entity'))"
            );
        }

        // identity-search's new org_name leg, and gp:entity-audit's grouping.
        // `current` is appended for the same reason plan 3 appended it to the five
        // key indexes: the read filters on it, and an index that stops at
        // org_name makes every probe read the whole version history and filter in
        // the server.
        if (! $this->indexExists('gp_identity', 'idx_org_name')) {
            DB::connection($this->connection)->statement(
                'ALTER TABLE `gp_identity` ADD INDEX `idx_org_name` (`org_name`, `current`),
                 ALGORITHM=INPLACE, LOCK=NONE'
            );
        }

        // The profile table is not versioned (docs/SCD2.md), so no `current`.
        if (! $this->indexExists('gp_identity_profile', 'idx_org_name')) {
            DB::connection($this->connection)->statement(
                'ALTER TABLE `gp_identity_profile` ADD INDEX `idx_org_name` (`org_name`),
                 ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    public function down(): void
    {
        $c = DB::connection($this->connection);

        foreach (['gp_identity', 'gp_identity_profile'] as $table) {
            if ($this->indexExists($table, 'idx_org_name')) {
                $c->statement("ALTER TABLE `$table` DROP INDEX `idx_org_name`");
            }
        }

        if ($this->constraintExists('gp_identity', 'ck_identity_entity_type')) {
            $c->statement('ALTER TABLE `gp_identity` DROP CONSTRAINT `ck_identity_entity_type`');
        }

        foreach (array_keys(self::TABLES) as $table) {
            foreach (['entity_type', 'org_name'] as $column) {
                if (Schema::connection($this->connection)->hasColumn($table, $column)) {
                    $c->statement("ALTER TABLE `$table` DROP COLUMN `$column`");
                }
            }
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (Schema::connection($this->connection)->hasColumn($table, $column)) {
            return;
        }

        // No explicit ALGORITHM clause: MySQL picks INSTANT for a defaulted
        // trailing column on 8.0.12+ and falls back to INPLACE otherwise.
        // Naming INSTANT explicitly would turn a server that cannot do it into a
        // hard failure instead of a slower success.
        DB::connection($this->connection)->statement(
            "ALTER TABLE `$table` ADD COLUMN `$column` $definition"
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

    private function constraintExists(string $table, string $name): bool
    {
        return (bool) DB::connection($this->connection)->selectOne(
            'SELECT 1 FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ? LIMIT 1',
            [$table, $name]
        );
    }
};
```

- [ ] **Step 4: Run to verify the schema test passes**

Run: `vendor/bin/phpunit tests/Feature/EntitySchemaTest.php`

Expected: PASS, 5 tests.

- [ ] **Step 5: Add the two columns to the `gp_identity` versioning spec**

In `app/GoldenProfile/Support/Versioner.php`, extend the `gp_identity` `attributes` list:

```php
        'gp_identity' => [
            'key' => ['identity_id'],
            'attributes' => [
                'canonical_first', 'canonical_middle', 'canonical_last', 'canonical_suffix',
                'canonical_dob', 'ssn_hash', 'npi', 'upin', 'dea_number', 'status', 'merged_into',
                // entity_type and org_name are ATTRIBUTES, not derived.
                //
                // org_name obviously: an organization renames, and the doc's
                // entity_names history is exactly the version chain that produces.
                //
                // entity_type less obviously. It is written once at identity
                // creation and afterwards only by gp:entity-reclassify, never by
                // Survivorship (see docs/ENTITY-TYPES.md: authority-plus-recency
                // over several source rows would make a type oscillate between
                // rebuilds and flip every tier predicate with it). Because the
                // only writer is a deliberate reclassification, making it an
                // attribute costs nothing in row growth and buys a free, complete
                // audit trail: the previous version survives at current = 0, so a
                // wrong reclassification is reversible by reading it back.
                'entity_type', 'org_name',
            ],
            'derived' => ['record_count', 'confidence'],
            'onCreate' => [],
            'surrogate' => null,
            'created' => 'first_seen',
            'updated' => 'last_updated',
        ],
```

- [ ] **Step 6: Pin the spec change**

In `tests/Unit/VersionerSpecTest.php`, add:

```php
    public function test_entity_type_and_org_name_are_versioned_attributes(): void
    {
        // entity_type as an attribute is what makes gp:entity-reclassify
        // reversible: the pre-reclassification version survives at current = 0.
        // As `derived` it would be overwritten in place and the old value lost.
        $spec = Versioner::spec('gp_identity');

        $this->assertContains('entity_type', $spec['attributes']);
        $this->assertContains('org_name', $spec['attributes']);
        $this->assertNotContains('entity_type', $spec['derived']);
        $this->assertNotContains('org_name', $spec['derived']);
    }
```

- [ ] **Step 7: Keep both `IDENTITY_KEY_INDEXES` constants in lockstep**

`SqlBackfill` and `SetFinalizer` each drop and rebuild `gp_identity`'s key indexes around their bulk
inserts. A new index that is absent from both constants survives the bulk insert and is maintained
row by row through it; worse, if one constant is edited and the other is not, the two classes disagree
about the schema. Both are edited here, in the same commit as the migration that creates the index.

In `app/GoldenProfile/SqlBackfill.php`, extend `IDENTITY_KEY_INDEXES`:

```php
    /** gp_identity key indexes, dropped during the residual bulk insert and rebuilt after. */
    private const IDENTITY_KEY_INDEXES = [
        'idx_ssn' => 'ssn_hash, current',
        'idx_npi' => 'npi, current',
        'idx_upin' => 'upin, current',
        'idx_dea' => 'dea_number, current',
        'idx_name_dob' => 'canonical_last, canonical_first, canonical_dob, current',
        // Added with 2026_09_05_000000. Listed here, and in the identical constant
        // in SetFinalizer, because both classes DROP every index they name and
        // rebuild it afterwards: an index missing from the list is maintained row
        // by row through a multi-million-row insert, and a list that disagrees
        // with its twin makes the two bulk paths disagree about the schema.
        'idx_org_name' => 'org_name, current',
    ];
```

Make the identical edit to `app/GoldenProfile/Materialize/SetFinalizer.php`'s
`IDENTITY_KEY_INDEXES` (the constant whose comment already reads "mirror of
`SqlBackfill::IDENTITY_KEY_INDEXES`").

> The `, current` suffixes come from plan 3 Task 3, which appended `current` to all five key
> indexes and updated both constants. If the constants in the working tree do not already carry
> `current`, plan 3 has not landed — stop and revisit Task 1 Step 1.

- [ ] **Step 8: Extend the index parity test**

Plan 3's `tests/Unit/IdentityKeyIndexParityTest.php` compares both constants against
`information_schema`. It iterates the constants, so it picks up `idx_org_name` automatically — but
add an explicit assertion so a silent removal from one constant fails by name rather than by a
count:

```php
    public function test_org_name_index_is_in_both_bulk_path_constants(): void
    {
        // Added by plan 4. Its absence from one constant and not the other is the
        // exact divergence this test class exists to catch, and it would be
        // invisible at runtime: the bulk path would simply leave the index in
        // place through its insert.
        foreach ([
            \App\GoldenProfile\SqlBackfill::class,
            \App\GoldenProfile\Materialize\SetFinalizer::class,
        ] as $class) {
            $constants = (new \ReflectionClass($class))->getConstants();

            $this->assertArrayHasKey(
                'idx_org_name',
                $constants['IDENTITY_KEY_INDEXES'],
                "$class::IDENTITY_KEY_INDEXES is missing idx_org_name"
            );
            $this->assertSame(
                'org_name, current',
                $constants['IDENTITY_KEY_INDEXES']['idx_org_name'],
                "$class disagrees with the migration on idx_org_name's columns"
            );
        }
    }
```

> `IDENTITY_KEY_INDEXES` is `private const` in both classes, so the test reads it through
> `ReflectionClass::getConstants()`, which returns private constants. Do not widen the constants'
> visibility to make a test easier to write.

- [ ] **Step 9: Record the change in the SCD-2 register**

In `docs/SCD2.md`, in the `gp_identity` row of the versioned-tables section, extend the attribute
list and add a note:

```markdown
`gp_identity` attributes: `canonical_first`, `canonical_middle`, `canonical_last`,
`canonical_suffix`, `canonical_dob`, `ssn_hash`, `npi`, `upin`, `dea_number`, `status`,
`merged_into`, **`entity_type`**, **`org_name`**.

`entity_type` and `org_name` were added by plan 4
(`2026_09_07_000000_add_entity_type_and_org_name`). Both are attributes rather than derived:
`org_name` because an organization renames and the version chain IS the doc's `entity_names`
history, and `entity_type` because its only writer is a deliberate reclassification
(`gp:entity-reclassify`), so versioning it costs no row growth and makes a wrong reclassification
reversible by reading the previous version. `Survivorship` never writes `entity_type` — see
docs/ENTITY-TYPES.md.
```

- [ ] **Step 10: Run the full suite**

Run:
```bash
vendor/bin/phpunit
vendor/bin/pint --dirty
```

Expected: PASS, 0 skipped. `EvalGateTest` unchanged — precision 1.0000, recall 1.0000, f1 1.0000,
`true_pairs` 11, 0 false merges, 0 false splits. Nothing has been reclassified, so no tier result can
have moved; if the gate does move here, the migration touched more than it claims.

- [ ] **Step 11: Commit**
```bash
git add database/migrations/2026_09_07_000000_add_entity_type_and_org_name.php \
        app/GoldenProfile/Support/Versioner.php \
        app/GoldenProfile/SqlBackfill.php \
        app/GoldenProfile/Materialize/SetFinalizer.php \
        docs/SCD2.md \
        tests/Feature/EntitySchemaTest.php \
        tests/Unit/VersionerSpecTest.php \
        tests/Unit/IdentityKeyIndexParityTest.php
git commit -m "feat(entity): add entity_type and org_name to the identity, staging and profile tables"
```

---

## Task 3: Infer the type at the one ingestion choke point

`StreamlineLocalConnector::personRow()` is where both ingestion paths meet, so the inference rule
goes there and nowhere else. The blocking-key rule moves out of three copies into one class at the
same time, because it now has two branches and three copies of a two-branch rule is how the
`Survivorship`/`SetFinalizer` tiebreak divergence happened.

**Dependency on plan 5b, added by this task (`00-PROGRAMME.md` §5).** Block-key construction is
5b's `BlockKeyBuilder`, so `BlockKey::for()`'s entity branch delegates to a new
`BlockKeyBuilder::entityNameState(?string $orgName, ?string $state): ?string` method rather than
computing the key itself. This plan states the method as a requirement on 5b's class; it is not
reimplemented here. Plan 5b lands before this plan in the canonical order, so the method is available
by the time this task runs.

**Files:**
- Create: `app/GoldenProfile/Support/BlockKey.php`
- Modify: `app/GoldenProfile/Connectors/StreamlineLocalConnector.php`
- Modify: `tests/Support/HubTestCase.php`
- Test: `tests/Unit/EntityInferenceTest.php`

**Interfaces:**
- Produces:
  - `BlockKey::for(?string $lastName, ?string $dob, string $entityType = 'individual', ?string $orgName = null, ?string $state = null): ?string`
  - `StreamlineLocalConnector::inferEntityType(object $emp): string`
  - `StreamlineLocalConnector::inferOrgName(object $emp): ?string`
  - `personRow()` returns two new keys, `entity_type` and `org_name`.
  - `HubTestCase::stageEntity(array $overrides = []): int`
  - `HubTestCase::stageAddress(int $stgPersonId, array $address): void`
- Consumes: `BlockKeyBuilder::entityNameState()` (plan 5b, new method added for this task).
- Consumed by: Tasks 4, 5, 6, 7, 9, 10.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EntityInferenceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\GoldenProfile\Connectors\StreamlineLocalConnector;
use App\GoldenProfile\Support\BlockKey;
use Tests\TestCase;

/**
 * The inference rule and the blocking key. No database: both are pure functions
 * over a source row, which is the whole reason they live where they do.
 */
class EntityInferenceTest extends TestCase
{
    private function connector(): StreamlineLocalConnector
    {
        return new StreamlineLocalConnector(1);
    }

    /** A source row shaped like streamline_local.employees. */
    private function emp(array $overrides = []): object
    {
        // first_name / last_name / address1 / city / state / zip / upin /
        // maiden_name / alt_last_name / notes are NOT NULL in the source, so
        // their blank is '' and never null. The defaults reproduce that.
        return (object) array_merge([
            'id' => 1, 'employeelist_id' => null,
            'first_name' => '', 'middle_name' => '', 'last_name' => '',
            'business' => '', 'alt_business1' => '', 'alt_business2' => '',
            'date_of_birth' => null, 'ssn_hash' => null, 'ssn_last_four' => null,
            'npi' => null, 'upin' => '', 'terminated' => 0,
            'address1' => '', 'address2' => '', 'city' => '', 'state' => '', 'zip' => '',
            'date_modified' => '2026-09-01 00:00:00',
        ], $overrides);
    }

    public function test_a_business_with_no_name_is_an_entity(): void
    {
        $emp = $this->emp(['business' => 'Ashgrove Dialysis Center', 'state' => 'OK']);

        $this->assertSame('entity', $this->connector()->inferEntityType($emp));
        $this->assertSame('Ashgrove Dialysis Center', $this->connector()->inferOrgName($emp));
    }

    public function test_a_name_with_no_business_is_an_individual(): void
    {
        $emp = $this->emp(['first_name' => 'Robert', 'last_name' => 'Smith']);

        $this->assertSame('individual', $this->connector()->inferEntityType($emp));
        $this->assertNull($this->connector()->inferOrgName($emp));
    }

    public function test_a_name_and_a_business_together_stay_an_individual(): void
    {
        // A sole proprietor: a real person who also trades under a practice
        // name. Classifying this as an organization would suppress the SSN, DEA,
        // licence and name+dob tiers for a person and permanently split them
        // from their own correctly-named records. The business name still
        // reaches gp_identity_alias through childRows(), so nothing is lost.
        $emp = $this->emp([
            'first_name' => 'Jane', 'last_name' => 'Roe',
            'business' => 'Roe Family Practice',
        ]);

        $this->assertSame('individual', $this->connector()->inferEntityType($emp));
        $this->assertNull(
            $this->connector()->inferOrgName($emp),
            'org_name belongs to entities only; a sole proprietor keeps their person name'
        );
    }

    public function test_an_empty_string_business_is_not_a_business(): void
    {
        // THE trap. employees.business is NULL on 10 of the 109 dev-source rows
        // and the EMPTY STRING on 96 of them, so `business IS NOT NULL` would
        // classify 96 of 109 rows as organizations. Measured 2026-09-04.
        foreach (['', '   ', "\t"] as $blank) {
            $emp = $this->emp(['business' => $blank, 'last_name' => 'Smith', 'first_name' => 'Bob']);
            $this->assertSame('individual', $this->connector()->inferEntityType($emp));
        }

        // And the converse: a blank NAME is '' too, never null.
        $emp = $this->emp(['business' => 'Pinewhistle Surgery Center', 'first_name' => '  ', 'last_name' => '']);
        $this->assertSame('entity', $this->connector()->inferEntityType($emp));
    }

    public function test_org_name_falls_back_through_the_alt_business_columns(): void
    {
        $emp = $this->emp(['business' => '', 'alt_business1' => 'Thistlebrook Medical Group']);
        $this->assertSame('entity', $this->connector()->inferEntityType($emp));
        $this->assertSame('Thistlebrook Medical Group', $this->connector()->inferOrgName($emp));

        $emp = $this->emp(['alt_business2' => 'Marrowstone Imaging']);
        $this->assertSame('Marrowstone Imaging', $this->connector()->inferOrgName($emp));
    }

    public function test_no_name_and_no_business_stays_an_individual(): void
    {
        // Not an entity: there is no organization name to be an entity WITH.
        // Plan 5 Task 7's quarantine owns rows with no identifying data at all.
        $this->assertSame('individual', $this->connector()->inferEntityType($this->emp()));
    }

    public function test_the_individual_block_key_is_unchanged(): void
    {
        $this->assertSame(soundex('Smith').'|1970', BlockKey::for('Smith', '1970-04-02'));
        $this->assertSame(soundex('Smith').'|____', BlockKey::for('Smith', null));
        $this->assertNull(BlockKey::for('', '1970-04-02'));
        $this->assertNull(BlockKey::for(null, null));
    }

    public function test_the_entity_block_key_prefixes_soundex_of_the_first_token_and_the_state(): void
    {
        $this->assertSame(
            'E:'.soundex('Ashgrove').'|OK',
            BlockKey::for(null, null, 'entity', 'Ashgrove Dialysis Center', 'OK')
        );

        // First token only: soundex() consumes every letter it is given, so
        // hashing the whole string would put "Thistlebrook Medical Group" and
        // "Thistlebrook Medical Group LLC" in different blocks.
        $this->assertSame(
            BlockKey::for(null, null, 'entity', 'Thistlebrook Medical Group', 'OH'),
            BlockKey::for(null, null, 'entity', 'Thistlebrook Medical Group LLC', 'OH')
        );

        // A spelled-out state is truncated to two letters and uppercased.
        $this->assertSame('E:'.soundex('Ashgrove').'|CA',
            BlockKey::for(null, null, 'entity', 'Ashgrove Dialysis Center', 'California'));

        $this->assertSame('E:'.soundex('Ashgrove').'|__',
            BlockKey::for(null, null, 'entity', 'Ashgrove Dialysis Center', ''));

        $this->assertNull(
            BlockKey::for(null, null, 'entity', null, 'OK'),
            'an entity with no org_name has no blocking evidence'
        );
    }

    public function test_an_entity_block_key_can_never_collide_with_an_individual_one(): void
    {
        // The E: prefix is what guarantees an organization and a person are
        // never in one candidate block -- the blocking-level counterpart of the
        // tier scoping in DeterministicResolver.
        $entity = BlockKey::for(null, null, 'entity', 'Smith Family Clinic', 'NY');
        $individual = BlockKey::for('Smith', '1970-04-02');

        $this->assertNotSame($entity, $individual);
        $this->assertStringStartsWith('E:', (string) $entity);
        $this->assertStringStartsNotWith('E:', (string) $individual);
    }

    public function test_person_row_carries_the_inferred_type_and_an_entity_block_key(): void
    {
        $row = $this->connector()->personRow($this->emp([
            'business' => 'Ashgrove Dialysis Center', 'state' => 'OK', 'zip' => '73008',
        ]));

        $this->assertSame('entity', $row['entity_type']);
        $this->assertSame('Ashgrove Dialysis Center', $row['org_name']);
        $this->assertSame('E:'.soundex('Ashgrove').'|OK', $row['block_key']);
        $this->assertNull($row['first_name']);
        $this->assertNull($row['last_name']);
    }

    public function test_npi_check_digit_validation_does_not_depend_on_the_type(): void
    {
        // A type-1 (individual) and a type-2 (organizational) NPI are
        // indistinguishable from the number: same ten digits, same Luhn check
        // digit over the nine-digit base prefixed with 80840. NPPES carries the
        // distinction in an Entity Type Code field streamline_local does not
        // mirror. So plan 5's validation is shared, and NPI cannot be used to
        // infer the type.
        $valid = 1234567893;

        $individual = $this->connector()->personRow($this->emp([
            'first_name' => 'Robert', 'last_name' => 'Smith', 'npi' => $valid,
        ]));
        $entity = $this->connector()->personRow($this->emp([
            'business' => 'Ashgrove Dialysis Center', 'npi' => $valid,
        ]));

        $this->assertSame('individual', $individual['entity_type']);
        $this->assertSame('entity', $entity['entity_type']);
        $this->assertSame($individual['npi'], $entity['npi'], 'the same NPI survives on both types');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/EntityInferenceTest.php`

Expected: FAIL — `Class "App\GoldenProfile\Support\BlockKey" not found`.

- [ ] **Step 3: Write `BlockKey`**

Create `app/GoldenProfile/Support/BlockKey.php`:

```php
<?php

namespace App\GoldenProfile\Support;

/**
 * The one implementation of the Pass B blocking-key rule.
 *
 * The rule used to be written out three times -- StreamlineLocalConnector,
 * HubTestCase and EvalRunner, the latter two carrying the comment "Same rule as
 * StreamlineLocalConnector::blockKey()". A one-branch rule survives that; a
 * two-branch rule does not. This codebase already has a documented case of two
 * implementations of one rule drifting apart (Survivorship's final tiebreak had
 * to be pinned to link_id ASC to match SetFinalizer's SQL "because a mismatch
 * broke the rebuild-produces-a-byte-identical-profile invariant"), so the second
 * branch arrives together with the collapse to one copy.
 *
 * WHAT A BLOCK KEY IS FOR
 * -----------------------
 * ProbabilisticResolver blocks candidates on it before scoring, and returns
 * no_match immediately when it is null. A block key is a RECALL device: it
 * decides who gets considered, never who matches. So an over-broad key costs
 * performance and block_size_cap pressure; it cannot cause a wrong bind, because
 * every candidate still has to clear the name gate and the score floor.
 *
 * INDIVIDUAL: soundex(surname) | birth-year (or ____)
 * Unchanged. Null when there is no surname -- which is what meant that before
 * plan 4 an organization's block key was NULL and Pass B never ran for one at
 * all.
 *
 * ENTITY: E: soundex(first token of org_name) | two-letter state (or __)
 *
 *   E:            An entity key is structurally unable to collide with an
 *                 individual key, so an organization and a person are never in
 *                 one candidate block. This is the blocking-level counterpart of
 *                 the entity_type predicate on every deterministic tier.
 *   first token   soundex() consumes every letter it is handed, so hashing the
 *                 whole name lets a trailing "LLC" move a record to a different
 *                 block -- which would split the two records of one organization
 *                 by way of the blocking key itself. The first alphabetic token
 *                 is the discriminating part of an organization name and is
 *                 stable across legal-form variants.
 *   state         Not zip. A multi-site organization's records carry different
 *                 zips, so blocking on zip would keep the two records of one
 *                 organization out of each other's candidate set. State is the
 *                 jurisdiction the licensing and exclusion registries key on and
 *                 it survives a branch address change.
 *
 * NAMED COST. employees.state is varchar(65), so a spelled-out state has to be
 * truncated, and two letters collide: Michigan, Missouri and Mississippi all
 * become MI. Harmless to correctness for the reason above -- blocking widens,
 * it does not decide -- but it inflates those buckets against block_size_cap
 * (2000), over which ProbabilisticResolver declines and the caller mints a new
 * identity stamped auto_match. That silent false split is pre-existing and plan
 * 6 owns it; gp:entity-audit reports the entity block-size distribution so it
 * can be watched.
 *
 * OWNERSHIP (00-PROGRAMME.md §5). Block-key construction belongs to plan 5b's
 * `BlockKeyBuilder`, not to this class -- 5b's staging legs and this entity leg
 * are two instances of the same mechanism, and a second class computing
 * blocking keys is exactly the divergence this docblock's own history lesson
 * warns about. So the RULE above stays (it is this plan's to define -- 5b has
 * no reason to know what an organization's name looks like), but the
 * CONSTRUCTION does not: `entity()` below is a thin call into a new
 * `BlockKeyBuilder::entityNameState(?string $orgName, ?string $state): ?string`
 * method. That method is a stated, explicit dependency this plan adds to plan
 * 5b's builder -- not a reimplementation -- and plan 5b lands before this plan
 * in the canonical order, so the method exists by the time Task 3 runs.
 */
class BlockKey
{
    /**
     * @param  string  $entityType  'individual' or 'entity'
     * @return string|null null when there is no blocking evidence at all
     */
    public static function for(
        ?string $lastName,
        ?string $dob,
        string $entityType = 'individual',
        ?string $orgName = null,
        ?string $state = null,
    ): ?string {
        if ($entityType === 'entity') {
            return self::entity($orgName, $state);
        }

        $last = self::clean($lastName);

        if ($last === null) {
            return null;
        }

        return soundex($last).'|'.($dob ? substr((string) $dob, 0, 4) : '____');
    }

    /**
     * Delegates to plan 5b's builder rather than reimplementing the mechanism
     * here -- see the class docblock's OWNERSHIP note. `entityNameState()` is a
     * new method this plan asks 5b to add: same first-alphabetic-token +
     * soundex + two-letter-state construction described above, just owned in
     * one place alongside 5b's `nameState()` and `nameStateZip()`.
     */
    private static function entity(?string $orgName, ?string $state): ?string
    {
        return BlockKeyBuilder::entityNameState($orgName, $state);
    }

    private static function clean(?string $v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === '' || $v === null) ? null : $v;
    }
}
```

- [ ] **Step 4: Wire the inference into the connector**

In `app/GoldenProfile/Connectors/StreamlineLocalConnector.php`, add the two inference methods above
`personRow()`:

```php
    /**
     * Infer whether a source row describes an organization or a person.
     *
     * streamline_local.employees has 70 columns and none of them is a record
     * type (verified 2026-09-04: no employee_type, entity_type, provider_type,
     * is_individual, is_business or record_type; record_status holds only NULL
     * and ''; facility_id is free-text client data). "Data Flow by CAMI"'s
     * process 1 branches on "Employee Type?", but that branch is a decision in
     * CAMI's import flow that is never persisted on the row -- so the type is
     * inferred here, at the choke point both ingestion paths share
     * (Engine::backfill()/sync() via ingest(), and SqlBackfill::stage()
     * directly), and nowhere else.
     *
     *   entity iff a business name is present AND both name columns are blank
     *
     * TRIM, NOT NULL CHECKS. first_name and last_name are NOT NULL char(100), so
     * a blank name is '' and never null; and measured on the dev source (n=109)
     * `business` is NULL on 10 rows and the EMPTY STRING on 96. A rule written as
     * `business IS NOT NULL` classifies 96 of 109 rows as organizations.
     *
     * THE FALSE-POSITIVE COST. A real person imported with both name columns
     * blank and their practice name in `business` is classified as an
     * organization. That suppresses the SSN, DEA, licence and name+dob tiers for
     * them and gives them an entity block key, so they can never bind to their
     * own correctly-named records -- a permanent, silent false split, because
     * nothing downstream reconsiders a classification. A row carrying BOTH a
     * name and a business is therefore left an individual (a sole proprietor is
     * a person who trades under a practice name), and its business name still
     * reaches gp_identity_alias through childRows(), so nothing is lost.
     */
    public function inferEntityType(object $emp): string
    {
        $hasBusiness = $this->inferOrgNameRaw($emp) !== null;
        $hasName = $this->clean($emp->first_name ?? null) !== null
            || $this->clean($emp->last_name ?? null) !== null;

        return ($hasBusiness && ! $hasName) ? 'entity' : 'individual';
    }

    /**
     * The organization's name, for entity rows only.
     *
     * Null for an individual even when the row carries a business name: a sole
     * proprietor's golden name is their own, and org_name on a person row would
     * put a practice name into the column every entity read path treats as the
     * subject's name.
     *
     * alt_business1 / alt_business2 are additional names for the SAME
     * organization (and alt_business3..9 arrive through
     * employee_additional_info), so the first non-blank wins and the rest keep
     * flowing into stg_person_alias as alias_type = 'business' exactly as before.
     */
    public function inferOrgName(object $emp): ?string
    {
        return $this->inferEntityType($emp) === 'entity'
            ? $this->inferOrgNameRaw($emp)
            : null;
    }

    /** First non-blank business column, regardless of inferred type. */
    private function inferOrgNameRaw(object $emp): ?string
    {
        foreach (['business', 'alt_business1', 'alt_business2'] as $column) {
            $value = $this->clean($emp->$column ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
```

Then, in `personRow()`, compute the type once and thread it into the returned row and the block key.
Replace the `$npi` line and the `return` array's tail:

```php
        $npi = (int) ($emp->npi ?? 0);
        $entityType = $this->inferEntityType($emp);
        $orgName = $this->inferOrgName($emp);

        return [
            'system_id' => $this->systemId,
            'source_table' => self::SOURCE_TABLE,
            'source_id' => $emp->id,
            'account_id' => $accountId ?: null,
            'employeelist_id' => $emp->employeelist_id ?: null,
            'entity_type' => $entityType,
            'org_name' => $orgName,
            'first_name' => $this->clean($emp->first_name),
            'middle_name' => $this->clean($emp->middle_name),
            'last_name' => $this->clean($emp->last_name),
            'date_of_birth' => $this->date($emp->date_of_birth),
            'ssn_hash' => $emp->ssn_hash ?: null,          // ingest as-is (global key)
            'ssn_last_four' => $emp->ssn_last_four ?: null,
            'npi' => $npi > 0 ? $npi : null,
            'upin' => $emp->upin ?: null,
            'dea_number' => null,                          // not present in this source
            'address1' => $this->clean($emp->address1),
            'city' => $this->clean($emp->city),
            'state' => $this->clean($emp->state),
            'zip' => $this->clean($emp->zip),
            'terminated' => (int) ($emp->terminated ?? 0),
            'source_modified' => $this->date($emp->date_modified, true),
            'ingested_at' => now(),
            'block_key' => BlockKey::for(
                $emp->last_name ?? null,
                $emp->date_of_birth ?? null,
                $entityType,
                $orgName,
                $emp->state ?? null,
            ),
        ];
```

> Plan 5 replaced the bare `$npi > 0` screen with its check-digit validator. Keep whatever
> `personRow()` currently does to `npi` — this edit only adds `entity_type`, `org_name` and the new
> `block_key` call. Do not re-open the NPI handling.

Delete the now-unused private `blockKey()` method from the connector and add the import:

```php
use App\GoldenProfile\Support\BlockKey;
```

- [ ] **Step 5: Point the two test-harness copies at the same class**

In `tests/Support/HubTestCase.php`, replace the private `blockKey()` body with a delegation, add the
two new `stagePerson()` defaults, and add the two helpers:

```php
    /** Same rule as the connector, because it is now literally the same code. */
    protected function blockKey(?string $last, ?string $dob): ?string
    {
        return \App\GoldenProfile\Support\BlockKey::for($last, $dob);
    }
```

In `stagePerson()`'s `$defaults`, add the two columns immediately after `employeelist_id`:

```php
            'employeelist_id' => 1,
            'entity_type' => 'individual',
            'org_name' => null,
```

and replace the block-key computation at the end of the method so an entity override produces an
entity key:

```php
        $row = array_merge($defaults, $overrides);

        if (! array_key_exists('block_key', $overrides)) {
            // Type-aware, so stageEntity() below gets an entity block key without
            // every caller having to know the rule. An explicit block_key
            // override still wins -- the resolver-ladder tests rely on that.
            $row['block_key'] = \App\GoldenProfile\Support\BlockKey::for(
                $row['last_name'],
                $row['date_of_birth'],
                $row['entity_type'],
                $row['org_name'],
                $row['state'] ?? null,
            );
        }

        return (int) $this->hub()->table('stg_person')->insertGetId($row);
```

Then add the two helpers after `stageLicense()`:

```php
    /**
     * Stage one organization. Returns stg_person_id.
     *
     * The person-shaped columns are nulled explicitly rather than left to
     * stagePerson()'s Robert Smith defaults: an entity carrying a first name and
     * a DOB is exactly the row shape the migration deliberately does NOT
     * constrain against (see the migration docblock), so a test fixture that
     * produced one would be asserting against a shape the resolver is entitled
     * to treat as an error.
     */
    protected function stageEntity(array $overrides = []): int
    {
        return $this->stagePerson(array_merge([
            'entity_type' => 'entity',
            'org_name' => 'Ashgrove Dialysis Center',
            'first_name' => null,
            'middle_name' => null,
            'last_name' => null,
            'name_suffix' => null,
            'date_of_birth' => null,
            'ssn_hash' => null,
            'state' => 'OK',
        ], $overrides));
    }

    /**
     * Attach an address to a staged person or entity.
     *
     * Pass B's address and zip signals compare stg_person_address against
     * gp_address, and gp_address is written by the resolver's enrich() from
     * stg_person_address -- so a Pass B test that stages only stg_person's own
     * address columns scores zero on both signals and proves nothing.
     */
    protected function stageAddress(int $stgPersonId, array $address): void
    {
        $this->hub()->table('stg_person_address')->insert(array_merge([
            'stg_person_id' => $stgPersonId,
            'address_type' => 'primary',
            'address1' => null,
            'address2' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
        ], $address));
    }
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/EntityInferenceTest.php`

Expected: PASS, 11 tests.

- [ ] **Step 7: Run the full suite**

Run:
```bash
vendor/bin/phpunit
vendor/bin/pint --dirty
```

Expected: PASS, 0 skipped. `EvalGateTest` unchanged at `true_pairs` 11, precision 1.0000, recall
1.0000 — the fixture is all individuals and `EvalRunner` still computes its own block key, so
nothing it stages has moved. Task 10 changes that.

- [ ] **Step 8: Commit**
```bash
git add app/GoldenProfile/Support/BlockKey.php \
        app/GoldenProfile/Connectors/StreamlineLocalConnector.php \
        tests/Support/HubTestCase.php \
        tests/Unit/EntityInferenceTest.php
git commit -m "feat(entity): infer entity_type and org_name at the ingestion choke point"
```

---

## Task 4: Type-scope the existing ladder, so a person can never bind to an organization

This task adds no new keys. It closes the three verified cross-type merge paths — a shared licence
number and state, a shared `upin`, a shared `npi` — in both the per-row resolver and `Engine::dedup()`,
and makes `applyMerge()` refuse a cross-type merge outright. It is separated from Task 5 because a
reviewer could reasonably accept a defensive narrowing and want more evidence before accepting new
match keys.

**Files:**
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php` (`matchDeterministic`,
  `createIdentity`, `backfillKeys`)
- Modify: `app/GoldenProfile/Engine.php` (`mergeByColumn`, `mergeByLicense`, `mergeByIdentifier`,
  `applyMerge`)
- Test: `tests/Feature/EntityLadderTest.php`

**Interfaces:**
- Produces: `matchDeterministic()` scopes every tier by `entity_type`; `createIdentity()` writes
  `entity_type` and `org_name`; `backfillKeys()` can fill a missing `org_name` but never changes
  `entity_type`; `Engine::applyMerge()` throws nothing and returns without merging when the two
  identities differ in type.
- Consumes: `Versioner::write()` / `Versioner::current()` (plan 3);
  `matchDeterministic(object $p, $licenses, $identifiers = [])` (plan 5).
- Consumed by: Task 5 (which adds tiers inside the scoped method), Task 10 (the `org-lic` /
  `person-lic` fixture foil).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EntityLadderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Engine;
use App\GoldenProfile\Resolution\DeterministicResolver;
use Tests\Support\HubTestCase;

/**
 * Type scoping and the entity match ladder.
 *
 * Every cross-type assertion here covers a merge that HAPPENS TODAY. The three
 * paths are a shared licence number and state, a shared upin, and a shared npi
 * (the tier plus Engine::mergeByColumn). There is deliberately no business-NAME
 * case: gp_identity_alias feeds identity-search only, and nothing in the
 * resolver, Survivorship or dedup reads an alias.
 */
class EntityLadderTest extends HubTestCase
{
    private function stageIdentifier(int $stgPersonId, string $type, string $value, ?string $state = null): void
    {
        $this->hub()->table('stg_person_identifier')->insert([
            'stg_person_id' => $stgPersonId, 'id_type' => $type, 'id_value' => $value, 'state' => $state,
        ]);
    }

    public function test_an_entity_and_a_person_sharing_a_licence_do_not_bind(): void
    {
        $org = $this->stageEntity(['org_name' => 'Pinewhistle Surgery Center', 'state' => 'MT']);
        $this->stageLicense($org, 'L-9001', 'MT');

        $person = $this->stagePerson([
            'first_name' => 'Ana', 'last_name' => 'Vasquez', 'date_of_birth' => '1983-11-04',
        ]);
        $this->stageLicense($person, 'L-9001', 'MT');

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertNotSame(
            $resolver->resolve($org),
            $resolver->resolve($person),
            'a facility licence number colliding with a professional one must not weld them together'
        );
    }

    public function test_an_entity_and_a_person_sharing_a_upin_do_not_bind(): void
    {
        $org = $this->stageEntity(['org_name' => 'Marrowstone Imaging', 'upin' => 'U-55031']);
        $person = $this->stagePerson([
            'first_name' => 'Dev', 'last_name' => 'Bhatt', 'date_of_birth' => '1976-02-14',
            'upin' => 'U-55031',
        ]);

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertNotSame($resolver->resolve($org), $resolver->resolve($person));
    }

    public function test_an_entity_and_a_person_sharing_an_npi_do_not_bind(): void
    {
        // NPIs are globally unique across type 1 and type 2, so a collision here
        // means one of the two classifications is wrong. Refusing the bind is
        // still right: welding an organization to a person is not a repair.
        // gp:entity-audit counts these on the real hub.
        $org = $this->stageEntity(['org_name' => 'Thistlebrook Medical Group', 'npi' => 1234567893]);
        $person = $this->stagePerson([
            'first_name' => 'Robert', 'last_name' => 'Smith', 'date_of_birth' => '1970-04-02',
            'npi' => 1234567893,
        ]);

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertNotSame($resolver->resolve($org), $resolver->resolve($person));
    }

    public function test_two_entities_sharing_an_npi_still_bind(): void
    {
        // Scoping narrows tiers; it must not disable them.
        $a = $this->stageEntity(['org_name' => 'Thistlebrook Medical Group', 'npi' => 1234567893]);
        $b = $this->stageEntity(['org_name' => 'Thistlebrook Medical Group LLC', 'npi' => 1234567893]);

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertSame($resolver->resolve($a), $resolver->resolve($b));
    }

    public function test_a_new_entity_identity_stores_its_type_and_name(): void
    {
        $org = $this->stageEntity(['org_name' => 'Ashgrove Dialysis Center']);

        $id = (new DeterministicResolver($this->systemId))->resolve($org);
        $row = $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->where('current', 1)->first();

        $this->assertSame('entity', $row->entity_type);
        $this->assertSame('Ashgrove Dialysis Center', $row->org_name);
        $this->assertNull($row->canonical_last);
        $this->assertNull($row->canonical_dob);
    }

    public function test_an_entity_identity_carries_no_person_only_fact(): void
    {
        // The row-shape rule the migration deliberately does NOT enforce with a
        // CHECK (a shape constraint would reject the `mixed` identities
        // gp:entity-reclassify preserves). Asserted here against the resolver
        // instead, which is the only thing that mints identities.
        $org = $this->stageEntity(['org_name' => 'Ashgrove Dialysis Center']);
        $id = (new DeterministicResolver($this->systemId))->resolve($org);

        $row = (array) $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->where('current', 1)->first();

        foreach (['canonical_first', 'canonical_middle', 'canonical_suffix',
            'canonical_dob', 'ssn_hash', 'dea_number'] as $personOnly) {
            $this->assertNull($row[$personOnly], "an entity must not carry $personOnly");
        }
    }

    public function test_backfill_fills_a_missing_org_name_but_never_flips_the_type(): void
    {
        $a = $this->stageEntity(['org_name' => 'Ashgrove Dialysis Center', 'npi' => 1234567893]);
        $resolver = new DeterministicResolver($this->systemId);
        $id = $resolver->resolve($a);

        // Blank the org name to simulate an identity minted before plan 4.
        $this->hub()->table('gp_identity')->where('identity_id', $id)->update(['org_name' => null]);

        $b = $this->stageEntity(['org_name' => 'Ashgrove Dialysis Center', 'npi' => 1234567893]);
        $this->assertSame($id, $resolver->resolve($b));

        $row = $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->where('current', 1)->first();

        $this->assertSame('Ashgrove Dialysis Center', $row->org_name);
        $this->assertSame('entity', $row->entity_type);
    }

    public function test_dedup_does_not_fold_an_entity_into_a_person(): void
    {
        // The set-based tier paths are guarded off by plan 3a, but dedup() is
        // not -- Engine::dedup() is reachable from gp:backfill and from
        // Engine::sync()'s finalize. Its four merge passes need the same scoping
        // as the per-row tiers or they undo them.
        $orgId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'entity', 'org_name' => 'Marrowstone Imaging',
            'upin' => 'U-55031',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);
        $personId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'individual',
            'canonical_first' => 'Dev', 'canonical_last' => 'Bhatt', 'canonical_dob' => '1976-02-14',
            'upin' => 'U-55031',
            'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ]);

        (new Engine)->dedup();

        foreach ([$orgId, $personId] as $id) {
            $this->assertSame(
                1,
                (int) $this->hub()->table('gp_identity')
                    ->where('identity_id', $id)->where('current', 1)->where('status', 'active')->count(),
                "identity $id must survive dedup as an active current row"
            );
        }
    }

    public function test_two_entities_sharing_a_upin_still_merge_in_dedup(): void
    {
        foreach (['Marrowstone Imaging', 'Marrowstone Imaging LLC'] as $name) {
            $this->hub()->table('gp_identity')->insert([
                'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'entity_type' => 'entity', 'org_name' => $name, 'upin' => 'U-55031',
                'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
                'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
            ]);
        }

        $this->assertGreaterThan(0, (new Engine)->dedup());
        $this->assertSame(
            1,
            (int) $this->hub()->table('gp_identity')
                ->where('entity_type', 'entity')->where('status', 'active')->where('current', 1)->count()
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/EntityLadderTest.php`

Expected: FAIL, 6 of 9. `test_an_entity_and_a_person_sharing_a_licence_do_not_bind` fails with
`Failed asserting that two variables are not the same` — they bind today. The two "still bind"
control tests pass already, which is the point of having them.

- [ ] **Step 3: Type-scope `matchDeterministic()`**

Replace `matchDeterministic()` in `app/GoldenProfile/Resolution/DeterministicResolver.php`. This is
the whole method as it should read after plans 3, 5 and this task:

```php
    /** @return array{0:?int,1:?string,2:?float} [identity_id, match_key, confidence] */
    private function matchDeterministic(object $p, $licenses, $identifiers = []): array
    {
        $hub = $this->hub();
        $type = $p->entity_type ?? 'individual';

        // Every tier below adds orderBy('identity_id') before ->value(). Without it
        // the winner among several rows sharing a key is whatever storage order
        // returns, so the same source row could bind to different identities across
        // runs. The set-based backfill pins MIN(identity_id); this agrees with it.
        //
        // Every tier ALSO filters current = 1 in addition to status = 'active'
        // (plan 3): `current` picks the newest VERSION, `status` says whether the
        // identity is live, and a superseded version can still carry a key its
        // successor dropped.
        //
        // Every tier ALSO filters entity_type (plan 4). Three things about that
        // predicate are worth knowing:
        //
        //   1. It is what stops an organization binding to a person. The three
        //      real cross-type paths are a shared licence number and state, a
        //      shared upin, and a shared npi -- verified from code. There is NO
        //      business-name path: gp_identity_alias feeds identity-search only,
        //      nothing in resolution reads an alias, and Engine::mergeByNameDob()
        //      requires canonical_dob IS NOT NULL, which an entity never has.
        //   2. It can only ever REMOVE candidates, so it cannot create a false
        //      merge. It can create a false split, and only where the
        //      classification is wrong -- which is why the inference rule's
        //      false-positive cost is spelled out in the connector and why
        //      gp:entity-reclassify refuses to touch ambiguous history.
        //   3. It is deliberately NOT added to the five key indexes. Each of those
        //      already narrows to roughly one row, so a two-valued column at the
        //      tail buys nothing measurable -- and it would cost a lockstep edit
        //      to SqlBackfill::IDENTITY_KEY_INDEXES, SetFinalizer's mirror of it,
        //      IdentityKeyIndexParityTest and the migration, which is the exact
        //      four-way divergence that pinning test exists to catch.
        //
        // Tier availability by type (docs/ENTITY-TYPES.md):
        //   individual  ssn_hash, npi, dea_number, upin, dea_multi, mmis+state,
        //               license+state, name+dob
        //   entity      npi, upin, tin, ein, uei        (no soft Pass A tier --
        //               soft entity matching goes to Pass B on purpose)
        $individual = $type === 'individual';

        // ssn_hash is individual-only. An organization has no SSN, and CAMI's
        // source has been observed carrying tax numbers in name-shaped columns,
        // so an entity reaching this tier would be matching on something that is
        // not an SSN at 0.99 confidence.
        //
        // It is additionally screened for filler values: a shared placeholder SSN
        // would otherwise collapse every person carrying it into one identity at
        // 0.99 with no name or DOB cross-check. See SsnHashGuard.
        if ($individual && $p->ssn_hash && ! $this->ssnGuard->isBlocked($p->ssn_hash)) {
            $id = $hub->table('gp_identity')->where('ssn_hash', $p->ssn_hash)
                ->where('status', 'active')->where('current', 1)->where('entity_type', $type)
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'ssn_hash', $this->confidence('ssn_hash', 0.99)];
            }
        }
        // npi is valid for BOTH types -- a type-2 NPI belongs to an organization
        // -- and is indistinguishable from a type-1 by the number alone, so the
        // scoping here is what keeps the two apart.
        if ($p->npi) {
            $id = $hub->table('gp_identity')->where('npi', $p->npi)
                ->where('status', 'active')->where('current', 1)->where('entity_type', $type)
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'npi', $this->confidence('npi', 0.99)];
            }
        }
        // dea_number is individual-only: a DEA registration is issued to a
        // prescriber. (Vestigial for this source in any case -- the connector
        // always stages stg_person.dea_number as null and DEA arrives through
        // stg_person_identifier; see plan 5's note on the dea_number config key.)
        if ($individual && $p->dea_number) {
            $id = $hub->table('gp_identity')->where('dea_number', $p->dea_number)
                ->where('status', 'active')->where('current', 1)->where('entity_type', $type)
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'dea_number', $this->confidence('dea_number', 0.99)];
            }
        }
        // upin is valid for both types; "Data Flow by CAMI" lists it on entities.
        if ($p->upin) {
            $id = $hub->table('gp_identity')->where('upin', $p->upin)
                ->where('status', 'active')->where('current', 1)->where('entity_type', $type)
                ->orderBy('identity_id')->value('identity_id');
            if ($id) {
                return [(int) $id, 'upin', $this->confidence('upin', 0.99)];
            }
        }
        // Multi-valued identifiers (plan 5: DEA, MMIS; plan 4: TIN, EIN, UEI).
        // Real-time here because each row is resolved sequentially -- by the time
        // THIS row is resolved, every earlier row's enrich() has already written
        // any identifier it carried into gp_identity_identifier, so there is no
        // chicken-and-egg the way there would be for a set-based bulk tier.
        // Task 5 replaces this loop's body with the per-type table; leave it
        // alone for now beyond the entity_type predicate.
        foreach ($identifiers as $ident) {
            $q = $hub->table('gp_identity_identifier as l')
                ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
                ->where('i.status', 'active')->where('i.current', 1)
                ->where('i.entity_type', $type)
                ->where('l.current', 1)
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
        // license_number + certification_state, individual-only.
        //
        // "Data Flow by CAMI" hangs licensing_credentials off individuals only,
        // and this is the half of that statement that changes behaviour: a
        // facility licence number colliding with a professional one in the same
        // state binds them at 0.99 today. gp_license rows are still WRITTEN for
        // an entity (see docs/ENTITY-TYPES.md) -- refusing the write would
        // discard facility licence numbers whose prevalence nobody has measured.
        if ($individual) {
            foreach ($licenses as $lic) {
                $q = $hub->table('gp_license as l')
                    ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
                    ->where('i.status', 'active')->where('i.current', 1)
                    ->where('i.entity_type', $type)
                    ->where('l.current', 1)
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
        }
        // name + dob (lower confidence). Structurally individual-only -- it needs
        // all three of first, last and DOB, and an entity has none of them -- but
        // the guard is explicit so a future connector that fills a name column on
        // an organization row cannot re-open the path silently.
        //
        // Plain column comparisons on purpose: the name columns are
        // utf8mb4_unicode_ci (already case-insensitive) and canonical_dob is a
        // DATE, so LOWER()/whereDate() only served to make the predicate
        // non-sargable -- idx_name_dob was skipped and every probe scanned ~6.5M
        // rows (EXPLAIN: type=ref key=idx_status rows=6475711 vs
        // key=idx_name_dob rows=1), which pinned incremental sync at ~0.03
        // rows/sec.
        if ($individual && $p->last_name && $p->first_name && $p->date_of_birth) {
            $id = $hub->table('gp_identity')
                ->where('status', 'active')->where('current', 1)->where('entity_type', $type)
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

- [ ] **Step 4: Carry the type onto a new identity, and backfill `org_name`**

Replace `createIdentity()` and `backfillKeys()`:

```php
    private function createIdentity(object $p): int
    {
        $now = now();

        // A brand-new logical identity: version 1, current. Explicit rather than
        // leaning on the column defaults, because insertGetId() has to return the
        // identity_id every child row will reference.
        //
        // entity_type comes from the staged row, which got it from the connector's
        // inference. It is written once, here, and afterwards changed ONLY by
        // gp:entity-reclassify -- never by Survivorship (docs/ENTITY-TYPES.md).
        return (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'entity_type' => $p->entity_type ?? 'individual',
            'org_name' => $p->org_name ?? null,
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
     * Supplying a key the identity did not have is a change to a golden fact, so
     * under the SCD-2 rule it is a new version. Versioner::write() decides: with
     * nothing to add, $upd is empty and no version is minted.
     *
     * org_name joins the list. Two reasons it belongs here rather than being left
     * to Survivorship: an identity minted before plan 4 has no org_name at all,
     * and reclassification (gp:entity-reclassify) is not the only way one can
     * acquire a name -- a second source row for the same organization may simply
     * be the first one to carry it.
     *
     * entity_type is deliberately ABSENT from both loops. It is a classification,
     * not a key: letting a later row's inference overwrite it would make an
     * identity's type depend on ingestion order, and every tier predicate above
     * would flip with it. A staged row whose type disagrees with the identity it
     * bound to is an alarm, and Survivorship logs it (Task 7).
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
        foreach ([
            'canonical_first' => 'first_name',
            'canonical_last' => 'last_name',
            'canonical_middle' => 'middle_name',
            'org_name' => 'org_name',
        ] as $col => $src) {
            if (empty($id->$col) && ! empty($p->$src)) {
                $upd[$col] = $p->$src;
            }
        }
        if ($upd) {
            $this->versioner->write('gp_identity', ['identity_id' => $identityId], $upd);
        }
    }
```

- [ ] **Step 5: Type-scope `Engine::dedup()`'s merge passes**

`Engine::dedup()` is not behind plan 3a's `SetBasedPathGuard` — it is reachable from `gp:backfill`
and from `Engine::sync()`'s finalize — so its four passes need the same scoping as the tiers, or they
undo them.

In `app/GoldenProfile/Engine.php`, replace `mergeByColumn()`:

```php
    /**
     * Merge active identities sharing a non-null value in $col.
     *
     * Grouped by (entity_type, $col), not by $col alone. Without entity_type in
     * the GROUP BY this pass would undo the tier scoping in
     * DeterministicResolver::matchDeterministic(): two identities of different
     * type sharing a upin or an npi would land in one group and be folded
     * together after the resolver deliberately kept them apart.
     */
    private function mergeByColumn(string $col, int $shard = 0, int $shards = 1): int
    {
        // $col is from a fixed internal whitelist — safe to interpolate.
        $hub = $this->hub();
        $n = 0;
        $q = $hub->table('gp_identity')->whereNotNull($col)
            ->where('status', 'active')->where('current', 1)
            ->select($col, 'entity_type');
        $q = $this->shardFilter($q, "CONCAT_WS('|',entity_type,$col)", $shard, $shards);
        $dupVals = $q->groupBy('entity_type', $col)->havingRaw('COUNT(*) > 1')->get();
        foreach ($dupVals as $row) {
            $val = $row->$col;
            // A filler ssn_hash is not evidence of shared identity. Resolution now
            // refuses to bind on one, but dedup would still fold together any
            // identities that already carry it — so screen here too.
            if ($col === 'ssn_hash' && $this->ssnGuard->isBlocked($val)) {
                continue;
            }
            $ids = $hub->table('gp_identity')
                ->where($col, $val)
                ->where('entity_type', $row->entity_type)
                ->where('status', 'active')->where('current', 1)
                ->orderBy('identity_id')->pluck('identity_id')->all();
            $survivor = (int) array_shift($ids);
            foreach ($ids as $loser) {
                $n += $this->mergeIdentity($survivor, (int) $loser);
            }
        }

        return $n;
    }
```

Replace `mergeByLicense()`:

```php
    /**
     * Merge active identities that share a license (number + state + board).
     *
     * entity_type joins the GROUP BY for the same reason as mergeByColumn, and
     * with a sharper consequence: the licence tier is the widest cross-type merge
     * path in the hub, because a facility licence number and a professional one
     * live in the same column space with no distinguishing format.
     */
    private function mergeByLicense(int $shard = 0, int $shards = 1): int
    {
        $hub = $this->hub();
        $n = 0;
        $q = $hub->table('gp_license')
            ->join('gp_identity', 'gp_identity.identity_id', '=', 'gp_license.identity_id')
            ->where('gp_identity.status', 'active')->where('gp_identity.current', 1)
            ->where('gp_license.current', 1)
            ->select('license_number', 'certification_state', 'certification_board', 'entity_type');
        $q = $this->shardFilter($q, "CONCAT_WS('|',entity_type,license_number,certification_state,certification_board)", $shard, $shards);
        $groups = $q->groupBy('entity_type', 'license_number', 'certification_state', 'certification_board')
            ->havingRaw('COUNT(DISTINCT gp_identity.identity_id) > 1')->get();
        foreach ($groups as $g) {
            $q = $hub->table('gp_license')
                ->join('gp_identity', 'gp_identity.identity_id', '=', 'gp_license.identity_id')
                ->where('gp_identity.status', 'active')->where('gp_identity.current', 1)
                ->where('gp_identity.entity_type', $g->entity_type)
                ->where('gp_license.current', 1)
                ->where('license_number', $g->license_number);
            $q = $g->certification_state === null
                ? $q->whereNull('certification_state') : $q->where('certification_state', $g->certification_state);
            $q = $g->certification_board === null
                ? $q->whereNull('certification_board') : $q->where('certification_board', $g->certification_board);
            $ids = $q->orderBy('gp_identity.identity_id')->distinct()->pluck('gp_identity.identity_id')->all();
            $survivor = (int) array_shift($ids);
            foreach ($ids as $loser) {
                $n += $this->mergeIdentity($survivor, (int) $loser);
            }
        }

        return $n;
    }
```

In `mergeByIdentifier()` (plan 5's state-scoped version), add `entity_type` to the select, the shard
expression, the `GROUP BY` and the per-group probe — the identical three-line change:

```php
            ->select('gii.id_type', 'gii.id_value', 'gii.state', 'gi.entity_type');
        $q = $this->shardFilter($q, "CONCAT_WS('|',gi.entity_type,gii.id_type,gii.id_value,gii.state)", $shard, $shards);
        $groups = $q->groupBy('gi.entity_type', 'gii.id_type', 'gii.id_value', 'gii.state')
            ->havingRaw('COUNT(DISTINCT gii.identity_id) > 1')->get();
```
and in the per-group subquery, beside the existing `where('gi.status', 'active')`:
```php
                ->where('gi.entity_type', $g->entity_type)
```

`mergeByNameDob()` needs no change: it already requires `canonical_first`, `canonical_last` and
`canonical_dob` to be non-null, and an entity has none of them. Leave a comment saying so, so the
next reader does not wonder whether it was missed:

```php
    private function mergeByNameDob(int $shard = 0, int $shards = 1): int
    {
        // No entity_type predicate, deliberately: this pass already requires
        // canonical_first, canonical_last AND canonical_dob to be non-null, and an
        // entity has none of the three, so no entity row can enter a group here.
        // Adding the predicate would be dead weight in a query that scans.
```

- [ ] **Step 6: Make `applyMerge()` refuse a cross-type merge**

Insert at the top of `applyMerge()`, before `$upd = []`:

```php
        // Defence in depth. Every caller has already been scoped by entity_type,
        // so reaching here with a mismatch means either a bug in a merge pass or
        // a steward action from plan 6. Returning without merging is the right
        // failure: a cross-type merge is unrepairable (gp_identity.status has a
        // 'split' value that nothing in this codebase ever writes, so there is no
        // undo), and the log row is what makes it findable.
        if (($s->entity_type ?? 'individual') !== ($l->entity_type ?? 'individual')) {
            $hub->table('gp_resolution_log')->insert([
                'action' => 'override',
                'identity_id' => $survivor,
                'affected_ids' => json_encode(['survivor' => $survivor, 'loser' => $loser]),
                'match_key' => 'entity_type',
                'reason' => 'refused cross-type merge: '.
                    ($s->entity_type ?? 'individual').' vs '.($l->entity_type ?? 'individual'),
                'actor' => 'engine',
                'created_at' => now(),
            ]);

            return;
        }
```

`mergeIdentity()` returns 1 unconditionally after calling `applyMerge()`, which would now
over-report. Change its tail so a refusal is counted as no merge:

```php
            $before = (int) $hub->table('gp_identity')
                ->where('identity_id', $loser)->where('current', 1)->value('version_no');

            $this->applyMerge($hub, $s, $l, $survivor, $loser);

            // applyMerge returns without doing anything when the two identities
            // differ in entity_type. Counting that as a merge would make dedup()'s
            // do/while loop spin forever: it repeats while $round > 0.
            $after = (int) $hub->table('gp_identity')
                ->where('identity_id', $loser)->where('current', 1)->value('version_no');

            return $after === $before && $before !== 0 ? 0 : 1;
```

> The version-number probe is the reliable signal under plan 3, where `applyMerge()` retires the
> loser with a new version rather than deleting the row. If plan 3's Task 8 gave `applyMerge()` a
> different completion signal, use that instead — the requirement is only that a refusal returns 0,
> because `dedup()`'s `do { … } while ($round > 0)` loop never terminates otherwise.

- [ ] **Step 7: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/EntityLadderTest.php`

Expected: PASS, 9 tests.

- [ ] **Step 8: Run the full suite and the gate**

Run:
```bash
vendor/bin/phpunit
vendor/bin/pint --dirty
```

Expected: PASS, 0 skipped. `EvalGateTest` **unchanged** — precision 1.0000, recall 1.0000, f1
1.0000, `true_pairs` 11, 0 false merges, 0 false splits. Every fixture record is
`entity_type = 'individual'`, so every tier's new predicate is satisfied by every candidate and no
result can move. Task 10 adds the records that exercise it. **If the gate moves here, the scoping
narrowed something it should not have** — most likely an `entity_type` predicate landed on a table
that does not have the column (`gp_license`, `gp_address` and `gp_identity_identifier` do not; only
`gp_identity`, `stg_person` and `gp_identity_profile` do).

- [ ] **Step 9: Commit**
```bash
git add app/GoldenProfile/Resolution/DeterministicResolver.php \
        app/GoldenProfile/Engine.php \
        tests/Feature/EntityLadderTest.php
git commit -m "fix(entity): scope every match key by entity_type so a person cannot bind to an organization"
```

---

## Task 5: The entity ladder — TIN, EIN and UEI

Three new `id_type` values on plan 5's existing `gp_identity_identifier` mechanism, plus the config
entries that make them tiers. No schema change: `id_type` is `varchar(16)` (verified), so `tin`,
`ein` and `uei` fit.

**Files:**
- Modify: `config/golden_profile.php` (`deterministic_keys`)
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php` (the identifier tier)
- Modify: `app/GoldenProfile/Connectors/StreamlineLocalConnector.php` (`additionalRows`)
- Modify: `tests/Unit/DeterministicKeyConfigTest.php`
- Test: `tests/Feature/EntityLadderTest.php` (extend)

**Interfaces:**
- Produces: `deterministic_keys` entries `tin`, `ein`, `uei`;
  `StreamlineLocalConnector::ENTITY_ID_TYPES` and `::STATE_SCOPED_ID_TYPES`;
  `additionalRows()` emitting `tin` / `ein` / `uei` identifiers.
- Consumes: plan 5's identifier tier and `enrich()` upsert loop; Task 4's `entity_type` predicate.
- Consumed by: Task 6 (the conflicting-TIN hard-no), Task 10 (the `org-tin-*` fixture pair).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/EntityLadderTest.php`:

```php
    public function test_two_entities_sharing_a_tin_bind_on_the_tin_tier(): void
    {
        // Different zips, different addresses, org names differing by a legal
        // suffix: nothing but the TIN can bind these, which is the point.
        $a = $this->stageEntity([
            'org_name' => 'Thistlebrook Medical Group', 'state' => 'OH', 'zip' => '43004',
        ]);
        $this->stageIdentifier($a, 'tin', '31-4402917');

        $b = $this->stageEntity([
            'org_name' => 'Thistlebrook Medical Group LLC', 'state' => 'OH', 'zip' => '45011',
        ]);
        $this->stageIdentifier($b, 'tin', '31-4402917');

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertSame($resolver->resolve($a), $resolver->resolve($b));
    }

    public function test_the_tin_tier_is_not_state_scoped(): void
    {
        // A TIN is federal. State-scoping it -- the way MMIS is scoped -- would
        // split one organization's records across its own branch states.
        $a = $this->stageEntity(['org_name' => 'Marrowstone Imaging', 'state' => 'OH']);
        $this->stageIdentifier($a, 'tin', '31-4402917', 'OH');

        $b = $this->stageEntity(['org_name' => 'Marrowstone Imaging', 'state' => 'TX']);
        $this->stageIdentifier($b, 'tin', '31-4402917', 'TX');

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertSame($resolver->resolve($a), $resolver->resolve($b));
    }

    public function test_ein_and_uei_bind_the_same_way_as_tin(): void
    {
        foreach ([['ein', '52-7719038'], ['uei', 'ZQ4T8LMN2XV9']] as [$type, $value]) {
            $a = $this->stageEntity(['org_name' => "Pinewhistle $type A"]);
            $this->stageIdentifier($a, $type, $value);
            $b = $this->stageEntity(['org_name' => "Pinewhistle $type B"]);
            $this->stageIdentifier($b, $type, $value);

            $resolver = new DeterministicResolver($this->systemId);

            $this->assertSame(
                $resolver->resolve($a),
                $resolver->resolve($b),
                "a shared $type must bind two entities"
            );
        }
    }

    public function test_a_person_and_an_entity_sharing_a_tin_do_not_bind(): void
    {
        // A TIN on a person row is a data-entry error (a sole proprietor's EIN
        // typed onto a person record). It must not bind them to the organization
        // that legitimately holds it.
        $org = $this->stageEntity(['org_name' => 'Thistlebrook Medical Group']);
        $this->stageIdentifier($org, 'tin', '31-4402917');

        $person = $this->stagePerson([
            'first_name' => 'Jane', 'last_name' => 'Roe', 'date_of_birth' => '1972-03-19',
        ]);
        $this->stageIdentifier($person, 'tin', '31-4402917');

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertNotSame($resolver->resolve($org), $resolver->resolve($person));
    }

    public function test_a_dea_number_does_not_bind_two_entities(): void
    {
        // DEA is individual-only: a DEA registration is issued to a prescriber.
        // An entity carrying one is a misclassified sole proprietor or bad data,
        // and binding on it would merge two organizations on a person's key.
        $a = $this->stageEntity(['org_name' => 'Ashgrove Dialysis Center']);
        $this->stageIdentifier($a, 'dea', 'AH1234563');
        $b = $this->stageEntity(['org_name' => 'Marrowstone Imaging']);
        $this->stageIdentifier($b, 'dea', 'AH1234563');

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertNotSame($resolver->resolve($a), $resolver->resolve($b));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/EntityLadderTest.php --filter=tin`

Expected: FAIL — `test_two_entities_sharing_a_tin_bind_on_the_tin_tier` reports two different
identities. The identifier tier probes `gp_identity_identifier` for whatever `id_type` it is handed,
but nothing stages a `tin` and its confidence key is unconfigured.

- [ ] **Step 3: Add the three config entries**

In `config/golden_profile.php`, in the `deterministic_keys` block:

```php
    'deterministic_keys' => [
        'ssn_hash' => 0.99,
        'npi' => 0.99,
        'dea_number' => 0.99,
        'upin' => 0.99,
        // Multi-valued identifiers from gp_identity_identifier. DEA is federal
        // and individual-only; MMIS is state-scoped and individual-only.
        'dea_multi' => 0.99,
        'mmis+state' => 0.99,
        // Entity identifiers (plan 4). All three are federal registrations, so
        // none is state-scoped -- scoping a TIN by state would split one
        // organization's records across its own branch states. TIN and EIN are
        // the same nine digits in this domain; both id_types are accepted because
        // "Data Model (What We Store)" names EIN while CAMI's data uses both
        // words, and normalising one to the other at ingestion would silently
        // rewrite a value a steward may later have to reconcile against a filing.
        'tin' => 0.99,
        'ein' => 0.99,
        'uei' => 0.99,
        'license_number+certification_state' => 0.99,
        'name+dob' => 0.95,
    ],
```

- [ ] **Step 4: Make the identifier tier type-aware**

Two constants on the connector give the tier and the ingestion path one shared answer to "which
identifier belongs to which type". In `app/GoldenProfile/Connectors/StreamlineLocalConnector.php`,
above `SOURCE_TABLE`:

```php
    /**
     * Which multi-valued identifier types belong to which record type.
     *
     * A DEA registration is issued to a prescriber and an MMIS number to an
     * enrolled individual provider; a TIN/EIN and a UEI are issued to a legal
     * entity. Matching on the wrong one is not a near-miss -- it binds at 0.99
     * with no name or address cross-check.
     */
    public const INDIVIDUAL_ID_TYPES = ['dea', 'mmis'];

    public const ENTITY_ID_TYPES = ['tin', 'ein', 'uei'];

    /**
     * Identifier types whose value is only unique WITHIN a state. MMIS numbers
     * are assigned per state Medicaid program, so MMIS-4471/CA and MMIS-4471/TX
     * are different providers. Everything else here is a federal registration.
     */
    public const STATE_SCOPED_ID_TYPES = ['mmis'];
```

Then replace the identifier tier's body in `DeterministicResolver::matchDeterministic()` (the
`foreach ($identifiers as $ident)` block Task 4 left in place):

```php
        // Multi-valued identifiers, from gp_identity_identifier.
        //
        // Real-time here because each row is resolved sequentially -- by the time
        // THIS row is resolved, every earlier row's enrich() has already written
        // any identifier it carried, so there is no chicken-and-egg the way there
        // would be for a set-based bulk tier (the same reason licence resolution
        // is handled after enrich() in the bulk path).
        //
        // Each type belongs to exactly one record type: DEA and MMIS are issued
        // to people, TIN/EIN and UEI to legal entities
        // (StreamlineLocalConnector::INDIVIDUAL_ID_TYPES / ENTITY_ID_TYPES). An
        // identifier of the wrong type for this row is SKIPPED rather than
        // matched loosely -- a TIN on a person row is a data-entry error and
        // binding on it at 0.99 would fold that person into the organization that
        // legitimately holds it.
        //
        // Only MMIS is state-scoped (STATE_SCOPED_ID_TYPES). TIN, EIN and UEI are
        // federal, so scoping them by state would split one organization's
        // records across its own branch states.
        $allowedIdTypes = $individual
            ? StreamlineLocalConnector::INDIVIDUAL_ID_TYPES
            : StreamlineLocalConnector::ENTITY_ID_TYPES;

        foreach ($identifiers as $ident) {
            if (! in_array($ident->id_type, $allowedIdTypes, true)) {
                continue;
            }

            $q = $hub->table('gp_identity_identifier as l')
                ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
                ->where('i.status', 'active')->where('i.current', 1)
                ->where('i.entity_type', $type)
                ->where('l.current', 1)
                ->where('l.id_type', $ident->id_type)
                ->where('l.id_value', $ident->id_value);

            if (in_array($ident->id_type, StreamlineLocalConnector::STATE_SCOPED_ID_TYPES, true)) {
                $ident->state === null ? $q->whereNull('l.state') : $q->where('l.state', $ident->state);
            }

            $id = $q->orderBy('l.identity_id')->value('l.identity_id');

            if ($id) {
                // dea_multi keeps plan 5's config key name; the entity types use
                // their own, so a calibration change to one cannot silently move
                // the other.
                $confKey = match ($ident->id_type) {
                    'dea' => 'dea_multi',
                    'mmis' => 'mmis+state',
                    default => $ident->id_type,
                };

                return [(int) $id, $confKey, $this->confidence($confKey, 0.99)];
            }
        }
```

Add the import to `DeterministicResolver`:

```php
use App\GoldenProfile\Connectors\StreamlineLocalConnector;
```

- [ ] **Step 5: Stage the entity identifiers from the source**

`employee_additional_info` is the EAV table `additionalRows()` already pivots. In
`StreamlineLocalConnector::additionalRows()`, after the MMIS block:

```php
        // MMIS (match key).
        if (! empty($v['mmis_number'])) {
            $identifiers[] = ['id_type' => 'mmis', 'id_value' => $v['mmis_number']];
        }
        // Entity identifiers (match keys). Several spellings are accepted because
        // employee_additional_info is a free-form EAV table whose `name` values
        // were never constrained -- and a missing spelling here is not a visible
        // failure, it is an entity that silently never binds to its own other
        // records. `tin_number`/`ein_number` land under their own id_type rather
        // than being normalised to one, because a steward reconciling against a
        // public filing needs to know which the source actually said.
        foreach ([
            'tin' => ['tin', 'tin_number', 'tax_id', 'tax_id_number'],
            'ein' => ['ein', 'ein_number', 'employer_id_number'],
            'uei' => ['uei', 'uei_number', 'unique_entity_id'],
        ] as $idType => $names) {
            foreach ($names as $name) {
                if (! empty($v[$name])) {
                    $identifiers[] = ['id_type' => $idType, 'id_value' => $v[$name]];
                    break;                 // first spelling present wins
                }
            }
        }
```

> **Verify the EAV key names before relying on them.** `employee_additional_info.name` is
> unconstrained free text and the list above is a guess at its spellings, which is the one place in
> this plan where a guess is unavoidable. Run this and add whatever it actually returns:
> ```bash
> php -r '$p=new PDO("mysql:host=192.168.56.22;dbname=streamline_local","root","root");
> foreach($p->query("SELECT DISTINCT name FROM employee_additional_info
>   WHERE name REGEXP \"tin|ein|uei|tax|entity\" ORDER BY name") as $r) echo $r["name"],"\n";'
> ```
> An empty result means the dev source carries none of these, which is expected (its three entity
> rows are hand-seeded smoke-test fixtures) and is not evidence the names are wrong. Record whatever
> the real hub's source has in `docs/ENTITY-TYPES.md` as part of Task 9's human task.

- [ ] **Step 6: Extend the config test**

In `tests/Unit/DeterministicKeyConfigTest.php`, extend the two tier lists:

```php
        foreach (['ssn_hash', 'npi', 'dea_number', 'upin', 'dea_multi', 'mmis+state',
            'tin', 'ein', 'uei', 'license_number+certification_state', 'name+dob'] as $tier) {
```
and
```php
        foreach (['ssn_hash', 'npi', 'dea_number', 'upin', 'dea_multi', 'mmis+state',
            'tin', 'ein', 'uei'] as $strong) {
```

`test_resolver_reads_config_rather_than_literals()` counts `$this->confidence(` call sites. The
identifier tier still contains exactly one (the `match` expression resolves the key, not the call),
so plan 5's expected count of 7 is unchanged. Add a comment saying so rather than editing the number:

```php
        $this->assertSame(
            7,
            preg_match_all('/\$this->confidence\(/', $source),
            'the 6 original tiers plus the multi-valued identifier tier. Plan 4 added tin/ein/uei '.
            'to that ONE tier through its match expression, so the count does not move.',
        );
```

- [ ] **Step 7: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/EntityLadderTest.php tests/Unit/DeterministicKeyConfigTest.php`

Expected: PASS, 14 + the config test's own count.

- [ ] **Step 8: Run the full suite and the gate**

Run:
```bash
vendor/bin/phpunit
vendor/bin/pint --dirty
```

Expected: PASS, 0 skipped. `EvalGateTest` unchanged at `true_pairs` 11 — the fixture carries no
`tin`, `ein` or `uei` identifier, so the new tiers cannot fire against it yet. Task 10 adds the
records that do.

- [ ] **Step 9: Commit**
```bash
git add config/golden_profile.php \
        app/GoldenProfile/Resolution/DeterministicResolver.php \
        app/GoldenProfile/Connectors/StreamlineLocalConnector.php \
        tests/Unit/DeterministicKeyConfigTest.php \
        tests/Feature/EntityLadderTest.php
git commit -m "feat(entity): add the TIN, EIN and UEI match tiers on the existing identifier mechanism"
```

---

## Task 6: Pass B for organizations

Before this task Pass B never runs for an organization: `match()` returns `no_match` on a null
`block_key` and `blockKey()` returned null for any blank surname. Task 3 gave entities a block key,
so this task has to arrive with it — an entity reaching `score()` today would be awarded the full
0.45 name weight for agreeing on nothing, because `jaroWinkler('', '')` returns `1.0`.

**Files:**
- Modify: `app/GoldenProfile/Support/NameMatcher.php`
- Modify: `app/GoldenProfile/Resolution/ProbabilisticResolver.php`
- Modify: `app/GoldenProfile/Resolution/DeterministicResolver.php` (one call site)
- Modify: `config/golden_profile.php`
- Modify: `tests/Unit/ProbabilisticScoringTest.php`
- Test: `tests/Feature/EntityPassBTest.php`

**Interfaces:**
- Produces:
  - `NameMatcher::orgCompatible(?string $a, ?string $b): bool`
  - `NameMatcher::normalizeOrg(?string $name): ?string`
  - `ProbabilisticResolver::match(object $p, $licenses, $identifiers = []): array{0:?int,1:float,2:string}`
  - config `probabilistic.entity_weights`, `probabilistic.entity_implemented_weights`,
    `probabilistic.hard_no.conflicting_tin`
- Consumes: `BlockKey` (Task 3), `StreamlineLocalConnector::ENTITY_ID_TYPES` (Task 5).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EntityPassBTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Support\NameMatcher;
use Tests\Support\HubTestCase;

/**
 * Pass B for organizations.
 *
 * Every entity pair here shares no hard identifier at all, so Pass A cannot
 * fire and the outcome is Pass B's alone -- which before this task was
 * unconditionally no_match, because a null block_key short-circuits match().
 */
class EntityPassBTest extends HubTestCase
{
    private function stageIdentifier(int $stgPersonId, string $type, string $value, ?string $state = null): void
    {
        $this->hub()->table('stg_person_identifier')->insert([
            'stg_person_id' => $stgPersonId, 'id_type' => $type, 'id_value' => $value, 'state' => $state,
        ]);
    }

    /** An entity staged with a full primary address, which Pass B needs. */
    private function org(string $name, string $state, string $zip, string $address1): int
    {
        $id = $this->stageEntity(['org_name' => $name, 'state' => $state, 'zip' => $zip, 'address1' => $address1]);
        $this->stageAddress($id, [
            'address1' => $address1, 'city' => 'Bethany', 'state' => $state, 'zip' => $zip,
        ]);

        return $id;
    }

    public function test_the_org_name_gate_normalizes_legal_form_and_punctuation(): void
    {
        $this->assertTrue(NameMatcher::orgCompatible('Thistlebrook Medical Group', 'Thistlebrook Medical Group LLC'));
        $this->assertTrue(NameMatcher::orgCompatible('Thistlebrook Medical Group, Inc.', 'thistlebrook  medical group'));
        $this->assertTrue(NameMatcher::orgCompatible('Ashgrove Dialysis Center P.C.', 'Ashgrove Dialysis Center'));

        // Equality, not similarity. This pair is the reason the gate exists: at
        // one address with one zip it scores above the review floor without it.
        $this->assertFalse(NameMatcher::orgCompatible('Ashgrove Dialysis Center', 'Ashgrove Dental Care'));
        $this->assertFalse(NameMatcher::orgCompatible('Ashgrove Dialysis Center', null));
        $this->assertFalse(NameMatcher::orgCompatible(null, null));

        // A name that is NOTHING but a legal form must not normalize to empty and
        // then match every other such name.
        $this->assertFalse(NameMatcher::orgCompatible('LLC', 'Inc'));
    }

    public function test_two_records_of_one_organization_merge_in_the_review_band(): void
    {
        $a = $this->org('Ashgrove Dialysis Center', 'OK', '73008', '77 Marlin Bend');
        $b = $this->org('Ashgrove Dialysis Center', 'OK', '73008', '77 Marlin Bend');

        $resolver = new DeterministicResolver($this->systemId);
        $idA = $resolver->resolve($a);
        $idB = $resolver->resolve($b);

        $this->assertSame($idA, $idB, 'same normalized name + same address + same zip must bind');

        // Review band, not auto_match: auto_match additionally needs a shared
        // exclusion registry. And a review-band bind is logged, which is the
        // whole reason soft entity matching goes through Pass B instead of a
        // deterministic org_name+zip tier.
        $this->assertSame('review', $this->hub()->table('gp_source_link')
            ->where('identity_id', $idB)->orderByDesc('link_id')->value('match_state'));

        $this->assertSame(1, (int) $this->hub()->table('gp_resolution_log')
            ->where('identity_id', $idB)->where('match_key', 'probabilistic')->count());
    }

    public function test_the_same_organization_name_in_a_different_zip_does_not_merge(): void
    {
        // Same state, so they DO land in one block -- this tests the score, not
        // the blocking key. addressOverlap() awards nothing without equal zips,
        // so the pair scores name alone (0.50), below review_band_floor 0.75.
        $a = $this->org('Ashgrove Dialysis Center', 'OK', '73008', '77 Marlin Bend');
        $b = $this->org('Ashgrove Dialysis Center', 'OK', '74003', '9 Cobbler Lane');

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertNotSame(
            $resolver->resolve($a),
            $resolver->resolve($b),
            'an organization is never bound on its name alone'
        );
    }

    public function test_two_similarly_named_organizations_at_one_address_do_not_merge(): void
    {
        // The gate's reason for existing. Without it: 0.50 x JW(~0.85) + 0.25
        // (address) + 0.10 (zip) = ~0.775, over the 0.75 review floor -- a false
        // merge of a dialysis centre into a dental practice.
        $a = $this->org('Ashgrove Dialysis Center', 'OK', '73008', '77 Marlin Bend');
        $b = $this->org('Ashgrove Dental Care', 'OK', '73008', '77 Marlin Bend');

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertNotSame($resolver->resolve($a), $resolver->resolve($b));
    }

    public function test_conflicting_tins_block_a_merge_that_would_otherwise_happen(): void
    {
        // A management company and its clinic: one trading name, one address,
        // two legal entities. Without the hard-no this pair scores 0.85.
        $a = $this->org('Ashgrove Dialysis Center', 'OK', '73008', '77 Marlin Bend');
        $this->stageIdentifier($a, 'tin', '31-4402917');
        $b = $this->org('Ashgrove Dialysis Center', 'OK', '73008', '77 Marlin Bend');
        $this->stageIdentifier($b, 'tin', '52-7719038');

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertNotSame($resolver->resolve($a), $resolver->resolve($b));
    }

    public function test_pass_b_never_scores_an_entity_against_a_person(): void
    {
        // Belt and braces on top of the E: block-key prefix: even if a future
        // block-key change put them in one bucket, the candidate is refused
        // before the gate.
        $person = $this->stagePerson([
            'first_name' => 'Ash', 'last_name' => 'Grove', 'date_of_birth' => '1980-01-01',
            'block_key' => 'E:'.soundex('Ashgrove').'|OK',
        ]);
        $this->stageAddress($person, ['address1' => '77 Marlin Bend', 'state' => 'OK', 'zip' => '73008']);

        $org = $this->stageEntity([
            'org_name' => 'Ashgrove Dialysis Center', 'state' => 'OK', 'zip' => '73008',
            'block_key' => 'E:'.soundex('Ashgrove').'|OK',
        ]);
        $this->stageAddress($org, ['address1' => '77 Marlin Bend', 'state' => 'OK', 'zip' => '73008']);

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertNotSame($resolver->resolve($person), $resolver->resolve($org));
    }

    public function test_an_entity_with_no_org_name_gets_no_block_key_and_no_pass_b(): void
    {
        // No name means no blocking evidence. Returning no_match is right; the
        // caller mints a fresh identity, and plan 5 Task 7's quarantine owns rows
        // with no identifying data at all.
        $a = $this->stageEntity(['org_name' => null, 'block_key' => null]);
        $b = $this->stageEntity(['org_name' => null, 'block_key' => null]);

        $resolver = new DeterministicResolver($this->systemId);

        $this->assertNotSame($resolver->resolve($a), $resolver->resolve($b));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/EntityPassBTest.php`

Expected: FAIL, 2. `test_the_org_name_gate_normalizes_legal_form_and_punctuation` errors with
`Call to undefined method App\GoldenProfile\Support\NameMatcher::orgCompatible()`, and
`test_two_records_of_one_organization_merge_in_the_review_band` reports two different identities.
The four "must not merge" tests pass already — they are the controls that prove the fix does not
over-merge.

- [ ] **Step 3: Add the org-name gate to `NameMatcher`**

Append to `app/GoldenProfile/Support/NameMatcher.php`:

```php
    /**
     * Legal-form tokens dropped from the tail of an organization name before
     * comparison. Deliberately a fixed list rather than a regex over "anything
     * short": stripping an unknown trailing token would turn "Ashgrove Dialysis"
     * and "Ashgrove Dental" into "Ashgrove" and merge them.
     *
     * Only ONE trailing token is dropped. "Ashgrove Dialysis Center LLC PC" is
     * not a real name, and iterating would eventually strip a meaningful word
     * from a name like "Marrowstone Imaging Co Group".
     */
    private const LEGAL_FORMS = [
        'llc', 'llp', 'lp', 'lc', 'plc', 'pllc', 'pc', 'pa', 'inc', 'incorporated',
        'corp', 'corporation', 'co', 'company', 'ltd', 'limited',
    ];

    /**
     * The organization-name gate for Pass B.
     *
     * EQUALITY of the normalized names, not similarity -- deliberately mirroring
     * the individual rule, where first and last must be EQUAL and only middle,
     * suffix and DOB may be merely compatible. The Jaro-Winkler weight still
     * exists in score(), but it operates behind this gate, exactly as the
     * individual name weight operates behind NameMatcher::compatible().
     *
     * Without the gate, "Ashgrove Dialysis Center" and "Ashgrove Dental Care" at
     * one street address in one zip score 0.50 x JW(~0.85) + 0.25 + 0.10 = ~0.775
     * and clear the 0.75 review floor -- a false merge of two unrelated practices.
     * A false merge welds two providers together and nothing downstream undoes it,
     * which is what config golden_profile.tracks.identity = precision_first means.
     */
    public static function orgCompatible(?string $a, ?string $b): bool
    {
        $a = self::normalizeOrg($a);
        $b = self::normalizeOrg($b);

        return $a !== null && $a === $b;
    }

    /**
     * Lowercase, drop punctuation, collapse whitespace, drop one trailing legal
     * form. Returns null for anything that normalizes to nothing -- including a
     * name that is ONLY a legal form, which must not become the empty string and
     * then match every other such name.
     */
    public static function normalizeOrg(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        // Punctuation to spaces rather than removed: "A.B.C Clinic" must become
        // "a b c clinic", not "abc clinic", or it stops matching "ABC Clinic"
        // written without the dots. Digits are kept -- "3M Dialysis" and
        // "Clinic 5" are real name shapes and the digit is discriminating.
        $n = strtolower($name);
        $n = preg_replace('/[^a-z0-9]+/', ' ', $n);
        $n = trim(preg_replace('/\s+/', ' ', (string) $n));

        if ($n === '') {
            return null;
        }

        $parts = explode(' ', $n);

        if (count($parts) > 1 && in_array(end($parts), self::LEGAL_FORMS, true)) {
            array_pop($parts);
        }

        $n = implode(' ', $parts);

        return $n === '' ? null : $n;
    }
```

- [ ] **Step 4: Add the entity weight set and the hard-no to config**

In `config/golden_profile.php`, inside the `probabilistic` block, after `implemented_weights`:

```php
        // Entity weights. A SEPARATE set, not a rebalance of the individual one.
        //
        // Why adding this is safe when rebalancing the weights above is not: the
        // comment above is right that changing those "changes merge behaviour
        // across the whole hub", and it does -- they govern ~13.38M live rows.
        // This set governs ZERO existing rows, because
        // 2026_09_07_000000_add_entity_type_and_org_name classified every existing
        // identity as 'individual'. It has no blast radius, so it can be set from
        // first principles now and calibrated later like the other one.
        //
        // Sums to exactly 1.00 with EVERY member implemented, unlike the
        // individual set (which tops out at exactly auto_merge_at because
        // provider_type has no source column). So auto_match IS reachable for an
        // entity, and what it takes is legible: an exact normalized org-name
        // match PLUS the same street address PLUS the same zip PLUS a shared
        // exclusion registry. The review band (0.75) is name+address+zip (0.85)
        // or name+zip+exclusion (0.75). Name alone is 0.50 -- below the floor, so
        // an organization is never bound on its name alone, which is the same
        // policy the nodob-a/nodob-b eval pair asserts for people.
        //
        // There is no dob weight and no provider_type weight: an organization has
        // no date of birth, and entity_type is the thing being matched WITHIN, so
        // it carries no information here.
        'entity_weights' => [
            'name' => 0.50,   // normalized org_name, Jaro-Winkler, behind the equality gate
            'address' => 0.25,   // address1 + zip agreement across any staged/golden pair
            'exclusion_share' => 0.15,   // shared exclusion registry (compliance signal)
            'zip' => 0.10,
        ],
        'entity_implemented_weights' => ['name', 'address', 'exclusion_share', 'zip'],
```

and extend `hard_no`:

```php
        // Hard-no rules: block a merge outright regardless of score (GPP "Get it wrong" safeguards).
        'hard_no' => [
            'conflicting_dob' => true,  // both non-null and different (individuals)
            'two_valid_npis' => true,  // both non-null and different
            // Two organizations at one address under one trading name with
            // different federal tax IDs are two legal entities -- a management
            // company and its clinic is the common shape. Without this, that pair
            // scores 0.85 and binds in the review band.
            'conflicting_tin' => true,
        ],
```

- [ ] **Step 5: Rewrite `ProbabilisticResolver`'s type-aware paths**

In `app/GoldenProfile/Resolution/ProbabilisticResolver.php`, replace `match()`, `hardNo()` and
`score()`, and add three helpers. `warnIfAutoMergeUnreachable()` also gains the entity set.

```php
    /**
     * @param  iterable  $identifiers  staged identifiers, for the conflicting-TIN hard-no
     * @return array{0:?int,1:float,2:string}
     */
    public function match(object $p, $licenses, $identifiers = []): array
    {
        // Before plan 4 this returned no_match for EVERY organization, because
        // block_key was null for any blank surname and an entity has no surname.
        // BlockKey::for() now produces 'E:<soundex of the first org-name token>|<state>'
        // for an entity, so the short-circuit below now only catches rows with no
        // blocking evidence at all -- an entity with no org_name, or a person with
        // no surname.
        if (! $p->block_key) {
            return [null, 0.0, 'no_match'];
        }

        $type = $p->entity_type ?? 'individual';
        $isEntity = $type === 'entity';

        // Oversized blocks carry no evidence. For individuals, block_key is
        // surname-soundex + DOB and rows with no DOB collapse into buckets like
        // "D500|____" holding 107,164 people -- that is "surname sounds like
        // D-500", not a candidate set. Scoring one costs ~30s per source row and
        // cannot produce a defensible match, so over the cap Pass B declines and
        // the caller mints a new identity.
        //
        // ENTITY BUCKETS INFLATE FOR A DIFFERENT REASON, and it is worth knowing:
        // employees.state is varchar(65), so a spelled-out state is truncated to
        // two letters and Michigan, Missouri and Mississippi all become MI. That
        // cannot cause a wrong bind -- blocking widens a candidate set, it never
        // decides, and every candidate still has to clear the org-name gate -- but
        // it does push those buckets toward the cap. Over the cap the caller mints
        // a new identity stamped auto_match, which is a SILENT FALSE SPLIT; the
        // config comment's claim that oversized blocks are "flagged for steward"
        // describes a half that does not exist. Plan 6 owns it.
        // gp:entity-audit reports the entity block-size distribution so it can be
        // watched rather than discovered.
        $cap = (int) ($this->cfg['block_size_cap'] ?? 0);
        if ($cap > 0) {
            $blockSize = (int) $this->hub()->table('stg_person')
                ->where('block_key', $p->block_key)->count();
            if ($blockSize > $cap) {
                return [null, 0.0, 'no_match'];
            }
        }

        $candidateIds = $this->hub()->table('gp_source_link as l')
            ->join('stg_person as sp', function ($j) {
                $j->on('sp.system_id', '=', 'l.system_id')
                    ->on('sp.source_table', '=', 'l.source_table')
                    ->on('sp.source_id', '=', 'l.source_id');
            })
            ->join('gp_identity as i', 'i.identity_id', '=', 'l.identity_id')
            ->where('sp.block_key', $p->block_key)
            ->where('i.status', 'active')->where('i.current', 1)
            // Type scoping at the candidate query, not only per candidate: the
            // E: prefix already keeps the two apart at the blocking level, but a
            // caller can supply an explicit block_key (HubTestCase and EvalRunner
            // both allow it), so the invariant is enforced where it is cheap.
            ->where('i.entity_type', $type)
            ->where(fn ($q) => $q->where('sp.source_id', '!=', $p->source_id)->orWhere('sp.system_id', '!=', $this->systemId))
            ->distinct()->pluck('l.identity_id');

        $best = null;
        $bestScore = 0.0;

        // Candidates are loaded in batches, not one query each. A block_key like
        // "smith|1992-09-21" can hold thousands of members (the seeded test-data
        // pile-ups run to 12k), and a per-candidate SELECT made every new source
        // row cost that many round-trips — measured at ~13,500 hub queries per
        // row, which pinned gp:sync at ~0.03 rows/sec.
        $identities = collect();
        foreach ($candidateIds->chunk(1000) as $batch) {
            $identities = $identities->merge(
                $this->hub()->table('gp_identity')
                    ->whereIn('identity_id', $batch->all())->where('current', 1)->get()
            );
        }

        $incomingTins = $this->entityTaxIds($identifiers);

        foreach ($identities as $identity) {
            $cid = $identity->identity_id;

            // Defence in depth against a hand-set block_key.
            if (($identity->entity_type ?? 'individual') !== $type) {
                continue;
            }
            if ($this->hardNo($p, $identity, $isEntity, $incomingTins)) {
                continue;
            }

            // The strict name prerequisite. For an individual: first+last equal,
            // middle/suffix/dob merely compatible. For an entity: the normalized
            // org name EQUAL, which is the same shape of rule -- see
            // NameMatcher::orgCompatible() for why equality and not similarity.
            $compatible = $isEntity
                ? NameMatcher::orgCompatible($p->org_name ?? null, $identity->org_name ?? null)
                : NameMatcher::compatible($p, $this->asNameObj($identity));

            if (! $compatible) {
                continue;
            }

            $score = $isEntity ? $this->scoreEntity($p, $identity) : $this->score($p, $identity);
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

        return [null, $bestScore, 'no_match'];
    }

    /**
     * Hard-no safeguards: block a merge regardless of similarity.
     *
     * @param  list<string>  $incomingTins  normalized tax ids on the incoming row
     */
    private function hardNo(object $p, object $identity, bool $isEntity, array $incomingTins): bool
    {
        $hn = $this->cfg['hard_no'];

        if (! $isEntity && ! empty($hn['conflicting_dob'])
            && NameMatcher::dobConflicts($p->date_of_birth, $identity->canonical_dob)) {
            return true;
        }
        if (! empty($hn['two_valid_npis']) && $p->npi && $identity->npi && (int) $p->npi !== (int) $identity->npi) {
            return true;
        }
        // Conflicting federal tax id -- the entity analog of two_valid_npis. Two
        // organizations at one address trading under one name with different
        // TIN/EIN values are two legal entities, and no amount of address
        // agreement should merge them. Only checked for entities: a tax id on a
        // person row is a data-entry error, and treating it as a hard-no there
        // would let one bad field permanently split a real person's records.
        if ($isEntity && ! empty($hn['conflicting_tin']) && $incomingTins !== []) {
            $existing = $this->entityTaxIdsOf((int) $identity->identity_id);
            if ($existing !== [] && array_intersect($incomingTins, $existing) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Entity score. A separate method rather than a branch inside score(),
     * because the two share only their address and zip legs and interleaving
     * them made both harder to read than either.
     *
     * There is no dob leg: an organization has no date of birth. If there were a
     * shared name leg, it would be actively dangerous --
     * jaroWinkler('', '') returns 1.0 from its FIRST LINE, before the
     * zero-length check, so a shared implementation would award the full name
     * weight to two entities for agreeing on nothing. That is latent today only
     * because entities never reach score(); giving them a block key without this
     * method would make it live.
     */
    private function scoreEntity(object $p, object $identity): float
    {
        $w = $this->cfg['entity_weights'];
        $sum = 0.0;

        // Jaro-Winkler over the NORMALIZED names. orgCompatible() has already
        // required them to be equal, so this is 1.0 in practice; it is computed
        // rather than hardcoded so a future relaxation of the gate degrades
        // gracefully instead of silently awarding a full weight.
        $sum += $w['name'] * self::jaroWinkler(
            (string) NameMatcher::normalizeOrg($p->org_name ?? null),
            (string) NameMatcher::normalizeOrg($identity->org_name ?? null),
        );

        [$addrHit, $zipHit] = $this->addressOverlap($p, $identity->identity_id);
        if ($addrHit) {
            $sum += $w['address'];
        }
        if ($zipHit) {
            $sum += $w['zip'];
        }

        if ($this->sharesExclusionRegistry($p, $identity->identity_id)) {
            $sum += $w['exclusion_share'];
        }

        return min(1.0, round($sum, 4));
    }

    /**
     * Normalized federal tax ids carried on a staged row's identifiers.
     *
     * TIN and EIN are pooled into ONE bucket here, even though they are separate
     * id_types at match time. At match time the distinction is kept because a
     * steward reconciling against a public filing needs to know which word the
     * source used; for the question this method answers -- "do these two
     * organizations disagree about their federal tax id?" -- the source's choice
     * of word is not a distinction, and treating them as different would let one
     * organization filed under "EIN" and the same one filed under "TIN" look like
     * a conflict.
     *
     * @param  iterable  $identifiers  rows with ->id_type / ->id_value, or [id_type][id_value]
     * @return list<string>
     */
    private function entityTaxIds($identifiers): array
    {
        $out = [];
        foreach ($identifiers as $ident) {
            $type = is_array($ident) ? ($ident['id_type'] ?? null) : ($ident->id_type ?? null);
            $value = is_array($ident) ? ($ident['id_value'] ?? null) : ($ident->id_value ?? null);

            if (! in_array($type, ['tin', 'ein'], true) || $value === null) {
                continue;
            }

            // Digits only, so 31-4402917 and 314402917 are the same id. A
            // formatting difference between two sources is not a conflict, and
            // treating it as one would hard-no a merge that should happen.
            $digits = preg_replace('/\D+/', '', (string) $value);
            if ($digits !== '') {
                $out[] = $digits;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The same, for an identity already in the hub.
     *
     * Only current identifier versions: a withdrawn tax id is recorded as a
     * version with current = 0 (docs/SCD2.md), and hard-no-ing a merge on a
     * number the organization no longer holds would split it from its own newer
     * records.
     *
     * @return list<string>
     */
    private function entityTaxIdsOf(int $identityId): array
    {
        return $this->entityTaxIds(
            $this->hub()->table('gp_identity_identifier')
                ->where('identity_id', $identityId)->where('current', 1)
                ->whereIn('id_type', ['tin', 'ein'])
                ->get(['id_type', 'id_value'])
        );
    }
```

Extend `warnIfAutoMergeUnreachable()` so the entity set gets the same visibility. Replace the whole
method — the static one-warning-per-process flag stays exactly as it is, and everything from the
`$implemented = …` line onwards moves into the new helper:

```php
    /**
     * One reachability check per weight set, once per process.
     *
     * The static flag is unchanged and still guards BOTH checks: the point of it
     * is that a warning about configuration should appear once in a log, not once
     * per resolved row, and that is as true of two sets as of one.
     */
    private function warnIfAutoMergeUnreachable(): void
    {
        if (self::$warnedUnreachable) {
            return;
        }
        self::$warnedUnreachable = true;

        $this->checkReachable('weights', 'implemented_weights', 'individual');
        $this->checkReachable('entity_weights', 'entity_implemented_weights', 'entity');
    }

    /**
     * score() can only ever add the weights it actually implements. If those sum
     * at or below auto_merge_at, 'auto_match' is unreachable in practice -- every
     * Pass B hit lands in the review band at best -- which is worth a log line
     * rather than silently behaving as though the configured band were in effect.
     *
     * <=, not <. Equality is the case that actually bites: the INDIVIDUAL
     * implemented weights sum to exactly auto_merge_at (0.92), so auto_match is
     * reachable only on a flawless score across every signal at once. A strict <
     * treated that knife-edge as healthy and logged nothing. The ENTITY set sums
     * to 1.00 with every member implemented, so it does not trip this at all --
     * which is the intended difference, not an oversight.
     */
    private function checkReachable(string $weightsKey, string $implementedKey, string $label): void
    {
        $weights = (array) ($this->cfg[$weightsKey] ?? []);
        $implemented = (array) ($this->cfg[$implementedKey] ?? []);

        if ($implemented === [] || $weights === []) {
            return;
        }

        $reachable = array_sum(array_intersect_key($weights, array_flip($implemented)));
        $threshold = (float) ($this->cfg['auto_merge_at'] ?? 1.0);

        if (round($reachable, 4) <= $threshold) {
            Log::warning('probabilistic auto_merge is unreachable with the implemented signals', [
                'signal_set' => $label,
                'max_reachable_score' => round($reachable, 4),
                'auto_merge_at' => $threshold,
                'declared_but_unimplemented' => array_values(
                    array_diff(array_keys($weights), $implemented)
                ),
            ]);
        }
    }
```

- [ ] **Step 6: Pass the identifiers through from the deterministic resolver**

In `DeterministicResolver::resolve()`, the Pass B call currently reads
`$this->probabilistic->match($p, $licenses);`. Change it to:

```php
            // $identifiers comes from plan 5's fetch at the top of resolve().
            // Pass B needs it for the conflicting-TIN hard-no.
            [$pid, $score, $state] = $this->probabilistic->match($p, $licenses, $identifiers);
```

- [ ] **Step 7: Pin the entity weight set**

In `tests/Unit/ProbabilisticScoringTest.php`, add:

```php
    /**
     * The entity set is the mirror image of the individual one: it sums to
     * exactly 1.00 with every member implemented, so auto_match IS reachable.
     * That difference is deliberate (docs/ENTITY-TYPES.md) and this test is what
     * keeps it from being eroded by a later "tidy-up" that adds an unimplemented
     * signal to the list.
     */
    public function test_the_entity_weight_set_is_complete_and_fully_implemented(): void
    {
        $weights = config('golden_profile.probabilistic.entity_weights');
        $implemented = config('golden_profile.probabilistic.entity_implemented_weights');

        $this->assertIsArray($weights, 'entity_weights must be declared');
        $this->assertIsArray($implemented, 'entity_implemented_weights must be declared');

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($weights), $implemented)),
            'every entity weight must be implemented — an entity has no unimplemented signal '.
            'the way individuals have provider_type'
        );

        $this->assertSame(1.0, round(array_sum($weights), 4), 'entity weights must sum to 1.00');

        // An organization is never bound on its name alone.
        $this->assertLessThan(
            (float) config('golden_profile.probabilistic.review_band_floor'),
            (float) $weights['name'],
            'the name weight alone must not reach the review band'
        );

        // auto_match must need essentially everything.
        $this->assertGreaterThanOrEqual(
            (float) config('golden_profile.probabilistic.auto_merge_at'),
            round(array_sum($weights), 4),
            'auto_match must be reachable for an entity'
        );
        $this->assertLessThan(
            (float) config('golden_profile.probabilistic.auto_merge_at'),
            round($weights['name'] + $weights['address'] + $weights['zip'], 4),
            'auto_match must additionally require the exclusion-share signal'
        );
    }

    public function test_the_entity_hard_no_is_configured(): void
    {
        $this->assertTrue((bool) config('golden_profile.probabilistic.hard_no.conflicting_tin'));
    }
```

- [ ] **Step 8: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/EntityPassBTest.php tests/Unit/ProbabilisticScoringTest.php`

Expected: PASS, 7 + 7 tests.

- [ ] **Step 9: Run the full suite and the gate**

Run:
```bash
vendor/bin/phpunit
vendor/bin/pint --dirty
```

Expected: PASS, 0 skipped. `EvalGateTest` unchanged at `true_pairs` 11, precision 1.0000, recall
1.0000. The fixture is all individuals, so `scoreEntity()` and `orgCompatible()` are never called
against it, and the individual weights were not touched. **`ResolverLadderTest` and
`ProbabilisticScoringTest` must also be unchanged** — `match()`'s new third parameter defaults to
`[]`, so any existing two-argument call site still works.

- [ ] **Step 10: Commit**
```bash
git add app/GoldenProfile/Support/NameMatcher.php \
        app/GoldenProfile/Resolution/ProbabilisticResolver.php \
        app/GoldenProfile/Resolution/DeterministicResolver.php \
        config/golden_profile.php \
        tests/Unit/ProbabilisticScoringTest.php \
        tests/Feature/EntityPassBTest.php
git commit -m "feat(entity): give organizations a blocking key, an org-name gate and their own Pass B weights"
```

---

## Task 7: Survivorship and the read model

`org_name` needs a canonical winner across an identity's source rows, entities need their own
authority order, `entity_type` must never be survived, and a shape disagreement among an identity's
rows must be logged rather than silently resolved. The profile read model then has to carry both
columns or the API cannot see them.

**Files:**
- Modify: `app/GoldenProfile/Resolution/Survivorship.php`
- Modify: `app/GoldenProfile/Materialize/ProfileMaterializer.php`
- Modify: `config/golden_profile.php` (`survivorship.field_authority`)
- Test: `tests/Feature/EntitySurvivorshipTest.php`

**Interfaces:**
- Produces: `Survivorship::IDENTITY_FIELDS` gains `'org_name' => 'org_name'`;
  `config('golden_profile.survivorship.field_authority.entity')`;
  `gp_identity_profile.entity_type` / `.org_name` populated.
- Consumes: `Versioner::write()` (plan 3), `entity_type` on `stg_person` and `gp_identity` (Task 2).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EntitySurvivorshipTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
use Tests\Support\HubTestCase;

class EntitySurvivorshipTest extends HubTestCase
{
    public function test_org_name_gets_a_canonical_winner_and_provenance(): void
    {
        $a = $this->stageEntity([
            'org_name' => 'Ashgrove Dialysis Center', 'npi' => 1234567893,
            'source_modified' => '2026-01-01 00:00:00',
        ]);
        $b = $this->stageEntity([
            'org_name' => 'Ashgrove Dialysis Centre of Bethany', 'npi' => 1234567893,
            'source_modified' => '2026-06-01 00:00:00',
        ]);

        $resolver = new DeterministicResolver($this->systemId);
        $id = $resolver->resolve($a);
        $this->assertSame($id, $resolver->resolve($b));

        (new Survivorship)->recompute($id);

        // Same authority (one source system), so recency decides: the newer row.
        $this->assertSame(
            'Ashgrove Dialysis Centre of Bethany',
            $this->hub()->table('gp_identity')
                ->where('identity_id', $id)->where('current', 1)->value('org_name')
        );

        // Both candidates recorded, one canonical -- the doc's entity_names
        // history, at the attribute level.
        $this->assertSame(2, (int) $this->hub()->table('gp_attribute')
            ->where('identity_id', $id)->where('attr_name', 'org_name')->count());
        $this->assertSame(1, (int) $this->hub()->table('gp_attribute')
            ->where('identity_id', $id)->where('attr_name', 'org_name')->where('is_canonical', 1)->count());
        $this->assertSame(1, (int) $this->hub()->table('gp_survivorship_audit')
            ->where('identity_id', $id)->where('attribute_name', 'org_name')->count());
    }

    public function test_a_renamed_organization_keeps_its_old_name_as_a_superseded_version(): void
    {
        // "individual_names / entity_names" from Data Flow by CAMI: the name
        // history is the version chain, not a second table.
        $a = $this->stageEntity(['org_name' => 'Ashgrove Dialysis Center', 'npi' => 1234567893]);
        $id = (new DeterministicResolver($this->systemId))->resolve($a);
        (new Survivorship)->recompute($id);

        $this->hub()->table('stg_person')->where('stg_person_id', $a)
            ->update(['org_name' => 'Larkspur Renal Partners', 'source_modified' => '2026-07-01 00:00:00']);
        (new Survivorship)->recompute($id);

        $this->assertSame('Larkspur Renal Partners', $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->where('current', 1)->value('org_name'));

        $this->assertSame(1, (int) $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->where('current', 0)
            ->where('org_name', 'Ashgrove Dialysis Center')->count());
    }

    public function test_person_only_fields_are_skipped_not_blanked_on_an_entity(): void
    {
        // Pre-existing behaviour (recompute() filters blank candidates and
        // continues), pinned because it is load-bearing and easy to break: if a
        // field with no candidates were written as NULL instead of omitted, the
        // first finalize after a reclassification would erase whatever a
        // previously-person identity legitimately held.
        $org = $this->stageEntity(['org_name' => 'Marrowstone Imaging']);
        $id = (new DeterministicResolver($this->systemId))->resolve($org);

        $this->hub()->table('gp_identity')->where('identity_id', $id)->where('current', 1)
            ->update(['canonical_last' => 'LEFTOVER']);

        (new Survivorship)->recompute($id);

        $this->assertSame('LEFTOVER', $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->where('current', 1)->value('canonical_last'),
            'a field with no staged candidate must be left alone, not nulled');
    }

    public function test_survivorship_never_changes_the_entity_type(): void
    {
        $org = $this->stageEntity(['org_name' => 'Marrowstone Imaging']);
        $id = (new DeterministicResolver($this->systemId))->resolve($org);

        // Force a disagreement: a person-shaped staged row linked to an entity
        // identity. Only reachable through a pre-plan-4 merge or a bad
        // classification, which is exactly when it matters.
        $this->hub()->table('stg_person')->where('stg_person_id', $org)->update([
            'entity_type' => 'individual', 'first_name' => 'Mara', 'last_name' => 'Stone',
        ]);

        (new Survivorship)->recompute($id);

        $this->assertSame('entity', $this->hub()->table('gp_identity')
            ->where('identity_id', $id)->where('current', 1)->value('entity_type'));

        $this->assertSame(1, (int) $this->hub()->table('gp_resolution_log')
            ->where('identity_id', $id)->where('match_key', 'entity_type')
            ->where('actor', 'survivorship')->count(),
            'a shape disagreement is an alarm, and it must be findable');
    }

    public function test_the_entity_authority_order_is_used_for_an_entity(): void
    {
        // nppes outranks state_license for an organization's legal name; for a
        // person the config's identity order puts state_license above
        // scraped_license but below streamline_local, and that order is unchanged.
        $nppes = $this->seedSystem('nppes', 90);
        $state = $this->seedSystem('state_license', 40);

        $a = $this->stageEntity([
            'system_id' => $state, 'org_name' => 'Ashgrove Dialysis Ctr', 'npi' => 1234567893,
            'source_modified' => '2026-08-01 00:00:00',      // newer, but lower authority
        ]);
        $b = $this->stageEntity([
            'system_id' => $nppes, 'org_name' => 'Ashgrove Dialysis Center', 'npi' => 1234567893,
            'source_modified' => '2026-01-01 00:00:00',
        ]);

        $id = (new DeterministicResolver($state))->resolve($a);
        $this->assertSame($id, (new DeterministicResolver($nppes))->resolve($b));

        (new Survivorship)->recompute($id);

        $this->assertSame(
            'Ashgrove Dialysis Center',
            $this->hub()->table('gp_identity')
                ->where('identity_id', $id)->where('current', 1)->value('org_name'),
            'nppes must outrank state_license for an organization name'
        );
    }

    public function test_the_profile_carries_the_type_and_the_org_name(): void
    {
        $org = $this->stageEntity(['org_name' => 'Ashgrove Dialysis Center']);
        $id = (new DeterministicResolver($this->systemId))->resolve($org);
        (new Survivorship)->recompute($id);
        (new ProfileMaterializer)->rebuild($id);

        $row = $this->hub()->table('gp_identity_profile')->where('identity_id', $id)->first();

        $this->assertSame('entity', $row->entity_type);
        $this->assertSame('Ashgrove Dialysis Center', $row->org_name);
        $this->assertNull($row->last_name);
    }
}
```

> `HubTestCase::seedSystem()` appends a `uniqid()` to the `system_code` it inserts, so
> `seedSystem('nppes')` stores something like `nppes-68b9…`. The authority lookup must therefore not
> be an exact `array_search` on the stored code — see Step 4, which is the one place this plan changes
> `authorityRank()`.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/EntitySurvivorshipTest.php`

Expected: FAIL, 5 of 6. `test_org_name_gets_a_canonical_winner_and_provenance` fails with
`Failed asserting that null is identical to 'Ashgrove Dialysis Centre of Bethany'` — `org_name` is
not in `IDENTITY_FIELDS`, so survivorship never writes it.
`test_person_only_fields_are_skipped_not_blanked_on_an_entity` passes already; it is a pin, not a
change.

- [ ] **Step 3: Add the entity authority order to config**

In `config/golden_profile.php`, in `survivorship.field_authority`:

```php
        'field_authority' => [
            'identity' => ['verified', 'nppes', 'streamline_local', 'state_license', 'scraped_license'],
            // Individuals and entities need different rules -- "Golden Profiles:
            // Delivery Plan & Checklist" §3 asks for this explicitly. The
            // difference is real, not cosmetic: a state licensing board is
            // authoritative about a PERSON's credential and says little about an
            // organization's legal name, while NPPES's type-2 registry and
            // SAM.gov are registries OF organizations. Survivorship::recompute()
            // selects this group when the identity is an entity; 'identity' is
            // unchanged and still governs every existing row.
            'entity' => ['verified', 'nppes', 'sam', 'streamline_local', 'state_license', 'scraped_license'],
            'license' => ['state_license', 'nppes', 'scraped_license', 'streamline_local'],
            'address' => ['nppes', 'state_license', 'streamline_local', 'scraped_license'],
            'exclusion' => ['leie', 'sam', 'state_exclusion', 'streamline_local'],
        ],
```

- [ ] **Step 4: Teach `Survivorship` about entities**

In `app/GoldenProfile/Resolution/Survivorship.php`, extend `IDENTITY_FIELDS`:

```php
    /** identity field <- staged column */
    private const IDENTITY_FIELDS = [
        'canonical_first' => 'first_name',
        'canonical_middle' => 'middle_name',
        'canonical_last' => 'last_name',
        'canonical_suffix' => 'name_suffix',
        'canonical_dob' => 'date_of_birth',
        'npi' => 'npi',
        'upin' => 'upin',
        'dea_number' => 'dea_number',
        'ssn_hash' => 'ssn_hash',
        // The organization's name. It needs a canonical winner for the same
        // reason canonical_last does -- several source rows spell it differently
        // and one of them has to win -- and the resulting gp_attribute /
        // gp_survivorship_audit rows ARE the doc's entity_names history at the
        // attribute level.
        //
        // entity_type is deliberately NOT here. It is a classification, not a
        // fact about the provider: letting authority-plus-recency decide it would
        // make an identity's type oscillate between rebuilds and flip every
        // deterministic tier predicate with it. Its only writers are
        // DeterministicResolver::createIdentity() and gp:entity-reclassify.
        //
        // The six person-only fields above stay in the list unchanged. For an
        // entity, every candidate for them is blank, so the isBlank() filter
        // below empties the candidate set and the field is SKIPPED -- absent from
        // $update rather than written as NULL. That distinction matters: writing
        // NULL would erase whatever a reclassified identity legitimately held.
        'org_name' => 'org_name',
    ];
```

Then, in `recompute()`, pick the authority group by type and log a disagreement. Replace the block
from the `$rows->isEmpty()` guard down to the start of the `foreach (self::IDENTITY_FIELDS ...)`
loop:

```php
        if ($rows->isEmpty()) {
            return;
        }

        // The identity's own type, not the staged rows' -- the staged rows are
        // evidence and the identity is the decision. Reading the current version
        // (plan 3) rather than any version, because a superseded version's type
        // is by definition not the one in force.
        $entityType = (string) ($hub->table('gp_identity')
            ->where('identity_id', $identityId)->where('current', 1)
            ->value('entity_type') ?? 'individual');

        // A shape disagreement among an identity's linked rows means the resolver
        // bound across types -- which the entity_type predicate on every tier is
        // supposed to prevent -- so it is either a merge that predates plan 4 or a
        // classification error. Either way it is an ALARM, not a value to
        // survive: recomputing the type from the loudest source row would let one
        // bad row flip an identity and, with it, every tier predicate. Log it and
        // leave the stored type alone. gp:entity-audit counts these hub-wide, and
        // plan 6 owns the steward surface that acts on them.
        $shapes = $rows->pluck('entity_type')->map(fn ($t) => $t ?: 'individual')->unique()->values();
        if ($shapes->count() > 1) {
            $hub->table('gp_resolution_log')->insert([
                'action' => 'override',
                'identity_id' => $identityId,
                'affected_ids' => json_encode(['link_ids' => $rows->pluck('link_id')->all()]),
                'match_key' => 'entity_type',
                'reason' => 'linked source rows disagree on record type ('.$shapes->implode(', ').
                    '); stored type kept as '.$entityType,
                'actor' => 'survivorship',
                'created_at' => now(),
            ]);
        }

        // 'entity' for an organization, 'identity' for a person. Both groups are
        // an authority ORDER over source system_codes, not a field list.
        $order = $this->authority[$entityType === 'entity' ? 'entity' : 'identity'] ?? [];
        $now = now();
        $update = [];
        $attrRows = [];
        $auditRows = [];
```

(the `foreach (self::IDENTITY_FIELDS as $canonical => $srcCol)` loop below is unchanged, and so is
everything after it — including the final `$hub->table('gp_identity')->…` write, which plan 3
already replaced with a `Versioner::write()` call.)

Finally, make `authorityRank()` tolerant of the test harness's suffixed system codes:

```php
    private static function authorityRank(array $order, ?string $code, ?int $reliabilityRank): int
    {
        $idx = array_search($code, $order, true);
        if ($idx !== false) {
            return $idx;                       // explicit authority order wins
        }

        // HubTestCase::seedSystem() stores 'nppes-68b9c1…' so that repeated calls
        // do not collide on system_code's unique index, and a real deployment may
        // equally register 'nppes-v2'. An exact match alone would silently drop
        // every such system to the reliability_rank fallback, which is how an
        // authority-order test can pass for the wrong reason. Prefix matching
        // keeps the configured order meaningful; the longest matching prefix wins
        // so 'state_license' cannot be claimed by a shorter entry.
        $best = false;
        foreach ($order as $i => $configured) {
            if (str_starts_with((string) $code, $configured.'-')
                && ($best === false || strlen($configured) > strlen($order[$best]))) {
                $best = $i;
            }
        }
        if ($best !== false) {
            return $best;
        }

        // unknown systems ranked after listed ones, best reliability_rank first
        return 100 - (int) ($reliabilityRank ?? 50);
    }
```

> This is a change to shared behaviour, not entity-specific, so it deserves the callout: it makes
> `seedSystem('nppes')` actually rank as `nppes` rather than falling to `reliability_rank`. Existing
> tests that relied on the fallback for a suffixed code would change behaviour — Step 6's full-suite
> run is what catches that. `SetFinalizer::authorityRankSql()` is the set-based mirror of this
> method and is behind plan 3a's guard; the prefix rule is added to the plan-3b obligation list in
> `docs/ENTITY-TYPES.md` so the two do not diverge.

- [ ] **Step 5: Carry both columns into the profile**

In `app/GoldenProfile/Materialize/ProfileMaterializer::rebuild()`, in the
`gp_identity_profile` `updateOrInsert()` payload, add the two fields immediately after
`identity_uuid`:

```php
                'identity_uuid' => $identity->identity_uuid,
                // The API needs both: IdentityProfileResource emits them, and
                // CredentialSearchController::resolveIdentity() resolves an
                // organization by (entity_type, org_name) -- it cannot use
                // first_name/last_name, which are NULL on every entity row.
                'entity_type' => $identity->entity_type ?? 'individual',
                'org_name' => $identity->org_name ?? null,
                'first_name' => $identity->canonical_first,
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/EntitySurvivorshipTest.php`

Expected: PASS, 6 tests.

- [ ] **Step 7: Run the full suite and the gate**

Run:
```bash
vendor/bin/phpunit
vendor/bin/pint --dirty
```

Expected: PASS, 0 skipped. `EvalGateTest` unchanged at `true_pairs` 11 — `EvalRunner` never calls
`Survivorship` or `ProfileMaterializer`, it calls `resolve()` only, so nothing in this task can move
the gate. If a *different* test moved, it is almost certainly the `authorityRank()` prefix change;
check whether that test was asserting the fallback for a suffixed code.

- [ ] **Step 8: Commit**
```bash
git add app/GoldenProfile/Resolution/Survivorship.php \
        app/GoldenProfile/Materialize/ProfileMaterializer.php \
        config/golden_profile.php \
        tests/Feature/EntitySurvivorshipTest.php
git commit -m "feat(entity): survive org_name, add the entity authority order, and log type disagreements"
```

---

## Task 8: The API — both endpoints, and what it does to the CAMI contract

Both endpoints are person-shaped. One of them half-works for entities by accident and the other
cannot resolve one at all. This task states exactly which, fixes both, and says what breaks.

### The measured state of the contract

**`identity-search` already finds organizations, by accident, and this plan must not break it.**
`IdentitySearchRequest` requires `last_name`; the controller ORs a canonical-name leg against
`AliasIndexer::identityIdsFor($last)`. Business names reach `gp_identity_alias` as
`alias_part = 'first'` rows, so passing an organization's name as `last_name` finds it through the
alias leg. That is the only entity lookup that works today, and it is why Task 1 ruled against
touching `gp_identity_alias`.

**`credential-search` cannot resolve an organization at all.** `CredentialSearchRequest` makes both
`first_name` and `last_name` `required`, and `resolveIdentity()` matches both exactly against
`gp_identity_profile`, with no alias fallback. Every entity row has NULL in both, so the endpoint
returns 404 for every organization. Verified by reading both files.

### What changes, and whether it breaks CAMI

| Change | Breaking? |
|---|---|
| `IdentityProfileResource` emits `entity_type` and `org_name` | **No.** Purely additive. A JSON consumer that reads named keys is unaffected |
| `identity-search`: `last_name` becomes `required_without:org_name`, `org_name` added | **No.** A caller sending only `last_name` gets exactly what it got before. The new `org_name` leg returns the same identities the alias leg already returned, deduplicated by the same profile query |
| `credential-search`: `first_name` / `last_name` become `required_without:org_name`, `org_name` added, `resolveIdentity()` gains an entity branch | **Contract change.** Backward compatible for callers — they always send both — but the documented contract ("Contract confirmed with CAMI 2026-07-20: required = registry, first_name, last_name") no longer holds, and CAMI must be told before it can use the new path. Written as a human task below, not slipped in silently |
| `credential-search` response `identity` block gains `entity_type` and `org_name` | **No.** Additive |

One pre-existing oddity is inherited rather than fixed, and named so nobody thinks it was missed:
in `identity-search`, `first_name` narrows only the canonical leg. The alias leg (and now the
`org_name` leg) is an outer `OR`, so supplying `first_name` does not narrow an alias or organization
hit. That is how the endpoint already behaves; changing it would change results for existing callers
and belongs in its own decision.

**Files:**
- Modify: `app/Http/Resources/IdentityProfileResource.php`
- Modify: `app/Http/Requests/IdentitySearchRequest.php`
- Modify: `app/Http/Requests/CredentialSearchRequest.php`
- Modify: `app/Http/Controllers/Api/V1/IdentitySearchController.php`
- Modify: `app/Http/Controllers/Api/V1/CredentialSearchController.php`
- Test: `tests/Feature/EntityApiTest.php`

**Interfaces:**
- Produces: `entity_type` and `org_name` in both endpoints' responses; an `org_name` input on both
  requests.
- Consumes: `gp_identity_profile.entity_type` / `.org_name` (Task 7), `idx_org_name` (Task 2).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EntityApiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Resolution\DeterministicResolver;
use App\GoldenProfile\Resolution\Survivorship;
use Tests\Support\HubTestCase;

class EntityApiTest extends HubTestCase
{
    /** Resolve, finalize and materialize one organization; returns identity_id. */
    private function publishedEntity(string $name = 'Ashgrove Dialysis Center'): int
    {
        $stg = $this->stageEntity(['org_name' => $name, 'state' => 'OK', 'zip' => '73008']);
        $id = (new DeterministicResolver($this->systemId))->resolve($stg);
        (new Survivorship)->recompute($id);
        (new ProfileMaterializer)->rebuild($id);

        return $id;
    }

    private function publishedPerson(): int
    {
        $stg = $this->stagePerson([
            'first_name' => 'Robert', 'last_name' => 'Smith', 'date_of_birth' => '1970-04-02',
        ]);
        $id = (new DeterministicResolver($this->systemId))->resolve($stg);
        (new Survivorship)->recompute($id);
        (new ProfileMaterializer)->rebuild($id);

        return $id;
    }

    public function test_identity_search_finds_an_organization_by_org_name(): void
    {
        $id = $this->publishedEntity();

        $response = $this->postJson('/api/v1/identity-search', ['org_name' => 'Ashgrove Dialysis Center']);

        $response->assertOk();
        $this->assertSame([$id], array_column($response->json('data'), 'identity_id'));
        $this->assertSame('entity', $response->json('data.0.entity_type'));
        $this->assertSame('Ashgrove Dialysis Center', $response->json('data.0.org_name'));
        $this->assertNull($response->json('data.0.last_name'));
    }

    public function test_identity_search_still_works_with_last_name_alone(): void
    {
        // The pre-plan-4 contract. Callers send last_name and nothing else.
        $id = $this->publishedPerson();

        $response = $this->postJson('/api/v1/identity-search', ['last_name' => 'Smith']);

        $response->assertOk();
        $this->assertContains($id, array_column($response->json('data'), 'identity_id'));
        $this->assertSame('individual', $response->json('data.0.entity_type'));
        $this->assertNull($response->json('data.0.org_name'));
    }

    public function test_identity_search_still_finds_an_organization_through_the_alias_index(): void
    {
        // The route that works TODAY: pass the organization's name as last_name
        // and the alias index finds it. Business names reach gp_identity_alias as
        // alias_part = 'first' rows. This must keep working -- it is the reason
        // gp_identity_alias was left alone.
        $stg = $this->stageEntity(['org_name' => 'Marrowstone Imaging']);
        $this->hub()->table('stg_person_alias')->insert([
            'stg_person_id' => $stg, 'alias_type' => 'business',
            'first_name' => 'Marrowstone Imaging', 'last_name' => null,
        ]);
        $id = (new DeterministicResolver($this->systemId))->resolve($stg);
        (new Survivorship)->recompute($id);
        (new ProfileMaterializer)->rebuild($id);

        $response = $this->postJson('/api/v1/identity-search', ['last_name' => 'Marrowstone Imaging']);

        $response->assertOk();
        $this->assertContains($id, array_column($response->json('data'), 'identity_id'));
    }

    public function test_identity_search_rejects_a_request_with_neither_name(): void
    {
        $this->postJson('/api/v1/identity-search', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['last_name']);
    }

    public function test_credential_search_resolves_an_organization_by_org_name(): void
    {
        // Before plan 4 this was a 404 for every organization: first_name and
        // last_name were both required and matched exactly against columns that
        // are NULL on every entity row.
        $id = $this->publishedEntity();

        $response = $this->postJson('/api/v1/credential-search', [
            'registry' => 'CA-RN',
            'org_name' => 'Ashgrove Dialysis Center',
        ]);

        $response->assertOk();
        $this->assertSame($id, $response->json('identity.identity_id'));
        $this->assertSame('entity', $response->json('identity.entity_type'));
        $this->assertSame('Ashgrove Dialysis Center', $response->json('identity.org_name'));
    }

    public function test_credential_search_still_requires_a_name_of_some_kind(): void
    {
        $this->postJson('/api/v1/credential-search', ['registry' => 'CA-RN'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    public function test_credential_search_by_person_name_is_unchanged(): void
    {
        $id = $this->publishedPerson();

        $response = $this->postJson('/api/v1/credential-search', [
            'registry' => 'CA-RN', 'first_name' => 'Robert', 'last_name' => 'Smith',
        ]);

        $response->assertOk();
        $this->assertSame($id, $response->json('identity.identity_id'));
        $this->assertSame('individual', $response->json('identity.entity_type'));
    }

    public function test_credential_search_by_org_name_does_not_return_a_person(): void
    {
        // The entity branch filters entity_type, so an organization name that
        // happens to equal somebody's surname cannot resolve to the person.
        $this->publishedPerson();
        $stg = $this->stageEntity(['org_name' => 'Smith']);
        $orgId = (new DeterministicResolver($this->systemId))->resolve($stg);
        (new Survivorship)->recompute($orgId);
        (new ProfileMaterializer)->rebuild($orgId);

        $response = $this->postJson('/api/v1/credential-search', [
            'registry' => 'CA-RN', 'org_name' => 'Smith',
        ]);

        $response->assertOk();
        $this->assertSame($orgId, $response->json('identity.identity_id'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/EntityApiTest.php`

Expected: FAIL, 5 of 8. `test_identity_search_finds_an_organization_by_org_name` fails with a 422 on
the missing `last_name`; `test_credential_search_resolves_an_organization_by_org_name` fails with a
422 on the missing `first_name` and `last_name`.

- [ ] **Step 3: Emit the two fields**

In `app/Http/Resources/IdentityProfileResource.php`, add the two fields immediately after
`identity_uuid` and extend the docblock:

```php
/**
 * Shapes a gp_identity_profile row for the API. Never exposes ssn_hash or
 * encrypted SSN — ssn_last_four only.
 *
 * entity_type says which of the name shapes below is meaningful. For an
 * organization, first_name / middle_name / last_name / date_of_birth /
 * ssn_last_four / dea_number are ALL null and org_name carries the name. Both
 * fields are additive, so an existing consumer reading named keys is unaffected;
 * a consumer that renders last_name for an organization already rendered a blank
 * before plan 4, and org_name is how it can stop.
 */
class IdentityProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'identity_id' => (int) $this->identity_id,
            'identity_uuid' => $this->identity_uuid,
            'entity_type' => $this->entity_type ?? 'individual',
            'org_name' => $this->org_name,
            'first_name' => $this->first_name,
```

- [ ] **Step 4: Accept `org_name` on both requests**

`app/Http/Requests/IdentitySearchRequest.php`:

```php
    /**
     * last_name is required_without:org_name rather than required, so an
     * organization can be searched by its own name instead of having it passed
     * as a surname.
     *
     * This is NOT a breaking change: a caller sending only last_name gets
     * exactly what it got before, including the alias-index leg that has been the
     * only working entity lookup. The org_name leg the controller adds returns
     * the same identities the alias leg already returned, so the result set for
     * an existing caller does not move either.
     */
    public function rules(): array
    {
        return [
            'last_name' => ['required_without:org_name', 'nullable', 'string', 'max:100'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'org_name' => ['required_without:last_name', 'nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
```

`app/Http/Requests/CredentialSearchRequest.php` — replace the docblock and the first three rules:

```php
/**
 * Contract confirmed with CAMI 2026-07-20:
 * required = registry, first_name, last_name; optional narrowers = license_number,
 * license_type, dob, ssn.
 *
 * AMENDED by plan 4 (individual vs entity), and CAMI has to be told:
 * first_name / last_name are now required_without:org_name, and org_name is
 * accepted. Backward compatible for every existing caller -- they always send
 * both names -- but the contract as documented above no longer holds.
 *
 * The reason it had to change: before this, the endpoint returned 404 for EVERY
 * organization. Both name fields were required and resolveIdentity() matched
 * them exactly against gp_identity_profile columns that are NULL on every entity
 * row, so there was no input that could resolve one.
 */
class CredentialSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registry' => ['required', 'string', 'max:255'],
            'first_name' => ['required_without:org_name', 'nullable', 'string', 'max:100'],
            'last_name' => ['required_without:org_name', 'nullable', 'string', 'max:100'],
            'org_name' => ['required_without_all:first_name,last_name', 'nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'license_type' => ['nullable', 'string', 'max:100'],
            // Both narrow identity resolution AND gate credential matches whose
            // scrape recorded an SSN or DOB — see CredentialSelector.
            'dob' => ['nullable', 'date_format:Y-m-d'],
            // 9 digits, dashes or spaces optional. Previously max:32 with no shape
            // check, so a typo silently became a non-matching hash instead of an
            // error the caller could see.
            'ssn' => ['nullable', 'string', 'max:32', 'regex:/^\d{3}[- ]?\d{2}[- ]?\d{4}$/'],
        ];
    }
```

- [ ] **Step 5: Add the `org_name` leg to `identity-search`**

In `app/Http/Controllers/Api/V1/IdentitySearchController::__invoke()`, replace the three input lines
and the `$q` construction:

```php
        $last = $request->filled('last_name') ? trim($request->input('last_name')) : null;
        $first = $request->filled('first_name') ? trim($request->input('first_name')) : null;
        $orgName = $request->filled('org_name') ? trim($request->input('org_name')) : null;
        $perPage = (int) ($request->input('per_page', 25));

        // Alias hits come from gp_identity_alias, resolved to ids up front.
        //
        // This leg used to be LOWER(aliases) LIKE '%"last":"x"%' over the JSON
        // rollup, which was wrong twice over. It matched nothing at all — MySQL
        // normalises stored JSON with a space after the colon ("last": "Smith"), so
        // the pattern could not match its own row — and a leading-wildcard LIKE on
        // a JSON column cannot use an index, so OR-ing it with the canonical leg
        // forced a full scan of all 13.6M rows for the statement as a whole.
        //
        // Both name inputs are probed against it. An organization's business name
        // reaches gp_identity_alias as an alias_part = 'first' row, so passing it
        // as last_name has been the ONLY working entity lookup and must keep
        // working; passing it as org_name should find the same identity through
        // both this leg and the org_name leg below.
        $aliasIds = [];
        foreach (array_filter([$last, $orgName]) as $name) {
            $aliasIds = array_merge($aliasIds, $this->aliasIndexer->identityIdsFor($name));
        }
        $aliasIds = array_values(array_unique($aliasIds));

        $q = GpIdentityProfile::query()->where(function ($outer) use ($last, $first, $orgName, $aliasIds) {
            // Canonical name. Plain comparisons, not LOWER(): the columns are
            // utf8mb4_unicode_ci so LOWER() only made this leg non-sargable
            // (EXPLAIN on this leg alone: type=ref key=idx_name_dob rows=116,
            // versus type=index rows=13661726 with LOWER()).
            if ($last !== null) {
                $outer->where(function ($w) use ($last, $first) {
                    $w->where('last_name', $last);
                    if ($first) {
                        $w->where('first_name', $first);
                    }
                });
            }

            // The organization leg. Backed by idx_org_name (org_name) on the
            // profile table, so it is an index read like the other two and cannot
            // poison the plan for the OR as a whole. entity_type is NOT filtered
            // here: org_name is null on every individual row, so an equality
            // predicate on it already selects entities, and adding the predicate
            // would only make the index a prefix match for no benefit.
            if ($orgName !== null) {
                $outer->orWhere('org_name', $orgName);
            }

            if ($aliasIds !== []) {
                // Primary-key lookups on the profile table.
                $outer->orWhereIn('identity_id', $aliasIds);
            }
        })
            // identity_id tiebreak: record_count alone leaves equal-ranked rows in
            // arbitrary storage order, which makes pagination unstable — a row can
            // repeat on page 2 or be skipped entirely between requests.
            ->orderByDesc('record_count')->orderBy('identity_id');
```

> The `if ($last !== null)` guard is load-bearing. `last_name` is now optional, and an
> unguarded `where('last_name', null)` becomes `last_name is null`, which matches **every
> organization row in the hub** — 13.6M rows through a two-phase paginate. The request rule
> guarantees at least one of the two is present, so the outer `where` closure is never empty.

- [ ] **Step 6: Add the entity branch to `credential-search`**

In `app/Http/Controllers/Api/V1/CredentialSearchController::resolveIdentity()`, replace the opening
query construction:

```php
    private function resolveIdentity(CredentialSearchRequest $r): ?GpIdentityProfile
    {
        // Plain column comparisons on purpose — same reasoning as
        // DeterministicResolver::matchDeterministic(). last_name/first_name are
        // utf8mb4_unicode_ci (already case-insensitive) and date_of_birth is a
        // DATE, so LOWER()/whereDate() only made the predicate non-sargable:
        // idx_name_dob(last_name, first_name, date_of_birth) was reduced to a full
        // index scan on EVERY request (EXPLAIN: type=index key=idx_name_dob
        // rows=13661726 vs type=ref rows=1 without the wrapping).
        //
        // Two mutually exclusive branches, because an organization has no
        // first_name or last_name at all: matching them would compare against
        // NULL on every entity row, which is why this endpoint returned 404 for
        // every organization before plan 4.
        if ($r->filled('org_name')) {
            // entity_type IS filtered here, unlike identity-search's org_name
            // leg: this endpoint returns ONE identity and acts on its credential
            // links, so a same-named individual resolving instead of the
            // organization would attach the wrong provider's credentials to a
            // compliance answer. idx_org_name (org_name) carries the probe.
            $q = GpIdentityProfile::query()
                ->where('entity_type', 'entity')
                ->where('org_name', trim($r->input('org_name')));
        } else {
            $q = GpIdentityProfile::query()
                ->where('entity_type', 'individual')
                ->where('last_name', $r->input('last_name'))
                ->where('first_name', $r->input('first_name'));

            if ($r->filled('dob')) {
                $q->where('date_of_birth', $r->date('dob')->toDateString());
            }

            // Only when a key exists. candidateHashes() returns [] without one, and
            // whereIn('ssn_hash', []) matches NOTHING — so an unavailable key would
            // turn every SSN-bearing request into a 404 rather than simply not
            // narrowing. __invoke() has already recorded the warning for this case.
            if ($r->filled('ssn') && $this->ssnHasher->available()) {
                $q->whereIn('ssn_hash', $this->ssnHasher->candidateHashes($r->input('ssn')));
            }
        }
```

The `license_number` narrower and everything below it stay exactly as they are — a licence number
narrows either branch. Then extend the final hydrate's column list so the response can echo the two
new fields:

```php
        return GpIdentityProfile::query()
            ->select('identity_id', 'identity_uuid', 'entity_type', 'org_name',
                'first_name', 'last_name', 'ssn_last_four')
            ->find($matches[0]);
```

And in `__invoke()`'s response, extend the `identity` block:

```php
            'identity' => [
                'identity_id' => (int) $identity->identity_id,
                'identity_uuid' => $identity->identity_uuid,
                'entity_type' => $identity->entity_type ?? 'individual',
                'org_name' => $identity->org_name,
                'first_name' => $identity->first_name,
                'last_name' => $identity->last_name,
                'ssn_last_four' => $identity->ssn_last_four,
            ],
```

- [ ] **Step 7: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/EntityApiTest.php`

Expected: PASS, 8 tests.

- [ ] **Step 8: Run the full suite and the gate**

Run:
```bash
vendor/bin/phpunit
vendor/bin/pint --dirty
```

Expected: PASS, 0 skipped. `EvalGateTest` unchanged at `true_pairs` 11 — the API is not on the
resolver path.

- [ ] **Step 9: Record the contract change for CAMI**

Add to `docs/ENTITY-TYPES.md`, at the end:

```markdown
## The API contract change — HUMAN TASK

`credential-search`'s documented contract was "required = registry, first_name,
last_name" (confirmed with CAMI 2026-07-20). Plan 4 relaxed both name fields to
`required_without:org_name` and added `org_name`, because before that there was
**no input that could resolve an organization** — both names were required and
matched against columns that are NULL on every entity row, so the endpoint
returned 404 for every organization in the hub.

Every existing caller keeps working unchanged: they send both names, and the
individual branch is byte-identical to the previous behaviour apart from an added
`entity_type = 'individual'` predicate, which every individual row satisfies.

**Tell CAMI before they can use it:**

- `POST /api/v1/credential-search` accepts `org_name` in place of
  `first_name` + `last_name`.
- Its `identity` response block gained `entity_type` and `org_name`.
- `POST /api/v1/identity-search` accepts `org_name`; `last_name` is now optional
  when `org_name` is given. `IdentityProfileResource` gained `entity_type` and
  `org_name`.
- For an organization, `first_name`, `middle_name`, `last_name`,
  `date_of_birth`, `ssn_last_four` and `dea_number` are all null. A screen
  rendering `last_name` for an organization was already rendering a blank before
  plan 4; `org_name` is how it stops.

Inherited and NOT changed: in `identity-search`, `first_name` narrows only the
canonical-name leg. The alias leg and the new `org_name` leg are outer ORs, so
supplying `first_name` does not narrow an alias or organization hit. That is
pre-existing behaviour; changing it would move results for existing callers and
belongs in its own decision.
```

- [ ] **Step 10: Commit**
```bash
git add app/Http/Resources/IdentityProfileResource.php \
        app/Http/Requests/IdentitySearchRequest.php \
        app/Http/Requests/CredentialSearchRequest.php \
        app/Http/Controllers/Api/V1/IdentitySearchController.php \
        app/Http/Controllers/Api/V1/CredentialSearchController.php \
        docs/ENTITY-TYPES.md \
        tests/Feature/EntityApiTest.php
git commit -m "feat(api): resolve and shape organizations on both endpoints"
```

---

## Task 9: `gp:entity-audit` and `gp:entity-reclassify`

The riskiest part of the plan, built as two commands so the measurement can happen without the
change. `gp:entity-audit` writes nothing at all; `gp:entity-reclassify` is `--dry-run` by default,
touches only unanimous evidence, and writes every change through `Versioner` so it is reversible.

**Files:**
- Create: `app/Console/Commands/GpEntityAudit.php`
- Create: `app/Console/Commands/GpEntityReclassify.php`
- Test: `tests/Feature/EntityReclassifyTest.php`

**Interfaces:**
- Produces:
  - `gp:entity-audit {--limit=20 : mixed identities to list}` → prints buckets, cross-type key
    collisions and the entity block-size distribution; exit 0 always (it is a report).
  - `gp:entity-reclassify {--apply} {--chunk=500} {--limit=}` → reclassifies `unanimous_entity`
    identities; without `--apply` it prints what it would do and changes nothing.
  - `GpEntityAudit::buckets(): array{unanimous_entity:int,unanimous_individual:int,mixed:int,no_signal:int}`
  - `GpEntityAudit::mixedIdentityIds(int $limit): list<int>`
- Consumes: `Versioner::write()` (plan 3), `BlockKey::for()` (Task 3).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EntityReclassifyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Console\Commands\GpEntityAudit;
use App\GoldenProfile\Resolution\DeterministicResolver;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\HubTestCase;

/**
 * Reclassifying existing history.
 *
 * The scenario every test here builds is a hub as it looks BEFORE plan 4: the
 * migration classified every row 'individual', and some of those rows are
 * organizations whose names arrived as stg_person_alias rows with
 * alias_type = 'business'.
 */
class EntityReclassifyTest extends HubTestCase
{
    /**
     * Stage an organization the way the hub held one before plan 4: staged as an
     * individual with no name, its business name present only as an alias.
     *
     * @return array{0:int,1:int} [stg_person_id, identity_id]
     */
    private function legacyOrg(string $name): array
    {
        $stg = $this->stagePerson([
            'entity_type' => 'individual',            // as the pre-plan-4 connector left it
            'org_name' => null,
            'first_name' => null, 'middle_name' => null, 'last_name' => null,
            'date_of_birth' => null, 'block_key' => null,
            'state' => 'OK', 'zip' => '73008',
        ]);
        $this->hub()->table('stg_person_alias')->insert([
            'stg_person_id' => $stg, 'alias_type' => 'business',
            'first_name' => $name, 'last_name' => null,
        ]);

        return [$stg, (new DeterministicResolver($this->systemId))->resolve($stg)];
    }

    private function legacyPerson(): array
    {
        $stg = $this->stagePerson(['first_name' => 'Robert', 'last_name' => 'Smith']);

        return [$stg, (new DeterministicResolver($this->systemId))->resolve($stg)];
    }

    public function test_the_audit_buckets_identities_by_the_shape_of_their_linked_rows(): void
    {
        [, $orgId] = $this->legacyOrg('Ashgrove Dialysis Center');
        [, $personId] = $this->legacyPerson();

        // A mixed identity: an organization and a person already welded together.
        // Reachable through the licence tier before Task 4 closed it.
        [$mixedStg, $mixedId] = $this->legacyOrg('Marrowstone Imaging');
        $extra = $this->stagePerson(['first_name' => 'Mara', 'last_name' => 'Stone']);
        $this->hub()->table('gp_source_link')->insert([
            'identity_id' => $mixedId, 'system_id' => $this->systemId,
            'source_table' => 'employees',
            'source_id' => (int) $this->hub()->table('stg_person')
                ->where('stg_person_id', $extra)->value('source_id'),
            'account_id' => 1, 'employeelist_id' => 1,
            'match_method' => 'deterministic', 'match_key' => 'license_registry',
            'match_score' => 0.99, 'match_state' => 'auto_match', 'is_pinned' => 0,
            'linked_at' => now(),
        ]);

        $buckets = (new GpEntityAudit)->buckets();

        $this->assertSame(1, $buckets['unanimous_entity']);
        $this->assertSame(1, $buckets['mixed']);
        $this->assertGreaterThanOrEqual(1, $buckets['unanimous_individual']);

        $this->assertSame([$mixedId], (new GpEntityAudit)->mixedIdentityIds(20));
        $this->assertNotSame($orgId, $personId);          // sanity
    }

    public function test_the_audit_writes_nothing(): void
    {
        [, $orgId] = $this->legacyOrg('Ashgrove Dialysis Center');

        $before = [
            'identity' => $this->hub()->table('gp_identity')->count(),
            'log' => $this->hub()->table('gp_resolution_log')->count(),
            'type' => $this->hub()->table('gp_identity')
                ->where('identity_id', $orgId)->where('current', 1)->value('entity_type'),
        ];

        Artisan::call('gp:entity-audit');

        $this->assertSame($before['identity'], $this->hub()->table('gp_identity')->count());
        $this->assertSame($before['log'], $this->hub()->table('gp_resolution_log')->count());
        $this->assertSame($before['type'], $this->hub()->table('gp_identity')
            ->where('identity_id', $orgId)->where('current', 1)->value('entity_type'));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        [, $orgId] = $this->legacyOrg('Ashgrove Dialysis Center');

        Artisan::call('gp:entity-reclassify');

        $this->assertSame('individual', $this->hub()->table('gp_identity')
            ->where('identity_id', $orgId)->where('current', 1)->value('entity_type'));
        $this->assertStringContainsString('would reclassify 1', Artisan::output());
    }

    public function test_apply_reclassifies_a_unanimous_entity_and_versions_the_change(): void
    {
        [$stg, $orgId] = $this->legacyOrg('Ashgrove Dialysis Center');

        Artisan::call('gp:entity-reclassify', ['--apply' => true]);

        $current = $this->hub()->table('gp_identity')
            ->where('identity_id', $orgId)->where('current', 1)->first();

        $this->assertSame('entity', $current->entity_type);
        $this->assertSame('Ashgrove Dialysis Center', $current->org_name);
        $this->assertSame(2, (int) $current->version_no);

        // Reversible: the pre-reclassification version survives.
        $previous = $this->hub()->table('gp_identity')
            ->where('identity_id', $orgId)->where('version_no', 1)->first();
        $this->assertSame(0, (int) $previous->current);
        $this->assertSame('individual', $previous->entity_type);

        // Findable: one log row per reclassified identity.
        $this->assertSame(1, (int) $this->hub()->table('gp_resolution_log')
            ->where('identity_id', $orgId)->where('actor', 'gp:entity-reclassify')->count());

        // The staged row moves too, so future syncs of it infer the same type and
        // Pass B gets an entity block key. Staging is not versioned, so this is a
        // plain UPDATE (docs/SCD2.md).
        $staged = $this->hub()->table('stg_person')->where('stg_person_id', $stg)->first();
        $this->assertSame('entity', $staged->entity_type);
        $this->assertSame('Ashgrove Dialysis Center', $staged->org_name);
        $this->assertSame('E:'.soundex('Ashgrove').'|OK', $staged->block_key);
    }

    public function test_apply_never_touches_a_mixed_identity(): void
    {
        // The single most important assertion in this task. A mixed identity is
        // an organization already merged with a person; there is no split
        // machinery in this codebase (gp_identity.status has a 'split' value
        // nothing ever writes), so reclassifying one would leave a person's
        // records permanently attached to an entity with no undo.
        [, $mixedId] = $this->legacyOrg('Marrowstone Imaging');
        $extra = $this->stagePerson(['first_name' => 'Mara', 'last_name' => 'Stone']);
        $this->hub()->table('gp_source_link')->insert([
            'identity_id' => $mixedId, 'system_id' => $this->systemId,
            'source_table' => 'employees',
            'source_id' => (int) $this->hub()->table('stg_person')
                ->where('stg_person_id', $extra)->value('source_id'),
            'account_id' => 1, 'employeelist_id' => 1,
            'match_method' => 'deterministic', 'match_key' => 'license_registry',
            'match_score' => 0.99, 'match_state' => 'auto_match', 'is_pinned' => 0,
            'linked_at' => now(),
        ]);

        Artisan::call('gp:entity-reclassify', ['--apply' => true]);

        $this->assertSame('individual', $this->hub()->table('gp_identity')
            ->where('identity_id', $mixedId)->where('current', 1)->value('entity_type'));
        $this->assertSame(1, (int) $this->hub()->table('gp_identity')
            ->where('identity_id', $mixedId)->count(), 'no version was minted');
    }

    public function test_apply_never_touches_a_person(): void
    {
        [, $personId] = $this->legacyPerson();

        Artisan::call('gp:entity-reclassify', ['--apply' => true]);

        $this->assertSame('individual', $this->hub()->table('gp_identity')
            ->where('identity_id', $personId)->where('current', 1)->value('entity_type'));
    }

    public function test_apply_is_idempotent(): void
    {
        [, $orgId] = $this->legacyOrg('Ashgrove Dialysis Center');

        Artisan::call('gp:entity-reclassify', ['--apply' => true]);
        Artisan::call('gp:entity-reclassify', ['--apply' => true]);

        // The second run finds nothing to do: the identity is already an entity,
        // so it is no longer in the unanimous_entity-AND-classified-individual set.
        $this->assertSame(2, (int) $this->hub()->table('gp_identity')
            ->where('identity_id', $orgId)->count(), 'a second run must not mint a third version');
    }

    public function test_the_audit_reports_cross_type_key_collisions(): void
    {
        // The number that sizes what Task 4 stopped doing. Built here as two
        // already-classified identities sharing a upin.
        foreach ([['entity', 'Marrowstone Imaging', null], ['individual', null, 'Bhatt']] as [$type, $org, $last]) {
            $this->hub()->table('gp_identity')->insert([
                'identity_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'entity_type' => $type, 'org_name' => $org, 'canonical_last' => $last,
                'upin' => 'U-55031',
                'confidence' => 1.0, 'record_count' => 0, 'status' => 'active',
                'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
            ]);
        }

        Artisan::call('gp:entity-audit');

        $this->assertStringContainsString('upin', Artisan::output());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/EntityReclassifyTest.php`

Expected: FAIL — `Class "App\Console\Commands\GpEntityAudit" not found`.

- [ ] **Step 3: Write `gp:entity-audit`**

Create `app/Console/Commands/GpEntityAudit.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only classification report over an existing hub. WRITES NOTHING.
 *
 * 2026_09_07_000000_add_entity_type_and_org_name classified every existing
 * identity as 'individual' -- deliberately the status quo rather than a guess, so
 * that the migration itself could not move a single match. This command produces
 * the evidence needed to decide what to reclassify, and gp:entity-reclassify acts
 * on a subset of it.
 *
 * WHY SHAPE COMES FROM STAGING, NOT FROM THE IDENTITY
 * ---------------------------------------------------
 * An identity minted before plan 4 carries no type information at all: no
 * org_name, and canonical_first/last simply null. The evidence lives one level
 * down. stg_person_alias.alias_type is enum('maiden','alt','business'), so an
 * organization name arriving through the connector is ALREADY LABELLED -- this is
 * not shape inference, it is a stored label. A staged row is entity-shaped when it
 * has a business alias and no name of its own.
 *
 * Verified from DDL 2026-09-04: stg_person_alias.alias_type carries the label,
 * gp_identity_alias.alias_part (enum('last','first')) does not, which is why the
 * probe goes to staging.
 *
 * THE FOUR BUCKETS
 * ----------------
 *   unanimous_entity      every linked staged row is entity-shaped -> reclassifiable
 *   unanimous_individual  every linked staged row is person-shaped  -> leave
 *   mixed                 both shapes on one identity               -> REPORT ONLY
 *   no_signal             no name and no business alias             -> plan 5 Task 7's quarantine
 *
 * `mixed` is the bucket that matters and the one nothing automates. It means an
 * organization and a person were welded together -- reachable before plan 4
 * through a shared licence number and state, a shared upin, or a shared npi (there
 * is no business-NAME merge path: nothing in resolution reads an alias). Splitting
 * one is not something this codebase can do: gp_identity.status has a 'split'
 * value that NOTHING ever writes, there is no way to decide which links belong to
 * the organization, and there is no undo. Counting them is worth more than
 * guessing at scale; plan 6 owns the steward surface.
 *
 * On the ~13.4M-row hub the two aggregates below are full scans of gp_source_link
 * joined to stg_person. Run it off-peak, on a replica if one is available. It
 * takes no locks and writes nothing, so an interrupted run costs only the time.
 */
class GpEntityAudit extends Command
{
    protected $signature = 'gp:entity-audit
        {--limit=20 : how many mixed identity ids to list}';

    protected $description = 'Read-only: bucket existing identities by individual/entity shape and '
        .'report cross-type key collisions and entity block sizes. Writes nothing.';

    private function hub()
    {
        return DB::connection(config('golden_profile.connections.hub', 'golden_profile'));
    }

    public function handle(): int
    {
        $buckets = $this->buckets();

        $this->info('Identity shape, from the linked staged rows:');
        $this->table(
            ['bucket', 'identities'],
            collect($buckets)->map(fn ($n, $b) => [$b, $n])->values()->all()
        );

        $mixed = $this->mixedIdentityIds((int) $this->option('limit'));
        if ($mixed !== []) {
            $this->newLine();
            $this->warn(
                'MIXED identities hold both an organization and a person. Nothing reclassifies '.
                'these: there is no split machinery in this codebase and no undo. Steward review '.
                '(plan 6). First '.count($mixed).':'
            );
            $this->line('  '.implode(', ', $mixed));
        }

        $this->newLine();
        $this->info('Cross-type key collisions — merges that happened before entity_type scoping:');
        $this->table(['shared key', 'colliding groups'], $this->crossTypeCollisions());

        $this->newLine();
        $cap = (int) config('golden_profile.probabilistic.block_size_cap', 0);
        $this->info("Largest entity blocking buckets (block_size_cap = $cap):");
        $this->table(['block_key', 'staged rows', 'over cap'], $this->entityBlockSizes());

        $this->newLine();
        $this->line('Paste these into the "Measured before reclassification" table in docs/ENTITY-TYPES.md.');

        // Always success: this is a report, and a non-zero exit would make it
        // unusable in a pipeline that runs it before deciding anything.
        return self::SUCCESS;
    }

    /**
     * @return array{unanimous_entity:int,unanimous_individual:int,mixed:int,no_signal:int}
     */
    public function buckets(): array
    {
        $rows = $this->hub()->select($this->shapeSql().'
            SELECT CASE
                       WHEN shaped = 0 AND named = 0 THEN \'no_signal\'
                       WHEN shaped = links           THEN \'unanimous_entity\'
                       WHEN shaped = 0               THEN \'unanimous_individual\'
                       ELSE \'mixed\'
                   END AS bucket,
                   COUNT(*) AS identities
            FROM shape
            GROUP BY bucket');

        $out = ['unanimous_entity' => 0, 'unanimous_individual' => 0, 'mixed' => 0, 'no_signal' => 0];
        foreach ($rows as $r) {
            $out[$r->bucket] = (int) $r->identities;
        }

        return $out;
    }

    /** @return list<int> */
    public function mixedIdentityIds(int $limit): array
    {
        $rows = $this->hub()->select($this->shapeSql().'
            SELECT identity_id FROM shape
            WHERE shaped > 0 AND shaped < links
            ORDER BY identity_id
            LIMIT '.max(1, $limit));

        return array_map(fn ($r) => (int) $r->identity_id, $rows);
    }

    /** Identities whose every linked staged row is entity-shaped, with their name. */
    public function unanimousEntities(?int $afterId, int $chunk): array
    {
        return $this->hub()->select($this->shapeSql().'
            SELECT identity_id, org_name, state FROM shape
            WHERE shaped = links AND links > 0 AND identity_id > ?
            ORDER BY identity_id
            LIMIT '.max(1, $chunk), [$afterId ?? 0]);
    }

    /**
     * The shape CTE every query above shares.
     *
     * shaped = linked staged rows that are entity-shaped (a business alias, no
     *          name of their own)
     * named  = linked staged rows carrying a first or last name
     * links  = linked staged rows
     * org_name / state = the organization name and state to adopt, taken from the
     *          LOWEST link_id so the choice is deterministic across runs --
     *          Survivorship will pick the authoritative winner at the next
     *          finalize, this only has to be stable.
     *
     * Only CURRENT identity versions and only active ones: a superseded version's
     * classification is not the one in force, and a merged-away identity is not
     * something to reclassify.
     *
     * TRIM() on both name columns, not IS NOT NULL. stg_person's names ARE
     * nullable (the connector's clean() turns the source's '' into null), but a
     * row written by an older path or by a fixture can carry '' -- and the source
     * columns they mirror are NOT NULL char(100), so '' is the value the data
     * actually contains upstream.
     */
    private function shapeSql(): string
    {
        return "WITH biz AS (
                    SELECT DISTINCT stg_person_id FROM stg_person_alias WHERE alias_type = 'business'
                ),
                shape AS (
                    SELECT l.identity_id,
                           COUNT(*) AS links,
                           SUM(CASE WHEN b.stg_person_id IS NOT NULL
                                     AND TRIM(COALESCE(sp.first_name,'')) = ''
                                     AND TRIM(COALESCE(sp.last_name,''))  = ''
                                    THEN 1 ELSE 0 END) AS shaped,
                           SUM(CASE WHEN TRIM(COALESCE(sp.first_name,'')) <> ''
                                      OR TRIM(COALESCE(sp.last_name,''))  <> ''
                                    THEN 1 ELSE 0 END) AS named,
                           SUBSTRING_INDEX(GROUP_CONCAT(
                               COALESCE(sp.org_name, a.first_name) ORDER BY l.link_id
                               SEPARATOR CHAR(31 USING utf8mb4)), CHAR(31 USING utf8mb4), 1) AS org_name,
                           SUBSTRING_INDEX(GROUP_CONCAT(
                               COALESCE(sp.state,'') ORDER BY l.link_id
                               SEPARATOR CHAR(31 USING utf8mb4)), CHAR(31 USING utf8mb4), 1) AS state
                    FROM gp_source_link l
                    JOIN gp_identity i
                      ON i.identity_id = l.identity_id AND i.current = 1 AND i.status = 'active'
                    JOIN stg_person sp
                      ON sp.system_id = l.system_id AND sp.source_table = l.source_table
                     AND sp.source_id = l.source_id
                    LEFT JOIN biz b ON b.stg_person_id = sp.stg_person_id
                    LEFT JOIN stg_person_alias a
                      ON a.stg_person_id = sp.stg_person_id AND a.alias_type = 'business'
                    WHERE i.entity_type = 'individual'
                    GROUP BY l.identity_id
                ) ";
    }

    /**
     * Identities of differing shape that share a hard key. Each row is a merge
     * that HAPPENED before Task 4's entity_type scoping and will not happen again
     * -- so it also sizes the false splits that scoping could introduce where a
     * classification is wrong.
     *
     * @return list<array{0:string,1:int}>
     */
    private function crossTypeCollisions(): array
    {
        $out = [];

        foreach (['npi', 'upin'] as $column) {
            $n = (int) ($this->hub()->selectOne(
                "SELECT COUNT(*) AS n FROM (
                     SELECT `$column` FROM gp_identity
                     WHERE `$column` IS NOT NULL AND TRIM(`$column`) <> ''
                       AND status = 'active' AND current = 1
                     GROUP BY `$column`
                     HAVING COUNT(DISTINCT entity_type) > 1
                 ) x"
            )->n ?? 0);
            $out[] = [$column, $n];
        }

        $n = (int) ($this->hub()->selectOne(
            "SELECT COUNT(*) AS n FROM (
                 SELECT gl.license_number, gl.certification_state
                 FROM gp_license gl
                 JOIN gp_identity i
                   ON i.identity_id = gl.identity_id AND i.current = 1 AND i.status = 'active'
                 WHERE gl.current = 1
                 GROUP BY gl.license_number, gl.certification_state
                 HAVING COUNT(DISTINCT i.entity_type) > 1
             ) x"
        )->n ?? 0);
        $out[] = ['license+state', $n];

        return $out;
    }

    /**
     * @return list<array{0:string,1:int,2:string}>
     */
    private function entityBlockSizes(): array
    {
        $cap = (int) config('golden_profile.probabilistic.block_size_cap', 0);

        $rows = $this->hub()->select(
            "SELECT block_key, COUNT(*) AS members
             FROM stg_person
             WHERE entity_type = 'entity' AND block_key IS NOT NULL
             GROUP BY block_key
             ORDER BY members DESC
             LIMIT 20"
        );

        return array_map(fn ($r) => [
            $r->block_key,
            (int) $r->members,
            ($cap > 0 && (int) $r->members > $cap) ? 'YES — silent false split' : '',
        ], $rows);
    }
}
```

- [ ] **Step 4: Write `gp:entity-reclassify`**

Create `app/Console/Commands/GpEntityReclassify.php`:

```php
<?php

namespace App\Console\Commands;

use App\GoldenProfile\Support\BlockKey;
use App\GoldenProfile\Support\Versioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reclassify existing identities whose linked staged rows are UNANIMOUSLY
 * organizations. Dry-run by default.
 *
 * WHAT IT DOES NOT DO, AND WHY THAT IS THE DESIGN
 * -----------------------------------------------
 * It touches `unanimous_entity` identities only -- every linked staged row is
 * entity-shaped. It never touches `mixed` (an organization and a person already
 * welded together) and never touches `no_signal`.
 *
 * Splitting a mixed identity is not possible here. gp_identity.status has a
 * 'split' value that NOTHING in this codebase ever writes: there is no split
 * machinery, no way to decide which of an identity's links belong to the
 * organization, and no undo. Guessing at scale would manufacture false splits
 * with no recovery path, so gp:entity-audit reports the count and plan 6 owns the
 * steward surface. Run the audit and fill in docs/ENTITY-TYPES.md before running
 * this with --apply.
 *
 * WHAT THE CHANGE ACTUALLY DOES TO AN IDENTITY
 * --------------------------------------------
 * Nothing moves. The identity keeps its identity_id, its identity_uuid, and every
 * gp_source_link, gp_license, gp_address, gp_identity_credential,
 * gp_identity_exclusion, gp_identity_identifier and gp_board_action row it holds.
 * It gains a type and a name.
 *
 * Its FUTURE matching changes: an incoming person row can no longer bind to it,
 * because every deterministic tier now carries an entity_type predicate. That can
 * only reduce merges, never create one -- the predicate strictly narrows each
 * tier -- so the failure mode it can produce is a false SPLIT, and only where the
 * classification is wrong. Which is exactly why unanimity is required.
 *
 * REVERSIBILITY
 * -------------
 * Every identity write goes through Versioner::write(), so the
 * pre-reclassification row survives at current = 0 with its original
 * entity_type and org_name. Reversing one identity is a Versioner::write() back
 * to the previous version's values; reversing a whole run means the same over the
 * gp_resolution_log rows this command emits, one per identity, actor
 * 'gp:entity-reclassify'.
 *
 * The staged rows get a plain UPDATE, not a version: staging is deliberately not
 * versioned (docs/SCD2.md -- it mirrors CAMI's live state, and CAMI is the system
 * of record for its own history). Their block_key is recomputed, without which
 * Pass B would keep treating a reclassified organization as a person with no
 * surname and therefore no block key at all.
 */
class GpEntityReclassify extends Command
{
    protected $signature = 'gp:entity-reclassify
        {--apply : actually write. Without this the command only reports}
        {--chunk=500 : identities per batch}
        {--limit= : stop after this many identities}';

    protected $description = 'Reclassify identities whose linked staged rows are unanimously '
        .'organizations. Dry-run unless --apply. Never touches mixed identities.';

    private function hub()
    {
        return DB::connection(config('golden_profile.connections.hub', 'golden_profile'));
    }

    public function handle(GpEntityAudit $audit): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $versioner = new Versioner;
        $after = 0;
        $seen = 0;
        $changed = 0;

        while (true) {
            $rows = $audit->unanimousEntities($after, $chunk);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $after = (int) $row->identity_id;
                $seen++;

                $orgName = is_string($row->org_name) ? trim($row->org_name) : null;
                $orgName = ($orgName === '' ? null : $orgName);

                if ($orgName === null) {
                    // Entity-shaped by its aliases but with no readable name --
                    // GROUP_CONCAT is truncated at group_concat_max_len, so a
                    // pile-up identity can lose it. Skip rather than write a
                    // nameless entity: an entity with no org_name has no block key
                    // and no Pass B, so it would be worse off than before.
                    $this->warn("identity $after: entity-shaped but no org_name resolved — skipped");

                    continue;
                }

                $changed++;

                if (! $apply) {
                    continue;
                }

                $this->hub()->transaction(function () use ($versioner, $row, $orgName) {
                    $identityId = (int) $row->identity_id;

                    $versioner->write(
                        'gp_identity',
                        ['identity_id' => $identityId],
                        ['entity_type' => 'entity', 'org_name' => $orgName],
                    );

                    // Staging: plain UPDATE, and the block_key MUST be recomputed
                    // or Pass B still sees a surname-less person.
                    $state = is_string($row->state) ? trim($row->state) : null;
                    $blockKey = BlockKey::for(null, null, 'entity', $orgName, $state ?: null);

                    $this->hub()->table('stg_person')
                        ->whereIn('stg_person_id', function ($q) use ($identityId) {
                            $q->select('sp.stg_person_id')
                                ->from('stg_person as sp')
                                ->join('gp_source_link as l', function ($j) {
                                    $j->on('l.system_id', '=', 'sp.system_id')
                                        ->on('l.source_table', '=', 'sp.source_table')
                                        ->on('l.source_id', '=', 'sp.source_id');
                                })
                                ->where('l.identity_id', $identityId);
                        })
                        ->update([
                            'entity_type' => 'entity',
                            'org_name' => $orgName,
                            'block_key' => $blockKey,
                        ]);

                    $this->hub()->table('gp_resolution_log')->insert([
                        'action' => 'override',
                        'identity_id' => $identityId,
                        'affected_ids' => json_encode(['identity_id' => $identityId]),
                        'match_key' => 'entity_type',
                        'reason' => 'reclassified individual -> entity on unanimous staged-row '.
                            'shape; org_name='.mb_substr($orgName, 0, 120),
                        'actor' => 'gp:entity-reclassify',
                        'created_at' => now(),
                    ]);
                });
            }

            if ($limit !== null && $seen >= $limit) {
                break;
            }
        }

        if ($apply) {
            $this->info("reclassified $changed identit".($changed === 1 ? 'y' : 'ies').' as entities');
            $this->line('Re-run `php artisan gp:rebuild-profile` (or the next finalize) so the '.
                'profile read model and the API pick up the new type.');
        } else {
            $this->info("would reclassify $changed identit".($changed === 1 ? 'y' : 'ies').
                ' (dry run — pass --apply to write)');
            $this->line('Run `php artisan gp:entity-audit` first and fill in the measured table '.
                'in docs/ENTITY-TYPES.md. Mixed identities are never touched by this command.');
        }

        return self::SUCCESS;
    }
}
```

> `Versioner::write()` opens its own transaction; nested inside this command's it becomes a
> savepoint, which is the same nesting `Engine::mergeIdentity()` already relies on and which
> `HubTestCase` documents as rolling back with the outer transaction.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/EntityReclassifyTest.php`

Expected: PASS, 8 tests.

> If `test_the_audit_buckets_identities_by_the_shape_of_their_linked_rows` fails on the `mixed`
> count, check the `GROUP_CONCAT` in `shapeSql()`: MySQL's `group_concat_max_len` defaults to 1024
> bytes, which truncates on a pile-up identity. That is why an empty `org_name` is skipped with a
> warning in Step 4 rather than written — a truncated name is worse than none.

- [ ] **Step 6: Run the full suite**

Run:
```bash
vendor/bin/phpunit
vendor/bin/pint --dirty
```

Expected: PASS, 0 skipped. `EvalGateTest` unchanged at `true_pairs` 11 — neither command is on the
resolver path.

- [ ] **Step 7: Register the human measurement task**

Both commands are useless without hub numbers nobody in this environment can get. The table is
already in `docs/ENTITY-TYPES.md` from Task 1; add the runbook beside it:

```markdown
### Reclassification runbook — HUMAN TASK

Requires production hub credentials. In order:

1. `php artisan gp:entity-audit` against the hub. Writes nothing, takes no locks;
   run it off-peak or on a replica — the two aggregates are full scans of
   `gp_source_link` joined to `stg_person`.
2. Paste its three tables into "Measured before reclassification" above.
3. Read the `mixed` count. It is the number of identities holding both an
   organization and a person, and NOTHING automates them. If it is large relative
   to `unanimous_entity`, stop and raise it — a large `mixed` population means the
   cross-type merge paths ran for longer than expected and the steward work in
   plan 6 has to come first.
4. Read the three cross-type collision counts. Each is a merge that used to happen
   and will not after this plan. They also bound the false splits that entity_type
   scoping can introduce where a classification is wrong.
5. `php artisan gp:entity-reclassify` (dry run). Confirm the count matches
   `unanimous_entity` from step 1, minus any "no org_name resolved" warnings.
6. `php artisan gp:entity-reclassify --apply --limit=100` first. Spot-check ten of
   the `gp_resolution_log` rows it wrote and confirm each identity's version 1 row
   still carries `entity_type = 'individual'` — that row is the undo.
7. `php artisan gp:entity-reclassify --apply` for the rest.
8. `php artisan gp:rebuild-profile` so the read model and both endpoints see the
   new type. Also verify the entity block-size distribution again: reclassified
   staged rows now carry entity block keys, and a bucket over `block_size_cap`
   (2000) is a silent false split.

9. Record, while you are in the source, the actual `employee_additional_info.name`
   spellings for TIN/EIN/UEI, and add any that are missing from
   `StreamlineLocalConnector::additionalRows()`. A missing spelling is not a
   visible failure — it is an entity that silently never binds to its own other
   records.

       php -r '$p = new PDO("mysql:host=<host>;dbname=streamline_local", ...);
       foreach ($p->query("SELECT DISTINCT name FROM employee_additional_info
         WHERE name REGEXP \"tin|ein|uei|tax|entity\" ORDER BY name") as $r) echo $r["name"], "\n";'
```

- [ ] **Step 8: Commit**
```bash
git add app/Console/Commands/GpEntityAudit.php \
        app/Console/Commands/GpEntityReclassify.php \
        docs/ENTITY-TYPES.md \
        tests/Feature/EntityReclassifyTest.php
git commit -m "feat(entity): add the read-only shape audit and the reversible reclassify command"
```

---

## Task 10: Eval fixture coverage, and the ratchet

The gate cannot test a capability the fixture has no data for, and the current fixture is **all
individuals** — there is no entity record in it. Without this task, entity resolution ships untested.

**Files:**
- Modify: `app/GoldenProfile/Eval/EvalSet.php`
- Modify: `app/GoldenProfile/Eval/EvalRunner.php`
- Modify: `tests/eval/identity-pairs.json`
- Modify: `tests/Unit/EvalSetShapeTest.php`
- Modify: `tests/Feature/EvalGateTest.php`
- Modify: `docs/EVALUATION.md`
- Modify: `docs/ENTITY-TYPES.md`

**Interfaces:**
- Produces: `EvalSet::addresses(string $ref): array`; `EvalSet` validation of `entity_type` /
  `org_name`; `EvalRunner` staging `entity_type`, `org_name`, addresses and the type-aware block key.
- Consumes: `EvalSet::identifiers()` (plan 5), `BlockKey::for()` (Task 3).

### What the eight new records prove

| Records | Truth | What fails if the implementation is wrong |
|---|---|---|
| `org-tin-a` / `org-tin-b` | together | Different zips, different addresses, names differing by a legal suffix — only the TIN tier can bind them. A missing `tin` tier is a false split |
| `org-name-a` / `org-name-b` | together | Same normalized name, same address, same zip, no identifier at all. Only entity Pass B can bind them, and it could not run at all before this plan. A null entity block key, a missing `orgCompatible()` branch, or missing address staging is a false split |
| `org-name-other-zip` | alone | Same name and same **state** as the pair above, so it lands in the same block — this tests the score, not the blocking key. Without the zip/address requirement it is a false merge |
| `org-similar-name` | alone | Same address and same zip as the pair, name similar but not equal. Without `orgCompatible()`'s equality gate it scores ~0.775, clears the 0.75 review floor, and is a false merge |
| `org-lic` / `person-lic` | apart | An organization and a person sharing a licence number and state. Before Task 4's type scoping these **merge**, so this record pair is a regression test for the plan's central fix |

`true_pairs` goes 11 → 13 (`org-tin` and `org-name` are one true pair each). Every value is
synthetic: `sv-manila/gp-cami` is public, and the organization names, addresses, tax IDs and the one
person are invented, following the fixture's existing convention.

- [ ] **Step 1: Extend `EvalSet` with address staging and entity validation**

In `app/GoldenProfile/Eval/EvalSet.php`, add after `licenses()`:

```php
    /** @return list<array{address1:?string,city:?string,state:?string,zip:?string}> */
    public function addresses(string $ref): array
    {
        foreach ($this->records as $r) {
            if ($r['ref'] === $ref) {
                return $r['addresses'] ?? [];
            }
        }

        return [];
    }
```

and add the entity rules inside `loadArray()`, in the per-record loop, after the duplicate-ref check:

```php
            $refs[$r['ref']] = true;

            // Entity records are validated here, in the loader, because a
            // malformed answer key scores a matcher against nonsense and reports
            // it as a number. A record claiming to be an organization while
            // carrying a DOB would exercise a row shape the resolver is entitled
            // to treat as an error, and the resulting metric would be
            // meaningless rather than merely wrong.
            $type = $r['entity_type'] ?? 'individual';

            if (! in_array($type, ['individual', 'entity'], true)) {
                throw new InvalidArgumentException(
                    "record '{$r['ref']}' has entity_type '$type'; expected 'individual' or 'entity'"
                );
            }

            if ($type === 'entity') {
                if (trim((string) ($r['org_name'] ?? '')) === '') {
                    throw new InvalidArgumentException("entity record '{$r['ref']}' has no org_name");
                }
                foreach (['first_name', 'last_name', 'middle_name', 'date_of_birth', 'ssn_hash'] as $personOnly) {
                    if (! empty($r[$personOnly])) {
                        throw new InvalidArgumentException(
                            "entity record '{$r['ref']}' carries $personOnly, which is person-only"
                        );
                    }
                }
            } elseif (! empty($r['org_name'])) {
                throw new InvalidArgumentException(
                    "individual record '{$r['ref']}' carries org_name; a sole proprietor's ".
                    'practice name belongs in an alias, not on the identity'
                );
            }
```

- [ ] **Step 2: Extend `EvalRunner` to stage the type, the name and the addresses**

In `app/GoldenProfile/Eval/EvalRunner.php`, add the two columns to the `stg_person` insert and stage
addresses beside the licences. The insert's first lines become:

```php
            $stgByRef[$ref] = (int) $this->hub()->table('stg_person')->insertGetId([
                'system_id' => $this->systemId,
                'source_table' => 'employees',
                'source_id' => crc32($ref),
                'account_id' => 1,
                'employeelist_id' => 1,
                'entity_type' => $r['entity_type'] ?? 'individual',
                'org_name' => $r['org_name'] ?? null,
                'first_name' => $r['first_name'] ?? null,
```

and its `block_key` line becomes a `BlockKey` call, replacing the private `blockKey()` method:

```php
                'block_key' => BlockKey::for(
                    $r['last_name'] ?? null,
                    $r['date_of_birth'] ?? null,
                    $r['entity_type'] ?? 'individual',
                    $r['org_name'] ?? null,
                    $r['state'] ?? null,
                ),
            ]);
```

Delete the private `blockKey()` method (the one commented "Same rule as
`StreamlineLocalConnector::blockKey()`") and add `use App\GoldenProfile\Support\BlockKey;`.

Then, after the identifier-staging loop plan 5 added, stage addresses:

```php
            // stg_person_address rows, not just stg_person's own address columns.
            //
            // Pass B's address and zip signals compare stg_person_address against
            // gp_address, and gp_address is written by the resolver's enrich()
            // from stg_person_address. Without these rows an entity pair scores
            // its org name alone (0.50), below review_band_floor (0.75), and every
            // Pass B fixture case would be a false split for a reason that has
            // nothing to do with the matcher.
            foreach ($set->addresses($ref) as $addr) {
                $this->hub()->table('stg_person_address')->insert([
                    'stg_person_id' => $stgByRef[$ref],
                    'address_type' => 'primary',
                    'address1' => $addr['address1'] ?? null,
                    'address2' => $addr['address2'] ?? null,
                    'city' => $addr['city'] ?? null,
                    'state' => $addr['state'] ?? null,
                    'zip' => $addr['zip'] ?? null,
                ]);
            }
```

- [ ] **Step 3: Raise the ratchet — write the failing assertion first**

In `tests/Feature/EvalGateTest.php`, change the `true_pairs` assertion:

```php
        // The eval set must not shrink. Deleting records raises every ratio for
        // free, so a floor on the metrics alone is not a regression net — this
        // pins the denominator.
        //
        //   9  plan 1's baseline: smith(3) + garcia(1) + kowalski(1) + chain(3) + ssn(1)
        //  +2  plan 5: mmis-a/mmis-b and dea-a/dea-b
        //  +2  plan 4: org-tin-a/org-tin-b (the TIN tier) and
        //              org-name-a/org-name-b (entity Pass B)
        //  = 13
        $this->assertGreaterThanOrEqual(
            13, $report['true_pairs'],
            'the eval set shrank — pairs were removed, not the matcher improved'
        );
```

- [ ] **Step 4: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=EvalGateTest`

Expected: FAIL — `true_pairs` is 11; no entity records exist yet.

- [ ] **Step 5: Add the fixture records**

In `tests/eval/identity-pairs.json`, extend `notes` and add the eight records and six truth clusters.

Append to the `notes` string:

> ` The org-* and person-lic records are plan 4's entity coverage, and every value in them is invented — the organization names, street addresses, tax identifiers and the one person. org-tin-a/org-tin-b share only a TIN (different zips, different addresses, names differing by a legal suffix), so they can be bound by nothing but the TIN tier. org-name-a/org-name-b share a normalized name, a street address and a zip and no identifier at all, so only entity Pass B can bind them — it could not run for an organization before plan 4, because a null block_key short-circuits match(). org-name-other-zip is the same name in the same STATE (so it lands in the same block; this tests the score, not the blocking key) with a different zip, and must stay apart. org-similar-name shares the address and zip but not the name, and exists to prove NameMatcher::orgCompatible()'s equality gate: without it the pair scores ~0.775 and clears the 0.75 review floor. org-lic and person-lic share a licence number and state and MUST stay apart — before entity_type scoping they merged, so that pair is the regression test for plan 4's central fix.`

Add to `"records"`, after the last plan 5 record:

```json
    { "ref": "org-tin-a", "entity_type": "entity", "org_name": "Thistlebrook Medical Group",
      "first_name": null, "last_name": null, "date_of_birth": null, "npi": null,
      "state": "OH", "zip": "43004",
      "addresses": [{ "address1": "18 Kestrel Row", "city": "Blacklick", "state": "OH", "zip": "43004" }],
      "identifiers": [{ "id_type": "tin", "id_value": "31-4402917" }] },
    { "ref": "org-tin-b", "entity_type": "entity", "org_name": "Thistlebrook Medical Group LLC",
      "first_name": null, "last_name": null, "date_of_birth": null, "npi": null,
      "state": "OH", "zip": "45011",
      "addresses": [{ "address1": "402 Sable Court", "city": "Hamilton", "state": "OH", "zip": "45011" }],
      "identifiers": [{ "id_type": "tin", "id_value": "31-4402917" }] },

    { "ref": "org-name-a", "entity_type": "entity", "org_name": "Ashgrove Dialysis Center",
      "first_name": null, "last_name": null, "date_of_birth": null, "npi": null,
      "state": "OK", "zip": "73008",
      "addresses": [{ "address1": "77 Marlin Bend", "city": "Bethany", "state": "OK", "zip": "73008" }] },
    { "ref": "org-name-b", "entity_type": "entity", "org_name": "Ashgrove Dialysis Center, Inc.",
      "first_name": null, "last_name": null, "date_of_birth": null, "npi": null,
      "state": "OK", "zip": "73008",
      "addresses": [{ "address1": "77 Marlin Bend", "city": "Bethany", "state": "OK", "zip": "73008" }] },
    { "ref": "org-name-other-zip", "entity_type": "entity", "org_name": "Ashgrove Dialysis Center",
      "first_name": null, "last_name": null, "date_of_birth": null, "npi": null,
      "state": "OK", "zip": "74003",
      "addresses": [{ "address1": "9 Cobbler Lane", "city": "Bartlesville", "state": "OK", "zip": "74003" }] },
    { "ref": "org-similar-name", "entity_type": "entity", "org_name": "Ashgrove Dental Care",
      "first_name": null, "last_name": null, "date_of_birth": null, "npi": null,
      "state": "OK", "zip": "73008",
      "addresses": [{ "address1": "77 Marlin Bend", "city": "Bethany", "state": "OK", "zip": "73008" }] },

    { "ref": "org-lic", "entity_type": "entity", "org_name": "Pinewhistle Surgery Center",
      "first_name": null, "last_name": null, "date_of_birth": null, "npi": null,
      "state": "MT", "zip": "59718",
      "licenses": [{ "license_number": "L-9001", "certification_state": "MT" }] },
    { "ref": "person-lic", "first_name": "Ana", "last_name": "Vasquez",
      "date_of_birth": "1983-11-04", "npi": null,
      "licenses": [{ "license_number": "L-9001", "certification_state": "MT" }] }
```

Add to `"truth"`:

```json
    ["org-tin-a", "org-tin-b"],
    ["org-name-a", "org-name-b"],
    ["org-name-other-zip"],
    ["org-similar-name"],
    ["org-lic"],
    ["person-lic"]
```

> `org-name-b`'s name is `"Ashgrove Dialysis Center, Inc."` rather than a repeat of `org-name-a`'s,
> so the pair also proves `NameMatcher::normalizeOrg()` drops punctuation and one trailing legal
> form. If the two names were byte-identical, a broken normalizer would still pass.

- [ ] **Step 6: Pin the fixture's own shape**

In `tests/Unit/EvalSetShapeTest.php`, add:

```php
    public function test_the_set_covers_both_record_types(): void
    {
        // The gate cannot test a capability the fixture has no data for, and
        // before plan 4 the fixture was ALL individuals -- so entity resolution
        // would have shipped untested. This asserts the coverage exists, not that
        // it is large.
        $types = array_map(
            fn ($r) => $r['entity_type'] ?? 'individual',
            $this->set()->records()
        );

        $this->assertGreaterThanOrEqual(6, count(array_filter($types, fn ($t) => $t === 'entity')),
            'the entity ladder, entity Pass B and the cross-type foil each need records');
        $this->assertGreaterThanOrEqual(15, count(array_filter($types, fn ($t) => $t === 'individual')));
    }

    public function test_the_set_contains_a_cross_type_licence_collision(): void
    {
        // The regression test for plan 4's central fix, asserted at the fixture
        // level so it cannot be quietly removed: an entity and an individual
        // sharing a licence number and state, in different truth clusters.
        $byRef = [];
        foreach ($this->set()->records() as $r) {
            $byRef[$r['ref']] = $r;
        }

        $this->assertArrayHasKey('org-lic', $byRef);
        $this->assertArrayHasKey('person-lic', $byRef);
        $this->assertSame('entity', $byRef['org-lic']['entity_type']);
        $this->assertSame('individual', $byRef['person-lic']['entity_type'] ?? 'individual');
        $this->assertSame(
            $this->set()->licenses('org-lic'),
            $this->set()->licenses('person-lic'),
            'the collision only tests anything while the two licences are identical'
        );

        foreach ($this->set()->truthClusters() as $cluster) {
            $this->assertNotSame(
                ['org-lic', 'person-lic'],
                array_values(array_intersect($cluster, ['org-lic', 'person-lic'])),
                'org-lic and person-lic must be in different truth clusters'
            );
        }
    }
```

- [ ] **Step 7: Run to verify the gate passes**

Run: `vendor/bin/phpunit --filter=EvalGateTest`

Expected: PASS — `true_pairs` 13, `predicted_pairs` 13, `false_merges` 0, `false_splits` 0,
precision 1.0000, recall 1.0000, f1 1.0000.

Diagnosing a failure here, because each mode points at a different task:

| Symptom | Cause |
|---|---|
| `false_splits` 1, `org-tin-*` apart | Task 5's TIN tier is not firing. Check `deterministic_keys` has `tin`, and that `enrich()` writes `gp_identity_identifier` (plan 5) |
| `false_splits` 1, `org-name-*` apart | Task 6. Either the entity block key is null (Task 3), or `orgCompatible()` rejects the pair (check the legal-form list handles `, Inc.`), or `EvalRunner` is not staging addresses (Step 2) — without addresses the pair scores 0.50 |
| `false_merges` 1, `org-similar-name` merged | `orgCompatible()`'s equality gate is missing or too loose. This is the false merge the gate exists to catch |
| `false_merges` 1, `org-name-other-zip` merged | `addressOverlap()` is awarding its zip flag without equal zips, or the entity weights let name alone clear the floor |
| `false_merges` 1, `org-lic` + `person-lic` merged | Task 4's licence tier scoping regressed. This is the central fix |
| Every entity record its own cluster and `true_pairs` 13 | `EvalSet` validation threw, or `entity_type` never reached `stg_person`. Run `vendor/bin/phpunit tests/Unit/EvalSetShapeTest.php` |

- [ ] **Step 8: Run the whole suite**

Run:
```bash
vendor/bin/phpunit
vendor/bin/pint --dirty
```

Expected: PASS, 0 skipped.

- [ ] **Step 9: Record the measured numbers**

In `docs/EVALUATION.md`, under "Achieved — measured on this branch", add a row to the table and a
paragraph:

```markdown
| True pairs | 13 |
| Records | 31 |
| Clusters | 20 |

**Plan 4 (individual vs entity)** raised `true_pairs` from 11 to 13 and added eight synthetic
records, six of them organizations. Measured after Task 10:
precision 1.0000 · recall 1.0000 · f1 1.0000 · 0 false merges · 0 false splits.

The two new true pairs are `org-tin-a`/`org-tin-b` (bound by the TIN tier alone — different zips,
different addresses, names differing by a legal suffix) and `org-name-a`/`org-name-b` (bound by
entity Pass B, which could not run for an organization at all before plan 4: a null `block_key`
short-circuits `ProbabilisticResolver::match()`).

Four singletons are foils, and each catches a specific failure: `org-name-other-zip` (same name,
same state so it blocks together, different zip — proves the score requires address agreement),
`org-similar-name` (same address and zip, name similar but not equal — proves
`NameMatcher::orgCompatible()`'s equality gate, without which it scores ~0.775 and clears the 0.75
review floor), and `org-lic` + `person-lic` (an organization and a person sharing a licence number
and state — these **merged** before `entity_type` scoping, so the pair is the regression test for
plan 4's central fix).

The ratchets (`false_splits === 0`, `recall === 1.0`) did not move: the entity work only ever
narrows a tier's candidate set or adds a capability, so no individual pair changed. **The
still-`PENDING` baseline table above is a separate obligation** — it needs production hub access and
sizes plan 2, not this plan.
```

- [ ] **Step 10: Note the coverage in the entity register**

Append to `docs/ENTITY-TYPES.md`:

```markdown
## Eval coverage

`tests/eval/identity-pairs.json` carries six synthetic organizations and one
synthetic person for the cross-type foil. `true_pairs` is 13.

| Records | Truth | Catches |
|---|---|---|
| `org-tin-a` / `org-tin-b` | together | The TIN tier. Nothing else can bind them |
| `org-name-a` / `org-name-b` | together | Entity Pass B, which did not run for an organization at all before plan 4 |
| `org-name-other-zip` | alone | The score's address/zip requirement (same name, same state, so it DOES block together) |
| `org-similar-name` | alone | `orgCompatible()`'s equality gate. Without it: ~0.775, over the review floor |
| `org-lic` / `person-lic` | apart | `entity_type` scoping on the licence tier — these merged before plan 4 |

Never delete a record to make the gate pass, and never lower `min-precision` or
`min-recall` (docs/EVALUATION.md forbids both). If entity matching legitimately
changes, re-baseline the ratchets in the same commit and say what moved and why.
```

- [ ] **Step 11: Commit**
```bash
git add app/GoldenProfile/Eval/EvalSet.php \
        app/GoldenProfile/Eval/EvalRunner.php \
        tests/eval/identity-pairs.json \
        tests/Unit/EvalSetShapeTest.php \
        tests/Feature/EvalGateTest.php \
        docs/EVALUATION.md \
        docs/ENTITY-TYPES.md
git commit -m "test(eval): add entity fixture coverage and raise the true_pairs ratchet to 13"
```

---

## Self-review

### Spec coverage

The three pages' requirements, and where each is met or explicitly not met.

**"Data Model (What We Store)"**

| Requirement | Status |
|---|---|
| `entity_type VARCHAR(12) NOT NULL` | **Done**, Task 2, verbatim including the type, plus `ck_identity_entity_type` |
| `org_name` | **Done**, Task 2, as its own column (not a reuse of `canonical_last` — reason in the migration docblock) |
| `provider_identifier.id_type` including `EIN` and `UEI` | **Done**, Task 5, on `gp_identity_identifier` — plan 5's mechanism, no parallel one, no schema change (`id_type` is `varchar(16)`) |

**"Data Flow by CAMI" (4099997697)**

| Requirement | Status |
|---|---|
| Separate `individuals` / `entities` tables | **Deliberately not built.** One table with a discriminator, ruled and costed in "One table or two" and in `docs/ENTITY-TYPES.md` |
| `individual_names` / `entity_names` | **Met by plan 3.** `canonical_*` and `org_name` are versioned attributes, so a rename is a version and the old name survives at `current = 0`. Pinned by `test_a_renamed_organization_keeps_its_old_name_as_a_superseded_version` |
| `addresses` book + `individual_addresses` / `entity_addresses` | **Met by `gp_address`**, versioned by plan 3, with an `entity_type` predicate on the parent instead of two join tables |
| `licensing_credentials` on individuals only | **Met for matching.** The licence tier and `Engine::mergeByLicense()` are individual-only. `gp_license` rows are still written for an entity — named as a deviation with its reason |
| `entities` carry UPIN, TIN + hash + last four, NPI | **UPIN, TIN, NPI done. Hash and last four deliberately NOT built** — see "Deliberate deviations" below |
| Process 1 branches on "Employee Type?" | **Met by inference**, because the source has no such column (70 columns verified). The branch is `StreamlineLocalConnector::inferEntityType()` |

**"Golden Profiles — Delivery Plan & Checklist"**

| Requirement | Status |
|---|---|
| §0 "Decide record types in scope: individual vs organization" | **Done.** `docs/ENTITY-TYPES.md` is the decision record |
| §3 "Plan individual vs entity profiling differences (rules, keys, survivorship)" | **Done.** Rules: the org-name gate and the entity hard-no (Task 6). Keys: the entity ladder (Task 5) and the entity blocking key (Task 3). Survivorship: `field_authority.entity`, `org_name`, and the never-survived type (Task 7) |

### The four design questions the brief said not to defer

1. **One table or two** — one table, ruled with both pages' requirements mapped and both options
   costed. Answered.
2. **How the type is decided** — inferred, because the source has no column; measured trap named;
   both error costs named; NPI ruled out as a signal. Answered.
3. **Entity match keys** — npi, upin, tin, ein, uei, all 0.99, on plan 5's mechanism; no soft Pass A
   tier and the structural reason why; NPI validation shown not to differ. Answered.
4. **Entity blocking and Pass B** — the null-`block_key` short-circuit verified from code; a key
   specified with its collision cost; the `jaroWinkler('','') === 1.0` trap found and closed; the
   `block_size_cap` false split named and reported. Answered.
5. **Entity survivorship** — one config correction (`field_authority.identity` is a system order,
   not a field list), `org_name` survived, person fields shown to be skipped not blanked, a separate
   entity authority order, type never survived, disagreement logged. Answered.
6. **The 107,882 alias rows** — reclassified only where unanimous, through a read-only-first pair of
   commands, never from a migration, never for `mixed`, with a human measurement task. Answered.

### Deliberate deviations from the design set

- **One golden table instead of two.** Ruled, costed, and consistent with plan 1's precedent.
- **No TIN hash or TIN last four.** The doc asks for "TIN + hash + last four". Plan 2 is removing
  exactly that pattern for SSN, and adding a second plaintext-plus-hash pair while the first is
  being deleted would build the thing the programme is dismantling. A TIN is issued to organizations
  and appears on public filings, so plan 2's confidentiality argument does not transfer. If a
  reviewer wants it anyway it is a small additive migration plus two `ProfileMaterializer` lines —
  but it should be argued against plan 2's rationale, not slipped in.
- **`gp_license` rows still written for entities.** Named, with the reason (unmeasured prevalence)
  and the measurement that would settle it (`scripts/entity-preflight.sql` query 2).
- **No `'business'` member on `gp_identity_alias.alias_part`.** The staging label exists and is lost
  downstream; adding it would mean rewriting `AliasIndexer` and a 108k-row index rebuild, to
  duplicate what `org_name` now carries — and `gp_identity_alias` is the only entity lookup that
  works today.
- **No row-shape CHECK constraint.** It would reject the `mixed` identities the plan preserves,
  making `gp:entity-reclassify` undeployable. Enforced in a test and counted by the audit instead.
  This is a real weakening versus a database constraint.

### Placeholder scan

Every task that changes code shows the code. No "add appropriate handling", no "similar to Task N",
no TBD. Three things are marked as needing a value that only production can supply, and all three
are written as human tasks with the exact command:

1. `docs/ENTITY-TYPES.md`'s "Measured before reclassification" table (13 `PENDING` rows) — needs the
   hub. `gp:entity-audit` and `scripts/entity-preflight.sql` produce every number.
2. The real `employee_additional_info.name` spellings for TIN/EIN/UEI (Task 5 Step 5, Task 9 Step 7).
   **This is the one place in the plan where a guess is unavoidable**: that column is unconstrained
   free text, the dev source carries none of these keys, and a missing spelling is not a visible
   failure — it is an entity that silently never binds. The plan says so at both sites.
3. Telling CAMI about the `credential-search` contract amendment (Task 8 Step 9).

`docs/EVALUATION.md`'s pre-programme baseline table stays `PENDING`. That is plan 2's blocker, not
this plan's; Task 10's numbers are recorded separately and explicitly labelled as local.

### Type and interface consistency

- Nothing references a type no task defines. `BlockKey` (Task 3), `NameMatcher::orgCompatible()` /
  `normalizeOrg()` (Task 6), `GpEntityAudit` / `GpEntityReclassify` (Task 9), `EvalSet::addresses()`
  (Task 10) are each created before first use.
- Signature changes and their call sites: `ProbabilisticResolver::match()` gains a third parameter
  with a default, so existing two-argument callers keep working (Task 6 Step 6 updates the one real
  caller); `matchDeterministic()` and `enrich()` already carry plan 5's `$identifiers`;
  `resolve(int): int` is unchanged, which is what keeps `EvalRunner` and every plan 1 test working.
- `Versioner::TABLES` gains two attribute names and no new table, so plan 3's
  `test_the_register_matches_docs_scd2` (which asserts the exact key list) is untouched.
- `Survivorship::authorityRank()`'s prefix matching is the one change in this plan to shared,
  non-entity behaviour. It is called out at the site.

### Known risks carried into execution

1. **The inference rule is the whole plan's foundation, and its ambiguous case is unmeasured.**
   `business` populated *and* a name present is 0 of 109 rows locally, which cannot rule out a
   population in production. If it is large, the rule's decision (stay individual) is still the safe
   one — it produces false negatives, which cost less than the permanent false splits a false
   positive causes — but the `mixed` bucket will be large and plan 6's steward work becomes the
   critical path. `gp:entity-audit` measures it before anything is written.
2. **`org_name` is `varchar(255)` but `gp_identity_alias.alias_name` is `varchar(100)`, on a
   `strict` connection.** An organization name over 100 characters staged as a business alias will
   make `AliasIndexer`'s `INSERT … SELECT TRIM(n.name)` throw rather than truncate. This is
   pre-existing and orthogonal — it fires today, without any of plan 4 — but plan 4 makes long
   organization names more visible, so it is worth knowing where the failure will come from. Not
   fixed here: changing that column's width is an index rebuild on the one table that carries the
   working entity search, and it belongs in its own change.
3. **Task 6's entity weights are set from first principles, not calibrated.** They govern zero
   existing rows, so the risk is bounded to newly classified entities, and the review band means a
   Pass B bind is logged rather than silent. But the numbers are a judgement, and the eval fixture's
   four organizations are a smoke test, not a calibration set.
4. **The two-letter state truncation in the entity block key collides** (`MI` for Michigan,
   Missouri and Mississippi). Harmless to correctness — blocking widens, it does not decide — but it
   inflates those buckets against `block_size_cap` (2000), over which the caller mints a new identity
   stamped `auto_match`: a silent false split. Pre-existing mechanism, plan 6 owns the fix, and
   `gp:entity-audit` reports the distribution so it is watched rather than discovered.
5. **The set-based paths are not converted.** They refuse to run under plan 3a's guard, so there is
   no live divergence, but six specific obligations are handed to plan 3b in
   `docs/ENTITY-TYPES.md` — including the `SetFinalizer::authorityRankSql()` mirror of the
   `authorityRank()` prefix change, which is the kind of divergence this codebase has already been
   bitten by once. If plan 3b lifts the guard without doing them, `residualCreateAndLink()` will
   mint every organization as an individual.
6. **`gp:entity-reclassify` is the riskiest artefact in the plan.** It is `--dry-run` by default,
   unanimity-gated, `Versioner`-written so every change is reversible, logged one row per identity,
   and its runbook requires the audit table to be filled in first and a `--limit=100` trial before
   the full run. What it cannot protect against is a systematically wrong inference rule: if the rule
   is wrong, unanimity means every row agrees on the wrong answer.
7. **`GROUP_CONCAT` truncation in the audit's shape query.** `group_concat_max_len` defaults to 1024
   bytes, so a pile-up identity (identity 3 folds 12,463 source rows) can lose its `org_name`. The
   reclassify command skips a nameless identity with a warning rather than writing one — an entity
   with no `org_name` has no block key and no Pass B, so it would be worse off than before — but the
   operator should expect warnings on the pile-up identities and may want to raise
   `group_concat_max_len` for the session.

### Collisions with two plans authored alongside this one

Two documents appeared in `docs/superpowers/plans/` during this plan's authoring that are not rows
in the brief's eight-plan table. Both intersect this plan, and both intersections are cheap to
resolve **if they are resolved deliberately**.

**`2026-09-03-gpp-conformance-pass-b-blocking.md` (numbered 5b, depends on 1 and 5).** It widens Pass
B's candidate discovery with a `name_state` leg and a `name_state_zip` leg, held in a new
`stg_person_block_key` child table, and explicitly leaves `stg_person.block_key` alone as the home of
the existing phonetic-last-name + birth-year leg. Three concrete overlaps:

1. **Migration filename collision — resolved.** As originally authored, both plans proposed
   `2026_09_06_000000_create_stg_person_block_key` (renumbered per `00-PROGRAMME.md` §3, which resolved the collision), and this plan's own Task 2 migration also
   collided on that prefix. This is no longer a per-implementer judgment call: `00-PROGRAMME.md` §3
   assigns 5b's migration `2026_09_06_000000_create_stg_person_block_key` and this plan's Task 2
   migration `2026_09_07_000000_add_entity_type_and_org_name`, strictly increasing in dependency
   order. Use those filenames; do not renumber at merge time.
2. **`BlockKeyBuilder` versus this plan's `BlockKey` — resolved the other way round.** 5b creates
   `app/GoldenProfile/Support/BlockKeyBuilder.php` to compute its two new legs; Task 3 here creates
   `app/GoldenProfile/Support/BlockKey.php` to collapse the three existing duplicate implementations
   of the `stg_person.block_key` rule and add its entity branch. Two similarly named support classes
   both computing blocking keys is precisely the divergence both plans argue against, and
   `00-PROGRAMME.md` §5 assigns block-key construction to 5b, not to this plan — the reverse of what
   this section originally proposed. **Task 3's entity branch delegates to a new
   `BlockKeyBuilder::entityNameState(?string $orgName, ?string $state): ?string` method instead of
   computing the key itself**; see Task 3 for the code. `BlockKey` keeps the individual-leg rule (it
   is pre-existing and 5b has no reason to own it) and the entity-leg *rule*, just not the entity-leg
   *construction*.
3. **5b's new legs are not entity-aware.** `name_state` and `name_state_zip` key on a person's name,
   which is NULL on every entity row, so an organization gets a null value for both legs and gains
   nothing from the widening. Under the canonical order (`00-PROGRAMME.md` §2) 5b lands before this
   plan, so this is a known gap this plan inherits rather than an open ordering question: an entity
   record has no widened Pass B candidate discovery until a follow-up teaches those legs to read
   `org_name` for `entity_type = 'entity'` rows. Either plan may pick that follow-up up; both must
   agree that an entity leg is prefixed so it cannot collide with an individual leg — the reason
   `BlockKey` prefixes `E:` at all.

**`2026-09-03-gpp-conformance-scd2-set-based-parity.md` (plan 3b).** This is the home for the six
obligations `docs/ENTITY-TYPES.md` hands over, which is good news — but it also **deletes
`SetBasedPathGuard`**. This plan's Task 4 scopes the per-row path only, and its justification for
not converting `SqlBackfill` / `SetFinalizer` is that 3a's guard makes them unreachable. **If 3b
lands before this plan, that justification evaporates**: the bulk paths become live, and
`residualCreateAndLink()` would mint every organization as an individual while the per-row path
classified it correctly — a divergence with no error anywhere. Task 1 Step 1 checks for
`SetBasedPathGuard`'s *presence*; if 3b has already removed it, that check will report `MISSING` and
stop, which is the right outcome but for a misleading reason. **If 3b has landed, do the six
obligations from `docs/ENTITY-TYPES.md` as part of Task 4 rather than deferring them,** and say so
in the commit.

### Task count, and the split if one is wanted

**Ten tasks. I am not recommending a split.** Each is independently testable, each ends with a
commit, and the sequence has one property that makes a long chain safe here: **Tasks 2 through 9
cannot move the eval gate**, because every fixture record is an individual and every change is
either additive or a narrowing that every individual row already satisfies. Each of those tasks
states that expectation explicitly, so a gate that *does* move is an immediate, localised signal
rather than a mystery discovered at the end.

If a reviewer wants it smaller, the cut line is clean and it is here:

- **4a — schema, matching and the gate:** Tasks 1, 2, 3, 4, 5, 6, 10. Ships the discriminator, the
  inference, the full entity ladder, entity Pass B, and the eval coverage that proves all of it.
  `EvalRunner` calls `resolve()` only — never `Survivorship`, never `ProfileMaterializer` — so Task
  10 has no dependency on 7, 8 or 9, and 4a is a complete, tested, self-consistent change.
- **4b — the read model, the API and the migration of history:** Tasks 7, 8, 9. Everything a
  *consumer* sees, plus the reclassification of existing rows. This is also the half that needs
  production hub numbers and a conversation with CAMI, so it is the natural place for a pause.

The one thing that must not be split apart is Tasks 3 and 6. Task 3 gives an organization a block
key, which is what lets it reach `ProbabilisticResolver::score()` for the first time — and
`jaroWinkler('', '')` returns `1.0` from its first line, so a hub running Task 3 without Task 6 would
award the full 0.45 name weight to two organizations for agreeing on nothing. They ship together or
not at all.


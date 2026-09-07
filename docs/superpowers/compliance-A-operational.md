# Compliance audit A — CAMI operational specs vs. the ten conformance plans

**Auditor scope:** three Confluence pages only —

- **P-CHK** — "Golden Profiles — Delivery Plan & Checklist" (4213309451, v7, 2026-09-02)
- **P-FLOW** — "Data Flow by CAMI" (4099997697, v3, 2026-07-21)
- **P-PROF** — "Proposed Process Flow by CAMI — with Profiling Algorithm" (4100390914, v4, 2026-07-21)

Out of my lane and audited elsewhere: Data Model, Building One Trusted Record, How Record Matching
Works, the matching appendices, Recommendations & Open Risks.

**Bounding scope decisions applied (not findings):**

- **SD-1** — conformance is inside the Laravel/MySQL hub. The AWS lakehouse, Iceberg/Spark, an ML
  matcher and external government-feed ingestion (NPPES / LEIE / SAM / state boards / scrapers) are
  rejected in `PROJECT_PLAN.md` §8.
- **SD-2** — code changes to match the docs on the two documented contradictions: SSN storage
  (plan 2) and the SCD-2 `current` flag (plan 3a).

Plan/task citations resolve against `docs/superpowers/plans/`; `00-PROGRAMME.md` is the tiebreak on
numbering and ownership.

---

## Verdict counts

**96 discrete requirements**, one row each in §§1-5. Primary verdict per row:

| Primary verdict | Rows |
|---|---|
| COVERED | 44 |
| PARTIAL | 29 |
| OUT OF SCOPE | 10 |
| **UNADDRESSED** | **11** |
| DEFERRED WITH CAUSE | 2 |

Two secondary counts matter more than the primary table, because a PARTIAL row's *missing half* is
where the work is:

- **21 distinct UNADDRESSED findings** (UN-1 … UN-21, registered exhaustively in §6). Eleven rows are
  wholly unaddressed; ten more are PARTIAL rows whose named missing component has no owner
  (UN-2, UN-5, UN-8, UN-9, UN-10, UN-11, UN-12, UN-15, UN-16, UN-17, UN-20, UN-21).
- **9 deferrals I judge sound** (§8). Only two are a row's whole verdict (P12, C3.6); the rest sit
  inside PARTIAL rows (C3.5 `(state, provider#)`, C3.9 the steward queue, C4.1 `gp_board_action`,
  C4.3 reinstatements, C4.5 rebuild reproducibility) or inside plan-level design refusals.

---

## 1. P-FLOW — Data Flow by CAMI: the core pattern

| # | Requirement | Source | Verdict | Evidence |
|---|---|---|---|---|
| F1 | "Nearly every table carries a `current tinyint(1)` flag plus `date_created` / `date_updated`." | P-FLOW § Core design pattern | **COVERED** for every table with a doc analog | Plan 3a Task 3 (`2026_09_04_000100_add_scd2_versioning`) adds `version_no`, `current`, `date_created`, `date_updated` and a single-current unique to the six tables that map onto doc tables: `gp_identity` (`individuals`/`entities`), `gp_license` (`licensing_credentials`), `gp_address` (`addresses`+joins), `gp_identity_credential` (`credential_matches`), `gp_identity_exclusion` (`exclusion_matches`), `gp_identity_identifier`. Plan 3a Task 1 commits the register as `docs/SCD2.md`. |
| F2 | "Insert a new row with `current = 1`, and set all preexisting rows to `current = 0`." | P-FLOW § Core design pattern | **COVERED** | Plan 3a Task 5 builds `Versioner::write()/current()/retire()` as the single write primitive; `00-PROGRAMME.md` §5 assigns it one owner (3a) and forbids a second write path. Plan 3a Tasks 6–9 convert `DeterministicResolver`, `Survivorship`/`ProfileMaterializer`, `Engine` and Pass B/API. Plan 3b Task 2 (`VersionerSql`/`SetVersionWriter`) gives the bulk path the same rule; 3b Task 8 removes `SetBasedPathGuard` only after proving parity. |
| F3 | "`current = 1` always points at the latest truth, while older rows preserve a full audit trail." | P-FLOW § Core design pattern | **COVERED** | Plan 3a Task 3 single-current unique index; Task 8 converts merge to "retire instead of delete"; Task 7 makes `ProfileMaterializer` read `current = 1` only; plan 3b Task 4 filters `SetFinalizer::materializeRange()`'s aggregates to `current = 1`. |
| F4 | "No new version unless a golden fact actually changed" is not in the doc, but the doc's rule taken literally would mint 13.38M rows per `finalizeAll()`. | P-FLOW § Core design pattern (consequence) | **COVERED** | Plan 3a § Row growth + Task 5: `Versioner::write()` compares only declared `attributes`, so an idempotent finalize writes no version. |
| F5 | The `current` name collision on `gp_identity_credential` (CAMI's mirrored currency flag already occupies it). | P-FLOW § Core pattern vs. existing schema | **COVERED** | Plan 3a Task 2 renames the mirrored column to `source_current` and updates all five readers (`ProfileMaterializer:84`, `SetFinalizer`'s `$cred` subquery, `CredentialSearchController:311`, `Engine::rollupCredentials()`, `SqlBackfill::rollup()`) **before** Task 3 adds the flag; migration `2026_09_04_000000` per `00-PROGRAMME.md` §3. The API field name `current` is preserved. This is scope decision SD-2 discharged. |
| F6 | "The Golden Profile also **cross-references CAMI** rather than replacing it — tables keep `cami_employee_id`, `cami_match_id`, and `cami_credential_match_id` columns." | P-FLOW § Core design pattern | **COVERED** (pre-existing, affirmed) | `gp_source_link(system_id, source_table, source_id)` is the employee pointer; `gp_identity_exclusion.match_id` and `gp_identity_credential.credential_match_id` are the match pointers (verified in `2026_07_20_140000_create_golden_profile_schema.php`). Plan 3a § "Which tables are versioned" explicitly rules `gp_source_link` untouched to preserve this. No plan needs to build it. |
| F7 | `date_created` / `date_updated` as literal column names. | P-FLOW § Core design pattern | **PARTIAL — accepted deviation** | Plan 3a § "`date_created`/`date_updated`: map, don't rename" adopts the doc names verbatim on the five tables that have no timestamps, and **maps** `gp_identity.first_seen`/`last_updated` instead of renaming, because `last_updated` is a public API field on `IdentityProfileResource`. Reasoning is sound and the cost of the alternative is enumerated. The un-mitigated part: `gp_identity.last_updated`'s *semantics* change (stops moving on every rebuild) and this is a visible change to both endpoints' output — recorded in `docs/SCD2.md` but not communicated to CAMI as a contract note the way plan 4 Task 8 Step 9 does for `credential-search`. |

### Judgement asked for: are plan 3a's non-versioning exclusions defensible against "nearly every table"?

**Yes, and `gp_source_link` in particular is defensible.** Three independent reasons, all checkable:

1. **The doc's own schema has no link table at all.** P-FLOW's fourteen tables key everything on
   `cami_employee_id` directly. A link table appears only in P-PROF's Schema-implication section, as
   one of two *options* for making the pointer many-to-one. So there is no doc row carrying `current`
   that `gp_source_link` is the analog of — "nearly every table" cannot be read to include it,
   because the doc never listed it.
2. **P-PROF assigns grouping and versioning to different halves of the design.** P-PROF's whole
   thesis is that grouping is the missing arrow and versioning alone yields "a version history, not a
   golden profile". Plan 3a §"The tension between the two design pages" quotes both pages and rules
   grouping out of scope for itself precisely so the two obligations stay separable. That is the
   correct reading, not an evasion.
3. **The mechanical cost is real and named.** `uq_source(system_id, source_table, source_id)` is
   what makes `DeterministicResolver::resolve()` idempotent (verified: `resolve()` returns the
   existing `identity_id` before consulting any tier). Versioning the table breaks that unique, and
   the audit trail a version would provide already exists in `gp_resolution_log` (append-only,
   `action` enum) and `gp_edge` (append-only match evidence) — both of which plan 3a correctly
   declines to version for the same reason.

The one soft spot, stated for completeness: plan 3a writes "a link is created once, and if the
grouping changes the row is repointed", which understates it — a repoint changes the single most
consequential fact in the system. But the plan routes that event to `gp_resolution_log`, and plan 3a
Task 8 makes merge retire-not-delete, so the audit trail the doc asks for does exist for that event.
**The exclusion stands.** The other twelve exclusions (`gp_attribute`, `gp_survivorship_audit`,
`gp_resolution_log`, `gp_edge`, `gp_board_action`, `gp_identity_resolution` — already SCD-2 via
`is_current` —, `gp_identity_profile`, `gp_identity_alias`, the five `stg_*` tables, the three `src_*`
buffers, `gp_source_system`/`gp_watermark`) each carry a specific reason and none has a doc analog
that carries `current`. All DEFERRED WITH CAUSE, all sound.

---

## 2. P-FLOW — the fourteen-table schema, mapped

Explicitly requested check #4. "Existing" means the table pre-dates the programme.

| # | Doc table | Mapped to | Verdict | Evidence |
|---|---|---|---|---|
| F8 | **`individuals`** — golden record for a person | `gp_identity` where `entity_type = 'individual'` | **COVERED** | Plan 4 Task 2 (`2026_09_07_000000_add_entity_type_and_org_name`) adds `entity_type VARCHAR(12) NOT NULL` + `ck_identity_entity_type CHECK`; plan 4 Task 3 infers the type at the one ingestion choke point. |
| F9 | **`entities`** — golden record for an organization (UPIN, TIN + hash + last four, NPI) | `gp_identity` where `entity_type = 'entity'`, `org_name`; TIN via `gp_identity_identifier.id_type = 'tin'` | **PARTIAL** | `upin`/`npi` are existing columns; `org_name` is plan 4 Task 2; TIN/EIN/UEI are plan 4 Task 5 riding plan 5's multi-valued identifier tier. **Not delivered:** `tin_hash` and `tin_last_four`. Nobody declines them either — see UN-8. |
| F10 | **`individual_names`** — name history (first / middle / last / **maiden**) | `gp_identity.canonical_*` under SCD-2 + `gp_identity_alias` | **PARTIAL** | Plan 3a Task 5 puts `canonical_first/middle/last/suffix` in `Versioner::TABLES['gp_identity']['attributes']`, so a rename mints a version and the prior name survives at `current = 0`. That is a *sequential* history. The doc's `maiden` is a **concurrent** variant — a person has a maiden name *and* a current name simultaneously, which SCD-2 on the parent row cannot represent. Maiden names do reach `gp_identity_alias`, but plan 4 § "What this plan deliberately does not touch" verifies `stg_person_alias.alias_type = enum('maiden','alt','business')` while `gp_identity_alias.alias_part = enum('last','first')` — **the maiden label is lost downstream and no plan restores it.** Plan 4 gives a reason for not touching the enum (it is the only working entity lookup path; its composite PK is its dedup guarantee) but that reason is about *business* aliases, not about maiden names as a golden fact. |
| F11 | **`entity_names`** — name history for an organization | `gp_identity.org_name` under SCD-2 + business aliases in `gp_identity_alias` | **COVERED** | Plan 4 Task 2 + plan 4 Task 7; `org_name` is a `Versioner` attribute so renames version. Plan 4 §"`org_name` is its own column" gives a mechanical reason not to reuse `canonical_last` (`Survivorship::IDENTITY_FIELDS` maps it from `stg_person.last_name`, NULL on entities) — verified and correct. |
| F12 | **`addresses`** — shared address book (address1/2, city, state, zip) | `gp_address` (collapses book + join) | **COVERED** | Existing table; plan 3a Task 3 versions it, which is how the doc's address *history* is kept. |
| F13 | **`individual_addresses`** — person ↔ address join | `gp_address.identity_id` + `entity_type` predicate on the parent | **COVERED (by argument)** | Plan 4's ruling table. A per-type join adds nothing an `entity_type` predicate does not give under a single-table discriminator. |
| F14 | **`entity_addresses`** — entity ↔ address join | same | **COVERED (by argument)** | same |
| F15 | **`licensing_credentials`** — licences/certs (certification number & state, license type, CSL number & state, DEA number, certification board) | `gp_license` + `gp_identity_identifier` (DEA) | **COVERED** | `gp_license` carries `license_number`, `certification_state`, `certification_board`, `license_type`, `license_type_id`, `registry`; CSL rows are staged by `StreamlineLocalConnector` (`addLic($v['csl_number'], $v['csl_state'], null, 'CSL', 'CSL')`, lines 233-235) so CSL is a `license_type`, not a separate column pair — a legitimate normalization. DEA is `gp_identity_identifier` (`2026_07_25_000001` docblock: "DEA/MMIS are match keys; CSL and alt licenses are child tables, not columns"). Plan 3a Task 3 versions both. |
| F16 | **`credential_databases`** — registry sources for credential verification (prefix, type, state, url, `match_status_map`, `required_fields`) | **nothing** | **UNADDRESSED (UN-1)** | No `gp_*` table exists (verified: full `Schema::create` inventory of `database/migrations/`). The only trace is a `registry varchar(255)` string on `gp_identity_credential`, `gp_license` and `src_credential_match` — a free-text label, not a catalogue. `gp_source_system` is the CAMI-source-system registry, not a credential-registry one. No plan builds it and no plan declines it: grep for `credential_databases`, `match_status_map`, `required_fields` across all ten plans returns **zero hits**. |
| F17 | **`credential_matches`** — versioned credential/licence check results (search params, `match_summary_status`, `match`, `status`, `expiry_date`, `check_date`) | `gp_identity_credential` | **PARTIAL** | The mirror exists and plan 3a Task 3 versions it, but four of the doc's six named payloads are absent from the golden table: **search params**, **`match`** (the raw payload), **`expiry_date`** and **`check_date`**. Consequence is not cosmetic — see UN-2. |
| F18 | **`credential_match_resolutions`** — notes/resolutions against a credential match | `gp_identity_resolution` where `domain = 'credential'` | **COVERED** | Plan 6 Task 5 (`ResolutionIngest::processCredentialResolutions()`) reads CAMI's `credential_match_resolutions` (verified as the correct source table; plan 6 §1 documents that `credential_match_actions` is a *different*, system-generated table and is deliberately not used); plan 6 Task 2 (`ResolutionRecorder`) is the single writer; plan 6 Task 1 (`ResolutionMapper::credentialTargetKey()`) pins `target_key` byte-identical to `CredentialSearchController.php:322-325`. |
| F19 | **`exclusion_lists`** — sanction registry sources (prefix, type, url, `verify_email`) | **nothing** | **UNADDRESSED (UN-3)** | No `gp_*` counterpart. `src_exclusion_record.exclusion_list_prefix varchar(64)` and `gp_identity_exclusion.registry varchar(64)` are label strings. Zero hits for `exclusion_lists` in the plans other than `00-PROGRAMME.md` §6 citing `exclusion_lists.type` as *source-side* evidence for the `gp_board_action` open decision — that citation shows the table is known and useful, and that no plan owns mirroring it. Doubly consequential because `type` is exactly what would settle §6. |
| F20 | **`exclusion_matches`** — versioned hits with eight match-quality flags, `match`, `hash` | `gp_identity_exclusion` | **PARTIAL** | Plan 3a Task 3 versions it and plan 7 Task 1 adds `matched_on`, `match_score`, `source_record` (the raw `exclusion_records.match` JSON mirrored verbatim — this satisfies the doc's `match`). But the schema carries **five** of the doc's eight flags; `is_diminutive_name_match`, `is_aka_name_match`, `is_npi_mismatch` are absent. Plan 7:441-447 states they are absent, that `Engine::rollupExclusions()` "has never mirrored" them, and that mirroring them would be "a fourth, separate expansion of what gp-cami pulls from `matches`, out of scope here" — a **boundary statement, not evidence that the expansion is unwarranted**, and no other plan picks it up. (The surrounding reasoning *is* excellent: plan 7 refuses to force `is_canonical_name_match` into the doc's `name_dob` enum value because `streamline_local.matches` carries no DOB-match or address-match flag at all, verified against the live schema, and asserting a DOB corroboration gp-cami never checked is "exactly the kind of fabricated certainty that turns a plausible-looking match into a false 'not excluded'". That part is right.) The doc's `hash` is unmirrored with no statement at all. |
| F21 | **`exclusion_match_actions`** — resolution steps with mismatch flags (DOB/SSN/first/middle/last/address/NPI/job), `action_type`, `resolved_via`, `resolution_source_data` | `gp_identity_resolution` where `domain = 'exclusion'` | **PARTIAL** | Plan 6 Task 4 (`ResolutionIngest::processExclusionActions()`) ingests `match_actions` and maps `action_type`, `resolved_via`, `is_auto_resolved`, `note`, `user_id`, `date_created` into `gp_identity_resolution` + `resolution_metadata`. **The eight per-attribute mismatch flags are not mapped.** Plan 7 §"What was checked" inspected them and correctly ruled them plan 6's domain rather than lifecycle — but plan 6 then does not carry them, so they fall between the two plans. `resolution_source_data` is likewise unmapped. |
| F22 | Cosmetic diagram typos to fix: `tin- varchar(500)`; `exclusion_list_id - id` → `int`; duplicated `date_created`/`date_updated` on `licensing_credentials`. | P-FLOW § Notes & open items | **OUT OF SCOPE (SD-1)** | These are corrections to the Visio source before it becomes DDL. gp-cami does not create the doc's literal tables, so there is nothing to fix. Plan 3a §Versioned notes the duplicate-timestamp typo in passing. |
| F23 | `individuals` carries **SSN + hash + last four**. | P-FLOW § Schema | **OUT OF SCOPE (SD-2) — and a documentation contradiction** | Plan 2 deletes `ssn_hash`, `ssn_last_four` and the full SSN under scope decision SD-2 and under P-CHK §1's own "never store SSN". See §7 Contradiction C-1. |
| F24 | `individuals` carries hire date / termination date, job title, `facility_id`. | P-FLOW § Schema | **UNADDRESSED (UN-4)** | No golden home for any of the four. Plan 4:279 and :967 cite `facility_id varchar(65) NOT NULL` only as evidence that it is free-text client data and therefore *not* an entity-type signal — it never becomes a golden attribute. `hire_date`, `termination` and `job_title` appear nowhere in any plan (the `Termination_Date` hits in plan 7 are the SAM registry's own field, unrelated). Low individual consequence, but four named doc columns with no owner and no stated decline. |
| F25 | `individuals` carries `mmis_number`. | P-FLOW § Schema | **COVERED** | Plan 5 Task 8 carries `state` through the DEA/MMIS identifier pipeline; Task 9 promotes DEA and `(state, MMIS)` to real match keys on `gp_identity_identifier`. Plan 3a Task 3 versions that table. |

---

## 3. P-FLOW — the four processes

| # | Requirement | Source | Verdict | Evidence |
|---|---|---|---|---|
| F26 | **Process 1.** Employee created/updated in CAMI fires the sync. | P-FLOW §1 | **COVERED** (pre-existing) | `Engine::sync()` on `gp_watermark`; plan 8 Task 6 adds `routes/console.php` scheduling for `gp:sync`. |
| F27 | **Process 1.** "Decide the **employee type**" before writing. | P-FLOW §1 | **COVERED** | Plan 4 Task 3 puts the inference at `StreamlineLocalConnector::personRow()` — the one choke point both ingestion paths call. |
| F28 | **Process 1.** Individual → versioned `individuals` insert; Entity → versioned `entities` insert. | P-FLOW §1 | **COVERED** | Plan 4 Task 2 + plan 3a Task 5/6 (one table, `entity_type` discriminator — see the judgement below). |
| F29 | **Process 2.** Credential match saved with a result → versioned `credential_matches` insert. | P-FLOW §2 | **COVERED** | Plan 3a Task 8 Step 5 converts `Engine::rollupCredentials()` from a batched `upsert()` to per-row `Versioner::write()`; plan 3b Task 7 does the set-based twin. |
| F30 | **Process 3.** Match saved in CAMI → versioned `exclusion_matches` insert. | P-FLOW §3 | **COVERED** | Plan 3a Task 8 Step 5 (`rollupExclusions()`); plan 7 Task 3 registers the three new columns in `Versioner`; plan 7 Task 4 computes and persists them; plan 3b Task 7 for the bulk path. |
| F31 | **Process 4, leg A.** "Check the Golden Profile for a valid `credential_matches` row matching the params … Has valid → **return the cached result to CAMI (no external call)**." | P-FLOW §4 | **PARTIAL** | The read exists and pre-dates the programme: `CredentialSearchController::latestQualifyingCredential()` + `CredentialSelector::pick()`. Plan 3a Task 9 Step 4 filters the two credential-search reads to `current = 1`; plan 4 Task 8 adds the entity branch; plan 2 Task 5 narrows the SSN contract. **The part that is not delivered:** the "no external call" property does not hold. The controller's own chunk loop queries `DB::connection('streamline_local')->table('credential_matches')` for `expiry_date`, `date_updated`, `date_created` and the payload-extracted `req_ssn`/`req_dob` on every request (verified, lines ~250-265), because those four values were never mirrored into `gp_identity_credential` (F17). A cache that must round-trip to the operational database to decide whether its own entry is usable is not the cache the doc specifies. No plan owns closing this. → **UN-2**. |
| F32 | **Process 4, leg B.** "Has name mismatch → check `credential_match_resolutions` … Has resolution → return the result to CAMI **and auto-resolve the name mismatch**." | P-FLOW §4 | **PARTIAL** | This is the leg plan 6 genuinely delivers, and it is the plan's real payoff. `CredentialSearchController::priorResolution()` already reads `gp_identity_resolution` correctly but **always returns null today because nothing ever writes that table** — plan 6 §2 states this explicitly ("the only thing that was missing was real data in the table"), and plan 6 Tasks 1, 2, 5, 6 supply it: `ResolutionMapper` (Task 1) pins `target_key`, `ResolutionRecorder` (Task 2) is the single writer, `processCredentialResolutions()` (Task 5) maps CAMI's `credential_match_resolutions`, `gp:ingest-resolutions` + watermarks (Task 6) run it. Plan 6 Task 8 adds `isWithinReuseDecayWindow()` and gates `auto_resolvable` on `golden_profile.resolution.reuse_decay_days`. **What is not delivered:** the endpoint *reports* `prior_resolution` with an `auto_resolvable` boolean; **it does not auto-resolve anything.** The doc's verb is "auto-resolve the name mismatch", i.e. write the resolution onto the new match. Plan 6 is honest that gp-cami is API-only and adds no identity- or match-mutation endpoints, but it does not say who performs the auto-resolve, and no plan assigns it to CAMI as a contract obligation the way plan 4 Task 8 Step 9 assigns the `credential-search` request change. → **UN-5**. |
| F33 | **Process 4, leg B'/C.** "No resolution → **trigger the bots to scrape** the registry, then return the result"; "No matches found → **trigger the bots to scrape**, then return the result." | P-FLOW §4 (twice); P-PROF §2 (twice more) | **UNADDRESSED (UN-6) — nobody's job** | Grep across all ten plans for `trigger the bot`, `bots to scrape`, `scrape the registry`, `scraper trigger`, `pbot`: **zero hits.** The controller has three outcomes (404 no identity; 200 with `match: null`; 200 with a match) and no scrape leg. The doc states this requirement four times across the two pages and it is the fallback path for *every* cache miss — i.e. the majority of traffic on day one. It is not out of scope under SD-1: the bots are CAMI's existing internal scrapers, not an external government feed. It is simply unowned. |
| F34 | **Process 4, net effect.** "The registry-scraping bots only run when the profile has no usable answer." | P-FLOW §4 | **UNADDRESSED** | Follows from F33. Nothing in gp-cami suppresses a scrape, because nothing in gp-cami is in the scrape decision path. The endpoint is advisory: CAMI must choose to consult it and choose to honour the answer, and no plan documents that contract. |

### Judgement asked for: what does plan 6 actually deliver of process 4?

Cleanly separated, plan 6 delivers **one of the three legs and half of a second**:

- **Leg B (resolution reuse) — delivered.** This was the genuine dead code (`priorResolution()`
  returning null forever) and plan 6 Tasks 1/2/5/6 fix the actual cause. The decay window (Task 8) is
  a real new capability and plan 6 correctly distinguishes it from a wiring. Plan 6 also states the
  over-merge amplification risk precisely — CAMI's own reuse is scoped to one `employee_id`,
  gp-cami's to an `identity_id` spanning many employees — and declines to invent a guard, which is
  the right call given the eval gate is the actual defence.
- **Leg A (cache hit) — pre-existing, and still not self-sufficient (UN-2).**
- **Leg C (trigger the bots) — nobody's (UN-6).** Not deferred; absent. Plan 6's own scope statement
  is "the *writer* layer (data reaching the hub)", which is a coherent boundary — but no plan picks
  up the reader/dispatch side, so the boundary has open air on the far side of it.
- **The auto-resolve write itself — nobody's (UN-5).**

---

## 4. P-PROF — Proposed Process Flow with Profiling Algorithm

| # | Requirement | Source | Verdict | Evidence |
|---|---|---|---|---|
| P1 | "`individuals.id` / `entities.id` effectively **is** the `profile_id`." | P-PROF § The key idea | **COVERED** | `gp_identity.identity_id`; plan 3a §"The tension between the two design pages" quotes this page and rules on it. |
| P2 | "If nothing groups across `cami_employee_id`, the result is a version history, not a golden profile. Profiling is the missing arrow." | P-PROF § The key idea | **COVERED** (pre-existing) | `DeterministicResolver` + `ProbabilisticResolver`; grouping materialised as many `gp_source_link` rows per `identity_id`. Plan 3a quotes this warning verbatim and treats the two pages as "two requirements, not two options" — the correct reading, and it is why 3a forbids itself from touching a matching threshold. |
| P3 | **Schema implication:** "the source-record pointer must be **many-to-one** against the golden identity — one `individuals.id` can map to many `cami_employee_id`s." Options: many-to-one `cami_employee_id`, or an explicit link table. | P-PROF § Schema implication | **COVERED — satisfied by the second option, `gp_source_link`** | Explicitly requested check #5. `gp_source_link` is a link table with `unique(system_id, source_table, source_id)` and a non-unique `identity_id` that many rows share (`idx_identity`), which is exactly the doc's second option. Plan 3a states this and pins it: "Do not version `gp_source_link`, do not change `uq_source`". Plan 4's ruling relies on the same property. Plan 8 §"What already conforms" re-verifies it as the crosswalk and does not rebuild it. **Verdict: satisfied, by the pre-existing `gp_source_link` table, and protected by plan 3a's explicit no-touch rule and plan 4's Task 1 assertion (`gp_source_link` is not versioned, `uq_source` unchanged).** |
| P4 | **Profiling in Employee Sync is "Primary":** resolve the incoming record to an existing golden identity vs. create a new one, *before* inserting or versioning. | P-PROF § Where profiling fits; §1 | **COVERED** | `DeterministicResolver::resolve()` runs before any write. Plan 4 Task 3 puts type inference upstream of it; plan 4 Task 4 type-scopes the ladder so a person can never bind to an organization; plan 5 Tasks 9/10 add the DEA and `(state, MMIS)` tiers; plan 5b Tasks 1–5 widen Pass B blocking; plan 8 Tasks 1–5 give it a profile-level block-key index and signature. |
| P5 | **Employee-sync match keys:** "NPI, SSN / `ssn_hash`, DOB, name, address, license number". | P-PROF §1 | **PARTIAL** | NPI, DOB, name, licence number are live tiers; NPI gains Luhn validation (plan 5 Tasks 1-3) and junk screening (plan 5 Tasks 4-5). **`ssn_hash` is removed by scope decision SD-2** (plan 2 Tasks 2-3, 7), with plan 5's MMIS/DEA tiers as compensation and the ordering rationale in `00-PROGRAMME.md` §2 — that part is OUT OF SCOPE, not a gap. **`address` is the genuinely partial one:** address is not a resolve-time tier and does not become one; it is a Pass B *score* signal only. Plan 8 Task 5 adds zip scoring from `gp_identity_signature`; plan 5b Task 2 adds a name+zip blocking leg. But `00-PROGRAMME.md` §6 records the verified arithmetic that a record missing both address and zip caps at 0.72 against a `review_band_floor` of 0.75 — so the address signal cannot by itself reach even the review band, and recalibration is "Phase-3 work nobody has scheduled". |
| P6 | **Employee-sync write:** "attach the incoming `individual_names`, `addresses`, and `licensing_credentials` under the identity". | P-PROF §1 | **COVERED** | `DeterministicResolver::enrich()`, versioned by plan 3a Task 6; plan 3b Task 7 for the set-based `enrich()`. |
| P7 | **Profiling in Credentialing Search:** "**Resolve search params to a golden profile** — first / middle / last name, credential id, license type → a golden `individuals` record." | P-PROF § Where profiling fits; §2 | **UNADDRESSED (UN-7)** | `CredentialSearchController::resolveIdentity()` is a **plain equality lookup** on `gp_identity_profile.last_name` + `.first_name`, optionally narrowed by `dob`, `ssn_hash` and a `gp_license.license_number` id-list — no resolver, no alias fallback, no middle name, no confidence, no tier ladder (verified by reading the method; plan 4 Task 8 independently confirms "matches both exactly against `gp_identity_profile`, with no alias fallback"). On multiple candidates it logs a warning and takes the **lowest `identity_id` at the top `record_count`** — an arbitrary pick, which is precisely the decision P-PROF was written to say must be made by the profiling algorithm. Four plans touch this method (2, 3a Task 9, 4 Task 8, 6 Task 8) and **none routes it through `DeterministicResolver`/`ProbabilisticResolver` or states a reason not to.** This is the single clearest case where P-PROF's central thesis is not implemented. |
| P8 | **Profiling drives the *valid vs. name-mismatch* branch**, via `is_canonical_name_match` / `is_diminutive_name_match` / `is_aka_name_match`. | P-PROF §2 | **PARTIAL** | The three flags are exclusion-side columns on CAMI's `matches`; only `is_canonical_name_match` reaches `gp_identity_exclusion` (F20), and none reaches the credential domain at all. The credential path's "valid vs. name mismatch" split is CAMI's own `match_summary_status` / `match_is_valid`, mirrored and read by `CredentialSelector`. So the branch *exists*, driven by the source's status, not by a profiling name comparison. Plan 4 Task 6 does build `NameMatcher::orgCompatible()` and plan 5b Task 2 builds name-based blocking legs, but neither is wired into credential search. Nobody states the substitution. |
| P9 | **Profiling in Credential/Exclusion Match Sync:** "Attach the match to the correct golden identity". | P-PROF § Where profiling fits; §§3-4 | **COVERED** | `Engine::rollupCredentials()` / `rollupExclusions()` attach by `identity_id` via the existing link; plan 3a Task 8 Step 5 versions both; plan 3b Task 7 the set-based twin. |
| P10 | **Profiling produces the match-quality flags stored on the match row** — "the matcher's per-record output". | P-PROF § Where profiling fits; §4 | **PARTIAL** | Plan 7 Task 2 (`ExclusionMatchClassifier`) + Task 4 compute `matched_on` and `match_score` from the five mirrored flags and persist them — a real implementation of "the matcher's per-record output". But the flags themselves are **mirrored from CAMI**, not produced by gp-cami's matcher, and three of the eight are not mirrored at all (F20). Plan 7 is explicit that it builds "the honest subset". Sound as far as it goes; the doc's stronger claim (gp-cami's matcher generates them) is unmet and unaddressed. |
| P11 | **Run modes:** "Incremental (`profiling_batch`) — runs inside the real-time sync flows" and "Full re-group (`profiling_full`) — a periodic pass that reconciles the whole population as data drifts." | P-PROF § Run modes | **COVERED** | Plan 8 Task 6 builds `gp:reprofile` (dedup + finalize, sharded) and schedules both it and `gp:sync` in `routes/console.php` with a shared mutex — plan 8 correctly notes `withoutOverlapping()` guards a command against *itself*, not against a sibling. Plan 8 §"What already conforms" verifies the incremental half is already real-time. Plan 8 Task 7 (`IncrementalBatchParityTest`) proves both paths converge to one clustering. |
| P12 | Doc's assertion that the diagram "implies 1:1" today. | P-PROF § Schema implication | **DEFERRED WITH CAUSE — the doc is wrong about the code** | Plan 3a and plan 8 both verify `gp_source_link` is already many-to-one. Correctly recorded as a doc/reality mismatch rather than work. |

### Judgement asked for: plan 4's single-table `entity_type` argument vs. P-FLOW's two tables

Explicitly requested check #3. **The argument holds. I would accept it, with one reservation.**

What makes it hold, and why it is not merely a self-assessment:

1. **The five-requirements decomposition is checkable and four of the five check out.** Distinguishability →
   `entity_type` + a named CHECK. Address book/join → `gp_address` already collapses both. Entity
   identifiers → `upin`/`npi` are existing columns, TIN/EIN/UEI ride plan 5's identifier table.
   Licence-tier scoping → plan 4 Task 4 is a behavioural change, not a schema claim, and it is the
   one that matters (a person can no longer bind to an organization on a shared licence; the
   `org-lic`/`person-lic` fixture pair at plan 4:5828 is a real regression test for it).
2. **The cost of two tables is enumerated concretely, not asserted.** Fourteen tables carry a bare
   `identity_id` (I verified the list against the migrations). The `gp_source_link` chicken-and-egg
   is the strongest single point: `resolve()`'s idempotency check must find the existing link *before*
   it knows the record's type, and two link tables make that impossible. The
   `gp_identity_profile`/two-phase-paging point is also real — identity 3's 69MB `credentials` blob
   is why a UNION would lose `idx_name_dob` on the phase-1 sort.
3. **The plan states what the discriminator costs rather than hiding it**: six permanently-NULL
   columns on entity rows, `org_name` NULL on 13.38M individual rows, an all-NULL `idx_name_dob`
   tuple per entity, and — the honest one — **no row-shape CHECK**, deliberately, because a shape
   constraint would reject the `mixed` identities design question 6 decides to preserve, making
   `gp:entity-reclassify` undeployable. Shape is enforced one level up in
   `EntitySchemaTest::test_an_entity_identity_carries_no_person_only_fact` and counted by
   `gp:entity-audit`. Plan 4 calls this "a real weakening versus a database constraint" itself.

**My reservation, and it is the F10 row above, not the schema choice:** the claim
"`individual_names`/`entity_names` … Plan 3. … That *is* the name-history table" is the weakest link
in the table. SCD-2 on the parent yields exactly **one** current name per identity. The doc's
`individual_names` carries `maiden` alongside first/middle/last, i.e. simultaneous variants. Business
aliases survive (via `gp_identity_alias`, which plan 4 deliberately leaves alone with a good reason);
maiden aliases reach that table too but lose their label, because `alias_part` is
`enum('last','first')`. So the argument is right that a *history* table is not needed and wrong that
`gp_identity` versioning fully replaces `individual_names`. Narrow gap, correctly attributable, and
plan 4's own reason for not touching the alias enum does not cover it.

**Also worth noting in the plan's favour:** the `entity_type` inference has no source column behind
it (`00-PROGRAMME.md` §6 confirms all 70 columns of `streamline_local.employees` were checked), and
plan 4 §"Measured state of the signal" publishes the actual n=109 distribution, names the
empty-string trap that would misclassify 96 of 109 rows, states plainly that 0 ambiguous rows out of
3 entity rows establishes shape but not production prevalence, and routes the real measurement to
`gp:entity-audit` as a human task. That is the correct handling of an unmeasurable input.

---

## 5. P-CHK — Delivery Plan & Checklist

### Data sources & trust tiers (preamble)

| # | Requirement | Verdict | Evidence |
|---|---|---|---|
| C-a | Ingest T1 sources: NPPES/NPI, OIG LEIE, SAM.gov. Ingest T2: state exclusion lists, downloadable licences, board actions/FSMB/DEA. Ingest T3: scraped licences. Exclude NPDB. | **OUT OF SCOPE (SD-1)** | External government-feed ingestion is rejected in `PROJECT_PLAN.md` §8. gp-cami has one source, `streamline_local`. |
| C-b | "Internal verified data — ground truth & answer key." | **COVERED** | Plan 1 Task 4 builds `tests/eval/identity-pairs.json` from internal verified data + a loader; Task 5 the scorer; Task 6 `gp:eval` + the quality gate. |
| C-c | Trust-tier ordering drives survivorship (verified > NPI registry > licence > scrape). | **PARTIAL** | `config/golden_profile.php` `survivorship.field_authority` exists and `Survivorship::authorityRank()` reads it for `field_authority.identity`; plan 4 Task 7 adds entity survivorship rules. But with one source there is one tier in practice, and plan 7 §"Survivorship: `field_authority.exclusion` is structurally … unwired" documents that the exclusion field-authority list is not merely unused but has no hook to plug into. Mostly moot under SD-1; recorded because plan 7 found and stated it. |
| C-d | "**No uniform format** … cleaning is **per-source**. Each source gets its own parser/cleaner." | **OUT OF SCOPE (SD-1) — but the pattern is honoured** | With one source, `StreamlineLocalConnector::personRow()` *is* that source's parser/cleaner, and plan 5 Task 3 correctly puts the NPI rejection there rather than in either resolver — "the parity the brief asks for, achieved by not duplicating the check at all". The architecture is per-source-shaped even at n=1. |

### §0 Discovery & Source Profiling — explicitly requested check #6

| # | Requirement (quoted) | Verdict | Evidence |
|---|---|---|---|
| C0.1 | "Inventory each source's real shape: fields, file type, volume, cadence, value distributions, null rates" | **PARTIAL** | Real discovery **was** done, in three places, and it is better than "they all assume": plan 4 §"Measured state of the signal" (n=109 `streamline_local` rows, `business`/name-blank cross-tab, NULL vs `''` split, `alt_business1/2` at 0/0); plan 7 §"What was checked" (84 `exclusion_records` rows profiled by prefix into a shape table, 387 `matches` rows, 29 `match_actions` rows, all against `192.168.56.22` read-only 2026-09-04); `00-PROGRAMME.md` §8 (all 70 `employees` columns checked; `AREALNULL` found in 22 of 84 rows; `stg_ssn` proven to be an index name not a column; MySQL vs PHP `SOUNDEX()` divergence measured). **What is missing:** systematic per-field null rates and value distributions across the ~13.4M-row real hub. Every plan that needs one says so and routes it to a human — `00-PROGRAMME.md` §7 tabulates five such measurements with what each gates. Discovery on the dev sample: done. Discovery at scale: correctly identified as blocked, never faked. |
| C0.2 | "Document each source's quirks (LEIE records missing NPIs, placeholder values, per-state file differences, scraped-page variability)" | **PARTIAL** | Genuinely done for the one in-scope source: plan 7's per-prefix shape table records `"AREALNULL"` as a literal string in `oig`, `sam2`'s suspicious uniform `date_deleted`, `ca1`'s `"indefinitely effective"` non-date, and ten prefixes with no date field at all. Plan 5 Task 4 (`JunkKeyGuard`) and Task 6 turn placeholder discovery into code; `00-PROGRAMME.md` §5 mandates `AREALNULL` in the placeholder list. LEIE/per-state/scraped-page quirks: OUT OF SCOPE (SD-1). |
| C0.3 | "Confirm which identifiers each source carries (NPI, license+state, provider#, medicaid id) and **their fill rates**" | **PARTIAL** | *Which* identifiers: confirmed, and plan 5:1801-1813 is a model of it — `provider_number` was grepped across the client codebase and found only on state exclusion-registry source tables, so `(state, provider#)` "as literally described has no data source in scope" and MMIS is documented as satisfying both wiki labels, as a stated judgement call rather than a discovery. *Fill rates*: **not measured anywhere.** `scripts/baseline-key-mix.sql` (plan 1 Task 1) measures the **hub-side key mix** — which `match_key` bound each link, and how many identities carry an `ssn_hash` — which is a different quantity from source-column fill rates. `gp:npi-audit` (plan 5 Task 3) is written as real runnable code for the one fill-rate-adjacent number that matters most, and left as a human task. |
| C0.4 | "Decide record types in scope: **individual vs organization (entity)** — they need different rules" | **COVERED** | Plan 4, whole document: §"One table or two" (ruling), §"Design question 2" (measured), Task 3 (inference), Task 4 (type-scoped ladder), Task 5 (entity ladder), Task 6 (entity Pass B), Task 7 (entity survivorship), Task 9 (`gp:entity-audit`/`gp:entity-reclassify`), Task 10 (eval fixture, `true_pairs` 11→13). This is the checklist item most thoroughly discharged of the ninety-one. |
| C0.5 | "Capture findings + open questions" | **COVERED** | `00-PROGRAMME.md` §§6-8 (open decisions, unmeasurable measurements, verified corrections to the authoring brief's own claims), plus per-plan committed registers: `docs/SCD2.md` (3a Task 1), `docs/EVALUATION.md` (1 Task 1, 8 Task 8), `docs/ENTITY-TYPES.md` (4 Task 1), `docs/EXCLUSION_LIFECYCLE.md` (7 Task 6). |

### §1 Data Gathering

| # | Requirement | Verdict | Evidence |
|---|---|---|---|
| C1.1 | Connect NPPES/NPI (monthly full + weekly deltas + lookup API) as the identity anchor | **OUT OF SCOPE (SD-1)** | External feed. |
| C1.2 | Connect OIG LEIE (monthly) and SAM.gov (daily API); flag older LEIE records with no NPI | **OUT OF SCOPE (SD-1)** | External feed. |
| C1.3 | "Stream **internal verified data** via CDC (**never store SSN**)" | **PARTIAL** | The CDC half is met by watermarked incremental sync (`gp_watermark` + `Engine::sync()`; plan 8 Task 6 schedules it). The "never store SSN" half is scope decision SD-2 and is delivered by plan 2 Tasks 2-4, 6, 7 (`2026_09_03_000000_drop_ssn_columns`; `SsnHasher`/`SsnHashGuard` and their env vars deleted; survivorship/materialization/ingestion of SSN stopped). Marked PARTIAL only because the `ssn` **request parameter** is deliberately retained to gate credential matches whose own scrape recorded an SSN (plan 2 §"decision 2"); it is never persisted and never logged, which honours the requirement's intent, and plan 2 Task 5 narrows the published contract accordingly. |
| C1.4 | Config-driven adapter for state exclusions & downloadable licences (CSV/Excel/web) | **OUT OF SCOPE (SD-1)** | |
| C1.5 | Scrapers for scraped licences + board actions with drift detection; skip NPDB | **OUT OF SCOPE (SD-1)** | Note: this is the *ingestion* of scraper output, distinct from F33's dispatch of CAMI's existing bots, which is **not** out of scope. |
| C1.6 | "Land everything raw in Bronze with a validating manifest (row count + checksum) per pull; keep an initial historical backfill separate from ongoing pulls" | **PARTIAL** | Bronze/Silver/Gold zoning is lakehouse vocabulary → OUT OF SCOPE (SD-1). The in-scope substance partly exists: `stg_*` is the raw landing layer, `src_*` are transport mirrors, and backfill (`gp:backfill`/`SqlBackfill`) is already a separate command from ongoing sync (`gp:sync`) — the requirement's last clause is satisfied by construction. **No plan builds a per-pull validating manifest (row count + checksum).** Grep returns nothing for a manifest, row-count assertion or checksum on a sync batch. Small, cheap, in scope, unowned. → **UN-9**. |

### §2 Processing, Cleaning & Transformation

| # | Requirement | Verdict | Evidence |
|---|---|---|---|
| C2.1 | "**Per source:** build its own parser/cleaner for that source's format, fields, and quirks" | **COVERED** for the one source | `StreamlineLocalConnector::personRow()` + `addLic()` closures; plan 5 Task 3 makes it the single normalization choke point and plan 4 Task 3 the single type-inference point. |
| C2.2 | "**Per source:** map its fields into the one common record (union, not join) → Silver" | **COVERED** for the one source | `stg_person` + `stg_person_alias`/`_address`/`_license`/`_identifier` are the common record; `personRow()` is the mapping. Silver naming is lakehouse vocabulary (SD-1). |
| C2.3 | "**Shared (all sources):** normalize names (**phonetic**), addresses (**geocode**), NPI (**check-digit**), license+state, dates" | **PARTIAL** | Phonetic names: covered — `soundex` in the block key, and plan 8 Task 2 pins `LEFT(SOUNDEX(x),4)` == PHP `soundex(x)` in `SoundexAgreementTest`, which fixes the real latent defect `00-PROGRAMME.md` §8 identified (MySQL does not truncate to 4 chars: `McDonald` → PHP `M235`, MySQL `M23543`). NPI check-digit: covered — plan 5 Tasks 1-3 (`NpiValidator`, Luhn over the `80840` prefix, rejection at ingestion, `gp:npi-audit`), including plan 5 Task 2 fixing the eval fixture's own two invalid NPIs. Licence+state: covered — existing tier; plan 4 Task 4 type-scopes it; plan 5 Task 8 carries `state` through the identifier pipeline. **Address geocoding: UNADDRESSED — zero hits for `geocode` across all ten plans.** Address normalization is string-level only. → **UN-10**. Date normalization: no shared normalizer; plan 7 declines the exclusion-date subset with cause (see C4.3). |
| C2.4 | "Strip junk/placeholder keys (all-zero NPI, \"INFORMATION NOT AVAILABLE\") before matching" | **COVERED** | Plan 5 Task 4 builds `JunkKeyGuard` (placeholder list + cardinality cap, parameterized by column, written from scratch rather than extending `SsnHashGuard` which plan 2 deletes); Task 5 wires it into every match-time site (`SqlBackfill::tierCreate`/`tierLink`, `Engine::mergeByColumn`); Task 6 screens "INFORMATION NOT AVAILABLE"-style junk *name* values at ingestion — the doc's second literal example. `00-PROGRAMME.md` §5 mandates `AREALNULL` in the placeholder list on evidence (22 of 84 rows). |
| C2.5 | "Quality gates — per-source schema/format checks *plus* shared rules (**valid / complete / unique / consistent / fresh**) with **quarantine + alert**" | **PARTIAL** | *Valid* → plan 5 Tasks 1-3 (NPI) + Task 6 (names). *Complete* → plan 5 Task 7 quarantines rows with no identifying data at all, in **both** ingestion paths (`gp_quarantine` + `QuarantineRecorder`, `QuarantineGateTest` proving both paths quarantine the same shape). *Unique* → `uq_source`, `uq_lic`, plan 3a's single-current uniques. *Consistent* → plan 4 Task 7 logs a shape disagreement among an identity's linked rows to `gp_resolution_log` as "an alarm, not a value". **_Fresh_: UNADDRESSED — no staleness or freshness gate anywhere** (grep for `freshness`/`stale` returns only `migrate:fresh`). **_Alert_: UNADDRESSED —** quarantine *records*, it never notifies; `gp_quarantine` and the `gp_resolution_log` alarm rows are tables nobody reads on a schedule. → **UN-11**. |
| C2.6 | "Keep a per-source input contract (**data_checker**) in sync with the matching code" | **UNADDRESSED (UN-12)** | Zero hits for `data_checker`, `input contract` or `schema contract` across all ten plans. The nearest thing is `IDENTITY_KEY_INDEXES`, which plan 4 Task 2 keeps in lockstep with its migration and which plan 4 flags as "a schema mirror rather than logic" — a manual discipline, not a checked contract. Real consequence: `personRow()` reads ~15 `employees` columns and a source column rename fails silently at staging, not loudly at a contract check. |

### §3 Record Matching (Profiling) — Plan

| # | Requirement | Verdict | Evidence |
|---|---|---|---|
| C3.1 | "**Build a labeled eval set + accuracy metrics first** (from internal verified data)" | **COVERED** | Plan 1 (executed; branch `feat/eval-harness`, 18 commits + `cf38efd`, 95 tests / 270 assertions) Tasks 4, 5, 6: `tests/eval/identity-pairs.json`, loader, `MatchScorer`, `gp:eval`, `EvalGateTest`. Delivered **first**, exactly as the doc orders it. |
| C3.2 | "Bake-off: custom engine vs AWS Entity Resolution vs Splink → pick one (**ADR**)" | **OUT OF SCOPE (SD-1)** | AWS Entity Resolution is lakehouse-architecture; the custom engine is already chosen and shipped. But note there is **no ADR** recording the choice — grep for `bake-off`, `Splink`, `AWS Entity Resolution`, `ADR` returns zero hits. `PROJECT_PLAN.md` §8 records the rejection at programme level, which is where I am told to leave it. |
| C3.3 | "Define blocking keys, strict hard rules, and confidence thresholds" | **COVERED** | Blocking: plan 5b Tasks 1-3 (`stg_person_block_key`, `BlockKeyBuilder`, two new legs staged in both paths), plan 4 Task 6 (entity block key, correctly delegated to 5b's builder per `00-PROGRAMME.md` §5), plan 8 Tasks 1-3 (profile-level inverted index). Hard rules: `ProbabilisticResolver::hardNo()`, extended by plan 8 Task 5 for NPI conflict from the signature and plan 4 Task 6 for `NameMatcher::orgCompatible()`. Thresholds: `auto_merge_at` / `review_band_floor` in config. |
| C3.4 | "Plan individual vs entity profiling differences (rules, keys, survivorship)" | **COVERED** | Plan 4 Tasks 4 (rules), 5 (entity ladder: TIN/EIN/UEI), 6 (entity keys + Pass B), 7 (entity survivorship). All four named dimensions. |

### §3 — Implement

| # | Requirement | Verdict | Evidence |
|---|---|---|---|
| C3.5 | "Block on NPI, (state, license), (state, provider#), (state, medicaid id), and name+state" | **PARTIAL + DEFERRED WITH CAUSE** | NPI, `(state, licence)` and `(state, MMIS)` are deterministic tiers (plan 5 Task 9), so blocking is moot for them; name+state and name+zip become Pass B legs (plan 5b Task 2). **`(state, provider#)` — DEFERRED WITH CAUSE, and the reason is real:** plan 5:1806-1813 grepped the client codebase and found `provider_number` only on state exclusion-registry source tables, concluding "`(state, provider#)` as literally described has no data source in scope"; plan 5:2562-2569 states plainly that treating MMIS as satisfying both labels is "a documented judgment call, not a discovery of a separate provider# field", and that the identifier mechanism accepts `id_type = 'provider_number'` with zero further schema change if a source ever appears. Plan 5b:1318-1319 concurs. That is exactly the shape a deferral should have. |
| C3.6 | "Merge overlapping blocks into **super-blocks**" | **DEFERRED WITH CAUSE** | Plan 5b §"Design decisions" item 2 ("Super-blocks: in scope, or not?") rules them out of 5b's scope with reasoning, and plan 5b §Design item 3 quantifies the candidate-set growth that motivates the caution. Reason stated, decision recorded — a deferral, not a gap. |
| C3.7 | "enforce strict hard rules (no bad merges)" | **COVERED** | `hardNo()` + plan 4 Task 4's type scoping (a person can never bind to an organization) + `00-PROGRAMME.md` §4's absolute: "`precision` stays 1.0000 and `false_merges` stays 0 at every step." Plan 5b Task 6 adds a false-merge foil to the fixture specifically to prove wider blocking does not buy a bad merge. |
| C3.8 | "Score → confidence bands (auto / review / no-match / **pinned**); write the crosswalk" | **COVERED** | Bands in `ProbabilisticResolver::match()` (`auto_match`/`review`/`no_match`), plus plan 6 Task 7's fourth state `declined_oversized`. Pinned: `gp_source_link.is_pinned` already implemented — `DeterministicResolver::resolve()` skips both re-matching and `enrich()` for a pinned link (plan 6 §Programme context verifies this and correctly declines to rebuild it). Crosswalk: `gp_source_link` (plan 8 §"What already conforms"). |
| C3.9 | "Route uncertain matches to a **steward review queue**" | **PARTIAL / DEFERRED WITH CAUSE** | The *flagging* is built: plan 6 Task 7 gives `match()` the distinct `declined_oversized` state, sets `gp_source_link.match_state = 'review'` instead of `'auto_match'`, and writes a `gp_resolution_log` row (`match_key = 'oversized_block'`) naming the measured block size and the cap — closing a real hole where an oversized-block decline was indistinguishable from "no candidate found". The **queue** is declined with cause: plan 6 states gp-cami is API-only per commit `f0a3126`, no UI, and spells out the manual remediation path (query `gp_source_link WHERE match_state = 'review'` joined to the log, decide by hand, lock in with `is_pinned`), naming `gp:steward-review` as "real, valuable follow-on work — explicitly not this plan's scope". Honest and specific. Marked PARTIAL not UNADDRESSED because the reason is stated; but nothing schedules the follow-on. |

### §3 — Test — explicitly requested check #7

| # | Requirement (quoted) | Verdict | Evidence |
|---|---|---|---|
| C3.10 | "Score against the eval set — **precision / recall / F1, tracking false splits vs false merges separately**" | **COVERED** | Plan 1 Task 5 `MatchScorer` emits all five; `00-PROGRAMME.md` §4's ratchet ladder tracks `true_pairs`, `false_splits`, `recall` per plan, holding `precision` at 1.0000 and `false_merges` at 0 as absolutes. The separate tracking is exactly what makes the plan-2 step legible (`recall` 1.0 → 0.9091 via **one false split**, `false_merges` unchanged). Plan 1 Task 5's note that `MatchScorer::score([], [])` returns a perfect score, and the `true_pairs >= N` floor that guards against it, is the detail that makes this real rather than decorative. |
| C3.11 | "**Before/after run-diff on every matching change** to catch regressions" | **UNADDRESSED (UN-13)** | **The specific thing asked about, and it does not exist anywhere.** Grep across all ten plans for `run-diff`, `run diff`, `rundiff`, `before/after diff`, `gp:diff`, `profile-diff`, `diff report`, `snapshot diff`, `clustering diff`: **zero hits.** What exists is adjacent but categorically different: (a) the eval gate re-run on a **fixed 13-pair fixture** (`00-PROGRAMME.md` §4; every plan closes with a gate task — 2 Task 2, 3a Task 10, 3b Task 8, 4 Task 10, 5 Task 10, 5b Task 6, 6 Task 9, 7 Task 7, 8 Task 8), and (b) two **path-parity** tests — plan 8 Task 7 `IncrementalBatchParityTest` and plan 5 `IdentifierTierParityTest` — which prove the per-row and set-based paths agree *with each other*, not that a change left the population's clustering where it was. A 13-pair fixture cannot detect a regression in the other 13.38M identities. `00-PROGRAMME.md` §7 concedes the adjacent measurements are unavailable without production credentials, but a run-diff needs no production access to *build* — it needs two runs over the same input, which the harness already produces. This is the largest test-coverage gap in the programme and no plan mentions it. |
| C3.12 | "Add unit/integration tests and wire the eval scorer into CI" | **COVERED** | Plan 1 Task 2 (`HubTestCase`, MySQL not SQLite, with the index-name reasoning and the `cf38efd` transaction-isolation fix), Task 3 (resolver ladder tests), Task 7 (CI). Every subsequent plan adds its own feature/unit tests and closes with `vendor/bin/phpunit` + `pint --test`. |
| C3.13 | "**Feed steward decisions back as new labels; re-tune the threshold**" | **UNADDRESSED (UN-14)** | Zero hits for `new label`, `as labels`, `re-tune`, `retune`, `threshold tuning`. Plan 6 builds the ingest that makes steward decisions available in `gp_identity_resolution` — the raw material — and stops there. Nothing reads them back into `tests/eval/identity-pairs.json` and nothing re-tunes `auto_merge_at`/`review_band_floor` from them. This compounds `00-PROGRAMME.md` §6's finding that the Pass B weights need recalibration ("Phase-3 work nobody has scheduled") and that a no-DOB record caps at 0.72 against a 0.75 floor: the doc's own feedback loop is the mechanism that would justify a recalibration, and it is missing. |

### §4 Building Golden Profiles

| # | Requirement | Verdict | Evidence |
|---|---|---|---|
| C4.1 | "Build the Gold tables: one golden_provider + names, addresses, licenses, exclusions, **board actions**" | **PARTIAL** | `gp_identity` + `gp_license` + `gp_address` + `gp_identity_exclusion` + `gp_identity_credential` all exist and are versioned by plan 3a Task 3. Names: see F10. **Board actions: DEFERRED, and correctly escalated rather than guessed** — `gp_board_action` exists (`2026_07_20_150000`) but `00-PROGRAMME.md` §6 records it as an open *design* decision spanning plans 6 and 7, with three named options, on the evidence that the source already delivers board actions as typed exclusion lists (`exclusion_lists.type` includes `board_action`; `albmba`, `armbba`, `gabnba`, `kymbba` verified) while the spec-shaped columns live in per-registry JSON that CAMI normalizes through ~10 field-name variants per attribute. `00-PROGRAMME.md` §6 is explicit that this is "for the design review, not for a plan to pick", and plan 6 split it out for exactly that reason. **This is the right call** — guessing the mapping means writing confidently wrong compliance data — but it does mean a doc-named Gold table is unbuilt with no scheduled resolution. |
| C4.2 | "**Survivorship** — pick the winning value per field (verified > NPI registry > licence > scrape; compliance = the issuer)" | **PARTIAL** | `Survivorship::recompute()` + `authorityRank()` + `config.survivorship.field_authority.identity` exist; plan 3a Task 7 versions the finalize pair; plan 3b Task 3 restructures `SetFinalizer::survivorship()` into one all-fields versioned pass; plan 4 Task 7 adds entity survivorship. "**Compliance = the issuer**" is the part that fails: plan 7 §"`field_authority.exclusion` is structurally, not just practically, unwired" documents that `Survivorship::recompute()` only ever resolves `IDENTITY_FIELDS` from `stg_person` columns, its set-based twin mirrors exactly that scope, and **neither has a hook for a licence- or exclusion-level field at all** — so the issuer-wins rule has nowhere to live. Plan 7 states this rather than pretending otherwise; plan 6 §3 reaches the same conclusion independently for `status_severity` and deletes the config key with a code comment explaining why, so a future engineer does not read the deletion as an oversight. Two plans converging on the same verified finding is strong evidence; the gap itself is real and unowned. |
| C4.3 | "Keep **provenance** + **history**; compliance facts **append-only**; handle **reinstatements**" | **PARTIAL + DEFERRED WITH CAUSE** | Provenance: `gp_attribute` (one row per candidate value per source link, with `observed_at`/`is_canonical`), `gp_survivorship_audit`, `gp_edge`, `gp_resolution_log`. History: plan 3a's SCD-2, whole plan. Append-only compliance: `00-PROGRAMME.md` §8 verifies "exclusions are never deleted" **already holds, vacuously** — neither `Engine::rollupExclusions()` nor `SqlBackfill::rollup()` ever deletes an exclusion (only credentials have a retire path); plan 7:687-691 and :791 confirm and preserve it; plan 3a Task 8 converts merge to retire-not-delete. **Reinstatements: DEFERRED WITH CAUSE, and the evidence is the best in the programme.** Plan 7 §"Decision: mirror the honest subset, defer the rest" declines `excl_type`, `excl_date`, `reinstate_date`, `waiver_date`, `waiver_state`, a date-driven `is_active`, and a "vanished from source" flag — on a per-prefix shape table showing ten registries with no date field at all, `oig`'s `date_deleted` holding the literal string `"AREALNULL"`, `sam2`'s uniform import-housekeeping `date_deleted`, `ca1`'s `"indefinitely effective"` in a date position, and `matches` having no soft-delete column and no evidence a row is ever removed. Its stated reason — a wrong mapping "can turn a still-excluded provider into a false 'not excluded'" — is the exact error direction the programme forbids, and it refuses to stub NULL columns nothing will populate. `has_active_exclusion` is left meaning "has an exclusion no steward has rejected", which starts working the moment plan 6 wires `link_state`. **This deferral is correct and I would not ask for more.** |
| C4.4 | "Mint a **stable golden_id** + **merge log** so downstream IDs don't break" | **COVERED** | `gp_identity.identity_id` + `identity_uuid`; `gp_resolution_log` is the merge log; plan 3a Task 8's retire-instead-of-delete keeps the loser resolvable; plan 4 §"What happens to the identities, precisely" (line 630ff) enumerates that reclassification preserves `identity_uuid` and every child-table pointer. Plan 4's design question 6 decision — do not reclassify or split `mixed` identities — is what protects downstream IDs from the entity work. |
| C4.5 | "Prove **full rebuild-from-scratch** works" | **PARTIAL — with a deliberate, documented loss** | Plan 3b Task 8 proves per-row/set-based parity and re-runs the gate through both paths, which is the closest thing to a rebuild proof. **But plan 2 §"Rebuild reproducibility after the SSN removal" states plainly: "The hub's current clustering is no longer reproducible from a from-scratch rebuild. This is the one permanent consequence of plan 2 and it is deliberate."** The reasoning is sound (un-merging correct `ssn_hash` merges would convert correct answers into false splits, the costlier error direction), the mechanism by which existing bindings survive is verified (`resolve()` returns the existing `identity_id` from `gp_source_link` before consulting any tier, so `gp:sync` reproduces today's clustering indefinitely), pinning was considered and rejected for a stated reason, and the loss is recorded in `gp_resolution_log` via `scripts/retire-ssn-tier-provenance.sql` with a rollout threshold. **So the requirement is knowingly not met, with cause, on a scope decision (SD-2) that forces the trade.** Worth flagging to the design review as a consequence of SD-2 rather than a plan defect: the checklist asks for a property the SSN removal makes unattainable, and no plan is empowered to restore it. |

### §5 Matching the Upcoming Data

| # | Requirement | Verdict | Evidence |
|---|---|---|---|
| C5.1 | "Keep persistent state: **crosswalk** + **block-key index** + **profile signatures**" | **COVERED** | All three, and plan 8 distinguishes what already existed from what it builds. Crosswalk: `gp_source_link` + `gp_watermark`, verified conformant and untouched. Block-key index: plan 8 Tasks 1-3 build `gp_identity_block_key` as a **profile-level** inverted index (the pre-existing `stg_person.block_key` blocks against staged *rows*, not profiles — a real and correctly-diagnosed defect), maintained by both paths, consumed by `ProbabilisticResolver`. Profile signature: plan 8 Tasks 4-5 build `gp_identity_signature` as a genuine per-field SET rather than `gp_identity`'s first-wins scalars, consumed by `hardNo()`'s NPI-conflict check and `score()`'s zip signal. |
| C5.2 | "Batch profiler: match new records against **candidate profiles only** (not the whole DB)" | **COVERED** | Plan 8 Task 3 replaces the `gp_source_link JOIN stg_person JOIN gp_identity` candidate query with a read of the new index, and changes `block_size_cap` from counting raw staged rows to counting **distinct active identities** — the pre-existing comparison was against the wrong denominator. Plan 5b Task 5 adds multi-leg candidate discovery capped per leg. |
| C5.3 | "Handle the 4 outcomes: **new / attach / new cluster / bridge-merge**" | **COVERED** | Plan 8 §"What already conforms" verifies three by reading the code: *attach* (`resolve()`'s existing-link short-circuit and Pass A/B binds, real-time), *new cluster* (sequential resolution — row 2 finds row 1's identity because `createIdentity()` seeds the key columns immediately, verified at lines 218-233; plan 8 Task 1 extends that immediacy to the block-key index so Pass B sees a same-batch sibling too), *new* (residual create). *Bridge-merge* is kept post-hoc in `Engine::dedup()`, **explicitly not moved to resolve time**, with the reasoning written into Task 6 — and what was actually missing, scheduling, is what Task 6 adds. |
| C5.4 | "Run **incremental daily**; **full re-profile weekly/monthly** to fix splits & drift" | **COVERED** | Plan 8 Task 6: `gp:reprofile` (dedup + finalize, sharded) scheduled in `routes/console.php` alongside `gp:sync`, with a **shared mutex** — plan 8 correctly identifies that `withoutOverlapping()` guards a command against itself, not against a sibling, which is the bug this scheduling would otherwise have shipped. Plan 8 Task 6 also names precisely what the reprofile is *for*: `gp_identity`'s key columns are first-wins (`backfillKeys()`'s `empty($id->$col) && !empty($p->$srcCol)`), so a changed source value never retracts at identity level even though staging replaces it correctly — and the fix is the periodic full pass, not incremental split logic. |
| C5.5 | "Keep it fast: **bound candidate pairs**, **precompute embeddings**, **monitor for mega-blocks**" | **PARTIAL** | Bound candidate pairs: covered — `block_size_cap` (plan 8 Task 3, now on the right denominator), per-leg caps (plan 5b Task 5), and plan 5b §Design item 3 quantifies candidate-set growth before widening. **Precompute embeddings: OUT OF SCOPE (SD-1)** — embeddings belong to the rejected ML matcher; zero hits for `embedding`, and that is correct. **Monitor for mega-blocks: PARTIAL —** plan 6 Task 7 *flags* an oversized-block decline per occurrence (`match_state = 'review'` + a `gp_resolution_log` row naming the block size and cap), which is per-record detection, not the monitoring the doc asks for. No aggregate block-size distribution report, no threshold alert, nothing scheduled. Folded into **UN-11**. |

### §6 Deployment — explicitly requested check #8

| # | Requirement (quoted) | Verdict | Evidence |
|---|---|---|---|
| C6.1 | "**Infrastructure-as-code** + dev/staging/prod; **Bronze/Silver/Gold zones**" | **OUT OF SCOPE (SD-1)** for the zones; **UNADDRESSED (UN-15)** for IaC | Bronze/Silver/Gold is lakehouse zoning — correctly out of scope. IaC is not lakehouse-specific: a Laravel/MySQL hub still has migrations, env, connections, queue workers and a scheduler to provision, and `00-PROGRAMME.md` §7 records that nobody in the environment even has production hub credentials. Grep for `terraform`, `IaC`, `infrastructure-as-code`: **zero hits** (the one `incremental-profiling` match is the substring "specifically"). Every plan's deployment surface is `php artisan migrate` run by hand, plus five hand-run SQL scripts flagged as human tasks. No plan declines IaC either. |
| C6.2 | "**Orchestrate** the pipeline (Step Functions / Airflow); **schedule per source**" | **PARTIAL** | Step Functions/Airflow: OUT OF SCOPE (SD-1). The in-scope substance is delivered: plan 8 Task 6 schedules `gp:sync` and `gp:reprofile` in `routes/console.php` with a shared mutex; plan 6 Task 6 adds `gp:ingest-resolutions` on the same cadence as `gp:sync` with its own watermarks. Per-source scheduling is moot at n=1. Marked PARTIAL because the Laravel scheduler carries no retry, backoff, failure alerting or dependency ordering between the three commands — nothing sequences `gp:ingest-resolutions` against `gp:reprofile`, and plan 6 Task 3 exists precisely because `Engine`'s rollups can revert a steward's `link_state`, which is an ordering hazard the scheduler does not encode. |
| C6.3 | "**Serve:** Golden Profile API + search (**OpenSearch**) + reporting" | **PARTIAL** | API: covered — `/api/v1/identity-search` and `/api/v1/credential-search`, extended by plan 4 Task 8 (`org_name` on both, entity branch in `resolveIdentity()`, `entity_type`/`org_name` in both responses, with the breaking-vs-additive analysis and a **human task** to tell CAMI about the `credential-search` contract amendment) and narrowed by plan 2 Task 5. Search: `gp_identity_alias` + `gp:rebuild-aliases` is the in-hub index; **OpenSearch is OUT OF SCOPE (SD-1)**. **Reporting: UNADDRESSED —** no reporting surface, no plan mentions one. → **UN-16**. Also carried forward: plan 4 Task 8 documents `identity-search`'s pre-existing oddity that `first_name` narrows only the canonical leg while the alias and new `org_name` legs are outer ORs, inherits it deliberately, and names it so it is not read as an oversight — correct handling. |
| C6.4 | "**Security & governance:** encryption, least-privilege, PII masking, audit trail" | **PARTIAL** | Audit trail: covered — plan 3a's SCD-2 + `gp_resolution_log` + `gp_survivorship_audit` + `gp_edge` + plan 6's `gp_identity_resolution` history. PII: plan 2 removes SSN entirely, which is the largest possible PII reduction, and `CredentialSearchController` already refuses to log names or DOBs ("identity ids only — names and DOBs are PII and do not belong in logs", verified in the multi-identity warning). Plan 2 Task 6 deletes the SSN encryption key and env vars and Task 9 records "Shared SSN encryption key — NOT REQUIRED (retired)". **Encryption at rest/in transit and least-privilege database grants: UNADDRESSED —** no plan specifies either, and plan 1's `HubTestCase` guard (database name must start with `gp_` and contain `test`) is a blast-radius mitigation for a test harness, not a grant model. Plan 1 Task 2 itself flags a mis-set `GP_TEST_DB_DATABASE` as "the single most dangerous value in this plan", which is exactly the risk least-privilege would bound. → **UN-17**. |
| C6.5 | "**Monitoring + alarms** (**freshness**; **zero-tolerance missed-exclusion**); **runbooks**" | **UNADDRESSED (UN-18) — the highest-consequence gap in §6** | Grep for `alarm` returns five hits, all in plan 4 and all the word used in its ordinary sense ("a shape disagreement is an alarm, not a value" — a log line). Grep for `monitor` returns exactly one hit: plan 3a:4710, "belongs in whatever monitoring plan 8 adds" — **and plan 8 adds none.** No freshness monitor (C2.5). **No missed-exclusion alarm at all**, despite the doc marking it zero-tolerance: `has_active_exclusion` is the relevant signal, `00-PROGRAMME.md` §8 verifies it is currently **inert** because `link_state` never leaves `candidate`, and plan 6 makes it start working — but nothing watches it, nothing alerts on a drop, and nothing detects an exclusion that should have matched and did not. Runbooks: partial — plan 3a §"Migration runbook", plan 4 §"Reclassification runbook — HUMAN TASK", plan 2 Task 8's hand-run script all exist as per-change runbooks, but there is no operational runbook for the running system. |
| C6.6 | "Pre-launch: **load test**, **security review**, **rebuild/DR drill**, then go live" | **UNADDRESSED (UN-19)** | Grep for `load test`, `DR drill`, `disaster`: **zero hits across all ten plans.** This is not lakehouse-only: plan 3a §"Row growth, indexes, and the performance cliff" reasons at length about a 13.4M-row hub whose row count multiplies under SCD-2, and `00-PROGRAMME.md` §7 lists "Row growth under SCD-2" as gating 3a's index sizing and records it as "currently reasoned, **not measured**". Plan 8 Task 8 quantifies row growth as an estimate and says so ("unmeasured; no production hub access"). A load test is the missing measurement for the programme's single largest performance risk, and it needs a scaled dataset, not production credentials. Rebuild/DR drill collides with C4.5: plan 2 states a from-scratch rebuild no longer reproduces the clustering, so a DR drill would *fail by design* and nobody has written down what a successful one now looks like. Security review: no plan owns one. |

---

## 6. Exhaustive UNADDRESSED register

Twenty-one findings. Ranked within each band by consequence.

**Band 1 — breaks the design's headline payoff**

| ID | Finding | Row | Why it matters |
|---|---|---|---|
| **UN-6** | **"Trigger the bots to scrape" is nobody's job.** Stated four times across P-FLOW §4 and P-PROF §2; zero hits for any scrape-dispatch term across all ten plans. | F33, F34 | It is the fallback for *every* cache miss — i.e. almost all traffic at launch. Without it the cache has two outcomes (hit, or nothing) and CAMI must implement the third itself with no contract telling it to. Not out of scope: these are CAMI's own existing bots, not an external feed. |
| **UN-7** | **Credentialing search never profiles.** `CredentialSearchController::resolveIdentity()` stays a plain equality lookup on `last_name`+`first_name`; on ties it takes the lowest `identity_id` at the top `record_count` and logs a warning. Four plans modify this method; none routes it through the resolver or explains why not. | P7, P8 | P-PROF exists to say this decision must be made by the profiling algorithm ("that hides the most important decision in the whole system"). The endpoint is the design's read side, so an arbitrary tie-break here is a wrong cached credential returned for a real person. No middle name, no alias fallback, no confidence. |
| **UN-2** | **The cache is not self-sufficient.** `expiry_date`, `check_date`, the search params and the raw `match` payload were never mirrored into `gp_identity_credential`, so `latestQualifyingCredential()` queries `streamline_local.credential_matches` on every request to decide whether its own cached row is usable. | F17, F31 | P-FLOW §4's promise is "return the cached result to CAMI (**no external call**)". A cache that round-trips to the operational DB per request has neither the latency nor the decoupling the design is for. Cheap to fix (four columns on a table plan 3a is already altering) and nobody owns it. |
| **UN-5** | **Nobody performs the auto-resolve.** Plan 6 makes `prior_resolution` real and adds `auto_resolvable`; the doc says "return the result to CAMI **and auto-resolve the name mismatch**". No plan writes the resolution onto the new match, and no plan assigns that to CAMI as a contract obligation. | F32 | The delivered half is the valuable half, so this is a last-mile gap — but "auto-resolve" is the doc's verb and an advisory boolean is not it. Plan 4 Task 8 Step 9 shows the programme knows how to hand CAMI a contract change; this one was not handed over. |

**Band 2 — no regression detection where the programme changes matching**

| ID | Finding | Row | Why it matters |
|---|---|---|---|
| **UN-13** | **No before/after run-diff on any matching change.** Zero hits for every diff-shaped term. What exists is a 13-pair fixture gate plus two path-parity tests. | C3.11 | Six of the ten plans change resolution behaviour (2, 4, 5, 5b, 6, 8) on a 13.38M-identity hub, and the only regression detector is a fixture that, after all ten plans, contains 13 pairs. `00-PROGRAMME.md` §4 is careful and disciplined about the ratchet — never lower a floor, re-baseline in the same commit — but the ratchet cannot see a change it has no fixture for. Unlike the §7 measurements, a run-diff needs **no production access to build**: two runs over the same staged input is what the harness already does. |
| **UN-14** | **No steward-decision → label feedback loop, and no threshold re-tuning.** Plan 6 supplies the raw material and stops; nothing reads it back into the eval fixture and nothing re-tunes the bands. | C3.13 | Compounds `00-PROGRAMME.md` §6: a no-DOB record caps at **0.72** against a `review_band_floor` of **0.75**, so it can never reach even the review band however perfect every other signal is; same for a record missing both address and zip; and `blockKey()` returns null on a blank surname so organizations never enter Pass B at all. Plans 5b and 6 therefore both deliver less than their scope suggests until the weights are recalibrated — and the doc's own feedback loop is the mechanism that would justify a recalibration. `00-PROGRAMME.md` calls it "Phase-3 work nobody has scheduled". |
| **UN-11** | **Quality gates record but never alert; no freshness gate; no mega-block monitoring.** `gp_quarantine` and the `gp_resolution_log` alarm rows are tables nobody reads on a schedule; grep for `freshness`/`stale` finds only `migrate:fresh`. | C2.5, C5.5 | The doc pairs "quarantine **+ alert**" and names *fresh* as one of five shared rules. A silently-growing quarantine table is a data-loss channel that looks like success. |
| **UN-18** | **No monitoring or alarms at all — including the zero-tolerance missed-exclusion alarm.** One `monitor` hit in ten plans, and it is plan 3a deferring to "whatever monitoring plan 8 adds"; plan 8 adds none. | C6.5 | The doc singles out missed exclusions as zero-tolerance. `has_active_exclusion` is verified inert today and starts working when plan 6 lands — and nothing will be watching it. This is the compliance-critical alarm in a compliance system. |

**Band 3 — doc schema with no counterpart and no decline**

| ID | Finding | Row |
|---|---|---|
| **UN-1** | `credential_databases` — the credential-registry catalogue (prefix, type, state, url, `match_status_map`, `required_fields`) has no `gp_*` counterpart. Zero hits in any plan. Only a free-text `registry` string exists. |
| **UN-3** | `exclusion_lists` — the sanction-registry catalogue (prefix, type, url, `verify_email`) has no `gp_*` counterpart. Doubly consequential: `exclusion_lists.type` is exactly the evidence `00-PROGRAMME.md` §6 needs to settle the `gp_board_action` open decision, and no plan mirrors it. |
| **UN-20** | Three of P-FLOW's eight `exclusion_matches` match-quality flags (`is_diminutive_name_match`, `is_aka_name_match`, `is_npi_mismatch`) are unmirrored, plus its `hash`. **Borderline: a deferral with a boundary but no owner.** Plan 7:441-447 says mirroring them is "out of scope here" — a scope statement rather than evidence the expansion is unwarranted — and no other plan claims it. The `hash` column has no statement at all. Classed UNADDRESSED under the rule that a deferral needs a *reason*, not just a boundary; downgrade to DEFERRED if the design review assigns it a plan. |
| **UN-21** | `exclusion_match_actions`'s eight per-attribute mismatch flags (DOB/SSN/first/middle/last name/address/NPI/job) and `resolution_source_data` are unmapped. Plan 7 routed them to plan 6's domain; plan 6 did not carry them. A gap between two plans, not inside one. |
| **UN-8** | `entities.tin_hash` / `tin_last_four` — no column, no decline. (Arguably *should* be declined on plan 2's own reasoning; nobody says so.) |
| **UN-4** | `individuals.hire_date`, termination date, `job_title`, `facility_id` — four named doc columns with no golden home and no stated decline. Lowest consequence in this band. |

**Band 4 — engineering hygiene the checklist asks for in scope**

| ID | Finding | Row |
|---|---|---|
| **UN-19** | No load test, no security review, no rebuild/DR drill. The load test is the missing measurement for the programme's largest performance risk (SCD-2 row growth on a 13.4M-row hub, "reasoned, not measured"); the DR drill collides with plan 2's deliberate loss of rebuild reproducibility and nobody has redefined what passing looks like. |
| **UN-17** | No encryption-at-rest/in-transit spec and no least-privilege grant model — despite plan 1 naming a mis-set `GP_TEST_DB_DATABASE` as "the single most dangerous value in this plan", which is exactly what grants would bound. |
| **UN-15** | No infrastructure-as-code. Deployment is hand-run `migrate` plus five hand-run SQL scripts. Not lakehouse-specific and not declined. |
| **UN-12** | No per-source input contract (`data_checker`). `personRow()` reads ~15 `employees` columns; a source rename fails silently at staging rather than loudly at a contract check. |
| **UN-16** | No reporting surface. |
| **UN-10** | No address geocoding. Address normalization is string-level only; zero hits for `geocode`. Interacts with UN-14 — the address signal already cannot reach the review band alone. |
| **UN-9** | No per-pull validating manifest (row count + checksum). The "separate historical backfill from ongoing pulls" half of C1.6 is satisfied by construction; the manifest half is not built. |

---

## 7. Where the documentation contradicts itself

Four, and all four are real findings about the docs rather than about the plans.

**C-1 — P-FLOW stores SSN; P-CHK forbids it. Same author, same programme.**
P-FLOW § Schema: `individuals` carries "**SSN + hash + last four**". P-CHK §1: "Stream **internal
verified data** via CDC (**never store SSN**)". P-CHK is the newer page (2026-09-02 vs 2026-07-21) and
scope decision SD-2 resolves it in P-CHK's favour, which is what plan 2 implements. But the older
page still reads as the schema of record and the contradiction is nowhere annotated in Confluence.
**Consequence the design review should own:** the resolution is not free. Plan 2 §"Rebuild
reproducibility" states that removing the tier makes the hub's clustering **permanently
irreproducible from a from-scratch rebuild** — which directly contradicts P-CHK §4's own "Prove
full rebuild-from-scratch works". So P-CHK contradicts itself once SD-2 is applied: §1 forbids the
key whose absence makes §4's proof unattainable. No plan can fix that; only the doc set can.
P-FLOW's `entities` "TIN + hash + last four" has the same shape and nobody has ruled on it (UN-8).

**C-2 — P-FLOW's schema is a version history; P-PROF says that is not a golden profile.**
P-FLOW keys everything on `cami_employee_id`, i.e. 1:1 CAMI-record-to-golden-row. P-PROF § The key
idea: "**If nothing groups across `cami_employee_id`, the result is a version history, not a golden
profile.** Profiling is the missing arrow." Read literally the two pages specify different systems.
**Plan 3a handles this correctly and its ruling should be lifted into the wiki**: it quotes both
pages, declares "these are two requirements, not two options", assigns grouping to the resolvers and
`gp_source_link` and versioning to itself, and forbids itself from touching a matching threshold. The
docs still need the annotation — an implementer who reads only P-FLOW builds the wrong thing, which is
plan 3a's own warning.

**C-3 — P-FLOW's two-table schema vs. the Data Model page's one table.**
Recorded here because plan 4 §"The two design pages disagree" identifies it and it spans my lane and
the Data Model auditor's: P-FLOW asks for separate `individuals` / `entities` (+ `individual_names` /
`entity_names`, + `individual_addresses` / `entity_addresses`), while the Data Model page asks for
one `golden_provider` with `entity_type VARCHAR(12) NOT NULL`. Plan 4 rules for one table and I
accept the ruling (see §4). Flagged for the composed audit because **the same feature is specified
two incompatible ways in two pages of the same wiki**, and only a plan document — not the wiki —
currently records which won.

**C-4 — P-FLOW's `current` on "nearly every table" vs. P-FLOW's own cross-reference principle.**
Internal to one page. § Core design pattern asserts `current` on "nearly every table" *and* that "the
Golden Profile also **cross-references CAMI** rather than replacing it". Applied to the staging
mirror the two collide: versioning a 13M-row mirror of CAMI's live state would duplicate an audit
trail the page itself says CAMI owns. Plan 3a resolves it the right way ("staging is a mirror of
CAMI's live state … History of a *source* row lives in CAMI, which is the system of record for it")
and cites the page's own cross-reference sentence to do it. A small contradiction, cleanly resolved,
noted so the composed audit does not read the staging exclusion as a miss.

---

## 8. Where a plan's refusal is right, and should not be treated as a failure

Recorded deliberately, because an audit that flags every deferral is useless.

1. **Plan 7's exclusion-lifecycle deferral (C4.3)** is the strongest reasoning in the programme.
   It profiled 84 `exclusion_records` rows into a per-prefix shape table, found ten registries with
   no date field at all, `"AREALNULL"` as a literal string, `sam2`'s uniform housekeeping stamp,
   `ca1`'s `"indefinitely effective"` in a date position, and `matches` with no soft-delete column —
   then declined five typed columns and a date-driven `is_active` because a wrong mapping "can turn a
   still-excluded provider into a false 'not excluded'". It refused to stub NULL columns nothing
   would populate, mirrored the raw payload verbatim as `source_record` instead, and left
   `has_active_exclusion` meaning the one thing gp-cami actually knows. Correct.
2. **Plan 5's `(state, provider#)` deferral (C3.5)** grepped for the field, found it only on state
   exclusion-registry source tables, said MMIS-as-both-labels is "a documented judgment call, not a
   discovery of a separate provider# field", and noted the identifier mechanism accepts
   `id_type = 'provider_number'` with zero schema change if a source appears. Correct.
3. **`gp_board_action` escalated rather than guessed (C4.1).** `00-PROGRAMME.md` §6 refuses to let a
   plan pick a mapping through ~10 field-name variants per attribute of unstructured per-registry
   JSON, on evidence that board actions already arrive as typed exclusion lists. Guessing means
   writing confidently wrong compliance data. Correct — though it does leave a doc-named Gold table
   unbuilt with no scheduled resolution.
4. **Plan 6 deleting `status_severity` with a code comment (C4.2)** rather than wiring a config key
   whose vocabulary exists nowhere upstream — verified across `stg_person_license`,
   `StreamlineLocalConnector`'s `addLic()` closures and `src_exclusion_record`. Wiring it "would mean
   **inventing** a capability, not fixing an existing one". Correct, and the comment is what stops a
   future engineer reading the deletion as an oversight.
5. **Plan 6 not building a steward-decision table or a `split` path (C3.9)** because `pin_match` is
   already implemented (`is_pinned`) and `split` has **no CAMI-side signal at all**. Refusing to
   fabricate a source is correct.
6. **Plan 4 preserving `mixed` identities and refusing a row-shape CHECK** rather than shipping a
   constraint that would make `gp:entity-reclassify` undeployable, and saying out loud that this is
   "a real weakening versus a database constraint". Correct, and honestly priced.
7. **Plan 3a not versioning `gp_source_link`** — see the judgement in §1. Correct, and it is the
   exclusion the doc's "nearly every table" phrasing most invites getting wrong.
8. **Plan 8 keeping bridge-merge post-hoc** in `Engine::dedup()` with the reasoning written into
   Task 6, and fixing what was actually missing (scheduling) rather than relocating working logic.
9. **Plan 5b naming the Pass B weight problem instead of faking a fixture case to hide it**
   (`00-PROGRAMME.md` §6). The 0.72-vs-0.75 arithmetic is the kind of thing a plan is tempted to
   bury; 5b published it.

---

_Audit A of three. Covers P-CHK, P-FLOW, P-PROF only. Verdicts assigned against the plan documents as
written on 2026-09-04, plus the repo state for pre-existing code (`app/`, `database/migrations/`).
Plan 1 is executed; plans 2-8 are written and unexecuted, so every non-plan-1 verdict is a judgement
about a specification, not about shipped code._

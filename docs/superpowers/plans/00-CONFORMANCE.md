# Conformance audit — the ten plans against the data-science team's documentation

Three independent audits, split by document group, then consolidated and spot-verified against the
running code. Full per-requirement tables with quotes and citations are in
`.superpowers/sdd/compliance-{A,B,C}-*.md`.

| Audit | Documents | Rows | Covered | Partial | Deferred w/ cause | Out of scope | **Unaddressed** |
|---|---|---|---|---|---|---|---|
| A | Delivery Plan & Checklist · Data Flow by CAMI · Proposed Process Flow | 96 | 44 | 29 | 2 | 10 | **11** (21 distinct incl. named halves of Partial rows) |
| B | Data Model · Building One Trusted Record · How Record Matching Works · Keeping Data Accurate | 109 | 42 | 20 | 13 | 8 | **26** |
| C | Recommendations & Open Risks · Cross-Source Matching · Incremental Profiling · Scaling & Performance · Success Metrics | 51 | 15 | 8 | 4 | 13 | **9** |

"Out of scope" means excluded by the two standing decisions — conformance inside the Laravel/MySQL
hub (no lakehouse, no ML matcher, no external government feeds), and code-follows-docs on SSN and
SCD-2. Audit C additionally separated the "Recommendations & Open Risks" items that are about
`sv-manila/profiling`, the **Python** engine, and not gp-cami at all: the matcher bake-off, the
prod-vs-dev embeddings rollback, and the embeddings cost/benefit question.

**Roughly 40% of auditable requirements are covered, 22% partial, and 18% genuinely unowned.** The
plans are individually strong; the gaps cluster in three themes, below.

---

## Theme 1 — the human-in-the-loop safeguard is structurally absent

The docs make review a load-bearing safeguard. Every piece of it is missing, and plan 6 fixes only
part.

| Verified finding | Evidence |
|---|---|
| **Review-band matches are *bound*, not held.** `DeterministicResolver` merges the record into the identity and *then* logs. "How Record Matching Works" says mid-confidence pairs "go to a review queue where a steward can... confirm, reject, or split them." | `in_array($state, ['auto_match','review'], true)` → `$identityId = $pid;` then `logReview()` |
| **`match_state` is written in four places and read in zero.** The band is recorded and never consumed. | 4 writes in `DeterministicResolver`/`SqlBackfill`; no reader anywhere |
| **`gp_edge` is an entirely dead table.** Not just its `detail` column — the whole match-evidence table. One reference in `app/`, inside the reset TRUNCATE list. Explainability is a **P1** in Open Risks: "persist why each merge happened (signals + score)". | `Engine.php:476` only |
| **`gp_identity.status = 'split'` is never written**, by code or by any plan. There is no un-merge capability; the programme only converges merges. This undercuts the docs' "everything is reversible" safeguard and the group-size mitigation. | no writer in `app/` or in ten plans |
| **No steward-decision → label feedback loop.** The P0's own "almost every other item gets easier" clause. Plan 6 ingests decisions; nothing routes them into the eval fixture or re-tunes thresholds. | 0 hits across ten plans |

Together: a review-band merge is permanent, unreviewed, unexplained and irreversible. That inverts
the docs' explicit precision-over-recall stance for compliance-sensitive matches.

Audit B's sharpest correction is to plan 6's own reasoning. Plan 6 declines `split` because it has
"no CAMI-side signal" — true and irrelevant, because a split is a decision about a merge *gp-cami*
made, so CAMI can never be its source. `split` is **unaddressed, not unavailable**; the honest cause
is that gp-cami exposes no identity-mutation endpoint. Plan 6 says exactly that elsewhere, about the
oversized-block flag, and never connects it here.

Plan 6's decision **not** to build a `steward_decision` table is nonetheless correct:
`confirm`/`reject` have a verified CAMI source, `pin_match` is already implemented via `is_pinned`,
and `rationale` is captured as `resolution_metadata.note`.

---

## Theme 2 — the credentialing cache, the design's headline payoff, does not work as specified

"Data Flow by CAMI" process 4 is the reason the project exists: answer a credential check from the
Golden Profile instead of re-querying the registry.

| Verified finding | Evidence |
|---|---|
| **The cache queries the source on every request.** `gp_identity_credential` holds no `expiry_date`, `check_date`, search params or raw `match`, so validity cannot be decided from the hub. Process 4's "return the cached result to CAMI — no external call" is not achieved. | `CredentialSearchController.php:253` → `DB::connection('streamline_local')->table('credential_matches')`; column list confirmed against the live schema |
| **Credentialing search never profiles.** `resolveIdentity()` is plain equality on `last_name` + `first_name`, optionally narrowed by dob/ssn/licence; on ties it takes the lowest `identity_id` at the top `record_count` and logs a warning. The Proposed Process Flow states this is exactly the decision profiling must make. Four plans modify the method; none routes it through the resolver, and none says why not. | `resolveIdentity()` read in full |
| **"Trigger the bots to scrape" is nobody's job.** Stated four times across both flow pages; zero hits for any scrape-dispatch term in ten plans. It is the fallback for every cache miss, and these are CAMI's own bots — not an external feed, so the out-of-scope decision does not cover it. | 0 hits across ten plans |
| **`credential_databases` and `exclusion_lists`** have no `gp_*` counterpart and no plan declines them. | audit A UN-1/UN-3 |

`priorResolution()` returning null always (because `gp_identity_resolution` is never written) is the
one part of process 4 that plan 6 does fix.

---

## Theme 3 — nothing is measured, and nothing is watched

| Verified finding | Evidence |
|---|---|
| **No monitoring or alarms at all**, including the checklist's "zero-tolerance missed-exclusion" alarm. One `monitor` hit across ten plans: plan 3a deferring to "whatever monitoring plan 8 adds"; plan 8 adds none. `has_active_exclusion` starts working when plan 6 lands and nothing will watch it. | audit A UN-18 |
| **No before/after run-diff on any matching change.** The checklist requires it. Six plans change resolution behaviour on a 13.38M-identity hub; the only detector is a 13-pair fixture plus two path-*parity* tests. **This needs no production access to build.** | 0 hits for every diff term |
| **No Success Metrics target is measured or alarmed** — the 99%/95%/85% figures, the review-queue SLA, the 24h/48h freshness. The capability exists; the measurement does not. | audit C |
| **`ingest_manifest` / `data_lineage` absent.** `gp_watermark` covers `source_watermark` only — no `row_count`, `checksum_sha256`, `schema_version`, `PENDING\|VALID\|REJECTED`, or stage trail. Without `row_count` the "row count within normal range" gate is unbuildable, so "bad data never silently flows downstream" is unenforceable at load granularity. | audit B |

---

## Other unaddressed items worth naming

- **Bi-temporal gap.** `valid_from`/`valid_to` — *business* validity — has **0 mentions in all ten
  plans**, though the Data Model states it as a convention across licences, exclusions, board
  actions, identifiers and addresses. Plan 3a supplies belief-time SCD-2; plan 7 correctly insists
  the two axes differ, and then nobody builds the second one.
- **`field_authority`: three of four lists are dead**, not two. Verified: `SetFinalizer:182` reads
  `.identity` explicitly and `Survivorship` uses only `['identity']`, so `.license`, `.address` and
  `.exclusion` are declared and never read. Plan 6 *deleted* `status_severity` on evidence and plan 7
  *explained* `.exclusion`'s unwirability — which makes `.license` and `.address` look missed rather
  than decided.
- **`excl_specialty` was missed, not declined** — zero hits in plan 7, absent from its migration, its
  "not built" list and its handover. `excl_type` and `waiver_state` are bundled into a deferral whose
  stated cause (a fabricated date producing a false "not excluded") cannot apply to them, and plan
  7's own sample table records `wvrstate` as present and unambiguous on LEIE.
- **Delivery Checklist §0 Discovery** — five items on profiling each source's real shape, quirks and
  identifier fill rates. The plans largely assume rather than discover, though several did ad-hoc
  investigation that amounts to partial discovery.
- **Maiden names have no golden home.** Plan 4's one-table argument holds, with one real crack: SCD-2
  on the parent gives *one* current name, so the doc's `maiden` — a concurrent variant, not a
  superseded one — has nowhere to live. Plan 4's reason for leaving `alias_part` alone covers
  business aliases, not maiden ones.

---

## Where the plans decline a requirement and are right to

An audit that treats every deferral as a failure is useless. These were checked and judged sound:

- **Plan 7 deferring typed exclusion lifecycle dates.** `matches` has none; `exclusion_records.match`
  is raw JSON in ~20 undocumented per-registry shapes across 30 prefixes, with a corrupted
  `AREALNULL` sentinel (verified: 22 of 84 local rows) and a `date_deleted` that means different
  things in `sam2` than in `oig`. Fabricating a date risks a false "not excluded".
- **Plan 5 deferring `(state, provider#)`** — `provider_number` exists nowhere in gp-cami's scope —
  **and the NPI-replacement trail**, which needs an NPPES feed §8 rejects.
- **Plan 5b rejecting super-blocks.** Independently verified: `SqlBackfill` references neither
  resolver, so the mechanism would serve zero callers.
- **Plan 3a excluding `gp_source_link` from versioning.** The doc set has no link table at all, the
  Proposed Process Flow offers one only as an option, and `uq_source` is the sole guarantee making
  `resolve()` idempotent.
- **Plan 4's single-table `entity_type`** over the two-table `individuals`/`entities` schema — the
  doc's two tables are five requirements that one table satisfies, and two tables would push a type
  discriminator onto fourteen tables carrying a bare `identity_id`.
- **Plan 6 not building `steward_decision`** (see Theme 1).

---

## The documentation contradicts itself in twelve places

These are findings about the docs, not the plans, and several cannot be fixed by any plan.

The one that matters most: **"never store SSN" and "prove full rebuild-from-scratch works" cannot
both hold.** The Delivery Checklist §1 forbids the key, and §4 requires the rebuild — and plan 2
establishes that removing `ssn_hash` makes the loss of rebuild reproducibility permanent and
deliberate. Once the code-follows-docs decision is applied, the checklist contradicts itself. **No
plan can resolve this; only the doc set can.**

Also:

- The Data Model says `golden_provider` / `provider_xref`; Data Flow by CAMI says
  `individuals` / `entities`. Same feature, two incompatible specs, and only a plan document records
  which won.
- `gold.exclusion` carries `is_active` *and* `reinstate_date` *and* `valid_from`/`valid_to`, while
  "Building One Trusted Record" promises exclusions are never deleted — a `valid_to` end-dates the
  row.
- Three different enums for the same decision axis across the pages.
- Internal to Data Flow by CAMI: `current` on "nearly every table" versus "cross-references CAMI
  rather than replacing it" collide on the 13M-row staging mirror. Plan 3a resolves this cleanly.
- Data Flow by CAMI's own 1:1 `cami_employee_id` schema *is* the "version history, not a golden
  profile" that its companion page warns against. Plan 3a rules correctly that these are two
  requirements rather than two options; the wiki carries no annotation.

---

## What to do about it

Ranked by consequence, and separated by who can act.

**Buildable now, no production access, no new decision:**

1. **Run-diff on matching changes** (Theme 3). Six plans change resolution on a 13.38M-identity hub
   and the only detector is a 13-pair fixture. This is the single highest-value missing capability
   and it is pure engineering.
2. **Write `gp_edge`** — the table and its `detail` column already exist, and the resolvers already
   compute the signals. This closes the P1 explainability requirement and makes review meaningful.
3. **Consume `match_state`**, and stop binding review-band matches before a human sees them. Or
   decide deliberately that binding-then-flagging is the intended behaviour and correct the doc.

**Needs a decision first:**

4. **The credential cache** (Theme 2) — either mirror `expiry_date`/`check_date`/params into
   `gp_identity_credential` so it can answer alone, or accept that it is an index over the source and
   amend process 4. It is currently neither.
5. **`split` / un-merge** — accept that merges are irreversible, or schedule the identity-mutation
   endpoint that would make them reversible.
6. **`gp_board_action`'s shape** (`00-PROGRAMME.md` §6).
7. **Pass B recalibration** — a record with no DOB caps at 0.72 against a 0.75 floor, so plans 5b and
   6 deliver less than their scope implies.

**Needs the doc set amended, not code:**

8. The SSN / rebuild-from-scratch contradiction, and the two naming schisms.

**Needs production access:**

9. `scripts/baseline-key-mix.sql` query 3 — which also decides whether the 5-before-2 ordering is
   necessary or merely prudent.

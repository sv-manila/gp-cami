# GPP Programme Extension — closing the wiki gap across `gp-cami` *and* `gp-cami-dashboard`

**Date:** 2026-09-07 · **Owner:** John Hombrebueno
**Spec:** [Golden Provider Profile — Wiki Home](https://streamlineverify.atlassian.net/wiki/spaces/DEV/pages/4066279429) (home + 28 descendant pages, DEV space)
**Read before this:** `HANDOFF.md` → `plans/00-PROGRAMME.md` → `plans/00-CONFORMANCE.md`

This document does **not** re-plan what is already planned. Fourteen plans are numbered and twelve of
them are written, and `00-PROGRAMME.md` is still the tiebreak on order, migration numbers and
component ownership. What this adds is the part of the wiki that **no existing plan owns**, and — for
the first time — the second repository, `gp-cami-dashboard`, which has never been audited against the
spec at all.

---

## 1. Starting position — verified 2026-09-07

| | `gp-cami` | `gp-cami-dashboard` |
|---|---|---|
| Remote | `sv-manila/gp-cami` (public) | `sv-manila/gp-cami-dashboard` |
| Branch here | `feat/eval-harness`, **28 commits ahead of `master`@`17d383d`, unpushed** | `master` @ `b66fcde`, clean |
| Suite | 95 tests, `pint --test` clean — the DB-backed half needs the `192.168.56.22` VM | 1 feature file (`DevOnlyGateTest`) + 5 unit files; no `vendor/` installed |
| Conformance plans | 14 numbered, **12 written**, **1 executed** (plan 1, the eval harness) | **0** |
| Migrations on disk | through `2026_08_10_000000` — none of plans 2–12 has landed | 4 (scaffold + `dashboard_analytics_tables`) |
| Wiki coverage (audit) | ~40% covered · 22% partial · **18% unowned** | never measured |

Housekeeping, not a risk: the `C:\ai codes\gp-cami` checkout is stale — its `origin/master` is
`3419fbc`, an ancestor of `17d383d`, and its `feat/set-based-identifiers` commits are already in
`master`. Fetch it or delete it; nothing is stranded there.

**Two scope decisions remain closed** (owner's, not open to a plan): conformance stays inside the
Laravel/MySQL hub — no lakehouse, no ML matcher, no external government feeds — and where code and
docs contradict each other on SSN and SCD-2, **the code changes to match the docs**.

---

## 2. What the wiki asks for that nobody owns

Derived from `00-CONFORMANCE.md`'s ranked action list, then re-checked against the twelve written
plans and the running code. Items already owned by plans 2–12 are **excluded** — they are not
repeated here. G7 and G8 come from the wiki pages directly; `00-CONFORMANCE.md` does not raise them.

| # | Wiki requirement | Where in the spec | Owned by | New |
|---|---|---|---|---|
| G1 | Steward can **confirm / reject / split / pin**, and the decision "immediately updates the golden layer" | *How Record Matching Works*; *Keeping Data Accurate* | plan 9 builds `IdentityMutator` + `gp:split` as **CLI only** — "settling and splitting are artisan operations, not endpoints". No HTTP **write** surface exists anywhere: `routes/api.php` serves two search endpoints, and plan 9 adds only a read-only `GET /api/v1/review-queue`. | **H15** |
| G2 | "Access is controlled down to the table, column, and even row level"; separate ingestion / stewardship / analytics / ML permissions; "every access is logged" | *Keeping Data Accurate* → Who can see what | nobody | **H13** + **D0** |
| G3 | "Birth dates and exact addresses are masked for analysts and ML unless their role specifically requires the real values" | same | nobody | **H13** + **D1** |
| G4 | A documented, automatically enforced **data contract** per source | same | nobody | **H13** |
| G5 | `ingest_manifest` / `data_lineage` — `row_count`, `checksum_sha256`, `schema_version`, `PENDING/VALID/REJECTED`, stage trail | *Data Model* | plan 11's `gp_run` covers `rows_read`, `rows_staged`, the watermark pair and status — and its own test asserts `checksum_sha256` and `s3_prefix` are **absent**. `schema_version` too. | **H14** |
| G6 | "Trace any value in a golden profile back to exactly where it came from" (`survivorship_audit`) | *Keeping Data Accurate*; *Data Model* | **partial, and better than the audit implies:** `gp_survivorship_audit` exists with two live writers — `Survivorship.php:130` and `SetFinalizer.php:120` — covering `Survivorship::IDENTITY_FIELDS`. Unowned: licences, addresses and credentials, plus the manifest and lineage tables. | **H14** |
| G7 | Step-1 standardization: nickname expansion, phonetic codes, **address geo-distance** | *How Record Matching Works* → Step 1 | plan 5 owns the NPI check-digit and junk keys; plan 5b owns block-key construction, and `soundex($last)` is already the live key (`StreamlineLocalConnector.php:263`). **Unowned: nickname expansion and address geo-distance.** | **H16** |
| G8 | Pass B must be able to reach the review band at all | *How Record Matching Works* Pass 2/3 | **verified defect:** a record with no DOB caps at **0.72** against a `review_band_floor` of **0.75**, and `blockKey()` returns null on a blank surname so organizations never enter Pass B | **H16** |
| G9 | Steward decisions "become new training examples that make the matching system smarter" | *Keeping Data Accurate*; Theme 1's P0 | **plan 9, Task 6** — `DecisionExporter` + `gp:export-labels` already build the exporter. `00-CONFORMANCE.md`'s "0 hits across ten plans" predates plan 9. | **D8** (UI only) |
| G10 | Credential answer served from the profile, "no external call" | *Data Flow by CAMI* process 4 | plan 10 — specified, **not written**. Unblocked 2026-09-07: the cache **is** to be self-sufficient (§7 decision C). | **H10** (author it) |
| G11 | `valid_from` / `valid_to` — *business* validity, distinct from plan 3a's belief-time SCD-2 | *Data Model* conventions | plan 12 — specified, **not written** | **H12** (author it) |
| G12 | Every steward-facing screen the spec describes: side-by-side compare **with the reasons**, quarantine queue, quality gates, freshness, drift, metric targets | *How Record Matching Works*; *Keeping Data Accurate*; *Success Metrics* | the dashboard renders the queue but **reads no evidence and records no decision** | **D-track** |

---

## 3. The shape of the change

```
                    ┌──────────────────────── gp-cami (hub) ─────────────────────────┐
 streamline_local ─►│  stg_*  ──►  Resolution (Pass A / Pass B)  ──►  gp_identity    │
   (SELECT-only)    │                       │                            │           │
                    │                       ▼                            ▼           │
                    │                    gp_edge                  gp_identity_profile│
                    │                 (plan 9: evidence)                  │          │
                    │  H14: ingest_manifest · data_lineage · survivorship_audit      │
                    │  H13: roles · masked views · data contracts · access log       │
                    │  H15: POST /api/v1/steward/*   ◄── the only write door         │
                    └──────────────────┬────────────────────────────┬────────────────┘
                                       │ read (masked)              │ write (audited)
                    ┌──────────────────▼────────────────────────────▼────────────────┐
                    │              gp-cami-dashboard (steward UI)                    │
                    │  D0 auth+roles · D1 masking · D2 evidence · D3 decisions       │
                    │  D4 quality gates · D5 metrics · D6 run-diff · D7 lineage      │
                    │  D8 decision→label loop · D9 history + entity views            │
                    └───────────────────────────────────────────────────────────────┘
```

**The load-bearing rule of this extension:** the dashboard never writes data to `golden_profile`
directly. Every mutation goes through **H15**, so authorization, audit and reversibility are enforced
once, in the hub, and a second steward client (CAMI itself) gets them for free. The dashboard's own
database keeps only its own artifacts — snapshots, quality flags, merge basis.

One existing carve-out, which survives the rule but must be stated: `gpdash:index-advisor --apply`
issues `CREATE INDEX` DDL against the hub connection (`GpDashIndexAdvisor.php:104`, on
`gp_source_link`, `gp_identity_credential`, `gp_identity_exclusion`, `gp_identity_profile`). It is
gated behind `--apply` plus a confirmation prompt and writes no rows. **H13 must give it its own
role** rather than let the dashboard's read credentials carry `INDEX` privilege.

---

## 4. Track H — new hub plans

Each row is a **plan document still to be authored**, to `AUTHORING-BRIEF.md`'s shape (Global
Constraints block verbatim, checkbox tasks, a test per task, an explicit eval-gate assertion) before
any code is written. Migration prefixes continue the register, which lives in two places:
`00-PROGRAMME.md` §3 assigns through `2026_09_09_000100` (plan 8), and `HANDOFF.md` §7 assigns
`2026_09_10_*`–`2026_09_13_*` to plans 9–12. `2026_09_14`–`2026_09_17` are free — verified, zero
occurrences repo-wide.

| Plan | Title | Migrations | Depends on | Exit gate |
|---|---|---|---|---|
| **H10** | Credential cache — make process 4 answer from the hub, **self-sufficiently** | `2026_09_11_*` (reserved) | 3a, 6 | A credential-search response is served with **zero** `streamline_local` queries for a cached, unexpired match; a miss or a stale row returns an explicit `refresh_required` verdict rather than falling through to the source; the staleness window is measured and documented |
| **H12** | Bi-temporal validity — `valid_from`/`valid_to` on licences, exclusions, identifiers, addresses | `2026_09_13_*` (reserved) | 3a, 7 | An as-of query returns the fact as it *was* on a past date, distinct from the belief-time version 3a returns |
| **H13** | Access control, PII masking and data contracts | `2026_09_14_*` | 1 | Four roles exist (`ingest`, `steward`, `analyst`, `ml`); DOB and address line are `NULL` for `analyst`/`ml` through a masked view; every read of an unmasked column writes an access-log row; each `gp_source_system` row carries a contract (owner, freshness SLA, PII flag) and a contract violation fails the load |
| **H14** | Ingest manifest, lineage, and survivorship audit extended past identity fields | `2026_09_15_*` | 11 | Every `gp:sync`/`gp:backfill` writes one manifest row with `row_count` + `checksum_sha256` + `schema_version` + `PENDING/VALID/REJECTED`; the row-count-range gate blocks promotion; `gp_survivorship_audit` covers licences, addresses and credentials, not just `Survivorship::IDENTITY_FIELDS` |
| **H15** | Steward write API — the identity-mutation door | `2026_09_16_*` | 6, 9, H13 | `POST /api/v1/steward/{confirm,reject,split,pin}` under `auth:sanctum` plus a `steward` ability; every call audited and idempotent by request key; a `split` is provably reversible; the eval ratchet **bit-for-bit unchanged** |
| **H16** | Pass B recalibration and Step-1 standardization | `2026_09_17_*` | 5, 5b | A record with no DOB can reach the review band; an organization enters Pass B; nickname expansion and address geo-distance are wired and weighted; **recall rises, `precision` stays 1.0000, `false_merges` stays 0** |

**H16 note.** Recalibration moves the eval numbers, which nothing else in the programme is allowed to
do. It therefore runs **last** in track H and re-baselines the ratchet in the same commit, stating the
new value and the reason — never by lowering a floor or removing a fixture record.

**H15 note.** `target_key` written by any steward endpoint must stay byte-identical to
`CredentialSearchController.php:322-325`'s `registry:license_number[:license_type]`, which
`00-PROGRAMME.md` §5 assigns to plan 6.

### H10 — what "self-sufficient" forces, now that decision C is settled

`gp_identity_credential` today holds 11 columns and **none** of the four things the selector needs:
`expiry_date`, `date_updated`/`date_created`, the search params, and the raw `match`. Every request
therefore round-trips to `streamline_local` (`CredentialSearchController.php:253`). Making the cache
self-sufficient means mirroring those, and that lands three consequences the H10 author must handle
rather than discover.

**1. The raw `match` payload contains plaintext SSN. Do not mirror it.** Verified: the controller
already extracts `$.request_params.ssn` and `$.request_params.date_of_birth` out of
`credential_matches.match`, so a straight mirror of that `mediumtext` column would put plaintext SSN
into the hub — the exact thing plan 2 exists to remove and the wiki forbids outright. Mirror the
**derived scalars** the selector consumes, never the payload.

**2. That leaves the SSN gate needing a form it can compare.** `CredentialSelector::pick()` gates a
candidate on the request's SSN and DOB against the ones the match's own scrape recorded. Three ways
to keep that gate without storing an SSN, and the choice is the owner's — see decision **C1** in §7:

| Option | Keeps today's exactness | Conflicts with plan 2 |
|---|---|---|
| (a) Store a keyed digest of `req_ssn` | yes | **yes** — reintroduces precisely what plan 2 deletes |
| (b) Store `req_ssn_last_four` + a `req_ssn_present` flag | no — two people sharing last-4 *and* DOB would both pass | no |
| (c) Store nothing; gate on DOB only, fall back to the source when the request carries an SSN | no, and it breaks self-sufficiency for SSN-bearing requests | no |

**Recommended default if C1 goes unanswered: (b).** The request always carries the full SSN, so
last-4 plus presence is enough to *reject* a mismatched cache row, which is the gate's actual job;
the residual false-accept needs a shared last-4 **and** a shared DOB. Document the precision loss in
`docs/EVALUATION.md` rather than leaving it implicit.

**3. A self-sufficient cache makes the miss path load-bearing, and nobody owns it.**
`00-CONFORMANCE.md` Theme 2 records that "trigger the bots to scrape" is stated four times across
the two flow pages and has zero hits across the plans. While the cache silently fell through to the
source, that gap was invisible; once it answers alone, a miss has to *do* something. gp-cami is
API-only and the bots are CAMI's, so H10's contract is: **return a `refresh_required` verdict and
let CAMI dispatch.** H10 does not gain a scraper dispatcher. State that in the plan so the next
reader does not treat the miss path as unfinished.

Plan 3a's first migration renames this table's `current` column to `source_current`, freeing the
name for the SCD-2 flag, and every mirrored column H10 adds sits on a table 3a has already
versioned — which is why H10 stays behind 3a in the order.

### Prerequisite bug fixes — already owned, listed so H-track authors do not re-own them

`00-PROGRAMME.md` §9 carries four verified live bugs. Two of them gate this extension:

- **`ProbabilisticResolver::sharesExclusionRegistry()` ignores its `$p` argument**, so the
  `exclusion_share` weight (0.07) fires whenever the *candidate* identity carries any exclusion at
  all. Plan 9 writes that contribution into `gp_edge.detail`, where a steward will read
  "exclusion_share fired" and believe a shared registry was found. **Fix before plan 9 ships, not
  after.** Owner: plan 5b or 6.
- **Set-based `ON DUPLICATE KEY UPDATE` duplicates a row whenever a unique-key part is NULL**, so
  `license_count` and `address_count` are inflated in any hub backfilled more than once. The
  dashboard surfaces `license_count` on `/compare` (`ReviewController.php:201`) and never shows
  `address_count`. Owner: plan 3b.

---

## 5. Track D — `gp-cami-dashboard`

The dashboard already has more than a first look suggests: a stats board, smart search, profile
detail, a review queue over `gp_source_link.match_state`, a quality page with a record-count
histogram and flag tabs, an account lens, a pipeline page, an API playground, and three commands —
`gpdash:snapshot`, `gpdash:merge-basis` and `gpdash:index-advisor`. (`gpdash:snapshot` is described
as nightly in its own prose but **nothing in the repo schedules it** — `bootstrap/app.php` has no
`withSchedule()` and `routes/console.php` registers only `inspire`. D4 and D5 depend on it running,
so scheduling it is part of D4.)

What it does not have is **any of the wiki's steward loop** — no evidence, no decision, no masking,
no auth, no metrics, no lineage. Its only access control is `DevOnly`, an
`app()->environment(['local','development'])` check.

| Task | What it delivers | Hub gate | Exit gate |
|---|---|---|---|
| **D0** | Auth, roles and an access log, replacing the `DevOnly` environment gate. Four roles mirroring H13. Session auth; no public route. | H13 | An unauthenticated request to any route 302s to login; a `steward`-only route 403s for an `analyst`; every request records actor, route and the identity ids it touched |
| **D1** | PII masking end to end: list views, profile detail, exports (`/export/{csv,json}`) and the API playground proxy. Masked by default; unmasking is a role, is logged, and is visible in the UI. | H13 | A feature test asserts **no** unmasked `date_of_birth` or `address1` in any response for an `analyst` — including the CSV export and the playground |
| **D2** | Evidence panel — `/compare` shows *why* the resolver bound or flagged a pair: signals, weights, score, band, block key. Reads `gp_edge`. | 9 | A review-queue row opens to the evidence that produced it; an unimplemented weight renders as unimplemented, not as zero |
| **D3** | Steward decisions from the queue — confirm / reject / split / pin, posted to H15. Optimistic row state, per-decision rationale, a decision log, and an undo for a `split`. | H15, D0 | A decision changes the hub and survives reload; a failed post leaves the queue unchanged and says so; no route writes `golden_profile` directly |
| **D4** | Quality-gate board — the wiki's seven checks on one page: the quarantine queue (`gp_quarantine`, plan 5), row-count range and freshness (`gp_run`, plan 11), uniqueness, consistency, crosswalk integrity, and drift in auto-match / new-provider rate. | 5, 11 | Each of the seven renders pass/fail with its threshold and the run it was measured on; a quarantined row is readable with its reason |
| **D5** | Metrics and alarm surface — the *Success Metrics* targets (99% / 95% / 85%, review-queue SLA, 24h/48h freshness) trended from `gp_metric`, breaches highlighted, alarm history listed. | 11 | Every target on that wiki page is either trended or explicitly marked not-computable-here, with a reason |
| **D6** | Run-diff viewer — renders plan 11's `RunDiff` so a matching change is reviewable *before* it ships: identities gained and lost, links moved between bands, clusters split or joined. | 11 | Two code states over the fixed corpus produce a readable diff; an empty diff is distinguishable from a diff that failed to run |
| **D7** | Lineage and survivorship views — trace any golden field to the source record that won it and the rule that picked it; show the ingest manifest the record arrived on. | H14 | Every scalar on a profile page has a "where did this come from" answer |
| **D8** | Decision → label loop, **UI only.** Plan 9 Task 6 already builds `DecisionExporter` + `gp:export-labels` writing `storage/app/review-labels.json` in `EvalSet`'s schema. D8 adds the steward-facing half: which decisions are exportable, an export button, and a review screen before a human promotes a batch. **Do not build a second exporter.** | 9, D3 | An export produced from the UI loads in `EvalSet`; the public hub repo receives **no** real person's data — the batch stays a local artifact until a human promotes it |
| **D9** | History and entity views — a profile's SCD-2 version timeline with as-of viewing, and correct rendering of `entity_type = entity` (org name, no DOB, no maiden name). | 3a, 4 | A version timeline renders for a profile with more than one version; an organization profile shows no person-only fields |

**D0 and D1 depend on H13 and on nothing else**, so the D-track can start as soon as H13 is authored
and executed — it does not wait for the whole hub ladder. Everything else in the D-track is gated on
a specific hub plan and must not start before it, because each one reads a table that does not yet
hold a row.

Decision A (2026-09-07) makes this repository **the** steward UI, which changes D0's standing: it is
no longer a hedge against a possible future deployment, it is the precondition for stewardship
existing at all. Nothing in D2–D9 is worth building on an app that cannot name its actor.

---

## 6. Combined execution order

`00-PROGRAMME.md` §2's canonical hub order is unchanged and still binding. This extension appends to
it and interleaves the dashboard:

**Revised 2026-09-07 by decision A: H13 is pulled forward.** As first drafted H13 sat after plan 11,
which put the entire dashboard track at the very end. But H13 needs no hub table that does not
already exist, and no plan from 2 to 11 contains a `GRANT`, a `CREATE ROLE` or a view — so its
roles, access log and data contracts have nothing to wait for. Now that the dashboard *is* the
steward UI, H13 is the one hub plan whose delay blocks a whole second repository, so it runs as a
parallel lane from the start.

**One part of H13 is not independent, and its plan must say so.** The masked views cover DOB on
`gp_identity` and the address line on `gp_address`, and both tables are in plan 3a's versioned
register — a view written before 3a starts silently returning superseded versions once 3a lands.
Plan 2 separately drops `gp_identity.ssn_hash` and `ssn_last_four`, breaking any view that
enumerates them. So H13's view definitions must be `current = 1`-aware from the start and must not
enumerate the columns plan 2 deletes; if that cannot be done cleanly, the view task alone lands
after plan 2 while roles, the access log and data contracts ship on schedule. This is the same
argument the H10 subsection makes for mirrored columns, applied to views.

```
hub A: 1(done) → 5 → 3a → 3b → 2 → 5b → 4 → 6 → 7 → 8 → 9 → 11 → H10 → H12 → H14 → H15 → H16
                                                          │    │    │            │     │
hub B: 1(done) → H13 ────────────────────────────────────────────────────────────┤     │
                  │                                       │    │    │           │     │
dash:             ├ D0 → D1                               │    │    │           │     │
                  │                                       ├ D2 │    │           │     ├ D3 → D8
                  │                                       │    ├ D4 ─ D5 ─ D6   │     │
                  │                                       │    │    │           ├ D7  │
                        after 3a + 4: ── D9 ──────────────┘    │    │           │     │
```

Read it as: nothing in the D-track starts before its gate lands. Lane B (H13 → D0 → D1) is genuinely
parallel to lane A and needs no hub table that does not already exist. Everything from D2 onward
rejoins lane A's gates.

**Why H13 comes before H15 and before the rest of the D-track.** H15 opens a write door and D0 puts a
UI in front of it. Both are meaningless without roles, and retrofitting authorization onto endpoints
that already exist is how a public repository grows an unauthenticated mutation route.

**Why H16 goes last.** Every other plan asserts the eval gate unchanged. H16 is the one plan whose
purpose is to move it, so it runs after everything that would otherwise be unable to tell its own
regression from H16's intended change.

---

## 7. Decisions required from the owner

`HANDOFF.md` §8 already lists six of its own. These are the ones this extension adds.

### Decided by the owner, 2026-09-07 — closed

| # | Decision | Answer | What it settles |
|---|---|---|---|
| **A** | Is `gp-cami-dashboard` the steward UI, or does stewardship live in CAMI? | **The dashboard is the steward UI.** | H15 is built for it as the first write client, and D3/D8 are in scope rather than speculative. Keep H15's contract client-agnostic anyway so CAMI can become a second steward client without a rewrite. |
| **C** | Should the credential cache be self-sufficient, or be reclassified as an index over the source with the doc amended? | **Self-sufficient.** | H10 is unblocked and its scope is fixed: mirror the selector's inputs into `gp_identity_credential`, answer without touching `streamline_local`, and return `refresh_required` on a miss. Costs a backfill and a staleness window, both of which H10 must measure. See §4's H10 subsection for the three consequences this creates. |

| **B** | Will the dashboard ever serve outside `local`/`development`? The wiki's access-control and masking requirements are only satisfiable if it does. | **Yes, by implication of A** — not answered directly. | If the dashboard is the steward UI then stewards are real named users doing compliance work, so it cannot stay behind an `APP_ENV` check forever. H13 + D0 + D1 become **mandatory and first**, not the "build it anyway" hedge they were while B was open. Confirm this reading before D0 ships a login page. |

Decision A also promotes decision D from a hedge to a blocker: an app that cannot name its actor
cannot record a steward decision.

### Still open

| # | Decision | Blocks | If unanswered |
|---|---|---|---|
| **C1** | **How the self-sufficient cache gates on SSN without storing one** — options (a)/(b)/(c) in §4's H10 subsection. New, created by decision C. | H10 | Take option **(b)**: `req_ssn_last_four` + a presence flag, with the precision loss documented in `docs/EVALUATION.md` |
| **D** | **Identity provider for D0** — SSO, local users, or hub-issued Sanctum tokens. Promoted to a blocker by decision A. | D0, and therefore D1–D9 | Local users plus a `steward` ability on the hub token: the smallest thing that satisfies the spec |
| **E** | **`gp_board_action`'s shape** (`00-PROGRAMME.md` §6) — build the normalizer, drop the table, or keep it as a projection of typed exclusion rows. | 6, 7, D7 | Deferred; D7 shows board actions as typed exclusions and says so on the page |
A seventh candidate — **PII in the eval fixture**, since D8 turns real steward decisions into labels
and `sv-manila/gp-cami` is public — is **already decided and needs nothing from the owner.** Plan 9
Task 6 writes `storage/app/review-labels.json` and deliberately never touches the committed
`tests/eval/identity-pairs.json`. D8 inherits that rule.

Two existing `HANDOFF.md` §8 items now gate more than they did: **Pass B recalibration** is H16, and
**`scripts/baseline-key-mix.sql` query 3** still decides whether the 5-before-2 order is necessary or
merely prudent. Both need production hub access, which nobody in this environment has.

---

## 8. Non-goals

Unchanged from the standing decisions, restated because this extension touches the pages that propose
them:

- **No lakehouse, no Spark/Glue/Iceberg, no Redshift.** The *Data Model* page's Redshift DDL and the
  *Scaling & Performance* page's Spark/GraphFrames design are out of scope by architecture, not by
  neglect. The portable requirement survives (cap oversized blocks); the runtime does not.
- **No ML matcher.** *How Record Matching Works* Pass 3 and *Using AI to Improve Matching* describe
  work in `sv-manila/profiling`, a separate Python engine. Pass A plus a recalibrated Pass B is the
  hub's matcher.
- **No external government feed ingestion** — LEIE, SAM, state boards and NPPES arrive through CAMI's
  existing bots, not through gp-cami.
- **No `steward_decision` table.** `confirm`/`reject` have a verified CAMI source, `pin_match` is
  already `is_pinned`, and `rationale` is `resolution_metadata.note`. Plan 6 decided this and the
  audit agreed.

---

## 9. Risks

| Risk | Consequence | Mitigation |
|---|---|---|
| **11 written plans are unexecuted**, 2 more (10, 12) are specified but unwritten, and this adds **4** genuinely new hub plans (H13–H16) plus 10 dashboard tasks | The ladder never finishes and the dashboard ships against tables holding no rows | Ship in the stated order and treat each exit gate as a stop. H13 + D0 + D1 is a complete, independently valuable slice — land it before starting D2. Note H10 and H12 **are** plans 10 and 12, same reserved prefixes; they are authoring work, not new scope |
| Nothing is pushed — 28 commits live only on this machine | Total loss of plan 1 and of every plan document | **Push `feat/eval-harness` and open the PR.** CI needs no secret. Open since 2026-09-04 as `HANDOFF.md` §8 item 6 |
| H16 moves the eval numbers | A later regression becomes indistinguishable from H16's intended change | H16 runs last and re-baselines the ratchet in the same commit, with the new value and the reason |
| `/compare` shows `license_count`, which plan 3b proves is inflated on any hub backfilled more than once | Stewards decide a merge on a wrong count, and the number will legitimately **fall** when 3b lands | Land 3b before D2/D3, since `/compare` is exactly the screen D2 and D3 build on; annotate the field until it does |
| **The self-sufficient cache (decision C) mirrors data out of `credential_matches.match`, whose `request_params.ssn` is plaintext SSN** | A straight mirror puts plaintext SSN in the hub — the thing plan 2 exists to delete and the wiki forbids | H10 mirrors derived scalars only, never the payload; decision C1 settles the SSN gate. A test must assert no mirrored column can hold a 9-digit SSN |
| Two documentation contradictions no plan can fix | Code gets built confidently wrong | "Never store SSN" (§1) vs "prove full rebuild-from-scratch works" (§4); and `golden_provider`/`provider_xref` vs `individuals`/`entities`. **The doc set must be amended** — raise both on the wiki |

---

## 10. What "done" means

The wiki is implemented when, on a hub with production-shaped data:

1. A mid-confidence pair is **held**, shown to a steward with its evidence, decided, and the decision
   is reversible and audited. *(9, H15, D0, D2, D3)*
2. A credential check is answered from the profile with no external call. *(H10)*
3. Bad data is quarantined and alarmed before it reaches the golden layer, and every load carries a
   manifest. *(5, 11, H14, D4)*
4. Any golden value traces to the source record that won it and the rule that picked it. *(H14, D7)*
5. An analyst cannot read a birth date, and the attempt is logged. *(H13, D1)*
6. Every *Success Metrics* target is a number somebody computes. *(11, D5)*
7. A matching change produces a reviewable before/after diff. *(11, D6)*

Anything on the wiki not on that list is either owned by plans 2–12, out of scope by §8, or in the
audit's declined-with-cause set in `00-CONFORMANCE.md`.

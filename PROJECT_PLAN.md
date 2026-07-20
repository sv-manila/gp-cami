# gp-cami — Project Plan

**Project:** `gp-cami` (Golden Profile for CAMI)
**Version:** 1.0
**Date:** 2026-07-20
**Owner:** John Hombrebueno
**Design source:** [`../GOLDEN_PROFILE_PLAN.md`](../GOLDEN_PROFILE_PLAN.md) (master spec, v1.0) + `golden-profile-explainer.html`

This is the build/scaffolding plan for the new repo. It does **not** redefine the design —
the master spec is authoritative for schema (§4), source mapping (§5), resolution logic (§6),
run modes (§7), API contracts (§10), and the full test plan (§11). This document says how to
stand up the repo and in what order to build, mapped to the master's 10 phases.

---

## 1. What gp-cami is

A source-agnostic identity hub. Collapses scattered person records in `streamline_local` into one
resolved golden identity per real person, links every credential match / exclusion hit / prior
resolution to it, and serves it to CAMI over two REST endpoints.

**Locked decisions** (from master §2): Laravel/PHP · hub DB `golden_profile` on its own server ·
identity scope **across accounts** · high-confidence probabilistic matches **auto-merge** · source
is **SELECT-only** by grant · hub stores **CAMI-encrypted SSN** + `ssn_last_four` (+ `ssn_hash` if CAMI encryption is non-deterministic); never plaintext, never returned in responses.

## 2. Stack & dependencies

| Item | Choice |
|---|---|
| Framework | Laravel (PHP) — Eloquent, migrations, artisan commands, queued jobs, API routes |
| Hub DB | `golden_profile` (MySQL/MariaDB), read/write — default connection |
| Source DB | `streamline_local`, **SELECT-only** connection |
| Queue | **Redis** — dedicated queue for engine jobs (parallel backfill + `Bus::batch` progress) |
| Composer | `streamlineverify/sv` (`MatchSummaryStatus` constants) · `streamlineverify/security` (SSN Crypter/CipherString + shared `encryption_keys`) |
| Auth | **`auth:sanctum`** on `/api/v1/*` |
| Tests | PHPUnit — 53+ tests per master §11 |

## 3. Repo layout

```
gp-cami/
├── app/
│   ├── Models/
│   │   ├── Gp/            # GpIdentity, GpSourceLink, GpEdge, GpAttribute, GpLicense,
│   │   │                  # GpAddress, GpIdentityCredential, GpIdentityExclusion,
│   │   │                  # GpIdentityResolution, GpIdentityProfile, GpSourceSystem,
│   │   │                  # GpResolutionLog, GpWatermark  (golden_profile connection)
│   │   └── Src/           # Employee, CredentialMatch, ExclusionMatch, MatchAction,
│   │                      # CredentialMatchAction  (streamline_local, read-only)
│   ├── GoldenProfile/     # engine module (source-agnostic)
│   │   ├── Connectors/    # StreamlineLocalConnector -> stg_person shape
│   │   ├── Resolution/    # PassA (deterministic), PassB (block + score), Survivorship
│   │   ├── Materialize/   # profile builder (gp_identity_profile)
│   │   └── Reuse/         # auto-resolution reuse (target_key lookup)
│   ├── Console/Commands/  # gp:backfill, gp:sync, gp:rebuild-profile
│   ├── Jobs/              # IngestChunkJob, ResolveBlockJob, MaterializeProfileJob
│   └── Http/
│       ├── Controllers/Api/V1/   # CredentialSearchController, IdentitySearchController
│       ├── Requests/             # CredentialSearchRequest, IdentitySearchRequest
│       └── Resources/            # identity/profile API resources
├── config/
│   ├── database.php       # golden_profile (default) + streamline_local (read-only)
│   └── golden_profile.php # thresholds, auto-merge cutoffs, chunk size, workers, qualifying-status set
├── database/migrations/   # all gp_* / stg_* DDL (master §4), golden_profile connection
├── routes/api.php         # /api/v1/credential-search, /api/v1/identity-search
├── tests/                 # Unit + Feature (master §11: T1–T53)
└── PROJECT_PLAN.md        # this file
```

## 4. Boundary rules (non-negotiable)

- All `gp_*` / `stg_*` tables live in `golden_profile`. **No FK points at any source DB.**
- `gp_source_link.source_id` is a plain indexed column; cross-DB integrity via engine + invariant INV1.
- Engine DB user: `SELECT`-only on every source, full DML on `golden_profile`. Guaranteed by grant.
- Resolution engine only ever sees `stg_*` staging — never source-specific schema. New source = connector + one `gp_source_system` row, no engine change.

## 5. Build order (mapped to master §12 phases)

| Ph | Deliverable | Exit gate |
|---|---|---|
| 0 | Scaffold Laravel app; **provision fresh `golden_profile` server** + grants; configure both DB connections + Redis queue; verify SELECT-only source grant; **clear legal/PII gate** (§8.6) | grant verified; empty DB provisions clean; PII policy approved |
| 1 | All `gp_*`/`stg_*` migrations + `gp_watermark` + StreamlineLocal connector | `php artisan migrate` clean; INV assertions pass on empty DB |
| 2 | Deterministic Pass A (keys 1–6: ssn_hash, npi, dea, upin, name+dob, license+state) + T1–T6, T8–T9 | precision ≥ 0.99 on fixtures **and held-out eval set** |
| 3 | Probabilistic Pass B (block + **calibrated per-source-pair** scores) + auto-merge + review queue + exclusion `link_state` candidates + T7 | recall ≥ 0.95 on **held-out** eval set; review queue works |
| 4 | Incremental / idempotent reruns + T10–T12, T17 | reruns clean, reversible |
| 5 | Scale hardening + `gp:backfill` Mode 1 (parallel, resumable, watermark hand-off) + T13–14, T27–30 | 1M-row backfill under target, resumable |
| 6 | Second-source proof (config-only new system) + T15–T16 | new source ingests without engine change |
| 7 | `gp_identity_profile` materialization + serving views + T18–T20 | single-identity read = one PK lookup |
| 8 | Resolution ingest + cross-account auto-resolution reuse + T47–51, T53 | reuse works; all auto-applies logged |
| 9 | REST API: `credential-search` + `identity-search` (Form Requests, Resources, config status filter, pagination, `prior_resolution`) + T31–46, T52 | endpoints auth + rate-limited; tests green |

Each phase is gated by its tests — no phase starts until the prior gate passes.

## 6. Engine commands

```
php artisan gp:backfill {system} [--from-id=] [--workers=]   # Mode 1 full backfill (chunked jobs, resumable)
php artisan gp:sync {system}                                 # Mode 2 incremental (scheduled)
php artisan gp:rebuild-profile [--identity=]                 # re-materialize gp_identity_profile
```
Chunk via `chunkById` (keyset, never `offset`); DB transaction + `updateOrInsert` per chunk for
idempotency; `Bus::batch()` for backfill progress + resume.

## 7. REST API (CAMI-facing, master §10)

- `POST /api/v1/credential-search` — → latest **qualifying** credential match + `prior_resolution`. Qualifying set from `MatchSummaryStatus` (`streamlineverify/sv`), filtered on `match_summary_status_code`, + `expiry_date` guard. `LIMIT 1` best match; log warning on multi-identity.
  - **Required:** `registry`, `first_name`, `last_name`
  - **Optional (narrow resolution):** `license_number`, `license_type`, `dob`, `ssn`
  - `ssn` arrives as **exact plaintext**. Stored **encrypted** using **CAMI's same encryption scheme** (compatible with `streamline_local.social_security_num`). Used as a resolution key (0.99). Never echoed back in any response.
  - **Note:** supersedes master spec §10 assumption (`last_name`+`license_number` required). Confirmed with CAMI 2026-07-20.

**SSN storage & matching (supersedes master spec §2 / §10 PII rule) — RESOLVED 2026-07-20 from `client/vendor/streamlineverify/security`:**
- Hub stores SSN **encrypted** via the shared `Streamlineverify\Security\Encryption\Crypter` (AES-256-CBC, `OPENSSL_RAW_DATA`, **random per-value IV**) — parity with `streamline_local.social_security_num`, decryptable with the shared key.
- Encryption is **non-deterministic** (random IV) → ciphertext **cannot** be compared. Matching uses the deterministic hash the Crypter emits: **`ssn_hash = sha512(plaintext_ssn + plaintext_key)`** (`CipherString::getHash()`). Store `ssn_hash` + `ssn_last_four` alongside the encrypted column.
- SSN key is a **global datapoint key** (`EncryptionKey::forDataPoint(subject, attribute, subject_id=NULL)`) — not per-account → `ssn_hash` is consistent **across accounts**, cross-account SSN merges work. Master spec's per-tenant-salt risk does **not** apply.
- Responses never return SSN or `ssn_hash` — `ssn_last_four` only.
- **Hard dependency:** require the **`streamlineverify/security`** composer package and access to the **same `encryption_keys` / plaintext SSN key** (via its `KeyManager`). Without the identical key, neither ciphertext parity nor hash-matching works.
- `POST /api/v1/identity-search` — `last_name` required → every matching identity (aliases, licenses, addresses, credentials, exclusions, resolutions, accounts) straight from `gp_identity_profile`. Matches canonical name **and** aliases. Never returns `ssn_hash`/encrypted SSN — `ssn_last_four` only.

## 8. Adopted review principles

Ported from an external v2 review (2026-07-20). The v2 plan itself proposed a different
architecture (AWS lakehouse + ML) and was **rejected** — gp-cami stays a Laravel/MySQL hub — but
these engineering principles are sound and are folded in here:

1. **Two-track error priority.** Identity resolution is **precision-first** (fear wrong merges); exclusion/sanction/board-action links are **recall-first** (fear missed hits). Tune the matcher per domain, not globally. → affects Phase 2/3 thresholds and Phase 8 rollups.
2. **Exclusion links = scored candidates, not silent merges.** `gp_identity_exclusion` carries `link_state` (`candidate|confirmed|rejected`) + `link_confidence`. A borderline exclusion surfaces as "possible match — review", never auto-cleared or dropped. (Sanctions never auto-merge at ingest.)
3. **Calibrated, per-source-pair thresholds.** No single global probabilistic cutoff. Set each threshold from labeled data; a pair without enough labels defaults to **recall-first + review**, not a guessed number. Emit calibrated scores, not raw model output.
4. **Independent held-out eval set.** Grade precision/recall against a labeled sample **not** used for survivorship tuning or fixtures — avoids circular self-grading. Add to the Phase 2/3 test gates alongside the existing answer-key fixtures.
5. **Survivorship: authority beats recency across trust tiers.** Recency only tiebreaks **within** the same `reliability_rank` tier. A fresh authoritative source outranks a stale scrape; internal "human-checked" values **decay** over a configurable window so a year-old manual clear stops overriding fresh government data. → refines `gp_source_system.reliability_rank` use in survivorship.
6. **Legal/PII as Phase-0 gates, not late risks.** PII handling + retention/deletion policy (incl. how "compliance facts never deleted" reconciles with deletion obligations) approved **before** ingestion. Encryption + masked views confirmed. → added to Phase 0 exit gate.

Not adopted: AWS lakehouse/Iceberg/Spark, AWS Entity Resolution vs Splink spike, ML matcher, external gov-feed ingestion (LEIE/SAM/state boards/scrapers), bitemporal lakehouse, 30-week/5-person timeline — all out of scope for the Laravel hub serving CAMI.

## 9. Resolved decisions (2026-07-20)

| Item | Decision |
|---|---|
| Hub DB server | **Provision fresh** in Phase 0 |
| API auth | **`auth:sanctum`** |
| Queue driver | **Redis** |
| Scaffold | **Held** — plan only for now; scaffold on go |

## 10. Immediate next steps (Phase 0, on go)

1. `composer create-project laravel/laravel .` inside `gp-cami/`.
2. Add `golden_profile` (default) + `streamline_local` (read-only) connections to `config/database.php`; creds to `.env`. Set `QUEUE_CONNECTION=redis`.
3. Require `streamlineverify/sv` + `streamlineverify/security`; install Sanctum (`php artisan install:api`).
4. Publish `config/golden_profile.php` with thresholds + qualifying-status set.
5. Provision fresh `golden_profile` server + grants; verify `SELECT`-only grant on source.
6. Clear the legal/PII gate (retention/deletion policy) — §8.6.
7. Commit scaffold; open Phase 1 (migrations).

## 11. Open items still to confirm

- **CAMI**: ~~required request fields for `credential-search`~~ ✓ confirmed 2026-07-20 (registry/first/last required; license#/type/dob/ssn optional). Still open: live `match_summary_status` qualifying codes.
- **Infra** (before Phase 0 execution): `golden_profile` server host/creds; SELECT-only source DB user; Redis endpoint.
- **Security** — ~~encryption deterministic?~~ ✓ resolved: non-deterministic AES-256-CBC; match via `sha512(ssn+key)`; key is global (cross-account works). See §7. **Still needed (infra, before Phase 2):** the shared plaintext SSN encryption key / `encryption_keys` access + `streamlineverify/security` package, so ciphertext and `ssn_hash` are compatible with CAMI.

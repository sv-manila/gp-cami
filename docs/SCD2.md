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

### Known gap: NULL key parts are not constrained

Because `CONCAT` propagates NULL, `current_key` is NULL for any row whose natural key has a NULL
part — and MySQL never constrains NULLs in a unique index. So `uq_*_current` does **not** enforce
single-current for those rows. `gp_address`'s natural key has four nullable parts
(`address1`, `city`, `state`, `zip`), so this is not a corner case there.

Plan 3b closes it in code, on both write paths. The schema-level fix — a `COALESCE` sentinel inside
`current_key` — has **no owner**: `00-PROGRAMME.md` §9 and §10 both defer it "to plan 5", but plan 5
was written and executed without it. Whoever picks it up should note that changing the expression
rebuilds the index on every versioned table, and that a sentinel string must be one no real value
can contain.

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

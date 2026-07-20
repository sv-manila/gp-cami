# Phase 0 — Provisioning runbook

Infra steps that gp-cami's code cannot perform for itself. Run these against the fresh
`golden_profile` server and the `streamline_local` source, then fill the matching `.env` values.

## 1. Create the hub database

```sql
CREATE DATABASE golden_profile
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

## 2. Hub engine user — full DML on golden_profile only

```sql
CREATE USER 'gp_engine'@'%' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON golden_profile.* TO 'gp_engine'@'%';
FLUSH PRIVILEGES;
```

`.env`: `GP_DB_*`.

## 3. Source user — SELECT-only (read-only guaranteed by grant, not convention)

Run on the `streamline_local` server. The engine must never be able to write to source.

```sql
CREATE USER 'gp_source_ro'@'%' IDENTIFIED BY '<strong-password>';
GRANT SELECT ON streamline_local.* TO 'gp_source_ro'@'%';
FLUSH PRIVILEGES;
```

`.env`: `SRC_DB_*`.

### Verify the grant is SELECT-only (Phase 0 exit gate)

```sql
SHOW GRANTS FOR 'gp_source_ro'@'%';
-- expect exactly: GRANT SELECT ON `streamline_local`.* TO ...
-- a write must fail:
-- INSERT INTO streamline_local.employees (id) VALUES (0);  -> ERROR 1142 (denied)
```

## 4. Redis

Provision a Redis instance for the engine queue + cache. Fill `REDIS_*` in `.env`
(`REDIS_CLIENT=predis`, `QUEUE_CONNECTION=redis`, `GP_ENGINE_QUEUE=gp-engine`).

## 5. Shared SSN encryption key (before Phase 2)

gp-cami stores SSN encrypted and matches on `ssn_hash = sha512(ssn + plaintext_key)` via
`streamlineverify/security`. It must use the **same plaintext SSN key as CAMI**, or neither
ciphertext parity nor hash-matching works.

- Give gp-cami read access to the same `encryption_keys` source (or provision its
  `KeyManager` with the identical key material).
- Confirm the SSN datapoint key id and set `GP_SSN_ENCRYPTION_KEY_ID`.

## 6. Legal / PII gate (Phase 0 exit gate)

- PII handling + retention/deletion policy written and approved **before** ingestion.
- Reconcile "compliance facts never deleted" with any deletion obligation.
- Confirm encryption at rest + masked analyst views.

---

## Phase 0 exit checklist

- [ ] `golden_profile` DB created; `gp_engine` connects (`GP_DB_*` filled).
- [ ] `gp_source_ro` verified SELECT-only; a test write is denied (`SRC_DB_*` filled).
- [ ] Redis reachable; queue config set.
- [ ] Shared SSN key access confirmed; `GP_SSN_ENCRYPTION_KEY_ID` set.
- [ ] Legal / PII policy approved.
- [ ] Empty hub provisions clean → open Phase 1 (migrations).

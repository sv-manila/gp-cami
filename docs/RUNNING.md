# Running gp-cami locally + client integration

## What's live

- Hub DB `golden_profile` on `app2.streamlineverify.local` (MySQL 8), fed from `streamline_local`.
- Schema: 17 `gp_*` / `stg_*` tables (migrations).
- Engine: connector → deterministic Pass A resolution → credential/exclusion rollups → profile
  materialization. Commands: `gp:backfill`, `gp:sync`, `gp:rebuild-profile`.
- REST API (Sanctum bearer): `POST /api/v1/identity-search`, `POST /api/v1/credential-search`.

## Bring it up

```bash
cd "C:/ai codes/gp-cami"
php artisan migrate --force              # provision hub schema
php artisan gp:backfill                  # Mode 1: resolve all source rows
php artisan serve --host=0.0.0.0 --port=8137   # reachable from the VM at 192.168.56.1:8137
```

Incremental after edits in the source: `php artisan gp:sync`.

Local backfill result: 104 source employees → 32 golden identities (72 name+dob merges),
7 licenses, 259 exclusion candidates. (Source has 0 credential_matches, empty addresses.)

### Auth token

A Sanctum token for the `cami@local.test` user is in `storage/cami_token.txt`. Regenerate:

```bash
php artisan tinker --execute='echo App\Models\User::firstOrCreate(["email"=>"cami@local.test"],["name"=>"CAMI","password"=>bcrypt(str()->random(24))])->createToken("cami-local")->plainTextToken;'
```

### Notes

- Local dev uses `root/root` and `CACHE_STORE=file` (no Redis needed — the engine runs inline).
  Redis is the target for the queue at scale; wire `REDIS_*` + `QUEUE_CONNECTION=redis` when available.
- SSN: `ssn_hash = sha512(ssn + key)`, key resolved locally via the security package's
  LocalStrategy. Ciphertext is never used for matching; SSN is never returned (ssn_last_four only).

## Client integration (C:\ai codes\client)

The local client (Laravel, runs in the Vagrant VM) calls gp-cami:

- `config/services.php` → `golden_profile` block (`url`, `token`, `timeout`).
- `.env`: `GOLDEN_PROFILE_URL=http://192.168.56.1:8137`, `GOLDEN_PROFILE_TOKEN=<sanctum token>`.
- `app/Services/GoldenProfile/GoldenProfileClient.php` — `identitySearch()` / `credentialSearch()`.
- `app/Console/Commands/GoldenProfileLookup.php` — `php artisan golden-profile:lookup {last} --first= --registry= --ssn=`.

Run from inside the VM (where the client boots):

```bash
php artisan golden-profile:lookup Adkins
php artisan golden-profile:lookup Adkins --first=Paula --registry=CA-BRN
```

Verified end to end from the client's HTTP stack: identity-search returns the resolved
identities (Paula Adkins #10, Morgan Adkins #18), credential-search resolves the identity,
and `ssn_hash` is never exposed to the client.

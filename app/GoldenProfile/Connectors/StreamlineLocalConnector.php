<?php

namespace App\GoldenProfile\Connectors;

use App\GoldenProfile\Support\NpiValidator;
use App\GoldenProfile\Support\QuarantineRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maps streamline_local.employees (+ its alt_* columns) into the canonical
 * staging shape (stg_person + alias/address/license). The engine only ever
 * reads staging, never the source schema. Read-only on the source.
 */
class StreamlineLocalConnector
{
    public const SOURCE_TABLE = 'employees';

    public function __construct(private int $systemId) {}

    /** Source connection (SELECT-only). */
    private function src()
    {
        return DB::connection('streamline_local');
    }

    /** Hub connection (read/write). */
    private function hub()
    {
        return DB::connection('golden_profile');
    }

    /**
     * Ingest one employee row into staging. Idempotent on
     * (system_id, source_table, source_id). Returns stg_person_id.
     *
     * $accountMap (employeelist_id => account_id) lets the caller batch the
     * source-side employeelists lookup once per chunk instead of once per row —
     * critical when the source is a high-latency (WAN) connection. When null,
     * falls back to a per-row source lookup.
     */
    /**
     * Map an employee row to the canonical stg_person shape (no DB write).
     * Shared by per-row ingest() and the set-based batch stager.
     */
    public function personRow(object $emp, ?array $accountMap = null): array
    {
        $accountId = null;
        if ($emp->employeelist_id) {
            $accountId = $accountMap !== null
                ? ($accountMap[$emp->employeelist_id] ?? null)
                : $this->src()->table('employeelists')
                    ->where('id', $emp->employeelist_id)->value('account_id');
        }

        // Format-valid means "10 digits with a correct NPPES check digit" — see
        // NpiValidator. A value that fails this is nulled here, not just skipped
        // by a caller, so every downstream consumer (both resolvers, both
        // ingestion paths, since they all share this one method) sees the same
        // fact: stg_person.npi is either a validated NPI or nothing. Rejecting a
        // value that a PRIOR load accepted can split an identity that currently
        // merges on it — see gp:npi-audit for measuring that against a real hub,
        // since the eval fixture cannot exercise this (Task 2's fix confirmed
        // neither corrected NPI is shared between records).
        $npi = (int) ($emp->npi ?? 0);
        $npiValid = $npi > 0 && NpiValidator::isValid((string) $npi);

        return [
            'system_id' => $this->systemId,
            'source_table' => self::SOURCE_TABLE,
            'source_id' => $emp->id,
            'account_id' => $accountId ?: null,
            'employeelist_id' => $emp->employeelist_id ?: null,
            'first_name' => $this->cleanName($emp->first_name),
            'middle_name' => $this->cleanName($emp->middle_name),
            'last_name' => $this->cleanName($emp->last_name),
            'date_of_birth' => $this->date($emp->date_of_birth),
            'ssn_hash' => $emp->ssn_hash ?: null,          // ingest as-is (global key)
            'ssn_last_four' => $emp->ssn_last_four ?: null,
            'npi' => $npiValid ? $npi : null,
            'upin' => $emp->upin ?: null,
            'dea_number' => null,                          // not present in this source
            'address1' => $this->clean($emp->address1),
            'city' => $this->clean($emp->city),
            'state' => $this->clean($emp->state),
            'zip' => $this->clean($emp->zip),
            'terminated' => (int) ($emp->terminated ?? 0),
            'source_modified' => $this->date($emp->date_modified, true),
            'ingested_at' => now(),
            'block_key' => $this->blockKey($emp->last_name, $emp->date_of_birth),
        ];
    }

    /**
     * $aiRows lets a caller supply this employee's employee_additional_info
     * rows instead of paying for a per-row source query, exactly as
     * $accountMap already does for the employeelists lookup. It also makes the
     * per-row path testable: phpunit.xml points SRC_DB_* at a dead socket on
     * purpose, so before this parameter existed no test could drive ingest()
     * past the additional-info fetch at all.
     */
    public function ingest(object $emp, ?array $accountMap = null, ?iterable $aiRows = null): ?int
    {
        $row = $this->personRow($emp, $accountMap);
        $children = $this->childRows($emp);

        // A row with no name, no valid npi, no ssn hash, no dea and no licence
        // — all judged AFTER junk-cleaning — carries nothing any resolver can
        // act on. Staging it mints a meaningless residual identity that lives
        // forever, so it goes to gp_quarantine instead. Returns null: callers
        // must not resolve a row that was never staged.
        $reason = (new QuarantineRecorder)->evaluate($row, $children['licenses']);
        if ($reason !== null) {
            (new QuarantineRecorder)->record($this->systemId, self::SOURCE_TABLE, (int) $emp->id, $reason);

            return null;
        }

        // Select-first instead of updateOrInsert: on a fresh load the common
        // path is a brand-new row, and knowing it's new lets us skip the three
        // child-table deletes (nothing to delete) and the id re-select.
        $key = ['system_id' => $this->systemId, 'source_table' => self::SOURCE_TABLE, 'source_id' => $emp->id];
        $stgId = (int) $this->hub()->table('stg_person')->where($key)->value('stg_person_id');
        $isNew = $stgId === 0;

        if ($isNew) {
            $stgId = (int) $this->hub()->table('stg_person')->insertGetId($row);
        } else {
            $this->hub()->table('stg_person')->where($key)->update($row);
        }

        $this->rebuildChildren($stgId, $emp, $isNew, $children, $aiRows);

        return $stgId;
    }

    /** Rebuild the flattened alias/address/license children for a staged person. */
    private function rebuildChildren(int $stgId, object $emp, bool $isNew = false, ?array $children = null, ?iterable $aiRows = null): void
    {
        $hub = $this->hub();
        // A freshly inserted staged person has no children yet — skip the
        // four (empty) deletes that dominate the fresh-load per-row cost.
        if (! $isNew) {
            $hub->table('stg_person_alias')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_address')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_license')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_identifier')->where('stg_person_id', $stgId)->delete();
        }

        // ingest() has already computed these to run the quarantine gate;
        // reuse them rather than paying for childRows() a second time per row.
        $c = $children ?? $this->childRows($emp);

        // employee_additional_info (DEA/MMIS + extra licenses/aliases). The
        // set-based backfill (SqlBackfill::stage()) has always fetched this per
        // chunk; the per-row path never did, so DEA/MMIS identifiers were
        // silently invisible to gp:sync and Engine::backfill() until this fix —
        // which makes promoting them to a real-time resolver tier (Task 9)
        // meaningless on that path without it.
        //
        // One extra source query per ingested row mirrors exactly what stage()
        // already does per chunk, just unbatched. ingest() is not on a hot bulk
        // path — that is stage()'s job — only on gp:sync's incremental,
        // already-per-row loop, so this does not change its performance
        // character.
        $aiRows ??= $this->src()->table('employee_additional_info')
            ->where('employee_id', $emp->id)->where('value', '<>', '')
            ->get(['name', 'value']);
        $extra = $this->additionalRows($aiRows, $emp->state ?? null);
        $c['aliases'] = array_merge($c['aliases'], $extra['aliases']);
        $c['licenses'] = array_merge($c['licenses'], $extra['licenses']);
        $c['identifiers'] = $extra['identifiers'];

        foreach (['stg_person_alias' => 'aliases', 'stg_person_address' => 'addresses',
            'stg_person_license' => 'licenses', 'stg_person_identifier' => 'identifiers'] as $table => $bucket) {
            if ($c[$bucket] ?? null) {
                $hub->table($table)->insert(array_map(
                    fn ($r) => $r + ['stg_person_id' => $stgId], $c[$bucket]
                ));
            }
        }
    }

    /**
     * Map an employee's alt_* columns to flattened alias/address/license child
     * rows (no stg_person_id, no DB write). Shared by ingest() and the batch
     * stager. Returns ['aliases'=>[], 'addresses'=>[], 'licenses'=>[]].
     */
    public function childRows(object $emp): array
    {
        $aliases = [];
        $addAlias = function ($type, $first, $last) use (&$aliases) {
            $first = $this->cleanName($first);
            $last = $this->cleanName($last);
            if ($first || $last) {
                $aliases[] = ['alias_type' => $type, 'first_name' => $first, 'last_name' => $last];
            }
        };
        $addAlias('maiden', null, $emp->maiden_name ?? null);
        $addAlias('maiden', null, $emp->alt_maiden_name_1 ?? null);
        $addAlias('maiden', null, $emp->alt_maiden_name_2 ?? null);
        $addAlias('alt', $emp->alt_first_name ?? null, $emp->alt_last_name ?? null);
        for ($i = 2; $i <= 5; $i++) {
            $addAlias('alt', $emp->{"alt_first_name_$i"} ?? null, $emp->{"alt_last_name_$i"} ?? null);
        }
        $addAlias('business', $emp->business ?? null, null);
        $addAlias('business', $emp->alt_business1 ?? null, null);
        $addAlias('business', $emp->alt_business2 ?? null, null);

        $addresses = [];
        if ($this->clean($emp->address1) || $this->clean($emp->city)) {
            $addresses[] = [
                'address_type' => 'primary',
                'address1' => $this->clean($emp->address1), 'address2' => $this->clean($emp->address2),
                'city' => $this->clean($emp->city), 'state' => $this->clean($emp->state), 'zip' => $this->clean($emp->zip),
            ];
        }
        if ($this->clean($emp->alt_address1_1 ?? null) || $this->clean($emp->alt_city_1 ?? null)) {
            $addresses[] = [
                'address_type' => 'alt',
                'address1' => $this->clean($emp->alt_address1_1 ?? null), 'address2' => $this->clean($emp->alt_address2_1 ?? null),
                'city' => $this->clean($emp->alt_city_1 ?? null), 'state' => $this->clean($emp->alt_state_1 ?? null), 'zip' => $this->clean($emp->alt_zip_1 ?? null),
            ];
        }

        $licenses = [];
        $addLic = function ($num, $state, $board, $type, $typeId, $primary) use (&$licenses) {
            $num = $this->clean($num);
            if ($num) {
                $licenses[] = [
                    'license_number' => $num,
                    'certification_state' => $this->clean($state),
                    'certification_board' => $this->clean($board),
                    'license_type' => $this->clean($type),
                    'license_type_id' => $this->clean($typeId),
                    'registry' => null,
                    'is_primary' => $primary,
                ];
            }
        };
        $addLic($emp->certification_number ?? null, $emp->certification_state ?? null, $emp->certification_board ?? null, $emp->license_type ?? null, $emp->license_type_id ?? null, 1);
        $addLic($emp->alt_certification_number ?? null, $emp->alt_certification_state ?? null, $emp->alt_certification_board ?? null, $emp->alt_license_type ?? null, $emp->alt_license_type_id ?? null, 0);

        return ['aliases' => $aliases, 'addresses' => $addresses, 'licenses' => $licenses];
    }

    /**
     * Pivot an employee's employee_additional_info rows (EAV: name => value)
     * into: multi-valued identifiers (DEA, MMIS — match keys), extra licenses
     * (CSL + alt cert/csl licenses), and business-name aliases.
     *
     * $state is the employee's own state (personRow()'s already-cleaned
     * value), attached to MMIS identifiers only — DEA registration is federal.
     *
     * @param  iterable  $aiRows  rows with ->name / ->value (or [name][value])
     * @param  string|null  $state  the employee's state, for state-scoped identifiers
     * @return array{identifiers:array,licenses:array,aliases:array}
     */
    public function additionalRows(iterable $aiRows, ?string $state = null): array
    {
        $state = $this->clean($state);
        $v = [];
        foreach ($aiRows as $r) {
            $name = is_array($r) ? ($r['name'] ?? null) : ($r->name ?? null);
            $val = is_array($r) ? ($r['value'] ?? null) : ($r->value ?? null);
            $val = $this->clean($val);
            if ($name !== null && $val !== null) {
                $v[$name] = $val;
            }
        }

        // Every identifier row carries the SAME key set, state included, even
        // where state is meaningless. Both staging paths insert these as one
        // multi-row statement and Laravel takes the column list from the first
        // row only, then binds array_values() of each subsequent row against
        // it — so a DEA row missing the key and an MMIS row carrying it fails
        // outright with "SQLSTATE[21S01] Column count doesn't match value
        // count at row 2". Measured. An employee holding both a DEA and an
        // MMIS number is not rare, so the shapes must not diverge.
        $identifiers = [];
        // DEA (match key, federal — never state-scoped, so state stays null).
        foreach (['dea_number', 'alt_dea_number', 'alt_license_dea_number_2', 'alt_license_dea_number_3',
            'alt_license_dea_number_4', 'alt_license_dea_number_5', 'alt_license_dea_number_6'] as $k) {
            if (! empty($v[$k])) {
                $identifiers[] = ['id_type' => 'dea', 'id_value' => $v[$k], 'state' => null];
            }
        }
        // MMIS (match key, state-scoped — see this plan's Task 8 for why
        // "(state, medicaid id)" and "(state, provider#)" both resolve to this
        // one field in streamline_local).
        if (! empty($v['mmis_number'])) {
            $identifiers[] = ['id_type' => 'mmis', 'id_value' => $v['mmis_number'], 'state' => $state];
        }

        $licenses = [];
        $addLic = function ($num, $state, $board, $type, $registry) use (&$licenses) {
            $num = $this->clean($num);
            if ($num) {
                $licenses[] = [
                    'license_number' => $num, 'certification_state' => $this->clean($state),
                    'certification_board' => $this->clean($board), 'license_type' => $this->clean($type),
                    'license_type_id' => null, 'registry' => $registry, 'is_primary' => 0,
                ];
            }
        };
        // CSL licenses (primary + alt).
        $addLic($v['csl_number'] ?? null, $v['csl_state'] ?? null, null, 'CSL', 'CSL');
        $addLic($v['alt_csl_number'] ?? null, $v['alt_csl_state'] ?? null, null, 'CSL', 'CSL');
        // Alt license sets (2..6): a cert license and a CSL license each.
        for ($i = 2; $i <= 6; $i++) {
            $addLic($v["alt_license_cert_number_$i"] ?? null, $v["alt_license_cert_state_$i"] ?? null,
                $v["alt_license_cert_board_$i"] ?? null, $v["alt_license_type_$i"] ?? null, null);
            $addLic($v["alt_license_csl_number_$i"] ?? null, $v["alt_license_csl_state_$i"] ?? null,
                null, 'CSL', 'CSL');
        }

        // Business-name aliases (alt_business3..9; 1-2 already come from employees).
        $aliases = [];
        for ($i = 3; $i <= 9; $i++) {
            if (! empty($v["alt_business$i"])) {
                $aliases[] = ['alias_type' => 'business', 'first_name' => $v["alt_business$i"], 'last_name' => null];
            }
        }

        return ['identifiers' => $identifiers, 'licenses' => $licenses, 'aliases' => $aliases];
    }

    private function blockKey(?string $last, ?string $dob): ?string
    {
        $last = $this->clean($last);
        if (! $last) {
            return null;
        }
        $year = $dob ? substr((string) $dob, 0, 4) : '____';

        return soundex($last).'|'.$year;
    }

    private function clean(?string $v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === '' || $v === null) ? null : $v;
    }

    /**
     * clean() trims and nulls empty strings for every text column; this is the
     * narrower, name-specific half of junk screening (Delivery Checklist:
     * "all-zero NPI, 'INFORMATION NOT AVAILABLE'"). Kept separate from clean()
     * on purpose — a value like "UNKNOWN" is unambiguous junk in a name field
     * but not necessarily in every other column, so this is applied only where
     * this plan has confirmed it belongs: first/middle/last name and
     * name-shaped alias fields.
     */
    private function cleanName(?string $v): ?string
    {
        $v = $this->clean($v);
        if ($v === null) {
            return null;
        }
        $placeholders = array_map('strtoupper', (array) config('golden_profile.junk.name_placeholders', []));

        return in_array(strtoupper($v), $placeholders, true) ? null : $v;
    }

    private function date(?string $v, bool $withTime = false): ?string
    {
        if (! $v || str_starts_with((string) $v, '0000')) {
            return null;
        }
        try {
            $c = Carbon::parse($v);

            return $withTime ? $c->toDateTimeString() : $c->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}

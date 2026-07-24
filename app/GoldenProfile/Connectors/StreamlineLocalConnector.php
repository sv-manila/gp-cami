<?php

namespace App\GoldenProfile\Connectors;

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
    public function ingest(object $emp, ?array $accountMap = null): int
    {
        $now = now();

        $accountId = null;
        if ($emp->employeelist_id) {
            $accountId = $accountMap !== null
                ? ($accountMap[$emp->employeelist_id] ?? null)
                : $this->src()->table('employeelists')
                    ->where('id', $emp->employeelist_id)->value('account_id');
        }

        $npi = (int) ($emp->npi ?? 0);
        $blockKey = $this->blockKey($emp->last_name, $emp->date_of_birth);

        $row = [
            'system_id' => $this->systemId,
            'source_table' => self::SOURCE_TABLE,
            'source_id' => $emp->id,
            'account_id' => $accountId ?: null,
            'employeelist_id' => $emp->employeelist_id ?: null,
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
            'ingested_at' => $now,
            'block_key' => $blockKey,
        ];

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

        $this->rebuildChildren($stgId, $emp, $isNew);

        return $stgId;
    }

    /** Rebuild the flattened alias/address/license children for a staged person. */
    private function rebuildChildren(int $stgId, object $emp, bool $isNew = false): void
    {
        $hub = $this->hub();
        // A freshly inserted staged person has no children yet — skip the
        // three (empty) deletes that dominate the fresh-load per-row cost.
        if (! $isNew) {
            $hub->table('stg_person_alias')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_address')->where('stg_person_id', $stgId)->delete();
            $hub->table('stg_person_license')->where('stg_person_id', $stgId)->delete();
        }

        // ---- aliases ----
        $aliases = [];
        $addAlias = function ($type, $first, $last) use (&$aliases) {
            $first = $this->clean($first);
            $last = $this->clean($last);
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
        if ($aliases) {
            $hub->table('stg_person_alias')->insert(array_map(
                fn ($a) => $a + ['stg_person_id' => $stgId], $aliases
            ));
        }

        // ---- addresses ----
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
        if ($addresses) {
            $hub->table('stg_person_address')->insert(array_map(
                fn ($a) => $a + ['stg_person_id' => $stgId], $addresses
            ));
        }

        // ---- licenses (primary + alt) ----
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
        if ($licenses) {
            $hub->table('stg_person_license')->insert(array_map(
                fn ($l) => $l + ['stg_person_id' => $stgId], $licenses
            ));
        }
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

    private function date(?string $v, bool $withTime = false): ?string
    {
        if (! $v || str_starts_with((string) $v, '0000')) {
            return null;
        }
        try {
            $c = \Illuminate\Support\Carbon::parse($v);

            return $withTime ? $c->toDateTimeString() : $c->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}

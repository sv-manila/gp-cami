<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base case for tests that need a real golden_profile hub.
 *
 * phpunit.xml deliberately points GP_DB_* at a dead socket so no test can
 * silently read or write the shared hub. That protection also made the resolver
 * untestable — nothing in tests/ has ever called resolve(). This class builds a
 * SEPARATE connection from GP_TEST_DB_*, pointed at a scratch schema, and
 * rebinds 'golden_profile' to it for the duration of the test run.
 *
 * MySQL, not SQLite, and deliberately so: the schema reuses index names across
 * tables (idx_identity is on eight of them). MySQL scopes index names per table;
 * SQLite scopes them per database, so `migrate` dies on the second gp_* table.
 * Renaming twenty-odd indexes on a live 13M-row hub to suit a test harness is
 * the wrong trade.
 *
 * Cost control: migrations run ONCE per process (the static flag), and each test
 * runs inside a transaction that is rolled back in tearDown. The engine opens its
 * own transactions in places (Engine::mergeIdentity) — those nest as savepoints
 * and roll back with the outer one.
 *
 * With no GP_TEST_DB_* configured the tests SKIP. That is intentional: a missing
 * test database must never silently fall through to a real one.
 */
abstract class HubTestCase extends TestCase
{
    private static bool $migrated = false;

    protected int $systemId;

    protected function setUp(): void
    {
        parent::setUp();

        $database = env('GP_TEST_DB_DATABASE');

        if (! $database) {
            $this->markTestSkipped('GP_TEST_DB_DATABASE is not set — see .env.example');
        }

        config([
            'database.connections.golden_profile' => [
                'driver' => 'mysql',
                'host' => env('GP_TEST_DB_HOST', '127.0.0.1'),
                'port' => env('GP_TEST_DB_PORT', '3306'),
                'database' => $database,
                'username' => env('GP_TEST_DB_USERNAME', 'root'),
                'password' => env('GP_TEST_DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => 'InnoDB',
            ],
        ]);

        DB::purge('golden_profile');

        $this->guardAgainstTheRealHub($database);

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', [
                '--database' => 'golden_profile',
                '--path' => ['database/migrations'],
                '--force' => true,
            ]);
            self::$migrated = true;
        }

        $this->hub()->beginTransaction();

        $this->systemId = $this->seedSystem();
    }

    protected function tearDown(): void
    {
        if (isset($this->systemId)) {
            $this->hub()->rollBack();
        }

        parent::tearDown();
    }

    /**
     * migrate:fresh DROPS EVERY TABLE. Getting this connection wrong once would
     * destroy the hub, so the name is checked rather than trusted.
     */
    private function guardAgainstTheRealHub(string $database): void
    {
        if (! str_contains($database, 'test')) {
            $this->fail(
                "refusing to migrate '$database': GP_TEST_DB_DATABASE must be a scratch ".
                "schema with 'test' in its name (e.g. gp_cami_test)"
            );
        }
    }

    protected function hub()
    {
        return DB::connection('golden_profile');
    }

    /** Register a source system and return its system_id. */
    protected function seedSystem(string $code = 'streamline_local', int $reliability = 50): int
    {
        return (int) $this->hub()->table('gp_source_system')->insertGetId([
            'system_code' => $code.'-'.uniqid(),
            'display_name' => $code,
            'reliability_rank' => $reliability,
            'is_active' => 1,
            'added_at' => now(),
        ]);
    }

    /**
     * Stage one person. Returns stg_person_id. Defaults mirror what the
     * StreamlineLocal connector produces, block_key included. A caller-supplied
     * block_key wins — the merge order matters, so overrides are applied last.
     */
    protected function stagePerson(array $overrides = []): int
    {
        static $nextSourceId = 1000;

        $defaults = [
            'system_id' => $this->systemId,
            'source_table' => 'employees',
            'source_id' => ++$nextSourceId,
            'account_id' => 1,
            'employeelist_id' => 1,
            'first_name' => 'Robert',
            'middle_name' => null,
            'last_name' => 'Smith',
            'name_suffix' => null,
            'date_of_birth' => '1970-04-02',
            'ssn_hash' => null,
            'ssn_last_four' => null,
            'npi' => null,
            'upin' => null,
            'dea_number' => null,
            'address1' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'terminated' => 0,
            'source_modified' => now()->toDateTimeString(),
            'ingested_at' => now(),
        ];

        $row = array_merge($defaults, $overrides);

        if (! array_key_exists('block_key', $overrides)) {
            $row['block_key'] = $this->blockKey($row['last_name'], $row['date_of_birth']);
        }

        return (int) $this->hub()->table('stg_person')->insertGetId($row);
    }

    /** Attach a license to a staged person. */
    protected function stageLicense(int $stgPersonId, string $number, ?string $state = null): void
    {
        $this->hub()->table('stg_person_license')->insert([
            'stg_person_id' => $stgPersonId,
            'license_number' => $number,
            'certification_state' => $state,
            'certification_board' => null,
            'license_type' => null,
            'license_type_id' => null,
            'registry' => null,
            'is_primary' => 1,
        ]);
    }

    /** Same rule as StreamlineLocalConnector::blockKey(). */
    protected function blockKey(?string $last, ?string $dob): ?string
    {
        $last = $last ? trim($last) : null;

        if (! $last) {
            return null;
        }

        return soundex($last).'|'.($dob ? substr((string) $dob, 0, 4) : '____');
    }
}

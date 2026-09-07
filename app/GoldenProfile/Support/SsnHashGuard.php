<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;

/**
 * Guards the ssn_hash deterministic tier against filler SSNs.
 *
 * ssn_hash is the highest-confidence key in Pass A (0.99), matched exactly with
 * no name or DOB cross-check. That is correct for a real SSN and catastrophic for
 * a filler one: CAMI's source data contains rows carrying values like 000-00-0000
 * or 123-45-6789, every one of which hashes identically, so an unguarded tier
 * binds all of those unrelated people to a single identity. Nothing downstream
 * undoes it — dedup's mergeByColumn('ssn_hash') actively makes it worse by folding
 * any survivors together too.
 *
 * ssn_hash arrives pre-hashed from the source, so the plaintext cannot be
 * inspected. Two complementary guards:
 *
 *  1. Known placeholders (config golden_profile.ssn.placeholder_plaintexts) hashed
 *     with the live key. Exact, but only works where a key is available.
 *  2. Cardinality: a hash attached to more than
 *     golden_profile.ssn.max_identities_per_hash *distinct people* — distinct
 *     (last_name, first_name, date_of_birth) triples in staging — cannot be one
 *     person's SSN. Needs no key and catches fillers nobody listed.
 *
 * Guard 2 is the load-bearing one, and it deliberately counts distinct people in
 * stg_person rather than rows in gp_identity. Counting identities looks like the
 * obvious check and does not work: the tiers mint ONE identity per distinct hash
 * and then link every row carrying it, so a filler hash ends up on exactly one
 * identity. The damage is invisible from the identity side and only shows up as
 * many unrelated names sharing the hash upstream.
 */
class SsnHashGuard
{
    /** @var array<string,bool> */
    private array $decisions = [];

    /** @var list<string>|null */
    private ?array $placeholderHashes = null;

    public function __construct(private ?SsnHasher $hasher = null) {}

    public function maxIdentitiesPerHash(): int
    {
        return max(1, (int) config('golden_profile.ssn.max_identities_per_hash', 3));
    }

    /** True when this hash must NOT be used as a deterministic identity key. */
    public function isBlocked(?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        return $this->decisions[$hash] ??= $this->decide($hash);
    }

    private function decide(string $hash): bool
    {
        if (in_array($hash, $this->placeholderHashes(), true)) {
            return true;
        }

        // How many distinct people carry this hash upstream? idx on
        // stg_person.ssn_hash keeps this to a narrow index range scan, and the
        // LIMIT means a filler hash on 100k rows stops after cap+1 groups.
        $cap = $this->maxIdentitiesPerHash();

        $distinctPeople = $this->hub()
            ->table('stg_person')
            ->where('ssn_hash', $hash)
            ->distinct()
            ->limit($cap + 1)
            ->pluck(DB::raw("CONCAT_WS('|', last_name, first_name, date_of_birth)"))
            ->count();

        return $distinctPeople > $cap;
    }

    private function hub()
    {
        return DB::connection(config('golden_profile.connections.hub', 'golden_profile'));
    }

    /**
     * Materialise the filler-hash blocklist into gp_ssn_hash_blocklist so the
     * set-based backfill tiers can anti-join against it instead of threading a
     * huge NOT IN list through every statement. Rebuilt from scratch each call.
     *
     * @return int number of blocked hashes
     */
    public function buildBlocklistTable(): int
    {
        $hub = $this->hub();
        $cap = $this->maxIdentitiesPerHash();

        // CREATE TABLE and TRUNCATE both cause an implicit COMMIT in MySQL, and
        // this method sits on resolveDeterministic()'s path — so running either
        // while a caller has a transaction open commits it. Create only when the
        // table is genuinely absent (checked, not IF NOT EXISTS, which commits
        // regardless), and clear with DELETE, which is transactional. The
        // blocklist holds a few thousand rows at most and has no AUTO_INCREMENT,
        // so TRUNCATE bought nothing here.
        $exists = $hub->selectOne(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            ['gp_ssn_hash_blocklist'],
        );

        if (! $exists) {
            $hub->statement('CREATE TABLE gp_ssn_hash_blocklist (
                ssn_hash VARCHAR(255) NOT NULL,
                reason VARCHAR(32) NOT NULL,
                distinct_people INT NOT NULL DEFAULT 0,
                PRIMARY KEY (ssn_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }

        $hub->table('gp_ssn_hash_blocklist')->delete();

        // Cardinality-detected fillers.
        $hub->statement(
            "INSERT INTO gp_ssn_hash_blocklist (ssn_hash, reason, distinct_people)
             SELECT ssn_hash, 'cardinality', people FROM (
                 SELECT ssn_hash,
                        COUNT(DISTINCT CONCAT_WS('|', last_name, first_name, date_of_birth)) people
                 FROM stg_person
                 WHERE ssn_hash IS NOT NULL AND ssn_hash <> ''
                 GROUP BY ssn_hash
             ) g WHERE g.people > ?",
            [$cap]
        );

        // Known placeholders (no-op when no key is available).
        foreach (array_chunk($this->placeholderHashes(), 200) as $chunk) {
            $hub->table('gp_ssn_hash_blocklist')->insertOrIgnore(
                array_map(fn ($h) => ['ssn_hash' => $h, 'reason' => 'placeholder', 'distinct_people' => 0], $chunk)
            );
        }

        return (int) $hub->table('gp_ssn_hash_blocklist')->count();
    }

    /**
     * Hashes of the configured filler SSNs. Empty when no key is available, in
     * which case the cardinality guard carries the whole load.
     *
     * @return list<string>
     */
    public function placeholderHashes(): array
    {
        if ($this->placeholderHashes !== null) {
            return $this->placeholderHashes;
        }

        $hasher = $this->hasher ??= new SsnHasher;
        $out = [];

        foreach ((array) config('golden_profile.ssn.placeholder_plaintexts', []) as $plain) {
            foreach ($hasher->candidateHashes((string) $plain) as $hash) {
                $out[$hash] = true;
            }
        }

        return $this->placeholderHashes = array_keys($out);
    }

    /**
     * SQL fragment excluding blocked hashes, for the set-based backfill tiers.
     * Anti-joins gp_ssn_hash_blocklist (see buildBlocklistTable) so the condition
     * stays a constant-size string no matter how many hashes are blocked.
     */
    public function exclusionSql(string $column): string
    {
        return " AND NOT EXISTS (SELECT 1 FROM gp_ssn_hash_blocklist b WHERE b.ssn_hash = $column)";
    }
}

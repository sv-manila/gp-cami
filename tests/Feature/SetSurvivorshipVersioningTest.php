<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\SetFinalizer;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * The set-based survivorship pass, versioned.
 *
 * Four properties, in the order they matter:
 *
 *  1. A canonical winner that moved mints ONE version for that identity — not one
 *     per changed field, which is what nine independent per-field writes would do.
 *  2. An identity whose winners are unchanged mints NOTHING. This is the rule that
 *     keeps finalizeAll() from adding ~13.38M gp_identity rows a run.
 *  3. record_count is DERIVED: it moves on the current row in place and never on
 *     its own mints a version, because gp_source_link already records when each
 *     link was made with better resolution than a version row would.
 *  4. gp_attribute and gp_survivorship_audit are unchanged in content. They are
 *     per-observation provenance — they ARE the history, so they do not have one
 *     (docs/SCD2.md) — and the restructure moves WHERE they are written from, not
 *     what lands in them.
 *
 * Runs under HubTestCase, not SetBasedTestCase: Task 1's guard makes the index
 * maintenance a no-op inside a transaction, and none of these assertions is about
 * the indexes.
 */
class SetSurvivorshipVersioningTest extends HubTestCase
{
    /** An identity with one linked staged row. Returns [identityId, stgPersonId, linkId]. */
    private function seedLinkedIdentity(array $person = [], array $identity = []): array
    {
        $stg = $this->stagePerson($person);
        $row = $this->hub()->table('stg_person')->where('stg_person_id', $stg)->first();

        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId(array_merge([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => $row->first_name,
            'canonical_middle' => $row->middle_name,
            'canonical_last' => $row->last_name,
            'canonical_dob' => $row->date_of_birth,
            'npi' => $row->npi,
            'confidence' => 1.0, 'record_count' => 1, 'status' => 'active',
            'version_no' => 1, 'current' => 1, 'first_seen' => now(), 'last_updated' => now(),
        ], $identity));

        $linkId = (int) $this->hub()->table('gp_source_link')->insertGetId([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => $row->source_id,
            'account_id' => 1, 'employeelist_id' => 1, 'match_method' => 'deterministic',
            'match_key' => 'name_dob', 'match_score' => 0.95, 'linked_at' => now(),
        ]);

        return [$identityId, $stg, $linkId];
    }

    public function test_an_unchanged_identity_mints_no_version(): void
    {
        [$identityId] = $this->seedLinkedIdentity();

        (new SetFinalizer)->survivorship();

        $this->assertSame(
            1, (int) $this->hub()->table('gp_identity')->where('identity_id', $identityId)->count(),
            'survivorship minted a version for an identity whose winners did not move'
        );
        $this->assertSame(1, (int) $this->hub()->table('gp_identity')
            ->where('identity_id', $identityId)->value('version_no'));
    }

    public function test_running_it_twice_is_still_one_version(): void
    {
        [$identityId] = $this->seedLinkedIdentity();

        $finalizer = new SetFinalizer;
        $finalizer->survivorship();
        $finalizer->survivorship();

        $this->assertSame(
            1, (int) $this->hub()->table('gp_identity')->where('identity_id', $identityId)->count(),
            'the pass is not idempotent — a second run with identical input must add nothing'
        );
    }

    public function test_several_moved_fields_mint_exactly_one_version(): void
    {
        // The whole reason the nine per-field UPDATEs had to go: each of them would
        // have had to version independently.
        [$identityId, $stg] = $this->seedLinkedIdentity();

        $this->hub()->table('stg_person')->where('stg_person_id', $stg)->update([
            'first_name' => 'Bob', 'middle_name' => 'Q', 'last_name' => 'Smyth',
            'source_modified' => now()->addMinute()->toDateTimeString(),
        ]);

        (new SetFinalizer)->survivorship();

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $identityId)
            ->orderBy('version_no')->get();

        $this->assertCount(2, $rows, 'three moved fields must produce ONE version, not three');
        $this->assertSame(0, (int) $rows[0]->current);
        $this->assertSame('Robert', $rows[0]->canonical_first);
        $this->assertSame(1, (int) $rows[1]->current);
        $this->assertSame('Bob', $rows[1]->canonical_first);
        $this->assertSame('Q', $rows[1]->canonical_middle);
        $this->assertSame('Smyth', $rows[1]->canonical_last);
        $this->assertSame(
            '1970-04-02', substr((string) $rows[1]->canonical_dob, 0, 10),
            'a field with an unchanged winner must carry forward, not go NULL'
        );
    }

    public function test_a_field_that_lost_all_its_candidates_carries_forward(): void
    {
        // No non-blank candidate means the field is ABSENT from the payload, which
        // Versioner::differs() skips. differsOnPresent models that as a NULL
        // incoming expression; differsOnAll would have called it a change and blanked
        // the canonical value.
        [$identityId, $stg] = $this->seedLinkedIdentity();

        $this->hub()->table('stg_person')->where('stg_person_id', $stg)
            ->update(['middle_name' => 'Q']);
        $finalizer = new SetFinalizer;
        $finalizer->survivorship();

        $this->hub()->table('stg_person')->where('stg_person_id', $stg)
            ->update(['middle_name' => '   ']);
        $finalizer->survivorship();

        $current = $this->hub()->table('gp_identity')
            ->where('identity_id', $identityId)->where('current', 1)->first();

        $this->assertSame('Q', $current->canonical_middle,
            'losing every candidate must carry the last known winner forward');
        $this->assertSame(2, (int) $current->version_no,
            'and it must not mint a version of its own');
    }

    public function test_record_count_moves_in_place_without_minting_a_version(): void
    {
        [$identityId] = $this->seedLinkedIdentity();
        (new SetFinalizer)->survivorship();

        // A second source row for the same identity: record_count 1 -> 2, and no
        // canonical value moves (identical person data).
        $second = $this->stagePerson();
        $row = $this->hub()->table('stg_person')->where('stg_person_id', $second)->first();
        $this->hub()->table('gp_source_link')->insert([
            'identity_id' => $identityId, 'system_id' => $this->systemId,
            'source_table' => 'employees', 'source_id' => $row->source_id,
            'account_id' => 1, 'employeelist_id' => 1, 'match_method' => 'deterministic',
            'match_key' => 'name_dob', 'match_score' => 0.95, 'linked_at' => now(),
        ]);

        (new SetFinalizer)->survivorship();

        $rows = $this->hub()->table('gp_identity')->where('identity_id', $identityId)->get();

        $this->assertCount(1, $rows, 'a record_count bump alone must never mint a version');
        $this->assertSame(2, (int) $rows[0]->record_count);
    }

    public function test_provenance_is_written_exactly_as_before(): void
    {
        // gp_attribute keeps every candidate with the winner flagged;
        // gp_survivorship_audit keeps the winner only. Both are fully rebuilt each
        // pass, so both must be idempotent, and neither is versioned.
        [$identityId, , $linkId] = $this->seedLinkedIdentity(['middle_name' => 'Q']);

        $finalizer = new SetFinalizer;
        $finalizer->survivorship();
        $finalizer->survivorship();

        $attrs = $this->hub()->table('gp_attribute')->where('identity_id', $identityId)
            ->orderBy('attr_name')->get();

        // first, middle, last, dob — the four fields the fixture supplies.
        $this->assertSame(4, $attrs->count(), 'provenance must be rebuilt, not appended to');
        $this->assertTrue($attrs->every(fn ($a) => (int) $a->is_canonical === 1),
            'a single candidate per field is always the winner');
        $this->assertTrue($attrs->every(fn ($a) => (int) $a->source_link_id === $linkId));

        $audit = $this->hub()->table('gp_survivorship_audit')
            ->where('identity_id', $identityId)->get();

        $this->assertSame(4, $audit->count());
        $this->assertStringStartsWith('authority[', $audit->first()->rule_applied);
        $this->assertStringEndsWith('] + recency', $audit->first()->rule_applied);
    }
}

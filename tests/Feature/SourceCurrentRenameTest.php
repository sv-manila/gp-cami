<?php

namespace Tests\Feature;

use App\GoldenProfile\Materialize\ProfileMaterializer;
use App\GoldenProfile\Support\CredentialSelector;
use Illuminate\Support\Str;
use Tests\Support\HubTestCase;

/**
 * The mirrored CAMI currency flag must live under its own name, because the SCD-2
 * version flag takes the name `current` in the next migration. If both meanings
 * ever share one column, every read that filters `current = 1` silently filters by
 * CAMI's flag instead of the version flag — no error, wrong answer.
 */
class SourceCurrentRenameTest extends HubTestCase
{
    public function test_the_mirrored_cami_flag_is_called_source_current(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        $this->assertTrue(
            $schema->hasColumn('gp_identity_credential', 'source_current'),
            'the mirrored CAMI credential_matches.current must be renamed source_current'
        );
        $this->assertFalse(
            $schema->hasColumn('gp_identity_credential', 'current'),
            'the name `current` must be free for the SCD-2 version flag'
        );
    }

    public function test_the_profile_json_still_publishes_the_field_as_current(): void
    {
        // The rename is internal. gp_identity_profile.credentials is a published
        // response shape, so the JSON key stays `current` and only the column
        // behind it changes.
        $identityId = $this->seedIdentityWithCredential(1);

        (new ProfileMaterializer)->rebuild($identityId);

        $credentials = json_decode(
            (string) $this->hub()->table('gp_identity_profile')
                ->where('identity_id', $identityId)->value('credentials'),
            true
        );

        $this->assertSame(true, $credentials[0]['current'], 'the JSON key must stay `current`');
    }

    /**
     * The reader the plan's file list missed, and the one whose failure is
     * silent. CredentialSelector::pick() sorts on `$l->current`, and its rows
     * come straight from gp_identity_credential via SELECT * in
     * CredentialSearchController::latestQualifyingCredential(). Left reading
     * `current`, the property would simply be absent after the rename and
     * `?? 0` would make the "current DESC" key a constant — every credential
     * ties on it and the ranking silently degrades to date order. Worse after
     * the next migration, when `current` exists again as the VERSION flag and
     * is 1 for every live row.
     *
     * So this asserts on the behaviour, not the column: a CAMI-current
     * credential must outrank a CAMI-superseded one even when the superseded
     * one has the newer date.
     */
    public function test_the_credential_selector_still_prefers_the_cami_current_row(): void
    {
        $identityId = $this->seedIdentityWithCredential(0, 5002, '2026-01-01');
        $this->addCredential($identityId, 1, 5003);

        $links = $this->hub()->table('gp_identity_credential')
            ->where('identity_id', $identityId)->get()
            ->map(function ($l) {
                // Older date on the CAMI-current row, so date order alone would
                // pick the wrong one and the source_current key has to decide.
                $l->date_updated = $l->credential_match_id === 5003 ? '2020-01-01' : '2026-01-01';
                $l->date_created = $l->date_updated;
                $l->expiry_date = null;

                return $l;
            });

        $winner = CredentialSelector::pick($links, false, '2026-09-07');

        $this->assertSame(5003, (int) $winner->credential_match_id,
            'the CAMI-current credential must win even with an older date');
    }

    private function seedIdentityWithCredential(int $sourceCurrent, int $matchId = 5001, ?string $resolved = null): int
    {
        $identityId = (int) $this->hub()->table('gp_identity')->insertGetId([
            'identity_uuid' => (string) Str::uuid(),
            'canonical_first' => 'Robert', 'canonical_last' => 'Smith',
            'canonical_dob' => '1970-04-02', 'confidence' => 1.0, 'record_count' => 0,
            'status' => 'active', 'first_seen' => now(), 'last_updated' => now(),
        ]);

        $this->addCredential($identityId, $sourceCurrent, $matchId, $resolved);

        return $identityId;
    }

    private function addCredential(int $identityId, int $sourceCurrent, int $matchId, ?string $resolved = null): void
    {
        $this->hub()->table('gp_identity_credential')->insert([
            'identity_id' => $identityId,
            'credential_match_id' => $matchId,
            'system_id' => $this->systemId,
            'registry' => 'NYEMED',
            'match_summary_status' => 'Valid',
            'match_summary_status_code' => 20,
            'match_is_valid' => 1,
            'source_current' => $sourceCurrent,
            'date_resolved' => $resolved,
            'link_state' => 'confirmed',
        ]);
    }
}

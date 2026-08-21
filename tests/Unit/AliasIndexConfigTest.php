<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The alias index and the response-shaping caps are both config-driven and both
 * guard against failures that are silent rather than loud, so the contract is
 * worth pinning. Hub-free: no query runs here.
 */
class AliasIndexConfigTest extends TestCase
{
    public function test_json_columns_cover_every_rollup_the_resource_emits(): void
    {
        $configured = (array) config('golden_profile.api.json_columns');

        // If a rollup column is added to the profile and not listed here, it is
        // exempt from the size cap and can exhaust memory on a single wide row --
        // which is exactly how identity 3 (69MB credentials) killed the endpoint.
        foreach (['identifiers', 'addresses', 'licenses', 'credentials', 'exclusions',
            'accounts', 'aliases', 'source_records', 'resolutions'] as $col) {
            $this->assertContains($col, $configured, "$col is not covered by the size cap");
        }
    }

    public function test_max_json_bytes_is_well_under_php_memory_limit(): void
    {
        $cap = (int) config('golden_profile.api.max_json_bytes');

        $this->assertGreaterThan(0, $cap);

        // A page can hold per_page rows, each up to the cap, and they are decoded
        // into PHP structures several times larger than the raw JSON. A cap near
        // memory_limit defeats its own purpose.
        $this->assertLessThanOrEqual(8 * 1048576, $cap,
            'a cap this high cannot prevent the memory exhaustion it exists to prevent');
    }

    public function test_per_page_is_capped_so_a_page_cannot_be_unbounded(): void
    {
        $rules = (new \App\Http\Requests\IdentitySearchRequest)->rules();

        // per_page x max_json_bytes is the worst-case page footprint, so an
        // uncapped per_page reintroduces the same failure by another route.
        $this->assertContains('max:100', $rules['per_page']);
        $this->assertContains('integer', $rules['per_page']);
    }

    public function test_alias_search_no_longer_depends_on_the_json_rollup(): void
    {
        // Comments are stripped first: the file deliberately documents the old
        // predicate, and prose describing a bug is not the bug.
        $code = implode("\n", array_filter(
            array_map('trim', file(app_path('Http/Controllers/Api/V1/IdentitySearchController.php'))),
            fn ($line) => ! str_starts_with($line, '//')
                && ! str_starts_with($line, '*')
                && ! str_starts_with($line, '/*'),
        ));

        // The old predicate could not match its own row: MySQL stores JSON with a
        // space after the colon, and '"last":"x"' has none. It must not come back.
        $this->assertStringNotContainsString('LOWER(aliases)', $code);
        $this->assertStringNotContainsString('"last":"', $code);
        $this->assertStringContainsString('aliasIndexer->identityIdsFor', $code);
    }
}

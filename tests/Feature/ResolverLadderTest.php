<?php

namespace Tests\Feature;

use Tests\Support\HubTestCase;

class ResolverLadderTest extends HubTestCase
{
    public function test_the_harness_migrates_the_hub_schema(): void
    {
        $schema = $this->hub()->getSchemaBuilder();

        $this->assertTrue($schema->hasTable('gp_identity'), 'gp_identity was not created');
        $this->assertTrue($schema->hasTable('stg_person'));
        $this->assertTrue($schema->hasTable('gp_source_link'));
    }
}

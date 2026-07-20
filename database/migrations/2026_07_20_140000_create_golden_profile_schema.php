<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Golden Profile hub schema (master GOLDEN_PROFILE_PLAN.md §4).
 * Runs on the golden_profile connection. gp_* = graph + rollups + profile;
 * stg_* = canonical staging the engine reads.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        $s = Schema::connection($this->connection);

        $s->create('gp_source_system', function (Blueprint $t) {
            $t->smallIncrements('system_id');
            $t->string('system_code', 32)->unique();
            $t->string('display_name', 100)->nullable();
            $t->string('connection_ref', 128)->nullable();
            $t->tinyInteger('reliability_rank')->default(50);
            $t->boolean('is_active')->default(true);
            $t->dateTime('added_at');
        });

        $s->create('gp_identity', function (Blueprint $t) {
            $t->bigIncrements('identity_id');
            $t->char('identity_uuid', 36)->unique();
            $t->string('canonical_first', 100)->nullable();
            $t->string('canonical_middle', 100)->nullable();
            $t->string('canonical_last', 100)->nullable();
            $t->date('canonical_dob')->nullable();
            $t->string('ssn_hash', 255)->nullable();
            $t->unsignedBigInteger('npi')->nullable();
            $t->char('upin', 50)->nullable();
            $t->string('dea_number', 100)->nullable();
            $t->decimal('confidence', 5, 4);
            $t->integer('record_count')->default(0);
            $t->enum('status', ['active', 'merged', 'split', 'review'])->default('active');
            $t->unsignedBigInteger('merged_into')->nullable();
            $t->dateTime('first_seen');
            $t->dateTime('last_updated');
            $t->index('ssn_hash', 'idx_ssn');
            $t->index('npi', 'idx_npi');
            $t->index('upin', 'idx_upin');
            $t->index('dea_number', 'idx_dea');
            $t->index(['canonical_last', 'canonical_first', 'canonical_dob'], 'idx_name_dob');
            $t->index('status', 'idx_status');
        });

        $s->create('gp_source_link', function (Blueprint $t) {
            $t->bigIncrements('link_id');
            $t->unsignedBigInteger('identity_id');
            $t->unsignedSmallInteger('system_id');
            $t->string('source_table', 64);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('account_id')->nullable();
            $t->unsignedBigInteger('employeelist_id')->nullable();
            $t->enum('match_method', ['deterministic', 'probabilistic', 'manual']);
            $t->string('match_key', 32)->nullable();
            $t->decimal('match_score', 5, 4);
            $t->dateTime('linked_at');
            $t->unique(['system_id', 'source_table', 'source_id'], 'uq_source');
            $t->index('identity_id', 'idx_identity');
            $t->index('system_id', 'idx_system');
        });

        $s->create('gp_edge', function (Blueprint $t) {
            $t->bigIncrements('edge_id');
            $t->unsignedBigInteger('identity_id');
            $t->unsignedBigInteger('src_link_id');
            $t->unsignedBigInteger('dst_link_id');
            $t->enum('edge_type', ['ssn_hash', 'npi', 'dea', 'upin', 'name_dob', 'license_registry', 'credential', 'exclusion', 'probabilistic', 'manual']);
            $t->decimal('weight', 5, 4);
            $t->json('detail')->nullable();
            $t->dateTime('created_at');
            $t->index('identity_id', 'idx_identity');
            $t->index(['src_link_id', 'dst_link_id'], 'idx_pair');
        });

        $s->create('gp_attribute', function (Blueprint $t) {
            $t->bigIncrements('attr_id');
            $t->unsignedBigInteger('identity_id');
            $t->string('attr_name', 64);
            $t->string('attr_value', 255)->nullable();
            $t->unsignedBigInteger('source_link_id');
            $t->boolean('is_canonical')->default(false);
            $t->dateTime('observed_at');
            $t->index(['identity_id', 'attr_name'], 'idx_identity_name');
        });

        $s->create('gp_identity_credential', function (Blueprint $t) {
            $t->unsignedBigInteger('identity_id');
            $t->unsignedBigInteger('credential_match_id');
            $t->unsignedSmallInteger('system_id');
            $t->string('registry', 255)->nullable();
            $t->string('match_summary_status', 50)->nullable();
            $t->integer('match_summary_status_code')->nullable();
            $t->tinyInteger('match_is_valid')->nullable();
            $t->tinyInteger('current')->nullable();
            $t->dateTime('date_resolved')->nullable();
            // adopted review principle: compliance links as reviewable candidates
            $t->enum('link_state', ['candidate', 'confirmed', 'rejected'])->default('confirmed');
            $t->decimal('link_confidence', 5, 4)->nullable();
            $t->primary(['system_id', 'credential_match_id']);
            $t->index('identity_id', 'idx_identity');
        });

        $s->create('gp_identity_exclusion', function (Blueprint $t) {
            $t->unsignedBigInteger('identity_id');
            $t->unsignedBigInteger('match_id');
            $t->unsignedSmallInteger('system_id');
            $t->unsignedBigInteger('exclusion_record_id')->nullable();
            $t->string('registry', 64)->nullable();
            $t->tinyInteger('is_ssn_match')->nullable();
            $t->tinyInteger('is_npi_match')->nullable();
            $t->tinyInteger('is_canonical_name_match')->nullable();
            $t->tinyInteger('is_upin_match')->nullable();
            $t->tinyInteger('is_license_number_match')->nullable();
            // adopted review principle: exclusion links are scored candidates, not silent merges
            $t->enum('link_state', ['candidate', 'confirmed', 'rejected'])->default('candidate');
            $t->decimal('link_confidence', 5, 4)->nullable();
            $t->primary(['system_id', 'match_id']);
            $t->index('identity_id', 'idx_identity');
        });

        $s->create('gp_identity_resolution', function (Blueprint $t) {
            $t->bigIncrements('resolution_id');
            $t->unsignedBigInteger('identity_id');
            $t->enum('domain', ['exclusion', 'credential']);
            $t->string('target_key', 255);
            $t->string('action_type', 30);
            $t->string('decision', 30);
            $t->unsignedTinyInteger('decision_status')->nullable();
            $t->json('resolution_metadata')->nullable();
            $t->unsignedInteger('resolved_by')->nullable();
            $t->dateTime('resolved_at');
            $t->unsignedSmallInteger('system_id');
            $t->string('source_action_table', 64);
            $t->unsignedBigInteger('source_action_id');
            $t->tinyInteger('is_auto_resolvable')->default(1);
            $t->tinyInteger('is_current')->default(1);
            $t->unique(['system_id', 'source_action_table', 'source_action_id'], 'uq_action');
            $t->index('identity_id', 'idx_identity');
            $t->index(['identity_id', 'domain', 'target_key', 'is_current'], 'idx_reuse');
        });

        $s->create('gp_resolution_log', function (Blueprint $t) {
            $t->bigIncrements('log_id');
            $t->enum('action', ['create', 'merge', 'split', 'relink', 'override']);
            $t->unsignedBigInteger('identity_id')->nullable();
            $t->json('affected_ids')->nullable();
            $t->string('match_key', 32)->nullable();
            $t->string('reason', 255)->nullable();
            $t->string('actor', 64)->nullable();
            $t->dateTime('created_at');
            $t->index('identity_id', 'idx_identity');
            $t->index('created_at', 'idx_created');
        });

        $s->create('gp_address', function (Blueprint $t) {
            $t->bigIncrements('address_id');
            $t->unsignedBigInteger('identity_id');
            $t->string('address1', 128)->nullable();
            $t->string('address2', 128)->nullable();
            $t->string('city', 65)->nullable();
            $t->string('state', 65)->nullable();
            $t->string('zip', 10)->nullable();
            $t->tinyInteger('is_primary')->default(0);
            $t->unsignedBigInteger('source_link_id');
            $t->unique(['identity_id', 'address1', 'city', 'state', 'zip'], 'uq_addr');
            $t->index('identity_id', 'idx_identity');
            $t->index('zip', 'idx_zip');
        });

        $s->create('gp_license', function (Blueprint $t) {
            $t->bigIncrements('license_id');
            $t->unsignedBigInteger('identity_id');
            $t->string('license_number', 100);
            $t->string('certification_state', 65)->nullable();
            $t->string('certification_board', 10)->nullable();
            $t->string('license_type', 100)->nullable();
            $t->string('license_type_id', 100)->nullable();
            $t->string('registry', 255)->nullable();
            $t->tinyInteger('is_verified')->default(0);
            $t->unsignedBigInteger('source_link_id');
            $t->unique(['identity_id', 'license_number', 'certification_state', 'certification_board'], 'uq_lic');
            $t->index('identity_id', 'idx_identity');
            $t->index(['license_number', 'certification_state'], 'idx_number_state');
        });

        $s->create('gp_identity_profile', function (Blueprint $t) {
            $t->unsignedBigInteger('identity_id')->primary();
            $t->char('identity_uuid', 36)->unique();
            $t->string('first_name', 100)->nullable();
            $t->string('middle_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('ssn_hash', 255)->nullable();
            $t->char('ssn_last_four', 4)->nullable();
            $t->unsignedBigInteger('npi')->nullable();
            $t->char('upin', 50)->nullable();
            $t->string('dea_number', 100)->nullable();
            $t->string('address1', 128)->nullable();
            $t->string('city', 65)->nullable();
            $t->string('state', 65)->nullable();
            $t->string('zip', 10)->nullable();
            $t->integer('address_count')->default(0);
            $t->json('addresses')->nullable();
            $t->tinyInteger('terminated')->nullable();
            $t->integer('license_count')->default(0);
            $t->json('licenses')->nullable();
            $t->decimal('confidence', 5, 4);
            $t->integer('record_count');
            $t->integer('account_count');
            $t->smallInteger('system_count');
            $t->json('aliases')->nullable();
            $t->json('source_records')->nullable();
            $t->json('accounts')->nullable();
            $t->integer('credential_count')->default(0);
            $t->json('credentials')->nullable();
            $t->integer('exclusion_count')->default(0);
            $t->tinyInteger('has_active_exclusion')->default(0);
            $t->json('exclusions')->nullable();
            $t->integer('resolution_count')->default(0);
            $t->json('resolutions')->nullable();
            $t->dateTime('first_seen');
            $t->dateTime('last_updated');
            $t->dateTime('profile_built_at');
            $t->index('ssn_hash', 'idx_ssn');
            $t->index('npi', 'idx_npi');
            $t->index('dea_number', 'idx_dea');
            $t->index(['last_name', 'first_name', 'date_of_birth'], 'idx_name_dob');
            $t->index('has_active_exclusion', 'idx_exclusion');
        });

        $s->create('gp_watermark', function (Blueprint $t) {
            $t->unsignedSmallInteger('system_id');
            $t->string('source_table', 64);
            $t->string('high_water', 64);
            $t->dateTime('updated_at');
            $t->primary(['system_id', 'source_table']);
        });

        $s->create('stg_person', function (Blueprint $t) {
            $t->bigIncrements('stg_person_id');
            $t->unsignedSmallInteger('system_id');
            $t->string('source_table', 64);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('account_id')->nullable();
            $t->unsignedBigInteger('employeelist_id')->nullable();
            $t->string('first_name', 100)->nullable();
            $t->string('middle_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('ssn_hash', 255)->nullable();
            $t->char('ssn_last_four', 4)->nullable();
            $t->unsignedBigInteger('npi')->nullable();
            $t->char('upin', 50)->nullable();
            $t->string('dea_number', 100)->nullable();
            $t->string('address1', 128)->nullable();
            $t->string('city', 65)->nullable();
            $t->string('state', 65)->nullable();
            $t->string('zip', 10)->nullable();
            $t->tinyInteger('terminated')->nullable();
            $t->dateTime('source_modified')->nullable();
            $t->dateTime('ingested_at');
            $t->string('block_key', 64)->nullable();
            $t->unique(['system_id', 'source_table', 'source_id'], 'uq_src');
            $t->index('ssn_hash', 'idx_ssn');
            $t->index('npi', 'idx_npi');
            $t->index('dea_number', 'idx_dea');
            $t->index('block_key', 'idx_block');
        });

        $s->create('stg_person_alias', function (Blueprint $t) {
            $t->unsignedBigInteger('stg_person_id');
            $t->enum('alias_type', ['maiden', 'alt', 'business']);
            $t->string('first_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->index('stg_person_id');
        });

        $s->create('stg_person_address', function (Blueprint $t) {
            $t->unsignedBigInteger('stg_person_id');
            $t->enum('address_type', ['primary', 'alt']);
            $t->string('address1', 128)->nullable();
            $t->string('address2', 128)->nullable();
            $t->string('city', 65)->nullable();
            $t->string('state', 65)->nullable();
            $t->string('zip', 10)->nullable();
            $t->index('stg_person_id');
            $t->index('zip', 'idx_zip');
        });

        $s->create('stg_person_license', function (Blueprint $t) {
            $t->unsignedBigInteger('stg_person_id');
            $t->string('license_number', 100);
            $t->string('certification_state', 65)->nullable();
            $t->string('certification_board', 10)->nullable();
            $t->string('license_type', 100)->nullable();
            $t->string('license_type_id', 100)->nullable();
            $t->string('registry', 255)->nullable();
            $t->tinyInteger('is_primary')->default(0);
            $t->index('stg_person_id');
            $t->index(['license_number', 'certification_state'], 'idx_lic');
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        foreach ([
            'stg_person_license', 'stg_person_address', 'stg_person_alias', 'stg_person',
            'gp_watermark', 'gp_identity_profile', 'gp_license', 'gp_address', 'gp_resolution_log',
            'gp_identity_resolution', 'gp_identity_exclusion', 'gp_identity_credential',
            'gp_attribute', 'gp_edge', 'gp_source_link', 'gp_identity', 'gp_source_system',
        ] as $table) {
            $s->dropIfExists($table);
        }
    }
};

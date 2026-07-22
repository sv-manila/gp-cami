<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns gp-cami with the canonical Golden Provider Profile spec (GPP Wiki, 2026-07-08):
 * match_state incl. Pinned, per-field survivorship audit, append-only board actions,
 * and suffix support for the refined name key.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        $s = Schema::connection($this->connection);

        // #3 Pinned + explicit match_state on the crosswalk (provider_xref analog).
        $s->table('gp_source_link', function (Blueprint $t) {
            $t->enum('match_state', ['auto_match', 'review', 'no_match', 'pinned'])
                ->default('auto_match')->after('match_method');
            $t->tinyInteger('is_pinned')->default(0)->after('match_state');
            $t->index('match_state', 'idx_match_state');
        });

        // #4 suffix support (source may lack it; nullable for future connectors).
        $s->table('stg_person', function (Blueprint $t) {
            $t->string('name_suffix', 20)->nullable()->after('last_name');
        });
        $s->table('gp_identity', function (Blueprint $t) {
            $t->string('canonical_suffix', 20)->nullable()->after('canonical_last');
        });

        // #2 survivorship audit: which source won each field, and why.
        $s->create('gp_survivorship_audit', function (Blueprint $t) {
            $t->bigIncrements('audit_id');
            $t->unsignedBigInteger('identity_id');
            $t->string('attribute_name', 60);
            $t->string('surviving_value', 500)->nullable();
            $t->unsignedSmallInteger('system_id')->nullable();
            $t->unsignedBigInteger('source_link_id')->nullable();
            $t->string('rule_applied', 120);
            $t->dateTime('decided_at');
            $t->index(['identity_id', 'attribute_name'], 'idx_identity_attr');
        });

        // #5 board actions — append-only disciplinary facts.
        $s->create('gp_board_action', function (Blueprint $t) {
            $t->bigIncrements('action_id');
            $t->unsignedBigInteger('identity_id');
            $t->unsignedSmallInteger('system_id');
            $t->string('registry', 80)->nullable();        // state board / FSMB / DEA
            $t->string('action_type', 120)->nullable();
            $t->date('action_date')->nullable();
            $t->date('resolution_date')->nullable();
            $t->string('description', 2000)->nullable();
            $t->string('source_url', 512)->nullable();
            $t->unsignedBigInteger('source_action_id')->nullable();
            $t->dateTime('ingested_at');
            $t->unique(['system_id', 'source_action_id'], 'uq_action');
            $t->index('identity_id', 'idx_identity');
            $t->index('action_date', 'idx_action_date');
        });

        // reflect board actions in the profile read model
        $s->table('gp_identity_profile', function (Blueprint $t) {
            $t->string('suffix', 20)->nullable()->after('last_name');
            $t->integer('board_action_count')->default(0)->after('exclusion_count');
            $t->tinyInteger('has_active_board_action')->default(0)->after('board_action_count');
            $t->json('board_actions')->nullable()->after('has_active_board_action');
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        $s->dropIfExists('gp_board_action');
        $s->dropIfExists('gp_survivorship_audit');
        $s->table('gp_source_link', function (Blueprint $t) {
            $t->dropIndex('idx_match_state');
            $t->dropColumn(['match_state', 'is_pinned']);
        });
        $s->table('stg_person', fn (Blueprint $t) => $t->dropColumn('name_suffix'));
        $s->table('gp_identity', fn (Blueprint $t) => $t->dropColumn('canonical_suffix'));
        $s->table('gp_identity_profile', fn (Blueprint $t) => $t->dropColumn([
            'suffix', 'board_action_count', 'has_active_board_action', 'board_actions',
        ]));
    }
};

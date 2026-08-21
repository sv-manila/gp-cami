<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-valued identity identifiers (DEA, MMIS, …) sourced from
 * employee_additional_info. One employee can carry several of each, so these
 * are child tables, not columns. DEA/MMIS are match keys; CSL and alt licenses
 * flow into the existing stg_person_license / gp_license tables instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('golden_profile');

        // Staged identifiers for a person (pre-resolution).
        $s->create('stg_person_identifier', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('stg_person_id');
            $t->string('id_type', 16);          // 'dea', 'mmis'
            $t->string('id_value', 100);
            $t->index('stg_person_id', 'idx_stg');
            $t->index(['id_type', 'id_value'], 'idx_type_value');
        });

        // Resolved identity identifiers (the golden multi-valued keys).
        $s->create('gp_identity_identifier', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('identity_id');
            $t->string('id_type', 16);
            $t->string('id_value', 100);
            $t->unsignedBigInteger('source_link_id')->nullable();
            $t->unique(['identity_id', 'id_type', 'id_value'], 'uq_identity_identifier');
            $t->index(['id_type', 'id_value'], 'idx_type_value');
        });
    }

    public function down(): void
    {
        $s = Schema::connection('golden_profile');
        $s->dropIfExists('stg_person_identifier');
        $s->dropIfExists('gp_identity_identifier');
    }
};

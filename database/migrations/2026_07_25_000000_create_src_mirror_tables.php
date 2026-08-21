<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hub-local mirrors of the source tables the set-based backfill needs to join.
 * Filled by a bulk copy from streamline_local so resolution + rollups run as
 * in-database INSERT…SELECT joins instead of per-row cross-server reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('golden_profile');

        $s->create('src_credential_match', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('registry', 255)->nullable();
            $t->string('match_summary_status', 50)->nullable();
            $t->integer('match_summary_status_code')->nullable();
            $t->tinyInteger('match_is_valid')->nullable();
            $t->tinyInteger('current')->nullable();
            $t->dateTime('date_resolved')->nullable();
        });

        $s->create('src_match', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('employee_id')->index();
            $t->unsignedBigInteger('exclusion_record_id')->nullable();
            $t->tinyInteger('is_ssn_match')->nullable();
            $t->tinyInteger('is_npi_match')->nullable();
            $t->tinyInteger('is_canonical_name_match')->nullable();
            $t->tinyInteger('is_upin_match')->nullable();
            $t->tinyInteger('is_license_number_match')->nullable();
        });

        $s->create('src_exclusion_record', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('exclusion_list_prefix', 64)->nullable();
        });
    }

    public function down(): void
    {
        $s = Schema::connection('golden_profile');
        $s->dropIfExists('src_credential_match');
        $s->dropIfExists('src_match');
        $s->dropIfExists('src_exclusion_record');
    }
};

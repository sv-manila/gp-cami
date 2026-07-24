<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Surface multi-valued identifiers (DEA, MMIS, …) on the materialized profile. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('golden_profile')->table('gp_identity_profile', function (Blueprint $t) {
            $t->integer('identifier_count')->default(0)->after('dea_number');
            $t->json('identifiers')->nullable()->after('identifier_count');
        });
    }

    public function down(): void
    {
        Schema::connection('golden_profile')->table('gp_identity_profile', function (Blueprint $t) {
            $t->dropColumn(['identifier_count', 'identifiers']);
        });
    }
};

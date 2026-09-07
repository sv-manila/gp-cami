<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MMIS numbers are state-scoped (the same provider can hold a different MMIS
 * number per state Medicaid program); DEA numbers are federal and never carry
 * a state. This column is informational on the storage row, not part of
 * either table's uniqueness — see this plan's Task 8 for why adding it to
 * gp_identity_identifier's unique key would silently break DEA de-duplication
 * (NULL is never equal to NULL in a MySQL unique constraint). The MATCHING
 * predicate that uses this column lives in DeterministicResolver and
 * Engine::mergeByIdentifier (Task 9), not in a constraint here.
 *
 * varchar(65) matches stg_person.state, which is the value that feeds it.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        $s->table('stg_person_identifier', function (Blueprint $t) {
            $t->string('state', 65)->nullable()->after('id_value');
        });
        $s->table('gp_identity_identifier', function (Blueprint $t) {
            $t->string('state', 65)->nullable()->after('id_value');
        });
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        $s->table('stg_person_identifier', fn (Blueprint $t) => $t->dropColumn('state'));
        $s->table('gp_identity_identifier', fn (Blueprint $t) => $t->dropColumn('state'));
    }
};

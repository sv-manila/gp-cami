<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gp_identity_credential.current is CAMI's own credential_matches.current,
 * mirrored across by Engine::rollupCredentials() and SqlBackfill::rollup(). The
 * SCD-2 migration that follows needs the name `current` for the version flag
 * (Data Flow by CAMI, DEV page 4099997697).
 *
 * Leaving both meanings on one column would fail silently rather than loudly:
 * every read that added `WHERE current = 1` would filter by CAMI's currency flag
 * — dropping credentials CAMI has superseded, keeping superseded VERSIONS — with
 * no error anywhere. So the mirror is renamed first, in its own migration, and
 * the API field name stays `current` (mapped in the readers) so no consumer
 * contract moves.
 *
 * RENAME COLUMN is metadata-only in MySQL 8 (ALGORITHM=INSTANT), so this stays
 * cheap even on the largest table in the hub. src_credential_match.current keeps
 * its name: that mirror is a verbatim copy of the CAMI column and is not
 * versioned.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        $s = Schema::connection($this->connection);

        if ($s->hasColumn('gp_identity_credential', 'current')
            && ! $s->hasColumn('gp_identity_credential', 'source_current')) {
            $s->table('gp_identity_credential', function (Blueprint $t) {
                $t->renameColumn('current', 'source_current');
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);

        if ($s->hasColumn('gp_identity_credential', 'source_current')
            && ! $s->hasColumn('gp_identity_credential', 'current')) {
            $s->table('gp_identity_credential', function (Blueprint $t) {
                $t->renameColumn('source_current', 'current');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quality-gate quarantine (Delivery Checklist §2/§3: "quarantine + alert").
 * A row that fails a data-quality gate is recorded here instead of the four
 * ad-hoc Log:: calls (or the runtime-created gp_ssn_hash_blocklist table)
 * gp-cami used before this. One row per offending source record, upserted —
 * re-ingesting the same still-bad row updates quarantined_at rather than
 * accumulating duplicates.
 */
return new class extends Migration
{
    protected $connection = 'golden_profile';

    public function up(): void
    {
        Schema::connection($this->connection)->create('gp_quarantine', function (Blueprint $t) {
            $t->bigIncrements('quarantine_id');
            $t->unsignedSmallInteger('system_id');
            $t->string('source_table', 64);
            $t->unsignedBigInteger('source_id');
            $t->string('reason', 64);
            $t->json('detail')->nullable();
            $t->dateTime('quarantined_at');
            $t->dateTime('resolved_at')->nullable();
            $t->unique(['system_id', 'source_table', 'source_id'], 'uq_quarantine_source');
            $t->index('reason', 'idx_reason');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('gp_quarantine');
    }
};

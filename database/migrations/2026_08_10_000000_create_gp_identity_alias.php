<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Searchable index of alias names.
 *
 * identity-search matches aliases as well as the canonical name, but it did so
 * with LOWER(aliases) LIKE '%"last":"x"%' against the JSON rollup on
 * gp_identity_profile. That was wrong twice over:
 *
 *  1. It never matched anything. MySQL normalises stored JSON with a space after
 *     the colon ("last": "Smith"), so the pattern could not match its own row.
 *  2. A leading-wildcard LIKE on a JSON column cannot use an index, and OR-ing it
 *     with the canonical-name leg forced a full scan of all 13.6M rows on every
 *     request — enough to exceed max_execution_time and return a 500.
 *
 * A multi-valued index on the JSON was the obvious fix and is unavailable: 107,882
 * of the 107,888 rows carrying aliases include a null surname, and
 * CAST(... AS CHAR ARRAY) rejects null. MySQL 8 has no JSON path filter and no
 * partial indexes, so a generated column cannot strip them. Making that route work
 * would mean rewriting the stored aliases, i.e. re-materialising every profile.
 *
 * BOTH name parts are indexed, not just the surname. stg_person_alias holds 108,527
 * rows of which only 17 have a non-blank last_name — almost every alias is an
 * entity/business name carried in first_name ({"last": null, "first": "Acme LLC"}).
 * Indexing surnames alone would have produced a 17-row table and a feature that
 * still found nothing, so alias_name holds either part and alias_part records
 * which it came from.
 *
 * Maintained by ProfileMaterializer (per identity) and SetFinalizer (set-based)
 * alongside the JSON rollup they already write, and rebuildable at any time with
 * `php artisan gp:rebuild-aliases`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('golden_profile')->dropIfExists('gp_identity_alias');

        Schema::connection('golden_profile')->create('gp_identity_alias', function (Blueprint $t) {
            $t->unsignedBigInteger('identity_id');
            $t->string('alias_name', 100);
            // 'last' = maiden/alternate surname, 'first' = given or entity name.
            $t->enum('alias_part', ['last', 'first']);

            // Composite primary key is also the search path (alias_name leads,
            // since that is the lookup) and the dedup guarantee: one row per
            // identity per distinct name per part, so inserts are idempotent.
            $t->primary(['alias_name', 'identity_id', 'alias_part']);

            // Maintenance reads by identity when a profile is re-materialised.
            $t->index('identity_id', 'idx_alias_identity');
        });
    }

    public function down(): void
    {
        Schema::connection('golden_profile')->dropIfExists('gp_identity_alias');
    }
};

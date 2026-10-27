<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the opt-in contact-visibility columns the Listing model and the
 * create/update endpoints have been writing to for weeks. The model's
 * $fillable + casts referenced these columns, but no migration created
 * them, so every Listing::create() call that reached the DB with the
 * new frontend's payload failed with "Unknown column 'is_contact_visible'"
 * and surfaced as a generic 500 "Server Error" (debug off in prod).
 *
 * Each column is guarded with hasColumn() so re-running against an
 * environment that already has them (added by hand on prod, say) is a
 * no-op instead of a hard fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (!Schema::hasColumn('listings', 'is_contact_visible')) {
                $table->boolean('is_contact_visible')->default(false)->after('price_type');
            }
            if (!Schema::hasColumn('listings', 'contact_phone')) {
                $table->string('contact_phone', 20)->nullable()->after('is_contact_visible');
            }
            if (!Schema::hasColumn('listings', 'contact_email')) {
                $table->string('contact_email', 255)->nullable()->after('contact_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            foreach (['is_contact_visible', 'contact_phone', 'contact_email'] as $col) {
                if (Schema::hasColumn('listings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

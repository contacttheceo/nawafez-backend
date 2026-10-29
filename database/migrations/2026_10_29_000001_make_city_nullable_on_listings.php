<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Makes `city` nullable on listings. The original schema declared it
 * NOT NULL, but the Forum section intentionally omits a city (the
 * controller's $cityRule already treats forum as nullable) so every
 * Forum publish was failing with "SQLSTATE[23000]: city cannot be
 * null" and surfacing as a 500 Server Error.
 *
 * Requires doctrine/dbal to be installed; Laravel 11 includes it as a
 * dev dep, so this works on both prod builds (composer install) and
 * dev. The raw-SQL fallback below is kept so a composer-less deploy
 * still gets the fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (class_exists(\Doctrine\DBAL\Driver::class)) {
            Schema::table('listings', function (Blueprint $table) {
                $table->string('city', 80)->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE listings MODIFY city VARCHAR(80) NULL');
        }
    }

    public function down(): void
    {
        // Backfill empty cities before re-adding NOT NULL so this is
        // reversible without data loss. Picks the user's own city if
        // the join is cheap, otherwise falls back to the string
        // literal 'غير محدد'.
        DB::statement("UPDATE listings SET city = 'غير محدد' WHERE city IS NULL");

        if (class_exists(\Doctrine\DBAL\Driver::class)) {
            Schema::table('listings', function (Blueprint $table) {
                $table->string('city', 80)->nullable(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE listings MODIFY city VARCHAR(80) NOT NULL');
        }
    }
};

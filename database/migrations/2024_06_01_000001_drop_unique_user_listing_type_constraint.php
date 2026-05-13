<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original interactions table had a UNIQUE constraint on
 * (user_id, listing_id, type). This was fine for bookmarks (one per listing)
 * but completely broke messages — users could only send ONE message per listing
 * before getting a duplicate-entry DB error.
 *
 * This migration drops that constraint. Bookmark uniqueness is already enforced
 * at the application level via Interaction::firstOrCreate() in InteractionController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            $table->dropUnique('unique_user_listing_type');
        });
    }

    public function down(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            $table->unique(['user_id', 'listing_id', 'type'], 'unique_user_listing_type');
        });
    }
};

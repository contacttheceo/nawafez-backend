<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('forum_category', 30)->nullable()->after('listing_type');
            $table->index('forum_category', 'listings_forum_cat_idx');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('listings_forum_cat_idx');
            $table->dropColumn('forum_category');
        });
    }
};

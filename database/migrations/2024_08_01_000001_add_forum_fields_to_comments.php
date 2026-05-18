<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('user_id');
            $table->boolean('is_official_answer')->default(false)->after('body');
            $table->boolean('is_marked_helpful')->default(false)->after('is_official_answer');
            $table->unsignedInteger('upvotes_count')->default(0)->after('is_marked_helpful');
            $table->softDeletes();

            $table->index('parent_id', 'comments_parent_idx');
            $table->index(['listing_id', 'is_official_answer', 'upvotes_count'], 'comments_listing_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_parent_idx');
            $table->dropIndex('comments_listing_sort_idx');
            $table->dropSoftDeletes();
            $table->dropColumn(['parent_id', 'is_official_answer', 'is_marked_helpful', 'upvotes_count']);
        });
    }
};

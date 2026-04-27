<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->cascadeOnDelete();

            $table->enum('type', [
                'bid',          // Blind bid submission (PDF)
                'saved_search', // Saved search with filters JSON
                'bookmark',     // Saved/favourite listing
                'alert',        // Smart match notification log
                'report',       // Report a listing
                'message',      // Internal inbox message
            ]);

            // Flexible payload per type
            $table->json('data')->nullable()->comment('Type-specific payload');

            $table->string('status', 40)->default('active');
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'type']);
            $table->index(['listing_id', 'type']);
            $table->index('type');

            // Prevent duplicate bookmarks
            $table->unique(['user_id', 'listing_id', 'type'], 'unique_user_listing_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Section & Type
            $table->enum('section', ['ma', 'fleet', 'contracts', 'jobs', 'forum']);
            $table->string('listing_type', 60)->comment('e.g. business_sale, license_transfer, tanker, van, delivery_contract');

            // Bilingual content
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            // Location
            $table->string('city', 80);
            $table->string('region', 80)->nullable();

            // Pricing
            $table->unsignedBigInteger('price')->nullable();
            $table->char('currency', 3)->default('SAR');
            $table->string('price_type', 30)->default('fixed')->comment('fixed | monthly | yearly | negotiable');

            // Status
            $table->enum('status', ['draft', 'pending_review', 'active', 'expired', 'sold', 'rejected'])
                  ->default('pending_review');
            $table->text('rejection_reason')->nullable();

            // Dynamic fields (section-specific data)
            $table->json('dynamic_data')->nullable();

            // Media (images + PDF paths)
            $table->json('media')->nullable()->comment('[{path, type, is_primary}]');

            // Auto-computed badges
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_financing_eligible')->default(false);
            $table->boolean('is_ready_to_operate')->default(false);

            // Expiry & stats
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('views_count')->default(0);

            $table->softDeletes();
            $table->timestamps();

            // Indexes for common filters
            $table->index('section');
            $table->index('status');
            $table->index('city');
            $table->index('is_featured');
            $table->index(['section', 'status', 'city']);
            $table->index('expires_at');
            $table->index('price');

            // Full-text search
            $table->fullText(['title_ar', 'title_en', 'description_ar', 'description_en'], 'listings_fulltext_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};

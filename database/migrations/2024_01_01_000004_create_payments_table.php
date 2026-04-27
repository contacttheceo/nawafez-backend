<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('payment_type', [
                'listing_fee',   // One-time fee to publish M&A listing
                'subscription',  // Monthly/yearly subscription
                'upgrade',       // Pin to top / featured badge
            ]);

            $table->unsignedInteger('amount')->comment('Amount in halalas (× 100)');
            $table->char('currency', 3)->default('SAR');

            $table->string('gateway', 30)->default('moyasar');
            $table->string('gateway_ref', 120)->nullable()->unique()->comment('Moyasar payment ID');

            $table->enum('status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');

            $table->json('webhook_data')->nullable()->comment('Raw webhook payload from Moyasar');
            $table->json('metadata')->nullable()->comment('Extra info: plan name, duration, etc.');

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('payment_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->enum('status', ['pending','active','cancelled','expired','suspended'])->default('pending');
            $table->enum('billing_cycle', ['monthly','yearly'])->default('monthly');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->string('source', 30)->nullable();           // 'admin_grant' | 'moyasar' | 'gift' | 'trial'
            $table->unsignedBigInteger('granted_by')->nullable(); // admin user id if source=admin_grant
            $table->unsignedBigInteger('last_payment_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'status']);
            $table->index('expires_at');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('plan_id')->references('id')->on('plans')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

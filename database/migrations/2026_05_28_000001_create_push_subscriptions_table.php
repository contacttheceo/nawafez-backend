<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // endpoint is the FCM/APNs URL — unique per device, can be long
            $table->string('endpoint', 500)->unique();
            $table->string('p256dh', 200)->nullable();   // VAPID auth secret
            $table->string('auth',   100)->nullable();   // VAPID auth secret
            $table->string('user_agent', 250)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};

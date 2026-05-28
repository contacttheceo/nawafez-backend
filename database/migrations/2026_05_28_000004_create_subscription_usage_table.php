<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('period_yyyymm', 7);   // '2026-05'
            $table->unsignedInteger('listings_posted')->default(0);
            $table->unsignedInteger('featured_used')->default(0);
            $table->unsignedInteger('pins_used')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'period_yyyymm']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_usage');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();        // free | basic | professional | enterprise
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('tagline_ar', 200)->nullable();
            $table->string('tagline_en', 200)->nullable();
            $table->unsignedInteger('price_monthly')->default(0);   // in SAR (whole riyals, not halalas)
            $table->unsignedInteger('price_yearly')->default(0);
            $table->json('features');
            /* features shape:
               {
                 "max_listings": 3,
                 "max_featured_per_month": 0,
                 "has_ma": false,
                 "has_pin": false,
                 "auto_renew_listings": false,
                 "has_trusted_badge": false,
                 "has_blind_bidding": false,
                 "ai_tools_level": "limited" | "full" | "priority",
                 "analytics_level": "basic" | "intermediate" | "advanced" | "advanced_export",
                 "support_level": "email_72h" | "email_48h" | "email_24h" | "dedicated_whatsapp",
                 "api_access": false,
                 "max_sub_users": 1
               }
            */
            $table->tinyInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);   // exactly one row = free tier
            $table->string('badge_color', 20)->nullable();   // for UI: 'gray' | 'navy' | 'emerald' | 'gold'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};

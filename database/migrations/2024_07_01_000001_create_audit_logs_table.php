<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for sensitive admin operations.
 *
 * Examples of actions:
 *   listing.approve / listing.reject / listing.feature
 *   listing.bulk_approve / listing.bulk_reject
 *   verification.approve / verification.reject
 *   user.suspend / user.unsuspend / user.delete
 *   user.toggle_trusted_payer
 *   report.resolve
 *   admin.impersonate.start / admin.impersonate.stop
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 60);
            $table->string('target_type', 40)->nullable()->comment('listing | user | verification | report');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('metadata')->nullable()->comment('reason, before/after values, etc.');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('admin_id');
            $table->index('action');
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Public newsletter signup. Used by the homepage hero CTA.
 *
 * Lazily creates the `newsletter_subscribers` table on first call so we
 * don't need a migration run on Freehostia.
 *
 * Limits:
 *   - 5 signups per IP per hour (anti-spam)
 *   - Duplicate emails return 200 idempotently (so the user sees success
 *     even if they accidentally re-submit)
 */
class NewsletterController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'  => ['required', 'email', 'max:255'],
            'locale' => ['nullable', 'in:ar,en'],
            'source' => ['nullable', 'string', 'max:64'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'بيانات غير صالحة', 'errors' => $validator->errors()], 422);
        }

        $email  = strtolower(trim($request->input('email')));
        $locale = $request->input('locale', 'ar');
        $source = $request->input('source', 'unknown');
        $ip     = $request->ip();

        $this->ensureTable();

        // Rate limit: 5 per IP per hour
        $recent = DB::table('newsletter_subscribers')
            ->where('ip', $ip)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($recent >= 5) {
            return response()->json(['message' => 'أكثر من اللازم — حاول لاحقاً'], 429);
        }

        // Idempotent insert
        $existing = DB::table('newsletter_subscribers')->where('email', $email)->first();
        if ($existing) {
            // Refresh last_seen but don't double-count
            DB::table('newsletter_subscribers')
                ->where('email', $email)
                ->update(['updated_at' => now()]);
            return response()->json([
                'message' => 'أنت مشترك بالفعل ✓',
                'already' => true,
            ]);
        }

        DB::table('newsletter_subscribers')->insert([
            'email'      => $email,
            'locale'     => $locale,
            'source'     => $source,
            'ip'         => $ip,
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Send a quick welcome (best-effort; failures don't break signup)
        try {
            app(AdminEmailService::class)->newsletterWelcome($email, $locale);
        } catch (\Throwable $e) {
            \Log::warning('newsletter welcome failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'تم الاشتراك ✓ — راقب بريدك',
            'already' => false,
        ]);
    }

    private function ensureTable(): void
    {
        if (Schema::hasTable('newsletter_subscribers')) return;
        Schema::create('newsletter_subscribers', function ($t) {
            $t->id();
            $t->string('email', 255)->unique();
            $t->string('locale', 8)->default('ar');
            $t->string('source', 64)->nullable();
            $t->string('ip', 45)->nullable()->index();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('unsubscribed_at')->nullable();
            $t->timestamps();
        });
    }
}

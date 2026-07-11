<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contact-visibility endpoints.
 *
 * Design decisions (matches the strategic analysis):
 *   - Only 'fleet' and 'jobs' sections can opt-in to public contact.
 *     Everything else stays behind internal messaging so Blind Bidding
 *     (contracts) and M&A confidentiality remain intact.
 *   - Opt-in is per-listing (owner ticks a box at create time).
 *   - Reveal endpoint requires authentication — blocks scrapers.
 *   - Rate limit: 5 reveals per user per day. If a user needs more, they
 *     probably aren't a real buyer.
 *   - WhatsApp redirect is public (no reveal, no phone in the HTML) but
 *     the phone never sits in a scrapable URL — it lives server-side and
 *     the endpoint just 302s to wa.me/… on click.
 *   - Every reveal is logged (audit + rate-limit data source).
 *
 * PDPL compliance: the opt-in checkbox in the create form is the explicit
 * consent required by the Saudi Personal Data Protection Law article 5.
 */
class ContactRevealController extends Controller
{
    private const OPT_IN_SECTIONS = ['fleet', 'jobs'];
    private const DAILY_REVEAL_LIMIT = 5;
    private const WA_REDIRECT_HOURLY_LIMIT = 20;

    public function reveal(Request $request, int $id): JsonResponse
    {
        $this->ensureLogTable();

        $listing = Listing::with('user:id,email,phone,name_ar,name_en')
            ->findOrFail($id);

        if (!in_array($listing->section, self::OPT_IN_SECTIONS, true)) {
            return response()->json([
                'message' => 'هذا القسم لا يسمح بكشف بيانات التواصل — استخدم الرسائل الداخلية.',
                'reason'  => 'section_not_allowed',
            ], 403);
        }

        if (!$listing->is_contact_visible) {
            return response()->json([
                'message' => 'صاحب الإعلان لم يفعّل كشف بيانات التواصل.',
                'reason'  => 'opt_out',
            ], 403);
        }

        if ($listing->status !== 'active') {
            return response()->json([
                'message' => 'الإعلان غير نشط.',
                'reason'  => 'listing_inactive',
            ], 403);
        }

        // Rate limit — count reveals by this user in the last 24h
        $userId = $request->user()->id;
        $recent = DB::table('contact_reveals')
            ->where('viewer_user_id', $userId)
            ->where('revealed_at', '>=', now()->subDay())
            ->count();
        if ($recent >= self::DAILY_REVEAL_LIMIT) {
            return response()->json([
                'message' => 'تجاوزت حد كشف التواصل اليومي — حاول غداً.',
                'reason'  => 'rate_limited',
                'limit'   => self::DAILY_REVEAL_LIMIT,
            ], 429);
        }

        // Prefer listing-level overrides, fall back to user profile
        $phone = $listing->contact_phone ?: ($listing->user->phone ?? null);
        $email = $listing->contact_email ?: ($listing->user->email ?? null);

        if (!$phone && !$email) {
            return response()->json([
                'message' => 'لا توجد بيانات تواصل مسجّلة لصاحب الإعلان.',
                'reason'  => 'no_data',
            ], 404);
        }

        // Log the reveal (audit + rate-limit source)
        DB::table('contact_reveals')->insert([
            'listing_id'      => $listing->id,
            'viewer_user_id'  => $userId,
            'owner_user_id'   => $listing->user_id,
            'ip'              => $request->ip(),
            'revealed_at'     => now(),
        ]);

        return response()->json([
            'phone' => $phone,
            'email' => $email,
            'owner_name' => $listing->user->name_ar ?? $listing->user->name_en,
            'remaining_today' => max(0, self::DAILY_REVEAL_LIMIT - $recent - 1),
        ]);
    }

    /**
     * Public GET /api/listings/{id}/wa-redirect
     * 302 to wa.me/<phone>?text=... — the phone never appears in the HTML
     * that Google/scrapers see, only in the outbound redirect location.
     */
    public function whatsappRedirect(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $this->ensureLogTable();

        $listing = Listing::with('user:id,phone,name_ar,name_en')
            ->findOrFail($id);

        if (!in_array($listing->section, self::OPT_IN_SECTIONS, true)
            || !$listing->is_contact_visible
            || $listing->status !== 'active') {
            return response()->json([
                'message' => 'التواصل عبر واتساب غير متاح لهذا الإعلان — استخدم الرسائل الداخلية.',
            ], 403);
        }

        $phone = $listing->contact_phone ?: ($listing->user->phone ?? null);
        if (!$phone) {
            return response()->json(['message' => 'لا يوجد رقم واتساب مسجّل.'], 404);
        }

        // Rate limit per IP — prevents bulk-scraping via automated hits
        $ip = $request->ip();
        $recent = DB::table('contact_reveals')
            ->where('ip', $ip)
            ->where('revealed_at', '>=', now()->subHour())
            ->count();
        if ($recent >= self::WA_REDIRECT_HOURLY_LIMIT) {
            return response()->json(['message' => 'حاول لاحقاً'], 429);
        }

        // Log the click
        DB::table('contact_reveals')->insert([
            'listing_id'      => $listing->id,
            'viewer_user_id'  => optional($request->user())->id,
            'owner_user_id'   => $listing->user_id,
            'ip'              => $ip,
            'revealed_at'     => now(),
        ]);

        $title = $listing->title_ar ?? $listing->title_en ?? '';
        $url = "https://www.nwafizlogi.com/ar/listings/{$listing->id}";
        $text = urlencode("مرحباً، بخصوص إعلانكم «{$title}» على نوافذ: {$url}");
        $digits = preg_replace('/\D/', '', $phone);

        return redirect()->away("https://wa.me/{$digits}?text={$text}", 302);
    }

    /**
     * Ensures the contact-tracking columns and reveal-log table exist.
     * Runs lazily so we don't need to run migrations on Freehostia.
     */
    private function ensureLogTable(): void
    {
        // Reveal log — used for both rate limiting and analytics.
        if (!Schema::hasTable('contact_reveals')) {
            Schema::create('contact_reveals', function ($t) {
                $t->id();
                $t->unsignedBigInteger('listing_id')->index();
                $t->unsignedBigInteger('viewer_user_id')->nullable()->index();
                $t->unsignedBigInteger('owner_user_id')->index();
                $t->string('ip', 45)->nullable()->index();
                $t->timestamp('revealed_at')->useCurrent();
            });
        }

        // Listing columns for opt-in + contact overrides.
        if (!Schema::hasColumn('listings', 'is_contact_visible')) {
            Schema::table('listings', function ($t) {
                $t->boolean('is_contact_visible')->default(false)->after('is_ready_to_operate');
                $t->string('contact_phone', 20)->nullable()->after('is_contact_visible');
                $t->string('contact_email', 255)->nullable()->after('contact_phone');
            });
        }
    }
}

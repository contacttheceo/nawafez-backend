<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Interaction;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\User;
use App\Services\AdminEmailService;
use App\Services\AuditLogger;
use App\Services\ResendMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    private function emailService(): AdminEmailService
    {
        return new AdminEmailService(new ResendMailer());
    }

    // GET /api/admin/dashboard
    public function dashboard(): JsonResponse
    {
        $thisWeek  = now()->subDays(7);
        $lastWeek  = now()->subDays(14);
        $thisMonth = now()->startOfMonth();

        $usersThisWeek    = User::where('created_at', '>=', $thisWeek)->count();
        $usersLastWeek    = User::whereBetween('created_at', [$lastWeek, $thisWeek])->count();
        $listingsThisWeek = Listing::where('created_at', '>=', $thisWeek)->count();
        $listingsLastWeek = Listing::whereBetween('created_at', [$lastWeek, $thisWeek])->count();

        // Per-section breakdown
        $sections = ['fleet', 'contracts', 'ma', 'jobs', 'forum'];
        $sectionCounts = [];
        foreach ($sections as $sec) {
            $sectionCounts[$sec] = Listing::where('status', 'active')->where('section', $sec)->count();
        }

        // Activity feed — latest 10 events (mix of users + listings)
        $recentUsers = User::latest()->take(5)->get()
            ->map(fn ($u) => [
                'type'    => 'user',
                'icon'    => '👤',
                'text_ar' => 'مستخدم جديد: ' . ($u->name_ar ?? $u->email),
                'text_en' => 'New user: ' . ($u->name_en ?? $u->email),
                'time'    => $u->created_at->toISOString(),
            ]);

        $recentListings = Listing::latest()->take(5)->get()
            ->map(fn ($l) => [
                'type'    => 'listing',
                'icon'    => '📋',
                'text_ar' => 'إعلان جديد: ' . ($l->title_ar ?? 'بدون عنوان'),
                'text_en' => 'New listing: ' . ($l->title_en ?? 'No title'),
                'time'    => $l->created_at->toISOString(),
            ]);

        $feed = $recentUsers->merge($recentListings)
            ->sortByDesc('time')
            ->take(10)
            ->values();

        return response()->json([
            'users' => [
                'total'      => User::count(),
                'business'   => User::where('role', 'business')->count(),
                'new_today'  => User::whereDate('created_at', today())->count(),
                'this_week'  => $usersThisWeek,
                'growth_pct' => $usersLastWeek > 0
                    ? round((($usersThisWeek - $usersLastWeek) / $usersLastWeek) * 100, 1)
                    : null,
            ],
            'listings' => [
                'total'          => Listing::count(),
                'active'         => Listing::where('status', 'active')->count(),
                'pending_review' => Listing::where('status', 'pending_review')->count(),
                'new_today'      => Listing::whereDate('created_at', today())->count(),
                'this_week'      => $listingsThisWeek,
                'growth_pct'     => $listingsLastWeek > 0
                    ? round((($listingsThisWeek - $listingsLastWeek) / $listingsLastWeek) * 100, 1)
                    : null,
                'sections'       => $sectionCounts,
            ],
            'reports' => [
                'pending' => Interaction::where('type', 'report')
                    ->whereJsonDoesntContain('data->resolved', true)->count(),
            ],
            'revenue' => [
                'total_sar'  => Payment::where('status', 'paid')->sum('amount') / 100,
                'this_month' => Payment::where('status', 'paid')
                    ->where('created_at', '>=', $thisMonth)
                    ->sum('amount') / 100,
            ],
            'recent_activity' => $feed,
        ]);
    }

    // GET /api/admin/analytics
    public function analytics(): JsonResponse
    {
        // مستخدمون يومياً (آخر 30 يوم)
        $usersDaily = User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // إعلانات يومياً (آخر 30 يوم)
        $listingsDaily = Listing::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // أعلى 10 إعلانات بالمشاهدات
        $topListings = Listing::where('status', 'active')
            ->orderByDesc('views_count')
            ->take(10)
            ->get(['id', 'title_ar', 'title_en', 'section', 'city', 'views_count', 'price']);

        // توزيع المدن (أكثر 10 مدن نشاطاً)
        $citiesBreakdown = Listing::where('status', 'active')
            ->selectRaw('city, COUNT(*) as count')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->groupBy('city')
            ->orderByDesc('count')
            ->take(10)
            ->get();

        // أداء الأقسام
        $sections = ['fleet', 'contracts', 'ma', 'jobs', 'forum'];
        $sectionStats = [];
        foreach ($sections as $sec) {
            $sectionStats[$sec] = [
                'active'      => Listing::where('status', 'active')->where('section', $sec)->count(),
                'pending'     => Listing::where('status', 'pending_review')->where('section', $sec)->count(),
                'total_views' => Listing::where('section', $sec)->sum('views_count'),
                'total_bids'  => Interaction::where('type', 'bid')
                    ->whereHas('listing', fn ($q) => $q->where('section', $sec))->count(),
            ];
        }

        // إيرادات شهرية (آخر 6 أشهر)
        $revenueMonthly = Payment::where('status', 'paid')
            ->where('created_at', '>=', now()->subMonths(6))
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, SUM(amount)/100 as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'users_daily'      => $usersDaily,
            'listings_daily'   => $listingsDaily,
            'top_listings'     => $topListings,
            'cities_breakdown' => $citiesBreakdown,
            'section_stats'    => $sectionStats,
            'revenue_monthly'  => $revenueMonthly,
        ]);
    }

    // GET /api/admin/users
    public function users(Request $request): JsonResponse
    {
        $query = User::withCount('listings');

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name_ar', 'like', $term)
                  ->orWhere('name_en', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    // PATCH /api/admin/users/{id}/trusted-payer
    public function toggleTrustedPayer(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $before = $user->is_trusted_payer;
        $user->update(['is_trusted_payer' => !$before]);

        AuditLogger::log(
            $request->user(),
            'user.toggle_trusted_payer',
            'user',
            $user->id,
            ['before' => $before, 'after' => $user->is_trusted_payer],
            $request
        );

        return response()->json([
            'message'          => $user->is_trusted_payer
                ? 'تم منح المستخدم شارة الدافع الموثوق.'
                : 'تم سحب شارة الدافع الموثوق.',
            'is_trusted_payer' => $user->is_trusted_payer,
        ]);
    }

    // PATCH /api/admin/users/{id}/verify-email
    // Manual override for when a user can't receive the verification email
    // (wrong address, mail provider blocks, deliverability issues, etc.)
    // Support workflow: user contacts support → admin verifies email manually.
    public function manuallyVerifyEmail(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->email_verified_at !== null) {
            return response()->json([
                'message' => 'هذا المستخدم متحقّق بالفعل.',
                'data'    => $user->only(['id', 'email', 'email_verified_at']),
            ], 422);
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        AuditLogger::log(
            $request->user(),
            'user.manually_verify_email',
            'user',
            $user->id,
            [
                'email' => $user->email,
                'note'  => $request->input('note', 'Manual verification by admin'),
            ],
            $request
        );

        return response()->json([
            'message' => "تم التحقق من بريد المستخدم {$user->email} يدوياً.",
            'data'    => $user->only(['id', 'email', 'email_verified_at']),
        ]);
    }

    // POST /api/admin/users/{id}/resend-verification
    // Admin triggers a fresh verification email to a user (e.g. they say
    // they didn't receive it). Bypasses the user's session — admin uses
    // their own privilege.
    public function adminResendVerification(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->email_verified_at !== null) {
            return response()->json([
                'message' => 'هذا المستخدم متحقّق بالفعل، لا حاجة لإعادة الإرسال.',
            ], 422);
        }

        try {
            // Reuse the private helper via reflection-safe wrapper: call the
            // controller through the container. AuthController exposes this
            // intent via resendVerification() but it expects $request->user().
            // We'll inline the email send here to keep the call simple.
            $frontendUrl = rtrim(env('FRONTEND_URL', 'https://www.nwafizlogi.com'), '/');
            $verifyUrl   = "{$frontendUrl}/ar/auth/verify-email?id={$user->id}&hash=" . sha1($user->email);
            $mailer      = new \App\Services\ResendMailer();
            $sent        = $mailer->send(
                $user->email,
                'تحقق من بريدك الإلكتروني — نوافذ',
                $this->verificationEmailHtml($verifyUrl, $user->name_ar)
            );

            AuditLogger::log(
                $request->user(),
                'user.admin_resend_verification',
                'user',
                $user->id,
                ['email' => $user->email, 'sent' => $sent],
                $request
            );

            return response()->json([
                'message' => $sent
                    ? "تم إرسال رابط التحقق مجدداً إلى {$user->email}."
                    : 'فشل الإرسال — راجع سجل الأخطاء.',
                'sent'    => $sent,
            ], $sent ? 200 : 500);
        } catch (\Throwable $e) {
            \Log::error('admin resend verification failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'حدث خطأ أثناء الإرسال: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function verificationEmailHtml(string $url, ?string $name): string
    {
        $safeName = htmlspecialchars($name ?: 'مستخدم نوافذ', ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $logo     = \App\Services\AdminEmailService::LOGO_URL;
        return "
        <div dir='rtl' style='font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:32px;background:#f9f9f9;border-radius:12px;'>
            <div style='text-align:center;margin-bottom:28px;padding:18px;background:white;border-radius:10px;'>
                <img src='{$logo}' alt='نوافذ' width='140' style='max-width:140px;height:auto;display:inline-block;border:0;' />
            </div>
            <h3 style='color:#0a2342;'>مرحباً {$safeName} 👋</h3>
            <p style='color:#444;line-height:1.7;'>أرسلت إدارة نوافذ رابط التحقق إليك. اضغط لتفعيل حسابك:</p>
            <div style='text-align:center;margin:28px 0;'>
                <a href='{$safeUrl}' style='background:#10b981;color:white;padding:14px 36px;border-radius:10px;text-decoration:none;font-weight:bold;font-size:15px;display:inline-block;'>
                    ✓ تفعيل الحساب
                </a>
            </div>
            <p style='color:#666;font-size:13px;'>لم يعمل الزر؟ انسخ الرابط:</p>
            <p style='background:white;border:1px solid #eee;padding:10px;border-radius:6px;font-family:monospace;font-size:11px;word-break:break-all;direction:ltr;text-align:left;color:#0a2342;'>{$safeUrl}</p>
        </div>";
    }

    /**
     * POST /api/admin/users/{id}/activate
     *
     * Full ceremonial activation: silently marks email as verified (if
     * not already), then sends the beautiful welcome-and-features email
     * with referral link. The "soft launch" experience for any user the
     * admin wants to bring on board personally.
     *
     * Works for ANY account regardless of current state — the email is
     * always informative, and verification is idempotent.
     */
    public function activateAccount(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $wasAlreadyVerified = $user->email_verified_at !== null;
        if (!$wasAlreadyVerified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // Fire the welcome email — wrapped in try/catch so a Resend hiccup
        // doesn't block the verification.
        $emailSent = false;
        try {
            app(\App\Services\AdminEmailService::class)->accountActivated($user);
            $emailSent = true;
        } catch (\Throwable $e) {
            \Log::error('activateAccount email failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'email'   => $user->email,
            ]);
        }

        AuditLogger::log(
            $request->user(),
            'user.activate',
            'user',
            $user->id,
            [
                'email'                 => $user->email,
                'was_already_verified'  => $wasAlreadyVerified,
                'welcome_email_sent'    => $emailSent,
            ],
            $request
        );

        return response()->json([
            'message' => $emailSent
                ? "تم تفعيل حساب {$user->email} وإرسال رسالة الترحيب."
                : "تم تفعيل الحساب لكن فشل إرسال الإيميل — راجع السجل.",
            'data'    => $user->only(['id', 'email', 'email_verified_at']),
            'email_sent' => $emailSent,
        ]);
    }

    // GET /api/admin/verifications
    public function pendingVerifications(): JsonResponse
    {
        $users = User::where('role', 'business')
            ->whereJsonContains('business_verification->status', 'pending')
            ->get(['id', 'name_ar', 'name_en', 'email', 'phone', 'business_verification', 'created_at']);

        return response()->json(['data' => $users]);
    }

    // POST /api/admin/verifications/{id}/approve
    public function approveVerification(Request $request, int $id): JsonResponse
    {
        $user                 = User::findOrFail($id);
        $verification         = $user->business_verification ?? [];
        $verification['status'] = 'approved';
        $verification['approved_at'] = now()->toISOString();

        $user->update(['business_verification' => $verification]);

        AuditLogger::log(
            $request->user(),
            'verification.approve',
            'user',
            $user->id,
            ['cr_number' => $verification['cr_number'] ?? null],
            $request
        );

        try { $this->emailService()->verificationApproved($user); }
        catch (\Throwable $e) { \Log::warning("verificationApproved email failed: " . $e->getMessage()); }

        return response()->json(['message' => 'تم قبول طلب التحقق التجاري.']);
    }

    // POST /api/admin/verifications/{id}/reject
    public function rejectVerification(Request $request, int $id): JsonResponse
    {
        $this->validate($request, [
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $user                 = User::findOrFail($id);
        $verification         = $user->business_verification ?? [];
        $verification['status']        = 'rejected';
        $verification['reject_reason'] = $request->reason;
        $verification['rejected_at']   = now()->toISOString();

        $user->update(['business_verification' => $verification]);

        AuditLogger::log(
            $request->user(),
            'verification.reject',
            'user',
            $user->id,
            ['reason' => $request->reason],
            $request
        );

        try { $this->emailService()->verificationRejected($user, $request->reason); }
        catch (\Throwable $e) { \Log::warning("verificationRejected email failed: " . $e->getMessage()); }

        return response()->json(['message' => 'تم رفض طلب التحقق التجاري.']);
    }

    // GET /api/admin/listings
    public function listings(Request $request): JsonResponse
    {
        $query = Listing::with('user:id,name_ar,name_en,email');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('section')) {
            $query->where('section', $request->section);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(fn ($q) =>
                $q->where('title_ar', 'like', $term)
                  ->orWhere('title_en', 'like', $term)
            );
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    // PATCH /api/admin/listings/{id}/approve
    public function approveListing(Request $request, int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        $listing->update(['status' => 'active']);

        AuditLogger::log(
            $request->user(),
            'listing.approve',
            'listing',
            $listing->id,
            ['title_ar' => $listing->title_ar, 'section' => $listing->section],
            $request
        );

        try { $this->emailService()->listingApproved($listing); }
        catch (\Throwable $e) { \Log::warning("listingApproved email failed: " . $e->getMessage()); }

        try {
            app(\App\Services\PushNotificationService::class)->notifyUser($listing->user_id, [
                'title' => 'تم نشر إعلانك ✓',
                'body'  => mb_substr($listing->title_ar ?? 'إعلانك', 0, 80),
                'url'   => '/ar/listings/'.$listing->id,
                'tag'   => 'listing-status-'.$listing->id,
            ]);
        } catch (\Throwable $e) { \Log::warning('push (approve): '.$e->getMessage()); }

        return response()->json(['message' => 'تم نشر الإعلان بنجاح.', 'data' => $listing]);
    }

    // PATCH /api/admin/listings/{id}/reject
    public function rejectListing(Request $request, int $id): JsonResponse
    {
        $this->validate($request, [
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $listing = Listing::findOrFail($id);
        $listing->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->reason,
        ]);

        AuditLogger::log(
            $request->user(),
            'listing.reject',
            'listing',
            $listing->id,
            ['reason' => $request->reason, 'title_ar' => $listing->title_ar],
            $request
        );

        try { $this->emailService()->listingRejected($listing, $request->reason); }
        catch (\Throwable $e) { \Log::warning("listingRejected email failed: " . $e->getMessage()); }

        try {
            app(\App\Services\PushNotificationService::class)->notifyUser($listing->user_id, [
                'title' => 'تم رفض إعلانك',
                'body'  => 'السبب: '.mb_substr($request->reason, 0, 90),
                'url'   => '/ar/dashboard',
                'tag'   => 'listing-status-'.$listing->id,
            ]);
        } catch (\Throwable $e) { \Log::warning('push (reject): '.$e->getMessage()); }

        return response()->json(['message' => 'تم رفض الإعلان.']);
    }

    // PATCH /api/admin/listings/{id}/feature
    public function toggleFeatured(Request $request, int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        $before = $listing->is_featured;
        $listing->update(['is_featured' => !$before]);

        AuditLogger::log(
            $request->user(),
            'listing.toggle_featured',
            'listing',
            $listing->id,
            ['before' => $before, 'after' => $listing->is_featured],
            $request
        );

        return response()->json([
            'message'     => $listing->is_featured ? 'تم تمييز الإعلان.' : 'تم إلغاء تمييز الإعلان.',
            'is_featured' => $listing->is_featured,
        ]);
    }

    // POST /api/admin/listings/bulk-approve
    public function bulkApproveListings(Request $request): JsonResponse
    {
        $this->validate($request, [
            'ids'   => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'exists:listings,id'],
        ]);

        $listings = Listing::whereIn('id', $request->ids)->get();
        $count = 0;
        foreach ($listings as $listing) {
            $listing->update(['status' => 'active']);
            try { $this->emailService()->listingApproved($listing); } catch (\Throwable $e) {}
            $count++;
        }

        AuditLogger::log(
            $request->user(),
            'listing.bulk_approve',
            null, null,
            ['ids' => $request->ids, 'count' => $count],
            $request
        );

        return response()->json(['message' => "تم قبول {$count} إعلان.", 'count' => $count]);
    }

    // POST /api/admin/listings/bulk-reject
    public function bulkRejectListings(Request $request): JsonResponse
    {
        $this->validate($request, [
            'ids'    => ['required', 'array', 'min:1', 'max:100'],
            'ids.*'  => ['integer', 'exists:listings,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $listings = Listing::whereIn('id', $request->ids)->get();
        $count = 0;
        foreach ($listings as $listing) {
            $listing->update(['status' => 'rejected', 'rejection_reason' => $request->reason]);
            try { $this->emailService()->listingRejected($listing, $request->reason); } catch (\Throwable $e) {}
            $count++;
        }

        AuditLogger::log(
            $request->user(),
            'listing.bulk_reject',
            null, null,
            ['ids' => $request->ids, 'count' => $count, 'reason' => $request->reason],
            $request
        );

        return response()->json(['message' => "تم رفض {$count} إعلان.", 'count' => $count]);
    }

    // GET /api/admin/reports
    public function reports(Request $request): JsonResponse
    {
        $reports = Interaction::where('type', 'report')
            ->with([
                'user:id,name_ar,name_en,email',
                'listing:id,title_ar,title_en,status',
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($reports);
    }

    // PATCH /api/admin/reports/{id}/resolve
    public function resolveReport(Request $request, int $id): JsonResponse
    {
        $report          = Interaction::where('type', 'report')->findOrFail($id);
        $data            = $report->data;
        $data['resolved']    = true;
        $data['resolved_at'] = now()->toISOString();

        $report->update(['data' => $data]);

        AuditLogger::log(
            $request->user(),
            'report.resolve',
            'report',
            $report->id,
            ['listing_id' => $report->listing_id],
            $request
        );

        return response()->json(['message' => 'تم تحديد البلاغ كمُعالَج.']);
    }

    // PATCH /api/admin/users/{id}/suspend
    public function suspendUser(Request $request, int $id): JsonResponse
    {
        $this->validate($request, [
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $user = User::findOrFail($id);

        if ($user->role === 'admin') {
            return response()->json(['message' => 'لا يمكن تعليق حساب إداري.'], 403);
        }
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'لا يمكنك تعليق حسابك.'], 403);
        }

        $user->update([
            'suspended_at'   => now(),
            'suspend_reason' => $request->reason,
        ]);
        // Revoke all tokens so they can't keep using the app
        $user->tokens()->delete();

        AuditLogger::log(
            $request->user(),
            'user.suspend',
            'user',
            $user->id,
            ['reason' => $request->reason],
            $request
        );

        try { $this->emailService()->accountSuspended($user, $request->reason); }
        catch (\Throwable $e) { \Log::warning("accountSuspended email failed: " . $e->getMessage()); }

        return response()->json(['message' => 'تم تعليق الحساب.']);
    }

    // PATCH /api/admin/users/{id}/unsuspend
    public function unsuspendUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['suspended_at' => null, 'suspend_reason' => null]);

        AuditLogger::log(
            $request->user(),
            'user.unsuspend',
            'user',
            $user->id,
            [],
            $request
        );

        return response()->json(['message' => 'تم إلغاء تعليق الحساب.']);
    }

    // DELETE /api/admin/users/{id}
    public function deleteUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->role === 'admin') {
            return response()->json(['message' => 'لا يمكن حذف حساب إداري.'], 403);
        }
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'لا يمكنك حذف حسابك من هنا.'], 403);
        }

        // Soft delete + revoke tokens; preserves financial records via FK
        $user->tokens()->delete();
        $user->delete();

        AuditLogger::log(
            $request->user(),
            'user.delete',
            'user',
            $user->id,
            ['name_ar' => $user->name_ar, 'email' => $user->email],
            $request
        );

        return response()->json(['message' => 'تم حذف الحساب.']);
    }

    // GET /api/admin/audit-logs
    public function auditLogs(Request $request): JsonResponse
    {
        $query = AuditLog::with('admin:id,name_ar,name_en,email')
            ->orderByDesc('created_at');

        if ($request->filled('admin_id')) {
            $query->where('admin_id', $request->admin_id);
        }
        if ($request->filled('action')) {
            $query->where('action', 'like', $request->action . '%');
        }
        if ($request->filled('target_type')) {
            $query->where('target_type', $request->target_type);
        }
        if ($request->filled('target_id')) {
            $query->where('target_id', $request->target_id);
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        return response()->json($query->paginate(25));
    }

    // GET /api/admin/revenue
    public function revenue(Request $request): JsonResponse
    {
        $payments = Payment::with('user:id,name_ar,name_en,email')
            ->where('status', 'paid')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($payments);
    }

    /* ── Comments Moderation (Phase: Forum) ────────────────────────────────── */

    /**
     * GET /api/admin/comments
     * Filters:
     *   ?reported=1        → only comments that have report interactions
     *   ?listing_id=42     → only comments under this listing
     *   ?search=keyword    → fulltext-ish on body
     */
    public function comments(Request $request): JsonResponse
    {
        $query = Comment::with([
            'user:id,name_ar,name_en,email,avatar,role',
            'listing:id,title_ar,title_en,section',
        ])->orderByDesc('created_at');

        if ($request->boolean('reported')) {
            $reportedCommentIds = Interaction::where('type', 'report')
                ->whereNotNull('data->comment_id')
                ->pluck('data')
                ->map(fn ($d) => is_array($d) ? ($d['comment_id'] ?? null) : null)
                ->filter()
                ->unique()
                ->values();
            $query->whereIn('id', $reportedCommentIds);
        }

        if ($request->filled('listing_id')) {
            $query->where('listing_id', $request->listing_id);
        }

        if ($request->filled('search')) {
            $query->where('body', 'like', '%' . $request->search . '%');
        }

        return response()->json($query->paginate(20));
    }

    /**
     * DELETE /api/admin/comments/{id}
     */
    public function deleteComment(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);
        $comment->delete();

        AuditLogger::log(
            $request->user(),
            'comment.delete',
            'comment',
            $comment->id,
            ['listing_id' => $comment->listing_id, 'preview' => mb_substr($comment->body, 0, 80)],
            $request
        );

        return response()->json(['message' => 'تم حذف التعليق.']);
    }
}

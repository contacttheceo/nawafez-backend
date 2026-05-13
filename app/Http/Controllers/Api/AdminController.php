<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Interaction;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
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
    public function toggleTrustedPayer(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['is_trusted_payer' => !$user->is_trusted_payer]);

        return response()->json([
            'message'          => $user->is_trusted_payer
                ? 'تم منح المستخدم شارة الدافع الموثوق.'
                : 'تم سحب شارة الدافع الموثوق.',
            'is_trusted_payer' => $user->is_trusted_payer,
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
    public function approveVerification(int $id): JsonResponse
    {
        $user                 = User::findOrFail($id);
        $verification         = $user->business_verification ?? [];
        $verification['status'] = 'approved';
        $verification['approved_at'] = now()->toISOString();

        $user->update(['business_verification' => $verification]);

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
    public function approveListing(int $id): JsonResponse
    {
        $listing = Listing::where('status', 'pending_review')->findOrFail($id);
        $listing->update(['status' => 'active']);

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

        return response()->json(['message' => 'تم رفض الإعلان.']);
    }

    // PATCH /api/admin/listings/{id}/feature
    public function toggleFeatured(int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        $listing->update(['is_featured' => !$listing->is_featured]);

        return response()->json([
            'message'     => $listing->is_featured ? 'تم تمييز الإعلان.' : 'تم إلغاء تمييز الإعلان.',
            'is_featured' => $listing->is_featured,
        ]);
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
    public function resolveReport(int $id): JsonResponse
    {
        $report          = Interaction::where('type', 'report')->findOrFail($id);
        $data            = $report->data;
        $data['resolved']    = true;
        $data['resolved_at'] = now()->toISOString();

        $report->update(['data' => $data]);

        return response()->json(['message' => 'تم تحديد البلاغ كمُعالَج.']);
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
}

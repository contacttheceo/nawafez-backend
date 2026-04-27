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
        return response()->json([
            'users' => [
                'total'    => User::count(),
                'business' => User::where('role', 'business')->count(),
                'new_today' => User::whereDate('created_at', today())->count(),
            ],
            'listings' => [
                'total'          => Listing::count(),
                'active'         => Listing::where('status', 'active')->count(),
                'pending_review' => Listing::where('status', 'pending_review')->count(),
                'new_today'      => Listing::whereDate('created_at', today())->count(),
            ],
            'reports' => [
                'pending' => Interaction::where('type', 'report')
                    ->whereJsonDoesntContain('data->resolved', true)->count(),
            ],
            'revenue' => [
                'total_sar'    => Payment::where('status', 'paid')->sum('amount') / 100,
                'this_month'   => Payment::where('status', 'paid')
                    ->whereMonth('created_at', now()->month)
                    ->sum('amount') / 100,
            ],
        ]);
    }

    // GET /api/admin/users
    public function users(Request $request): JsonResponse
    {
        $query = User::query();

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
        $verification['status']      = 'rejected';
        $verification['reject_reason'] = $request->reason;
        $verification['rejected_at'] = now()->toISOString();

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

        return response()->json($query->orderByDesc('created_at')->paginate(20));
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
            'status'          => 'rejected',
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
        $report       = Interaction::where('type', 'report')->findOrFail($id);
        $data         = $report->data;
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Interaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules;

class UserController extends Controller
{
    // GET /api/user/listings
    public function myListings(Request $request): JsonResponse
    {
        $listings = Listing::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(12);

        return response()->json($listings);
    }

    // GET /api/user/dashboard
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $stats = [
            'total_listings'  => Listing::where('user_id', $user->id)->count(),
            'active_listings' => Listing::where('user_id', $user->id)->where('status', 'active')->count(),
            'total_views'     => Listing::where('user_id', $user->id)->sum('views_count'),
            'total_bids'      => Interaction::whereHas('listing', fn($q) => $q->where('user_id', $user->id))
                                    ->where('type', 'bid')->count(),
            'unread_messages' => Interaction::where('type', 'message')
                                    ->whereJsonContains('data->to_user_id', $user->id)
                                    ->whereJsonDoesntContain('data->read_at', null)
                                    ->count(),
            'bookmarks_count' => Interaction::where('user_id', $user->id)
                                    ->where('type', 'bookmark')->count(),
        ];

        $recentListings = Listing::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->take(5)
            ->get(['id', 'title_ar', 'title_en', 'status', 'views_count', 'created_at', 'expires_at']);

        return response()->json([
            'stats'           => $stats,
            'recent_listings' => $recentListings,
        ]);
    }

    // PUT /api/user/profile
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->validate($request, [
            'name_ar'  => ['sometimes', 'string', 'max:120'],
            'name_en'  => ['sometimes', 'string', 'max:120'],
            'phone'    => ['sometimes', 'nullable', 'string', 'max:20'],
            'password' => ['sometimes', 'confirmed', Rules\Password::min(8)],
        ]);

        $data = $request->only(['name_ar', 'name_en', 'phone']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return response()->json([
            'message' => 'تم تحديث الملف الشخصي بنجاح.',
            'data'    => $user->fresh(),
        ]);
    }

    // POST /api/user/business-verification
    public function uploadBusinessVerification(Request $request): JsonResponse
    {
        $this->validate($request, [
            'company_name'     => ['required', 'string', 'max:200'],
            'cr_number'        => ['required', 'string', 'max:30'],
            'cr_document'      => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $user = $request->user();

        $path = $request->file('cr_document')->store("verifications/{$user->id}", 'public');

        $user->update([
            'role'                  => 'business',
            'business_verification' => [
                'company_name' => $request->company_name,
                'cr_number'    => $request->cr_number,
                'document'     => $path,
                'status'       => 'pending',
                'submitted_at' => now()->toISOString(),
            ],
        ]);

        return response()->json([
            'message' => 'تم رفع وثائق التحقق. سيتم مراجعتها خلال 24-48 ساعة.',
        ]);
    }

    // DELETE /api/user/account
    public function deleteAccount(Request $request): JsonResponse
    {
        $this->validate($request, [
            'password' => ['required'],
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'كلمة المرور غير صحيحة.'], 403);
        }

        // Revoke all tokens
        $user->tokens()->delete();

        // Soft-delete the account
        $user->delete();

        return response()->json(['message' => 'تم حذف حسابك بنجاح.']);
    }
}

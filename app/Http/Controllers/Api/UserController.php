<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Interaction;
use App\Services\ImageProcessor;
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

    // POST /api/user/avatar
    public function uploadAvatar(Request $request): JsonResponse
    {
        $this->validate($request, [
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        // Delete old avatar if stored in uploads/
        if ($user->avatar && str_starts_with($user->avatar, 'uploads/')) {
            @unlink(public_path($user->avatar));
        }

        // Resize to 400x400 max + compress to JPEG q85
        $uploadDir = public_path("uploads/avatars/{$user->id}");
        $processor = new ImageProcessor();
        $filename  = $processor->processToFile(
            $request->file('avatar'),
            $uploadDir,
            uniqid('av_'),
            ['max_width' => 400, 'max_height' => 400, 'quality' => 85]
        );

        if ($filename === null) {
            return response()->json(['message' => 'فشل معالجة الصورة.'], 500);
        }

        $path = "uploads/avatars/{$user->id}/{$filename}";
        $user->update(['avatar' => $path]);

        return response()->json([
            'message'     => 'تم تحديث الصورة الشخصية بنجاح.',
            'avatar_path' => $path,
        ]);
    }

    // DELETE /api/user/avatar
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar) {
            if (str_starts_with($user->avatar, 'uploads/')) {
                @unlink(public_path($user->avatar));
            }
            $user->update(['avatar' => null]);
        }

        return response()->json(['message' => 'تم حذف الصورة الشخصية.']);
    }

    // GET /api/user/bids — bids submitted by the logged-in user
    public function myBids(Request $request): JsonResponse
    {
        $bids = Interaction::where('user_id', $request->user()->id)
            ->where('type', 'bid')
            ->with('listing:id,title_ar,title_en,section,status,price')
            ->latest()
            ->get()
            ->map(fn($b) => [
                'id'           => $b->id,
                'amount'       => $b->data['amount']       ?? 0,
                'message'      => $b->data['message']      ?? null,
                'submitted_at' => $b->data['submitted_at'] ?? $b->created_at,
                'listing'      => $b->listing ? [
                    'id'       => $b->listing->id,
                    'title_ar' => $b->listing->title_ar,
                    'title_en' => $b->listing->title_en,
                    'section'  => $b->listing->section,
                    'status'   => $b->listing->status,
                    'price'    => $b->listing->price,
                ] : null,
            ]);

        return response()->json(['data' => $bids]);
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

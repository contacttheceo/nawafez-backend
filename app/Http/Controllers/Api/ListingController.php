<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ListingController extends Controller
{
    // GET /api/listings
    public function index(Request $request): JsonResponse
    {
        $query = Listing::with('user:id,name_ar,name_en,role,is_trusted_payer,avatar')
                        ->active();

        // Section filter
        if ($request->filled('section')) {
            $query->section($request->section);
        }

        // City filter
        if ($request->filled('city')) {
            $query->inCity($request->city);
        }

        // Price range
        if ($request->filled('price_min')) {
            $query->where('price', '>=', $request->price_min);
        }
        if ($request->filled('price_max')) {
            $query->where('price', '<=', $request->price_max);
        }

        // Listing type filter (for_sale, for_rent, wanted, offering)
        if ($request->filled('listing_type')) {
            $types = array_filter(array_map('trim', explode(',', $request->listing_type)));
            if (count($types) > 0) {
                $query->whereIn('listing_type', $types);
            }
        }

        // Full-text search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Sorting
        match ($request->get('sort', 'newest')) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'featured'   => $query->orderByDesc('is_featured')->orderByDesc('created_at'),
            default      => $query->orderByDesc('created_at'),
        };

        // Homepage quick-fetch: limit param (max 20, returns flat array without pagination wrapper)
        if ($request->filled('limit') && (int) $request->limit <= 20) {
            return response()->json([
                'data' => $query->take((int) $request->limit)->get(),
            ]);
        }

        $listings = $query->paginate(12);

        return response()->json($listings);
    }

    // GET /api/listings/featured
    public function featured(): JsonResponse
    {
        $listings = Listing::with('user:id,name_ar,name_en,role,is_trusted_payer,avatar')
                           ->active()
                           ->featured()
                           ->orderByDesc('created_at')
                           ->take(6)
                           ->get();

        return response()->json(['data' => $listings]);
    }

    // GET /api/listings/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        $listing = Listing::with('user:id,name_ar,name_en,role,is_trusted_payer,avatar')
                          ->findOrFail($id);

        // Hide non-public listings (rejected/draft) from anyone but the owner or admin.
        // pending_review and expired stay viewable so the owner sees status banners
        // and direct links from emails keep working.
        $hidden = ['rejected', 'draft'];
        if (in_array($listing->status, $hidden, true)) {
            $viewer = $request->user();
            $isOwner = $viewer && $viewer->id === $listing->user_id;
            $isAdmin = $viewer && $viewer->role === 'admin';
            if (!$isOwner && !$isAdmin) {
                return response()->json(['message' => 'الإعلان غير متاح.'], 404);
            }
        }

        return response()->json(['data' => $listing]);
    }

    // POST /api/listings
    public function store(Request $request): JsonResponse
    {
        $section = $request->section;

        $listingTypeRule = in_array($section, ['fleet', 'contracts'])
            ? ['required', 'string', 'max:60']
            : ['nullable', 'string', 'max:60'];

        $cityRule = $section === 'forum'
            ? ['nullable', 'string', 'max:80']
            : ['required', 'string', 'max:80'];

        $this->validate($request, [
            'section'        => ['required', 'in:ma,fleet,contracts,jobs,forum'],
            'listing_type'   => $listingTypeRule,
            'title_ar'       => ['required', 'string', 'max:255'],
            'title_en'       => ['nullable', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'city'           => $cityRule,
            'price'          => ['nullable', 'integer', 'min:0'],
            'dynamic_data'   => ['nullable', 'json'],
            'images.*'       => ['nullable', 'image', 'max:5120'],
        ]);

        $user = $request->user();

        if (! $request->listing_type) {
            $request->merge([
                'listing_type' => match ($section) {
                    'jobs'  => 'job',
                    'forum' => 'discussion',
                    'ma'    => 'acquisition',
                    default => 'listing',
                },
            ]);
        }

        $status = in_array($section, ['ma', 'contracts']) ? 'pending_review' : 'active';

        $media = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $i => $image) {
                $dir  = public_path("uploads/listings/{$user->id}");
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $name    = \Illuminate\Support\Str::random(40) . '.' . $image->getClientOriginalExtension();
                $image->move($dir, $name);
                $path    = "listings/{$user->id}/{$name}";
                $media[] = ['path' => $path, 'type' => 'image', 'is_primary' => $i === 0];
            }
        }

        $dynamicData = $request->dynamic_data ? json_decode($request->dynamic_data, true) : [];

        $listing = Listing::create([
            'user_id'        => $user->id,
            'section'        => $section,
            'listing_type'   => $request->listing_type,
            'title_ar'       => $request->title_ar,
            'title_en'       => $request->title_en,
            'description_ar' => $request->description_ar,
            'description_en' => $request->description_en,
            'city'           => $request->city,
            'region'         => $request->region,
            'price'          => $request->price,
            'price_type'     => $request->price_type ?? 'fixed',
            'contact_phone'  => $request->contact_phone,
            'status'         => $status,
            'dynamic_data'   => $dynamicData,
            'media'          => $media,
            'expires_at'     => now()->addDays(30),   // ← 30 days
        ]);

        $listing->update([
            'is_financing_eligible' => $listing->computeFinancingEligibility(),
            'is_ready_to_operate'   => $listing->computeReadyToOperate(),
        ]);

        return response()->json(['data' => $listing, 'message' => 'تم نشر الإعلان بنجاح.'], 201);
    }

    // POST /api/listings/{id} — full update (FormData)
    public function update(Request $request, int $id): JsonResponse
    {
        $listing = Listing::where('user_id', $request->user()->id)->findOrFail($id);

        $this->validate($request, [
            'title_ar'       => ['sometimes', 'string', 'max:255'],
            'title_en'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'city'           => ['sometimes', 'nullable', 'string', 'max:80'],
            'region'         => ['sometimes', 'nullable', 'string', 'max:80'],
            'price'          => ['sometimes', 'nullable', 'integer', 'min:0'],
            'price_type'     => ['sometimes', 'nullable', 'string', 'in:fixed,negotiable,on_request'],
            'contact_phone'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'listing_type'   => ['sometimes', 'nullable', 'string', 'max:60'],
            'section'        => ['sometimes', 'in:ma,fleet,contracts,jobs,forum'],
            'dynamic_data'   => ['sometimes', 'nullable', 'json'],
            'images.*'       => ['sometimes', 'image', 'max:5120'],
            'remove_images'  => ['sometimes', 'nullable', 'json'],  // JSON array of paths to remove
        ]);

        $data = $request->only([
            'title_ar', 'title_en', 'description_ar', 'description_en',
            'city', 'region', 'price', 'price_type', 'contact_phone', 'listing_type',
        ]);

        // Section change → back to pending_review
        if ($request->filled('section') && $request->section !== $listing->section) {
            $data['section'] = $request->section;
            $data['status']  = 'pending_review';
        }

        // Dynamic data
        if ($request->has('dynamic_data')) {
            $data['dynamic_data'] = $request->dynamic_data
                ? json_decode($request->dynamic_data, true)
                : [];
        }

        // Handle image removal
        $currentMedia = $listing->media ?? [];
        if ($request->filled('remove_images')) {
            $toRemove = json_decode($request->remove_images, true) ?? [];
            foreach ($toRemove as $path) {
                // Delete physical file
                $fullPath = public_path("uploads/{$path}");
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
            // Remove from media array
            $currentMedia = array_values(
                array_filter($currentMedia, fn($m) => !in_array($m['path'], $toRemove))
            );
        }

        // Handle new image uploads
        $user = $request->user();
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $dir = public_path("uploads/listings/{$user->id}");
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $name          = \Illuminate\Support\Str::random(40) . '.' . $image->getClientOriginalExtension();
                $image->move($dir, $name);
                $path          = "listings/{$user->id}/{$name}";
                $currentMedia[] = ['path' => $path, 'type' => 'image', 'is_primary' => false];
            }
            // First image is always primary
            foreach ($currentMedia as $idx => &$m) {
                $m['is_primary'] = ($idx === 0);
            }
            unset($m);
        }

        $data['media'] = $currentMedia;

        // Recompute badges
        $listing->update($data);
        $listing->update([
            'is_financing_eligible' => $listing->computeFinancingEligibility(),
            'is_ready_to_operate'   => $listing->computeReadyToOperate(),
        ]);

        return response()->json([
            'data'    => $listing->fresh(),
            'message' => isset($data['status']) && $data['status'] === 'pending_review'
                ? 'تم تحديث الإعلان. سيُعاد مراجعته بسبب تغيير القسم.'
                : 'تم تحديث الإعلان بنجاح.',
        ]);
    }

    // PATCH /api/listings/{id}/pause
    public function pause(Request $request, int $id): JsonResponse
    {
        $listing = Listing::where('user_id', $request->user()->id)->findOrFail($id);

        if (!in_array($listing->status, ['active'])) {
            return response()->json([
                'message' => 'لا يمكن تعطيل هذا الإعلان في وضعه الحالي.',
            ], 422);
        }

        $listing->update(['status' => 'paused']);

        return response()->json(['message' => 'تم تعطيل الإعلان مؤقتاً.', 'status' => 'paused']);
    }

    // PATCH /api/listings/{id}/unpause
    public function unpause(Request $request, int $id): JsonResponse
    {
        $listing = Listing::where('user_id', $request->user()->id)->findOrFail($id);

        if ($listing->status !== 'paused') {
            return response()->json([
                'message' => 'الإعلان غير معطّل.',
            ], 422);
        }

        // Check not expired
        if ($listing->expires_at && $listing->expires_at->isPast()) {
            return response()->json([
                'message' => 'انتهت صلاحية الإعلان. يرجى تجديده أولاً.',
            ], 422);
        }

        $listing->update(['status' => 'active']);

        return response()->json(['message' => 'تم إعادة تفعيل الإعلان.', 'status' => 'active']);
    }

    // PATCH /api/listings/{id}/renew
    public function renew(Request $request, int $id): JsonResponse
    {
        $listing = Listing::where('user_id', $request->user()->id)->findOrFail($id);

        // Only allow renewing expired or active/paused listings (not pending/rejected)
        if (in_array($listing->status, ['pending_review'])) {
            return response()->json([
                'message' => 'لا يمكن تجديد إعلان قيد المراجعة.',
            ], 422);
        }

        $newExpiry = now()->addDays(30);
        $newStatus = in_array($listing->status, ['expired']) ? 'active' : $listing->status;

        $listing->update([
            'expires_at' => $newExpiry,
            'status'     => $newStatus,
        ]);

        return response()->json([
            'message'    => 'تم تجديد الإعلان لمدة 30 يوماً إضافياً.',
            'expires_at' => $newExpiry->toISOString(),
            'status'     => $newStatus,
        ]);
    }

    // DELETE /api/listings/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        $listing = Listing::where('user_id', $request->user()->id)->findOrFail($id);
        $listing->delete();

        return response()->json(['message' => 'تم حذف الإعلان.']);
    }

    // POST /api/listings/{id}/view
    public function recordView(int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);

        // Don't count views on listings that aren't publicly visible
        if (in_array($listing->status, ['rejected', 'draft'], true)) {
            return response()->json(['views' => $listing->views_count]);
        }

        $listing->incrementViews();
        return response()->json(['views' => $listing->views_count]);
    }
}

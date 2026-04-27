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
        $query = Listing::with('user:id,name_ar,name_en,role,is_trusted_payer')
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

        $listings = $query->paginate(12);

        return response()->json($listings);
    }

    // GET /api/listings/featured
    public function featured(): JsonResponse
    {
        $listings = Listing::with('user:id,name_ar,name_en,role,is_trusted_payer')
                           ->active()
                           ->featured()
                           ->orderByDesc('created_at')
                           ->take(6)
                           ->get();

        return response()->json(['data' => $listings]);
    }

    // GET /api/listings/{id}
    public function show(int $id): JsonResponse
    {
        $listing = Listing::with('user:id,name_ar,name_en,role,is_trusted_payer')
                          ->active()
                          ->findOrFail($id);

        return response()->json(['data' => $listing]);
    }

    // POST /api/listings
    public function store(Request $request): JsonResponse
    {
        $this->validate($request, [
            'section'        => ['required', 'in:ma,fleet,contracts,jobs,forum'],
            'listing_type'   => ['required', 'string', 'max:60'],
            'title_ar'       => ['required', 'string', 'max:255'],
            'title_en'       => ['nullable', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'city'           => ['required', 'string', 'max:80'],
            'price'          => ['nullable', 'integer', 'min:0'],
            'dynamic_data'   => ['nullable', 'json'],
            'images.*'       => ['nullable', 'image', 'max:5120'], // 5MB each
        ]);

        $user    = $request->user();
        $section = $request->section;

        // Determine status: M&A and contracts need review
        $status = in_array($section, ['ma', 'contracts']) ? 'pending_review' : 'active';

        // Store images
        $media = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $i => $image) {
                $path    = $image->store("listings/{$user->id}", 'public');
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
            'status'         => $status,
            'dynamic_data'   => $dynamicData,
            'media'          => $media,
            'expires_at'     => now()->addDays(60),
        ]);

        // Compute auto badges
        $listing->update([
            'is_financing_eligible' => $listing->computeFinancingEligibility(),
            'is_ready_to_operate'   => $listing->computeReadyToOperate(),
        ]);

        return response()->json(['data' => $listing, 'message' => 'تم نشر الإعلان بنجاح.'], 201);
    }

    // POST /api/listings/{id} (with FormData)
    public function update(Request $request, int $id): JsonResponse
    {
        $listing = Listing::where('user_id', $request->user()->id)->findOrFail($id);

        $listing->update($request->only([
            'title_ar', 'title_en', 'description_ar', 'description_en',
            'city', 'region', 'price',
        ]));

        return response()->json(['data' => $listing, 'message' => 'تم تحديث الإعلان.']);
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
        $listing->incrementViews();

        return response()->json(['views' => $listing->views_count]);
    }
}

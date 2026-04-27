<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Interaction;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InteractionController extends Controller
{
    // POST /api/listings/{id}/bookmark
    public function bookmark(Request $request, int $id): JsonResponse
    {
        $listing = Listing::active()->findOrFail($id);

        $interaction = Interaction::firstOrCreate(
            [
                'user_id'    => $request->user()->id,
                'listing_id' => $listing->id,
                'type'       => 'bookmark',
            ],
            ['data' => []]
        );

        return response()->json([
            'message'    => 'تمت إضافة الإعلان إلى المفضلة.',
            'bookmarked' => true,
            'id'         => $interaction->id,
        ]);
    }

    // DELETE /api/listings/{id}/bookmark
    public function removeBookmark(Request $request, int $id): JsonResponse
    {
        Interaction::where([
            'user_id'    => $request->user()->id,
            'listing_id' => $id,
            'type'       => 'bookmark',
        ])->delete();

        return response()->json(['message' => 'تمت إزالة الإعلان من المفضلة.', 'bookmarked' => false]);
    }

    // GET /api/bookmarks
    public function myBookmarks(Request $request): JsonResponse
    {
        $bookmarks = Interaction::where('user_id', $request->user()->id)
            ->where('type', 'bookmark')
            ->with('listing:id,title_ar,title_en,price,city,section,status,media,is_featured')
            ->latest()
            ->paginate(12);

        return response()->json($bookmarks);
    }

    // POST /api/listings/{id}/bid
    public function submitBid(Request $request, int $id): JsonResponse
    {
        $listing = Listing::active()->findOrFail($id);

        $this->validate($request, [
            'amount'  => ['required', 'integer', 'min:1'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        // Overwrite existing bid from same user
        $bid = Interaction::updateOrCreate(
            [
                'user_id'    => $request->user()->id,
                'listing_id' => $listing->id,
                'type'       => 'bid',
            ],
            [
                'data' => [
                    'amount'     => $request->amount,
                    'message'    => $request->message,
                    'submitted_at' => now()->toISOString(),
                ],
            ]
        );

        return response()->json([
            'message' => 'تم تقديم عرض السعر بنجاح.',
            'data'    => $bid,
        ], 201);
    }

    // POST /api/listings/{id}/report
    public function report(Request $request, int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);

        $this->validate($request, [
            'reason'  => ['required', 'string', 'in:spam,fraud,duplicate,inappropriate,wrong_category,other'],
            'details' => ['nullable', 'string', 'max:500'],
        ]);

        Interaction::updateOrCreate(
            [
                'user_id'    => $request->user()->id,
                'listing_id' => $listing->id,
                'type'       => 'report',
            ],
            [
                'data' => [
                    'reason'     => $request->reason,
                    'details'    => $request->details,
                    'reported_at' => now()->toISOString(),
                ],
            ]
        );

        return response()->json(['message' => 'تم الإبلاغ عن الإعلان. سيتم مراجعته من قِبل فريق نوافذ.']);
    }

    // POST /api/saved-searches
    public function saveSearch(Request $request): JsonResponse
    {
        $this->validate($request, [
            'name'    => ['required', 'string', 'max:100'],
            'filters' => ['required', 'array'],
        ]);

        $saved = Interaction::create([
            'user_id'    => $request->user()->id,
            'listing_id' => null,
            'type'       => 'saved_search',
            'data'       => [
                'name'       => $request->name,
                'filters'    => $request->filters,
                'created_at' => now()->toISOString(),
            ],
        ]);

        return response()->json(['message' => 'تم حفظ البحث.', 'data' => $saved], 201);
    }

    // GET /api/saved-searches
    public function mySavedSearches(Request $request): JsonResponse
    {
        $searches = Interaction::where('user_id', $request->user()->id)
            ->where('type', 'saved_search')
            ->latest()
            ->get();

        return response()->json(['data' => $searches]);
    }

    // DELETE /api/saved-searches/{id}
    public function deleteSavedSearch(Request $request, int $id): JsonResponse
    {
        Interaction::where('user_id', $request->user()->id)
            ->where('type', 'saved_search')
            ->findOrFail($id)
            ->delete();

        return response()->json(['message' => 'تم حذف البحث المحفوظ.']);
    }
}

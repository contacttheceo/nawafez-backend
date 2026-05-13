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

        // Owner cannot bid on their own listing
        if ($listing->user_id === $request->user()->id) {
            return response()->json([
                'message' => 'لا يمكنك تقديم عرض على إعلانك الخاص.',
            ], 403);
        }

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

    // GET /api/listings/{id}/bids  — public summary + owner full list
    public function getBids(Request $request, int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);

        if ($listing->section !== 'ma') {
            return response()->json(['bid_count' => 0, 'highest_bid' => null, 'bids' => []]);
        }

        $bids = Interaction::where('listing_id', $listing->id)
            ->where('type', 'bid')
            ->get();

        $bidCount   = $bids->count();
        $highestBid = $bidCount > 0
            ? $bids->max(fn($b) => $b->data['amount'] ?? 0)
            : null;

        // Owner sees details — but NOT bidder identity
        $isOwner  = $request->user()?->id === $listing->user_id;
        $bidsList = [];

        if ($isOwner && $bidCount > 0) {
            $bidsList = $bids
                ->sortByDesc(fn($b) => $b->data['amount'] ?? 0)
                ->values()
                ->map(fn($b) => [
                    'amount'       => $b->data['amount']      ?? 0,
                    'message'      => $b->data['message']     ?? null,
                    'submitted_at' => $b->data['submitted_at'] ?? $b->created_at,
                ])->toArray();
        }

        return response()->json([
            'bid_count'   => $bidCount,
            'highest_bid' => $highestBid > 0 ? $highestBid : null,
            'bids'        => $bidsList,
        ]);
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

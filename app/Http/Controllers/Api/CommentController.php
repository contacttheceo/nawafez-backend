<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    /**
     * GET /api/listings/{id}/comments
     *
     * Returns top-level comments with nested replies (one level deep).
     * Forum default sort: official first → upvotes desc → newest.
     * Other sections default sort: newest first.
     *
     * Query params:
     *   sort = newest | votes | official  (default: official for forum, newest otherwise)
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        $isForum = $listing->section === 'forum';
        $sort    = $request->query('sort', $isForum ? 'official' : 'newest');

        $viewerId = $request->user()?->id;

        $query = Comment::where('listing_id', $listing->id)
            ->topLevel()
            ->with([
                'user:id,name_ar,name_en,avatar,role,is_trusted_payer',
                'replies' => function ($q) {
                    $q->with('user:id,name_ar,name_en,avatar,role,is_trusted_payer')
                      ->orderBy('created_at');
                },
            ]);

        match ($sort) {
            'votes'    => $query->orderByDesc('upvotes_count')->orderByDesc('created_at'),
            'newest'   => $query->latest(),
            default    => $query->orderByDesc('is_official_answer')
                                ->orderByDesc('is_marked_helpful')
                                ->orderByDesc('upvotes_count')
                                ->orderByDesc('created_at'),
        };

        $paginated = $query->paginate(20);

        // Attach viewer_voted flag — efficient single query
        if ($viewerId) {
            $allIds = collect();
            foreach ($paginated->items() as $c) {
                $allIds->push($c->id);
                foreach ($c->replies as $r) $allIds->push($r->id);
            }
            $votedIds = \App\Models\CommentVote::where('user_id', $viewerId)
                ->whereIn('comment_id', $allIds)
                ->pluck('comment_id')
                ->flip();

            foreach ($paginated->items() as $c) {
                $c->setAttribute('viewer_voted', $votedIds->has($c->id));
                foreach ($c->replies as $r) {
                    $r->setAttribute('viewer_voted', $votedIds->has($r->id));
                }
            }
        }

        return response()->json($paginated);
    }

    /**
     * POST /api/listings/{id}/comments
     *
     * Body: { body, parent_id? }
     * parent_id, if provided, must point to a top-level comment on the same listing.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);

        $this->validate($request, [
            'body'      => ['required', 'string', 'min:2', 'max:1000'],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
        ]);

        // If replying, enforce: parent must be on this listing AND top-level (one level deep)
        if ($request->filled('parent_id')) {
            $parent = Comment::find($request->parent_id);
            if (!$parent || $parent->listing_id !== $listing->id) {
                return response()->json(['message' => 'الرد على تعليق من إعلان مختلف غير مسموح.'], 422);
            }
            if ($parent->parent_id !== null) {
                return response()->json(['message' => 'الرد على رد غير مسموح (مستوى واحد فقط).'], 422);
            }
        }

        $comment = Comment::create([
            'listing_id' => $listing->id,
            'user_id'    => $request->user()->id,
            'parent_id'  => $request->parent_id,
            'body'       => trim($request->body),
        ]);

        $comment->load('user:id,name_ar,name_en,avatar,role,is_trusted_payer');

        return response()->json([
            'message' => 'تم إضافة التعليق بنجاح.',
            'data'    => $comment,
        ], 201);
    }

    /**
     * DELETE /api/listings/{id}/comments/{commentId}
     * Comment author, listing owner, or admin can delete.
     */
    public function destroy(Request $request, int $id, int $commentId): JsonResponse
    {
        $comment = Comment::where('listing_id', $id)->findOrFail($commentId);
        $listing = Listing::findOrFail($id);

        $userId = $request->user()->id;
        if ($comment->user_id !== $userId
            && $listing->user_id !== $userId
            && $request->user()->role !== 'admin'
        ) {
            return response()->json(['message' => 'غير مصرح لك بحذف هذا التعليق.'], 403);
        }

        $comment->delete();
        return response()->json(['message' => 'تم حذف التعليق.']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Interaction;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommentInteractionController extends Controller
{
    /**
     * POST /api/comments/{id}/vote — toggle upvote
     */
    public function toggleVote(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);
        $userId  = $request->user()->id;

        $voted = null;
        DB::transaction(function () use ($comment, $userId, &$voted) {
            $existing = CommentVote::where([
                'comment_id' => $comment->id,
                'user_id'    => $userId,
            ])->first();

            if ($existing) {
                $existing->delete();
                $comment->decrement('upvotes_count');
                $voted = false;
            } else {
                CommentVote::create([
                    'comment_id' => $comment->id,
                    'user_id'    => $userId,
                ]);
                $comment->increment('upvotes_count');
                $voted = true;
            }
        });

        return response()->json([
            'upvotes_count' => $comment->fresh()->upvotes_count,
            'voted'         => $voted,
        ]);
    }

    /**
     * POST /api/comments/{id}/mark-helpful — listing owner only
     * Marks ONE answer as helpful; unmarks all others on the same listing.
     */
    public function markHelpful(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);
        $listing = $comment->listing;

        if (!$listing || $listing->user_id !== $request->user()->id) {
            return response()->json(['message' => 'هذا متاح لصاحب السؤال فقط.'], 403);
        }

        DB::transaction(function () use ($comment, $listing) {
            Comment::where('listing_id', $listing->id)
                ->where('id', '!=', $comment->id)
                ->update(['is_marked_helpful' => false]);
            $comment->update(['is_marked_helpful' => true]);
        });

        return response()->json(['message' => 'تم تعليم الإجابة كمفيدة.', 'is_marked_helpful' => true]);
    }

    /**
     * POST /api/comments/{id}/unmark-helpful
     */
    public function unmarkHelpful(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);
        $listing = $comment->listing;

        if (!$listing || $listing->user_id !== $request->user()->id) {
            return response()->json(['message' => 'هذا متاح لصاحب السؤال فقط.'], 403);
        }

        $comment->update(['is_marked_helpful' => false]);
        return response()->json(['message' => 'تم إزالة تعليم الإجابة.', 'is_marked_helpful' => false]);
    }

    /**
     * POST /api/admin/comments/{id}/mark-official — admin only
     */
    public function markOfficial(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);
        $comment->update(['is_official_answer' => true]);

        AuditLogger::log(
            $request->user(),
            'comment.mark_official',
            'comment',
            $comment->id,
            ['listing_id' => $comment->listing_id, 'preview' => mb_substr($comment->body, 0, 80)],
            $request
        );

        return response()->json(['message' => 'تم اعتماد الإجابة كرسمية.', 'is_official_answer' => true]);
    }

    /**
     * POST /api/admin/comments/{id}/unmark-official — admin only
     */
    public function unmarkOfficial(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);
        $comment->update(['is_official_answer' => false]);

        AuditLogger::log(
            $request->user(),
            'comment.unmark_official',
            'comment',
            $comment->id,
            ['listing_id' => $comment->listing_id],
            $request
        );

        return response()->json(['message' => 'تم إلغاء اعتماد الإجابة.', 'is_official_answer' => false]);
    }

    /**
     * POST /api/comments/{id}/report — any authenticated user
     */
    public function report(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);

        $this->validate($request, [
            'reason'  => ['required', 'string', 'in:spam,abuse,off_topic,misleading,other'],
            'details' => ['nullable', 'string', 'max:500'],
        ]);

        // Reuse Interaction system — target_type = 'comment' inside data
        Interaction::updateOrCreate(
            [
                'user_id'    => $request->user()->id,
                'listing_id' => $comment->listing_id,
                'type'       => 'report',
            ],
            [
                'data' => [
                    'comment_id'  => $comment->id,
                    'reason'      => $request->reason,
                    'details'     => $request->details,
                    'reported_at' => now()->toISOString(),
                ],
            ]
        );

        return response()->json(['message' => 'تم الإبلاغ عن التعليق.']);
    }
}

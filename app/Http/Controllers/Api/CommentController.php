<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    // GET /api/listings/{id}/comments  — public
    public function index(int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);

        $comments = Comment::where('listing_id', $listing->id)
            ->with('user:id,name_ar,name_en,avatar,role,is_trusted_payer')
            ->latest()
            ->paginate(20);

        return response()->json($comments);
    }

    // POST /api/listings/{id}/comments  — auth required
    public function store(Request $request, int $id): JsonResponse
    {
        try {
            $listing = Listing::findOrFail($id);

            $this->validate($request, [
                'body' => ['required', 'string', 'min:2', 'max:1000'],
            ]);

            $comment = Comment::create([
                'listing_id' => $listing->id,
                'user_id'    => $request->user()->id,
                'body'       => trim($request->body),
            ]);

            // Load user relation for response
            $comment->load('user:id,name_ar,name_en,avatar,role,is_trusted_payer');

            return response()->json([
                'message' => 'تم إضافة التعليق بنجاح.',
                'data'    => $comment,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message' => 'DB Error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // DELETE /api/listings/{id}/comments/{commentId}  — comment owner OR listing owner
    public function destroy(Request $request, int $id, int $commentId): JsonResponse
    {
        $comment = Comment::where('listing_id', $id)->findOrFail($commentId);
        $listing = Listing::findOrFail($id);

        $userId = $request->user()->id;

        // Only the commenter, the listing owner, or an admin can delete
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

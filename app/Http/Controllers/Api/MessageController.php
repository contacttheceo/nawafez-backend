<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Interaction;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    /**
     * GET /api/messages
     * Return the user's inbox — one entry per conversation thread.
     * A thread is identified by (other_user_id + listing_id).
     */
    public function inbox(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Fetch all messages involving this user, then group into threads
        $messages = Interaction::where('type', 'message')
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                  ->orWhereJsonContains('data->to_user_id', $userId);
            })
            ->with('listing:id,title_ar,title_en')
            ->orderByDesc('created_at')
            ->get();

        // Group into threads
        $threads = $messages
            ->groupBy(function ($msg) use ($userId) {
                $otherId = $msg->user_id === $userId
                    ? data_get($msg->data, 'to_user_id')
                    : $msg->user_id;
                return "{$otherId}_{$msg->listing_id}";
            })
            ->map(function ($group) use ($userId) {
                $latest    = $group->first();
                $otherId   = $latest->user_id === $userId
                    ? data_get($latest->data, 'to_user_id')
                    : $latest->user_id;
                $otherUser = User::select('id', 'name_ar', 'name_en', 'role')->find($otherId);

                return [
                    'thread_key'   => "{$otherId}_{$latest->listing_id}",
                    'other_user'   => $otherUser,
                    'listing'      => $latest->listing,
                    'last_message' => [
                        'body'       => data_get($latest->data, 'body'),
                        'sent_at'    => $latest->created_at,
                        'is_mine'    => $latest->user_id === $userId,
                    ],
                    'unread_count' => $group
                        ->where('user_id', '!=', $userId)
                        ->whereNull('data->read_at')
                        ->count(),
                ];
            })
            ->values();

        return response()->json(['data' => $threads]);
    }

    /**
     * GET /api/messages/{userId}/{listingId}
     * Return the full message thread between this user and another user
     * about a specific listing.
     */
    public function thread(Request $request, int $otherUserId, int $listingId): JsonResponse
    {
        $userId = $request->user()->id;

        $messages = Interaction::where('type', 'message')
            ->where('listing_id', $listingId)
            ->where(function ($q) use ($userId, $otherUserId) {
                $q->where(function ($q2) use ($userId, $otherUserId) {
                    $q2->where('user_id', $userId)
                       ->whereJsonContains('data->to_user_id', $otherUserId);
                })->orWhere(function ($q2) use ($userId, $otherUserId) {
                    $q2->where('user_id', $otherUserId)
                       ->whereJsonContains('data->to_user_id', $userId);
                });
            })
            ->orderBy('created_at')
            ->get()
            ->map(function ($msg) use ($userId) {
                // Mark incoming messages as read
                if ($msg->user_id !== $userId && !data_get($msg->data, 'read_at')) {
                    $data             = $msg->data;
                    $data['read_at']  = now()->toISOString();
                    $msg->update(['data' => $data]);
                }

                return [
                    'id'      => $msg->id,
                    'body'    => data_get($msg->data, 'body'),
                    'sent_at' => $msg->created_at,
                    'is_mine' => $msg->user_id === $userId,
                ];
            });

        return response()->json(['data' => $messages]);
    }

    /**
     * POST /api/messages
     * Send a message to another user about a listing.
     */
    public function send(Request $request): JsonResponse
    {
        $this->validate($request, [
            'to_user_id' => ['required', 'integer', 'exists:users,id', 'different:' . $request->user()->id],
            'listing_id' => ['required', 'integer', 'exists:listings,id'],
            'body'       => ['required', 'string', 'max:2000'],
        ]);

        // Prevent self-messaging
        if ((int) $request->to_user_id === $request->user()->id) {
            return response()->json(['message' => 'لا يمكنك مراسلة نفسك.'], 422);
        }

        $message = Interaction::create([
            'user_id'    => $request->user()->id,
            'listing_id' => $request->listing_id,
            'type'       => 'message',
            'data'       => [
                'to_user_id' => (int) $request->to_user_id,
                'body'       => $request->body,
                'read_at'    => null,
            ],
        ]);

        return response()->json([
            'message' => 'تم إرسال الرسالة.',
            'data'    => [
                'id'      => $message->id,
                'body'    => data_get($message->data, 'body'),
                'sent_at' => $message->created_at,
                'is_mine' => true,
            ],
        ], 201);
    }
}

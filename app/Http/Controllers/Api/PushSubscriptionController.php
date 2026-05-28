<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    // GET /api/user/push-subscriptions
    public function index(Request $request): JsonResponse
    {
        $subs = PushSubscription::where('user_id', $request->user()->id)
            ->select(['id', 'endpoint', 'user_agent', 'created_at', 'last_seen_at'])
            ->orderByDesc('id')
            ->get();
        return response()->json(['data' => $subs]);
    }

    // POST /api/user/push-subscriptions
    //
    // Idempotent — if the same endpoint exists we just bump last_seen_at and
    // refresh the keys (browsers occasionally rotate them).
    public function store(Request $request): JsonResponse
    {
        $this->validate($request, [
            'endpoint'   => ['required', 'string', 'max:500'],
            'p256dh'     => ['nullable', 'string', 'max:200'],
            'auth'       => ['nullable', 'string', 'max:100'],
            'user_agent' => ['nullable', 'string', 'max:250'],
        ]);

        $sub = PushSubscription::updateOrCreate(
            ['endpoint' => $request->endpoint],
            [
                'user_id'      => $request->user()->id,
                'p256dh'       => $request->p256dh,
                'auth'         => $request->auth,
                'user_agent'   => $request->user_agent,
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Subscription registered',
            'data'    => $sub->only(['id', 'endpoint']),
        ], 201);
    }

    // DELETE /api/user/push-subscriptions
    public function destroy(Request $request): JsonResponse
    {
        $this->validate($request, [
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $request->endpoint)
            ->delete();

        return response()->json(['message' => 'Subscription removed']);
    }
}

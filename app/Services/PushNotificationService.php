<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Web Push dispatcher.
 *
 * Architecture: we *store* subscriptions in our MySQL but *send* via a Vercel
 * Next.js API route (POST {FRONTEND_URL}/api/push/send) that has the mature
 * `web-push` npm library + Vercel's better cold-start latency to FCM/APNs.
 *
 * Authentication uses a shared secret (PUSH_INTERNAL_SECRET) sent as
 * `X-Internal-Secret`. Both backend and frontend env must match.
 *
 * Always wrapped in try/catch + Log — push failures must NEVER bubble up
 * and break the user-facing action that triggered them (sending a message,
 * approving a listing, etc).
 */
class PushNotificationService
{
    /**
     * Send a notification to one user (across all their devices).
     *
     * @param  array{title:string,body?:string,url?:string,tag?:string,icon?:string,badge?:string}  $payload
     */
    public function notifyUser(User|int $user, array $payload): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        $subs   = PushSubscription::where('user_id', $userId)->get();
        if ($subs->isEmpty()) return;

        $this->send($subs->all(), $payload);
    }

    /**
     * Send to multiple users at once.
     *
     * @param  iterable<User|int>  $users
     * @param  array{title:string,body?:string,url?:string,tag?:string,icon?:string,badge?:string}  $payload
     */
    public function notifyUsers(iterable $users, array $payload): void
    {
        $userIds = collect($users)
            ->map(fn ($u) => $u instanceof User ? $u->id : (int) $u)
            ->unique()
            ->values()
            ->all();
        if (empty($userIds)) return;

        $subs = PushSubscription::whereIn('user_id', $userIds)->get();
        if ($subs->isEmpty()) return;

        $this->send($subs->all(), $payload);
    }

    /**
     * Raw send — POST to Vercel /api/push/send and prune any subscriptions
     * the push service reports as gone (410 / 404).
     *
     * @param  PushSubscription[]  $subs
     */
    private function send(array $subs, array $payload): void
    {
        $url    = rtrim(env('FRONTEND_URL', 'https://www.nwafizlogi.com'), '/').'/api/push/send';
        $secret = env('PUSH_INTERNAL_SECRET');
        if (!$secret) {
            Log::warning('PushNotificationService: PUSH_INTERNAL_SECRET not set, skipping');
            return;
        }

        $body = json_encode([
            'subscriptions' => array_map(fn ($s) => $s->toPushPayload(), $subs),
            'payload'       => $payload,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Internal-Secret: '.$secret,
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            Log::warning('PushNotificationService: dispatch failed', [
                'status' => $status,
                'error'  => $err,
                'body'   => is_string($response) ? substr($response, 0, 300) : null,
            ]);
            return;
        }

        // Prune expired endpoints (410 = unsubscribed, 404 = endpoint gone)
        $data = json_decode((string) $response, true);
        if (is_array($data) && !empty($data['expired'])) {
            PushSubscription::whereIn('endpoint', $data['expired'])->delete();
        }
    }
}

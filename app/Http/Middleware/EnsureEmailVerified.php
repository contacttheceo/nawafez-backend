<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block POST/create actions for users whose email is not verified.
 *
 * Applied to: listing creation, comments, messages, bids — anywhere a user
 * publishes content. Reading is still allowed (so users can browse first,
 * verify after).
 *
 * Returns 403 with a structured reason the frontend uses to render a clear
 * "verify your email" prompt instead of a generic error.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No user → let the auth middleware handle it (we shouldn't override 401)
        if (!$user) {
            return $next($request);
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'message' => 'يجب التحقق من بريدك الإلكتروني قبل نشر أي محتوى. تحقق من رسالة التحقق التي أرسلناها لك، أو اطلب إرسالها مجدداً من صفحة الملف الشخصي.',
                'reason'  => 'email_not_verified',
                'email'   => $user->email,
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ResendMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name_ar'               => ['required', 'string', 'max:120'],
            'name_en'               => ['required', 'string', 'max:120'],
            'email'                 => ['required', 'email', 'max:255', 'unique:users'],
            'password'              => ['required', 'confirmed', Rules\Password::min(8)],
            'phone'                 => ['nullable', 'string', 'max:20'],
        ]);

        $user = User::create([
            'name_ar'  => $request->name_ar,
            'name_en'  => $request->name_en,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'phone'    => $request->phone,
            'role'     => 'individual',
        ]);

        // Send verification email via Resend HTTP API
        try {
            $this->sendVerificationEmail($user);
        } catch (\Throwable $e) {
            \Log::warning('Registration verification email failed: ' . $e->getMessage());
        }

        // Welcome email to the new user + notification to all admins.
        // Wrapped separately so one failure doesn't block the other.
        try {
            app(\App\Services\AdminEmailService::class)->welcomeUser($user);
        } catch (\Throwable $e) {
            \Log::warning('Welcome email failed: ' . $e->getMessage());
        }
        try {
            app(\App\Services\AdminEmailService::class)->newUserRegisteredToAdmin($user);
        } catch (\Throwable $e) {
            \Log::warning('Admin new-user email failed: ' . $e->getMessage());
        }

        $token = $user->createToken('nawafez_app')->plainTextToken;

        return response()->json([
            'message' => 'Account created. Please verify your email.',
            'token'   => $token,
            'user'    => $this->userResource($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
            ], 401);
        }

        $user = Auth::user();

        // Block suspended users
        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            Auth::logout();
            return response()->json([
                'message' => 'تم تعليق حسابك. للاستفسار: info@nwafizlogi.com',
                'reason'  => $user->suspend_reason,
            ], 403);
        }

        $token = $user->createToken('nawafez_app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userResource($request->user())]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Find user — don't reveal whether email exists
        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'إذا كان البريد مسجلاً، ستصلك رسالة خلال دقائق.',
            ]);
        }

        // Create password reset token
        $token       = Password::createToken($user);
        $frontendUrl = env('FRONTEND_URL', 'https://nawafez.vercel.app');
        $resetUrl    = "{$frontendUrl}/auth/reset-password?token={$token}&email={$user->email}";

        // Send via Resend HTTP API (bypasses SMTP — uses HTTPS port 443)
        $mailer = new ResendMailer();
        $sent   = $mailer->send(
            $user->email,
            'إعادة تعيين كلمة المرور — نوافذ',
            $this->resetPasswordEmailHtml($resetUrl)
        );

        if (!$sent) {
            return response()->json([
                'message' => 'فشل إرسال البريد الإلكتروني — يرجى المحاولة لاحقاً.',
            ], 500);
        }

        return response()->json([
            'message' => 'تم إرسال رابط إعادة تعيين كلمة المرور على بريدك الإلكتروني.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => ['required'],
            'email'                 => ['required', 'email'],
            'password'              => ['required', 'confirmed', Rules\Password::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
            }
        );

        return response()->json([
            'message' => $status === Password::PASSWORD_RESET
                ? 'تم تغيير كلمة المرور بنجاح.'
                : 'رابط إعادة التعيين غير صالح أو منتهي.',
        ], $status === Password::PASSWORD_RESET ? 200 : 422);
    }

    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->email))) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return response()->json(['message' => 'تم التحقق من البريد الإلكتروني بنجاح.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 422);
        }

        $mailer = new ResendMailer();
        $this->sendVerificationEmail($user);

        return response()->json(['message' => 'تم إرسال رابط التحقق مجدداً.']);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function sendVerificationEmail(User $user): void
    {
        $frontendUrl = env('FRONTEND_URL', 'https://nawafez.vercel.app');
        $id          = $user->getKey();
        $hash        = sha1($user->email);
        $verifyUrl   = "{$frontendUrl}/auth/verify-email?id={$id}&hash={$hash}";

        $mailer = new ResendMailer();
        $mailer->send(
            $user->email,
            'تحقق من بريدك الإلكتروني — نوافذ',
            $this->verifyEmailHtml($verifyUrl, $user->name_ar)
        );
    }

    private function resetPasswordEmailHtml(string $url): string
    {
        return "
        <div dir='rtl' style='font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:32px;background:#f9f9f9;border-radius:12px;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <div style='display:inline-block;background:#0a2342;color:white;width:48px;height:48px;border-radius:10px;font-size:24px;font-weight:900;line-height:48px;'>ن</div>
                <h2 style='color:#0a2342;margin:12px 0 4px;'>نوافذ</h2>
            </div>
            <h3 style='color:#0a2342;'>إعادة تعيين كلمة المرور</h3>
            <p style='color:#444;line-height:1.6;'>تلقينا طلباً لإعادة تعيين كلمة المرور الخاصة بحسابك. اضغط على الزر أدناه للمتابعة:</p>
            <div style='text-align:center;margin:28px 0;'>
                <a href='{$url}' style='background:#0a2342;color:white;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:bold;font-size:15px;'>
                    إعادة تعيين كلمة المرور
                </a>
            </div>
            <p style='color:#888;font-size:12px;border-top:1px solid #eee;padding-top:16px;'>
                إذا لم تطلب هذا، يمكنك تجاهل الرسالة. الرابط صالح لمدة 60 دقيقة فقط.
            </p>
        </div>";
    }

    private function verifyEmailHtml(string $url, string $name): string
    {
        return "
        <div dir='rtl' style='font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:32px;background:#f9f9f9;border-radius:12px;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <div style='display:inline-block;background:#0a2342;color:white;width:48px;height:48px;border-radius:10px;font-size:24px;font-weight:900;line-height:48px;'>ن</div>
                <h2 style='color:#0a2342;margin:12px 0 4px;'>نوافذ</h2>
            </div>
            <h3 style='color:#0a2342;'>مرحباً {$name}،</h3>
            <p style='color:#444;line-height:1.6;'>شكراً لتسجيلك في منصة نوافذ. اضغط على الزر أدناه لتفعيل حسابك:</p>
            <div style='text-align:center;margin:28px 0;'>
                <a href='{$url}' style='background:#10b981;color:white;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:bold;font-size:15px;'>
                    تفعيل الحساب
                </a>
            </div>
            <p style='color:#888;font-size:12px;border-top:1px solid #eee;padding-top:16px;'>
                إذا لم تسجّل في نوافذ، يمكنك تجاهل هذه الرسالة.
            </p>
        </div>";
    }

    private function userResource(User $user): array
    {
        return [
            'id'                    => $user->id,
            'name_ar'               => $user->name_ar,
            'name_en'               => $user->name_en,
            'email'                 => $user->email,
            'phone'                 => $user->phone,
            'avatar_url'            => $user->avatar ?? null,  // relative path — frontend builds URL
            'role'                  => $user->role,
            'is_trusted_payer'      => $user->is_trusted_payer,
            'email_verified_at'     => $user->email_verified_at,
            'business_verification' => $user->business_verification,
            'subscription_data'     => $user->subscription_data,
        ];
    }
}

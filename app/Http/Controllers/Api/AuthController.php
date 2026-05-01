<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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

        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            \Log::warning('Registration email failed: ' . $e->getMessage());
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

        $user  = Auth::user();
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

        $status = Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => $status === Password::RESET_LINK_SENT
                ? 'تم إرسال رابط إعادة تعيين كلمة المرور على بريدك الإلكتروني.'
                : 'تعذر إرسال الرابط، تأكد من صحة البريد الإلكتروني.',
        ], $status === Password::RESET_LINK_SENT ? 200 : 422);
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
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 422);
        }
        $request->user()->sendEmailVerificationNotification();
        return response()->json(['message' => 'تم إرسال رابط التحقق مجدداً.']);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function userResource(User $user): array
    {
        return [
            'id'                    => $user->id,
            'name_ar'               => $user->name_ar,
            'name_en'               => $user->name_en,
            'email'                 => $user->email,
            'phone'                 => $user->phone,
            'role'                  => $user->role,
            'is_trusted_payer'      => $user->is_trusted_payer,
            'email_verified_at'     => $user->email_verified_at,
            'business_verification' => $user->business_verification,
            'subscription_data'     => $user->subscription_data,
        ];
    }
}

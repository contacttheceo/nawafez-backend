<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'https://nawafez.vercel.app'));

        // Point password-reset links to the Next.js frontend
        ResetPassword::createUrlUsing(function ($user, string $token) use ($frontendUrl) {
            return "{$frontendUrl}/auth/reset-password?token={$token}&email={$user->email}";
        });

        // Point email-verification links to the Next.js frontend
        // The frontend page calls POST /api/auth/verify-email/{id}/{hash} to confirm
        VerifyEmail::createUrlUsing(function ($notifiable) use ($frontendUrl) {
            $id   = $notifiable->getKey();
            $hash = sha1($notifiable->getEmailForVerification());
            return "{$frontendUrl}/auth/verify-email?id={$id}&hash={$hash}";
        });
    }
}

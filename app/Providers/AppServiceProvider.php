<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Point password-reset links to the Next.js frontend
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'https://nawafez.vercel.app'));
            return "{$frontendUrl}/auth/reset-password?token={$token}&email={$user->email}";
        });
    }
}

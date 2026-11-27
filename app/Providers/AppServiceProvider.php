<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('api', fn ($r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('auth', fn ($r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('login-account', function ($r) {
            $email = $r->input('email');
            return Limit::perMinute(10)->by(hash('sha256', is_string($email) ? mb_strtolower(trim($email)) : ''));
        });
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('pangantani.frontend_url').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
        \Illuminate\Auth\Notifications\VerifyEmail::createUrlUsing(function ($user) {
            $signed = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]);
            return config('pangantani.frontend_url').'/verify-email?'.http_build_query(['verification_url' => $signed]);
        });
    }
}

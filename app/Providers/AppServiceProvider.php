<?php

namespace App\Providers;

use App\Models\Transaction;
use App\Support\Email;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::bind('transaction', fn (string $value): Transaction => request()->user()->transactions()->findOrFail($value));

        Password::defaults(fn () => Password::min(12));

        Scramble::configure()->withDocumentTransformers(function (OpenApi $document): void {
            $document->secure(SecurityScheme::http('bearer')->as('bearerAuth'));
        });

        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('identity:'.hash('sha256', Email::normalize($request->input('email')).'|'.$request->ip())),
        ]);
        RateLimiter::for('auth-recovery', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}

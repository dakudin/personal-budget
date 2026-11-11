<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasVerifiedEmail(), 403, 'Підтвердьте email для доступу до фінансових операцій.');

        return $next($request);
    }
}

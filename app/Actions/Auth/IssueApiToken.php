<?php

namespace App\Actions\Auth;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

class IssueApiToken
{
    public function handle(User $user): NewAccessToken
    {
        return $user->createToken('api', ['*'], now()->addMinutes(max(1, config('sanctum.token_lifetime_minutes'))));
    }
}

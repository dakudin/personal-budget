<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

class LoginUser
{
    public function handle(string $email, #[\SensitiveParameter] string $password): NewAccessToken
    {
        return DB::transaction(function () use ($email, $password) {
            $user = User::where('email', $email)->lockForUpdate()->first();

            if (! $user || ! Hash::check($password, $user->password)) {
                throw new AuthenticationException('Невірний email або пароль.');
            }

            return app(IssueApiToken::class)->handle($user);
        });
    }
}

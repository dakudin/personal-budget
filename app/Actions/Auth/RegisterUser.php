<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

class RegisterUser
{
    public function handle(#[\SensitiveParameter] array $data): NewAccessToken
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->assignRole('user');

            $token = app(IssueApiToken::class)->handle($user);

            event(new Registered($user));

            return $token;
        });
    }
}

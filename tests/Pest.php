<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

function authHttp(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
{
    // Rebuild guards between HTTP requests, as a fresh application would do.
    Auth::forgetGuards();
    Auth::shouldUse('web');
    app()->forgetInstance('auth.driver');

    return test()->json($method, $uri, $data, $token ? ['Authorization' => 'Bearer '.$token] : [], JSON_PRESERVE_ZERO_FRACTION);
}

function loginToken(User $user, string $password = 'initial-password-123'): string
{
    return authHttp('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => $password])
        ->assertOk()->json('token');
}

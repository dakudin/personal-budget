<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

it('stores and resolves a Sanctum token', function () {
    /** @var \Tests\TestCase $this */
    $user = User::factory()->create();
    $token = $user->createToken('foundation-check', ['read']);

    expect(PersonalAccessToken::findToken($token->plainTextToken)->tokenable->is($user))->toBeTrue();
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

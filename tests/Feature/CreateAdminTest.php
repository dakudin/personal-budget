<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

it('creates an administrator with a hashed password', function () {
    /** @var TestCase $this */
    $this->artisan('app:create-admin', ['--name' => 'Admin', '--email' => 'admin@example.com'])
        ->expectsQuestion('Пароль (щонайменше 12 символів)', 'secure-test-password')
        ->assertSuccessful();

    $admin = User::where('email', 'admin@example.com')->sole();

    expect($admin->hasRole('admin', 'web'))->toBeTrue()
        ->and(Hash::check('secure-test-password', $admin->password))->toBeTrue();
});

it('rejects a short password without creating an account', function () {
    /** @var TestCase $this */
    $this->artisan('app:create-admin', ['--name' => 'Admin', '--email' => 'admin@example.com'])
        ->expectsQuestion('Пароль (щонайменше 12 символів)', 'short')
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
});

it('does not elevate an existing user', function () {
    /** @var TestCase $this */
    $user = User::factory()->create();

    $this->artisan('app:create-admin', ['--name' => 'Admin', '--email' => $user->email])
        ->assertFailed();

    expect($user->fresh()->hasRole('admin', 'web'))->toBeFalse();
    $this->assertDatabaseCount('users', 1);
});

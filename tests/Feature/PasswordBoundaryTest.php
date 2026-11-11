<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

beforeEach(function () {
    /** @var TestCase $this */
    $this->seed(RoleSeeder::class);
});

dataset('unsupported bcrypt passwords', [
    'null byte' => ["long-password-\0-123"],
    'ASCII beyond 72 bytes' => [str_repeat('a', 73)],
    'Unicode beyond 72 bytes' => [str_repeat('я', 37)],
]);

it('rejects unsupported registration passwords without creating credentials', function (string $password) {
    /** @var TestCase $this */
    authHttp('POST', '/api/v1/auth/register', registrationData([
        'password' => $password, 'password_confirmation' => $password,
    ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with('unsupported bcrypt passwords');

it('rejects unsupported replacement passwords without consuming credentials', function (string $password, string $mode) {
    /** @var TestCase $this */
    $user = User::factory()->create(['password' => 'initial-password-123']);
    $bearer = loginToken($user);
    $reset = Password::createToken($user);
    $data = ['password' => $password, 'password_confirmation' => $password];
    if ($mode === 'reset') {
        authHttp('POST', '/api/v1/auth/reset-password', $data + ['email' => $user->email, 'token' => $reset])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    } else {
        authHttp('PUT', '/api/v1/auth/password', $data + ['current_password' => 'initial-password-123'], $bearer)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }
    expect(Hash::check('initial-password-123', $user->fresh()->password))->toBeTrue()
        ->and(Password::tokenExists($user, $reset))->toBeTrue();
    authHttp('GET', '/api/v1/me', token: $bearer)->assertOk();
})->with('unsupported bcrypt passwords')->with(['reset', 'change']);

it('rejects a truncated password suffix at API login and current password verification', function () {
    $password = str_repeat('a', 72);
    $user = User::factory()->create(['password' => $password]);
    $bearer = loginToken($user, $password);
    authHttp('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => $password.'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    authHttp('PUT', '/api/v1/auth/password', [
        'current_password' => $password.'x', 'password' => 'replacement-password-456',
        'password_confirmation' => 'replacement-password-456',
    ], $bearer)->assertUnprocessable()->assertJsonValidationErrors('current_password');
    authHttp('GET', '/api/v1/me', token: $bearer)->assertOk();
});

it('rejects unsupported administrator passwords at creation', function (string $password) {
    /** @var TestCase $this */
    $this->artisan('app:create-admin', ['--name' => 'Admin', '--email' => 'admin@example.com'])
        ->expectsQuestion('Пароль (щонайменше 12 символів)', $password)->assertFailed();
    $this->assertDatabaseCount('users', 0);
})->with('unsupported bcrypt passwords');

it('rejects truncated password suffixes in the configured Filament login', function () {
    $password = str_repeat('a', 72);
    $admin = User::factory()->create(['password' => $password])->assignRole('admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(Filament::getPanel('admin')->getLoginRouteAction())
        ->fillForm(['email' => $admin->email, 'password' => $password.'x'])
        ->call('authenticate')->assertHasFormErrors(['password']);
    /** @var TestCase $this */
    $this->assertGuest();
});

it('accepts a Unicode password at the 72 byte boundary', function () {
    $password = str_repeat('я', 36);
    $response = authHttp('POST', '/api/v1/auth/register', registrationData([
        'password' => $password, 'password_confirmation' => $password,
    ]))->assertCreated();
    authHttp('POST', '/api/v1/auth/login', ['email' => $response->json('user.email'), 'password' => $password])->assertOk();
});

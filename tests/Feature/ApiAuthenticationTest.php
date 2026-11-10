<?php

use App\Actions\Auth\IssueApiToken;
use App\Actions\Auth\ReplacePassword;
use App\Models\User;
use App\Notifications\ResetPasswordToken;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function registrationData(array $overrides = []): array
{
    return array_replace([
        'name' => 'API User',
        'email' => 'api@example.com',
        'password' => 'initial-password-123',
        'password_confirmation' => 'initial-password-123',
    ], $overrides);
}

function resetCredentials(User $user): array
{
    return [
        'email' => $user->email,
        'token' => Password::createToken($user),
        'password' => 'replacement-password-456',
        'password_confirmation' => 'replacement-password-456',
    ];
}

it('registers a normalized user without trusting privileged fields', function () {
    $response = authHttp('POST', '/api/v1/auth/register', registrationData([
        'email' => '  API@Example.COM  ',
        'role' => 'admin',
        'roles' => ['admin'],
        'permissions' => ['*'],
        'email_verified_at' => now(),
        'remember_token' => 'injected',
        'id' => 9999,
    ]))->assertCreated()->assertJsonPath('user.email', 'api@example.com')
        ->assertJsonPath('token_type', 'Bearer')->assertJsonStructure(['user', 'token', 'token_type', 'expires_at']);

    $user = User::sole();
    expect($user->getRoleNames()->all())->toBe(['user'])
        ->and($user->permissions()->count())->toBe(0)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->remember_token)->toBeNull()
        ->and(Hash::check('initial-password-123', $user->password))->toBeTrue()
        ->and($response->json('user'))->not->toHaveKeys(['password', 'remember_token', 'permissions', 'roles'])
        ->and(PersonalAccessToken::findToken($response->json('token'))->token)->not->toBe($response->json('token'));

    authHttp('GET', '/api/v1/me', token: $response->json('token'))->assertOk()->assertJsonPath('data.id', $user->id);
    $this->actingAs($user, 'web')->get('/admin')->assertForbidden();
});

it('validates registration', function (array $overrides, string $field) {
    authHttp('POST', '/api/v1/auth/register', registrationData($overrides))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'invalid email' => [['email' => 'invalid'], 'email'],
    'non-string email' => [['email' => ['api@example.com']], 'email'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'missing confirmation' => [['password_confirmation' => null], 'password'],
    'different confirmation' => [['password_confirmation' => 'different-password'], 'password'],
]);

it('rejects an existing email after normalization', function () {
    User::factory()->create(['email' => 'api@example.com']);
    authHttp('POST', '/api/v1/auth/register', registrationData(['email' => ' API@EXAMPLE.COM ']))
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->assertDatabaseCount('users', 1);
});

it('rolls back registration and role assignment if token issuance fails', function () {
    $this->mock(IssueApiToken::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Token issuance failed.'));
    authHttp('POST', '/api/v1/auth/register', registrationData())->assertStatus(500);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('model_has_roles', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('logs in with normalized email and a configurable expiration', function () {
    config(['sanctum.token_lifetime_minutes' => 90]);
    $this->freezeTime();
    $user = User::factory()->create(['email' => 'api@example.com', 'password' => 'initial-password-123']);
    $response = authHttp('POST', '/api/v1/auth/login', ['email' => ' API@Example.COM ', 'password' => 'initial-password-123'])
        ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('expires_at', now()->addMinutes(90)->startOfSecond()->toISOString());
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    authHttp('GET', '/api/v1/me', token: $response->json('token'))->assertOk();
    $this->travel(91)->minutes();
    authHttp('GET', '/api/v1/me', token: $response->json('token'))->assertUnauthorized();
});

it('returns the same unauthorized response for unknown email and incorrect password', function () {
    $user = User::factory()->create(['password' => 'initial-password-123']);
    $incorrect = authHttp('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertUnauthorized()->json();
    $unknown = authHttp('POST', '/api/v1/auth/login', ['email' => 'unknown@example.com', 'password' => 'wrong-password'])
        ->assertUnauthorized()->json();
    expect($unknown)->toBe($incorrect);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('requires a valid bearer token on protected endpoints even without an accept header', function (string $method, string $uri) {
    $this->call($method, $uri)->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
})->with([
    ['GET', '/api/v1/me'],
    ['POST', '/api/v1/auth/logout'],
    ['PUT', '/api/v1/auth/password'],
]);

it('rejects invalid expired and revoked bearer tokens over HTTP', function (string $kind) {
    $user = User::factory()->create(['password' => 'initial-password-123']);
    $token = loginToken($user);
    authHttp('GET', '/api/v1/me', token: $token)->assertOk();
    if ($kind === 'expired') {
        PersonalAccessToken::findToken($token)->update(['expires_at' => now()->subSecond()]);
    } elseif ($kind === 'revoked') {
        PersonalAccessToken::findToken($token)->delete();
    } else {
        $token .= 'incorrect';
    }
    authHttp('GET', '/api/v1/me', token: $token)->assertUnauthorized();
})->with(['expired', 'revoked', 'incorrect']);

it('does not accept an admin web session as API authentication', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $this->actingAs($admin, 'web')->getJson('/api/v1/me')->assertUnauthorized();
    $this->actingAs($admin, 'web')->get('/admin')->assertOk();
});

it('logs out only the current bearer token', function () {
    $user = User::factory()->create(['password' => 'initial-password-123']);
    $first = loginToken($user);
    $second = loginToken($user);
    authHttp('POST', '/api/v1/auth/logout', token: $first)->assertNoContent();
    authHttp('GET', '/api/v1/me', token: $first)->assertUnauthorized();
    authHttp('POST', '/api/v1/auth/logout', token: $first)->assertUnauthorized();
    authHttp('GET', '/api/v1/me', token: $second)->assertOk();
});

it('sends a reset notification while keeping known unknown and throttled responses neutral', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'api@example.com']);
    $known = authHttp('POST', '/api/v1/auth/forgot-password', ['email' => ' API@EXAMPLE.COM '])->assertStatus(202)->json();
    $unknown = authHttp('POST', '/api/v1/auth/forgot-password', ['email' => 'missing@example.com'])->assertStatus(202)->json();
    $throttled = authHttp('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202)->json();
    expect($known)->toBe($unknown)->toBe($throttled)
        ->and(array_keys($known))->toBe(['message']);
    Notification::assertCount(1);
    Notification::assertSentToTimes($user, ResetPasswordToken::class, 1);
    Notification::assertSentTo($user, ResetPasswordToken::class, function ($notification) use ($user, $known) {
        $mail = $notification->toMail($user);
        $storedToken = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        expect($notification->via($user))->toBe(['mail'])
            ->and($mail->actionUrl)->toBeNull()
            ->and($mail->introLines)->toContain('`'.$notification->token.'`')
            ->and((string) $mail->render())->toContain($notification->token)
            ->and(json_encode($known))->not->toContain($notification->token)
            ->and($storedToken)->not->toBe($notification->token)
            ->and(Hash::check($notification->token, $storedToken))->toBeTrue()
            ->and(Password::tokenExists($user, $notification->token))->toBeTrue();

        return true;
    });
});

it('resets over the public API using the original broker token from the email notification', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => 'initial-password-123']);
    $bearer = loginToken($user);
    authHttp('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
    $notification = Notification::sent($user, ResetPasswordToken::class)->sole();
    $data = [
        'email' => $user->email,
        'token' => $notification->token,
        'password' => 'replacement-password-456',
        'password_confirmation' => 'replacement-password-456',
    ];

    authHttp('POST', '/api/v1/auth/reset-password', $data)->assertNoContent();
    authHttp('GET', '/api/v1/me', token: $bearer)->assertUnauthorized();
    $newToken = loginToken($user, $data['password']);
    authHttp('GET', '/api/v1/me', token: $newToken)->assertOk();
    authHttp('POST', '/api/v1/auth/reset-password', $data)->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('uses the configured default broker expiration in both the email and token verification', function () {
    Notification::fake();
    config([
        'auth.defaults.passwords' => 'api_recovery',
        'auth.passwords.api_recovery' => [...config('auth.passwords.users'), 'expire' => 17],
    ]);
    $user = User::factory()->create(['password' => 'initial-password-123']);
    authHttp('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
    $notification = Notification::sent($user, ResetPasswordToken::class)->sole();
    expect($notification->expiresInMinutes)->toBe(17)
        ->and((string) $notification->toMail($user)->render())->toContain('Токен діє 17 хвилин');
    $this->travel(18)->minutes();
    authHttp('POST', '/api/v1/auth/reset-password', [
        'email' => $user->email,
        'token' => $notification->token,
        'password' => 'replacement-password-456',
        'password_confirmation' => 'replacement-password-456',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
    expect(Hash::check('initial-password-123', $user->fresh()->password))->toBeTrue();
});

it('validates forgot password email', function () {
    Notification::fake();
    authHttp('POST', '/api/v1/auth/forgot-password', ['email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('email');
    Notification::assertNothingSent();
});

it('rejects invalid expired reused and mismatched reset tokens without changing the password', function (string $kind) {
    $user = User::factory()->create(['password' => 'initial-password-123']);
    $bearer = loginToken($user);
    $data = resetCredentials($user);
    if ($kind === 'invalid') {
        $data['token'] = 'incorrect';
    } elseif ($kind === 'expired') {
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1)]);
    } elseif ($kind === 'mismatched') {
        $data['email'] = User::factory()->create()->email;
    } else {
        authHttp('POST', '/api/v1/auth/reset-password', $data)->assertNoContent();
        $bearer = loginToken($user, $data['password']);
    }
    $previousHash = $user->fresh()->password;
    authHttp('POST', '/api/v1/auth/reset-password', $data)->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($user->fresh()->password)->toBe($previousHash);
    authHttp('GET', '/api/v1/me', token: $bearer)->assertOk();
})->with(['invalid', 'expired', 'reused', 'mismatched']);

it('replaces the password and invalidates only the affected user credentials', function (string $mode) {
    $user = User::factory()->create(['password' => 'initial-password-123', 'remember_token' => 'previous-remember-token']);
    $other = User::factory()->create(['password' => 'initial-password-123']);
    $first = loginToken($user);
    $second = loginToken($user);
    $otherToken = loginToken($other);
    foreach ([$user, $other] as $sessionUser) {
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $sessionUser->id, 'payload' => '', 'last_activity' => time()]);
    }
    $data = resetCredentials($user);
    if ($mode === 'reset') {
        authHttp('POST', '/api/v1/auth/reset-password', $data)->assertNoContent();
    } else {
        authHttp('PUT', '/api/v1/auth/password', [
            'current_password' => 'initial-password-123',
            'password' => $data['password'],
            'password_confirmation' => $data['password_confirmation'],
        ], $first)->assertNoContent();
    }
    expect(Hash::check($data['password'], $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->remember_token)->not->toBe('previous-remember-token')
        ->and(Password::tokenExists($user->fresh(), $data['token']))->toBeFalse();
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
    authHttp('GET', '/api/v1/me', token: $first)->assertUnauthorized();
    authHttp('GET', '/api/v1/me', token: $second)->assertUnauthorized();
    authHttp('GET', '/api/v1/me', token: $otherToken)->assertOk()->assertJsonPath('data.id', $other->id);
    authHttp('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'initial-password-123'])->assertUnauthorized();
    $newToken = loginToken($user, $data['password']);
    authHttp('GET', '/api/v1/me', token: $newToken)->assertOk();
})->with(['change', 'reset']);

it('validates the current password and new password without revoking tokens on failure', function (array $overrides, string $field) {
    $user = User::factory()->create(['password' => 'initial-password-123']);
    $token = loginToken($user);
    authHttp('PUT', '/api/v1/auth/password', array_replace([
        'current_password' => 'initial-password-123',
        'password' => 'replacement-password-456',
        'password_confirmation' => 'replacement-password-456',
    ], $overrides), $token)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Hash::check('initial-password-123', $user->fresh()->password))->toBeTrue();
    authHttp('GET', '/api/v1/me', token: $token)->assertOk();
})->with([
    'wrong current password' => [['current_password' => 'incorrect-password'], 'current_password'],
    'missing current password' => [['current_password' => null], 'current_password'],
    'short new password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'missing confirmation' => [['password_confirmation' => null], 'password'],
]);

it('validates reset password confirmation and length without consuming the reset token', function (array $overrides) {
    $user = User::factory()->create();
    $data = resetCredentials($user);
    authHttp('POST', '/api/v1/auth/reset-password', array_replace($data, $overrides))
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    expect(Password::tokenExists($user, $data['token']))->toBeTrue();
})->with([
    [['password' => 'short', 'password_confirmation' => 'short']],
    [['password_confirmation' => null]],
]);

it('limits login by normalized email plus IP and releases it after Retry-After', function () {
    for ($i = 0; $i < 5; $i++) {
        authHttp('POST', '/api/v1/auth/login', ['email' => $i % 2 ? ' API@Example.COM ' : 'api@example.com', 'password' => 'incorrect'])->assertUnauthorized();
    }
    $response = authHttp('POST', '/api/v1/auth/login', ['email' => 'api@example.com', 'password' => 'incorrect'])
        ->assertStatus(429)->assertHeader('Retry-After');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
    $this->travel(61)->seconds();
    authHttp('POST', '/api/v1/auth/login', ['email' => 'api@example.com', 'password' => 'incorrect'])->assertUnauthorized();
});

it('limits login attempts across different emails from one IP', function () {
    for ($i = 0; $i < 30; $i++) {
        authHttp('POST', '/api/v1/auth/login', ['email' => 'user'.$i.'@example.com', 'password' => 'incorrect'])->assertUnauthorized();
    }
    authHttp('POST', '/api/v1/auth/login', ['email' => 'another@example.com', 'password' => 'incorrect'])
        ->assertStatus(429)->assertHeader('Retry-After');
});

it('limits registration and recovery requests', function (string $path) {
    for ($i = 0; $i < 5; $i++) {
        authHttp('POST', $path)->assertUnprocessable();
    }
    authHttp('POST', $path)->assertStatus(429)->assertHeader('Retry-After');
})->with(['/api/v1/auth/register', '/api/v1/auth/forgot-password', '/api/v1/auth/reset-password']);

it('does not expose public web reset routes', function (string $method, string $path) {
    expect(Route::has('password.reset'))->toBeFalse()
        ->and(Route::has('password.update'))->toBeFalse()
        ->and(Route::has('password.reset.success'))->toBeFalse();
    $this->call($method, $path)->assertNotFound();
})->with([
    ['GET', '/reset-password/example-token'],
    ['GET', '/reset-password/success'],
    ['GET', '/reset-password'],
    ['POST', '/reset-password'],
]);

function adminSession(User $user): string
{
    $id = Str::random(40);
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'payload' => base64_encode(json_encode([
            Auth::guard('web')->getName() => $user->id,
            'password_hash_web' => $user->password,
            '_token' => Str::random(40),
        ])),
        'last_activity' => time(),
    ]);

    return $id;
}

function panelWithSession(string $session): TestResponse
{
    Auth::forgetGuards();
    Auth::shouldUse('web');
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    return test()->withCookie(config('session.cookie'), $session)->get('/admin');
}

it('invalidates existing Filament session cookies after password replacement while preserving another admin session', function (string $mode) {
    config(['session.driver' => 'database']);
    $admin = User::factory()->create(['password' => 'initial-password-123'])->assignRole('admin');
    $other = User::factory()->create()->assignRole('admin');
    $session = adminSession($admin);
    $otherSession = adminSession($other);
    panelWithSession($session)->assertOk();
    panelWithSession($otherSession)->assertOk();

    $data = resetCredentials($admin);
    if ($mode === 'reset') {
        authHttp('POST', '/api/v1/auth/reset-password', $data)->assertNoContent();
    } else {
        $token = loginToken($admin);
        authHttp('PUT', '/api/v1/auth/password', [
            'current_password' => 'initial-password-123',
            'password' => $data['password'],
            'password_confirmation' => $data['password_confirmation'],
        ], $token)->assertNoContent();
    }

    panelWithSession($session)->assertRedirect('/admin/login');
    panelWithSession($otherSession)->assertOk();
})->with(['change', 'reset']);

it('rolls back password and credential revocation on a transaction failure', function (string $mode) {
    $user = User::factory()->create(['password' => 'initial-password-123', 'remember_token' => 'original']);
    $token = loginToken($user);
    $data = resetCredentials($user);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $this->mock(ReplacePassword::class)->shouldReceive('handle')->once()->andReturnUsing(function (User $user, string $password) {
        (new ReplacePassword)->handle($user, $password);
        throw new RuntimeException('Simulated failure after credential revocation.');
    });
    if ($mode === 'reset') {
        authHttp('POST', '/api/v1/auth/reset-password', $data)->assertStatus(500);
    } else {
        authHttp('PUT', '/api/v1/auth/password', [
            'current_password' => 'initial-password-123',
            'password' => $data['password'],
            'password_confirmation' => $data['password_confirmation'],
        ], $token)->assertStatus(500);
    }
    expect(Hash::check('initial-password-123', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->remember_token)->toBe('original')
        ->and(Password::tokenExists($user, $data['token']))->toBeTrue();
    $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);
    authHttp('GET', '/api/v1/me', token: $token)->assertOk();
})->with(['change', 'reset']);

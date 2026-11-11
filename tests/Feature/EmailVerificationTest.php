<?php

use App\Actions\Auth\IssueApiToken;
use App\Actions\Auth\SendEmailVerificationCode;
use App\Actions\Auth\VerifyEmailCode as VerifyEmailCodeAction;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ResetPasswordToken;
use App\Notifications\VerifyEmail as LegacyVerifyEmail;
use App\Notifications\VerifyEmailCode;
use App\Support\EmailVerificationCode;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

beforeEach(function () {
    /** @var TestCase $this */
    $this->seed(RoleSeeder::class);
});

function emailCodeRegistration(): array
{
    return [
        'name' => 'Verification User',
        'email' => 'verify@example.com',
        'password' => 'initial-password-123',
        'password_confirmation' => 'initial-password-123',
    ];
}

function userWithEmailCode(string $code = '048219', array $attributes = []): User
{
    $user = User::factory()->unverified()->create($attributes);
    $user->forceFill([
        'email_verification_code_hash' => EmailVerificationCode::hash($user->email, $code),
        'email_verification_code_expires_at' => now()->addMinutes(10),
        'email_verification_code_attempts' => 0,
    ])->save();

    return $user;
}

function processVerificationMail(): void
{
    while ($job = Queue::connection('database')->pop('mail')) {
        $job->fire();
        $job->delete();
    }
}

function verificationMailTransport(): ArrayTransport
{
    $mailer = Mail::mailer('array');

    if (! $mailer instanceof Mailer) {
        throw new UnexpectedValueException('Expected a concrete Laravel mailer.');
    }

    $transport = $mailer->getSymfonyTransport();

    if (! $transport instanceof ArrayTransport) {
        throw new UnexpectedValueException('Expected the array mail transport.');
    }

    return $transport;
}

it('registers an unverified user and queues exactly one encrypted code without trusting service fields', function () {
    Queue::fake();
    Mail::fake();
    $response = authHttp('POST', '/api/v1/auth/register', array_replace(emailCodeRegistration(), [
        'email' => ' VERIFY@EXAMPLE.COM ',
        'email_verified_at' => now(),
        'email_verification_code_hash' => 'injected',
        'email_verification_code_expires_at' => now()->addYear(),
        'email_verification_code_attempts' => 0,
        'role' => 'admin',
    ]))->assertCreated()->assertJsonPath('user.email_verified_at', null)
        ->assertJsonPath('token_type', 'Bearer')->assertJsonStructure(['user', 'token', 'expires_at']);
    $user = User::sole();
    expect($user->getRoleNames()->all())->toBe(['user'])
        ->and($user->email)->toBe('verify@example.com')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->email_verification_code_hash)->not->toBe('injected')
        ->and($user->isFillable('email_verification_code_hash'))->toBeFalse()
        ->and($user->isFillable('email_verification_code_expires_at'))->toBeFalse()
        ->and($user->isFillable('email_verification_code_attempts'))->toBeFalse();
    foreach (['email_verification_code_hash', 'email_verification_code_expires_at', 'email_verification_code_attempts'] as $field) {
        expect($user->toArray())->not->toHaveKey($field);
        expect($response->json('user'))->not->toHaveKey($field);
    }
    Queue::assertPushedOn('mail', SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($user, $response) {
        $notification = $job->notification;

        if (! $notification instanceof VerifyEmailCode) {
            return false;
        }

        expect($notification)->toBeInstanceOf(VerifyEmailCode::class)
            ->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeEncrypted::class)
            ->and($job->shouldBeEncrypted)->toBeTrue()
            ->and($job->afterCommit)->toBeTrue()
            ->and($job->channels)->toBe(['mail'])
            ->and($job->notifiables->sole()->is($user))->toBeTrue()
            ->and($notification->code)->toMatch('/\A[0-9]{6}\z/')
            ->and($notification->expiresInMinutes)->toBe(10)
            ->and($notification->expiresAt->timestamp)->toBe($user->email_verification_code_expires_at->timestamp)
            ->and(EmailVerificationCode::matches($user->email, $notification->code, $user->email_verification_code_hash))->toBeTrue()
            ->and($response->getContent())->not->toContain($notification->code, $user->email_verification_code_hash);

        return true;
    });
    Queue::assertPushed(SendQueuedNotifications::class, 1);
    Mail::assertNothingSent();
});

it('publishes encrypted code mail after commit and discards code and job on rollback', function (bool $commit) {
    config(['queue.default' => 'database']);
    Mail::fake();
    DB::beginTransaction();
    authHttp('POST', '/api/v1/auth/register', emailCodeRegistration())->assertCreated();
    expect(DB::table('jobs')->count())->toBe(0);
    if (! $commit) {
        DB::rollBack();
        expect(DB::table('jobs')->count())->toBe(0)->and(User::count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->count())->toBe(0);

        return;
    }
    DB::commit();
    $record = DB::table('jobs')->sole();
    $payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
    $job = unserialize(Crypt::decrypt($payload['data']['command']));

    if (! $job instanceof SendQueuedNotifications || ! $job->notification instanceof VerifyEmailCode) {
        throw new UnexpectedValueException('Expected an email verification notification job.');
    }

    expect($record->queue)->toBe('mail')
        ->and($job)->toBeInstanceOf(SendQueuedNotifications::class)
        ->and($job->notification)->toBeInstanceOf(VerifyEmailCode::class)
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->shouldBeEncrypted)->toBeTrue()
        ->and($record->payload)->not->toContain($job->notification->code, $job->notification->email);
    Mail::assertNothingSent();
})->with(['commit' => true, 'rollback' => false]);

it('does not register or queue a code if issuing the token fails', function () {
    Queue::fake();
    Event::fake([Registered::class]);
    /** @var TestCase $this */
    $this->mock(IssueApiToken::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Token issuance failed.'));
    authHttp('POST', '/api/v1/auth/register', emailCodeRegistration())->assertStatus(500);
    Event::assertNotDispatched(Registered::class);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('users', 0);
});

it('delivers a human readable code and confirms through the public API without a bearer token', function () {
    config(['queue.default' => 'database', 'mail.default' => 'array']);
    $response = authHttp('POST', '/api/v1/auth/register', emailCodeRegistration())->assertCreated();
    $token = $response->json('token');
    $transport = verificationMailTransport();
    expect($transport->messages())->toHaveCount(0);
    authHttp('GET', '/api/v1/categories', token: $token)->assertForbidden();
    processVerificationMail();
    $mail = $transport->messages()->sole()->getOriginalMessage();

    if (! $mail instanceof Email) {
        throw new UnexpectedValueException('Expected an email message.');
    }

    $body = $mail->getTextBody();
    expect($mail->getTo()[0]->getAddress())->toBe('verify@example.com')
        ->and($body)->toContain('verify@example.com', 'Введіть цей код у формі підтвердження email.', '10 хвилин')
        ->not->toContain($token, 'Bearer', 'Authorization', '/api/', 'signature', 'GET', 'POST')
        ->and($mail->getHtmlBody())->toContain('<strong')
        ->and(DB::table('jobs')->count())->toBe(0);
    expect(preg_match('/\b[0-9]{6}\b/', $body, $matches))->toBe(1);
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => ' VERIFY@EXAMPLE.COM ', 'code' => $matches[0]])
        ->assertOk()->assertExactJson(['verified' => true, 'message' => 'Email підтверджено.']);
    authHttp('GET', '/api/v1/categories', token: $token)->assertOk();
});

it('consumes a leading zero code exactly once and dispatches Verified after commit', function () {
    Event::fake([Verified::class]);
    $user = userWithEmailCode();
    $token = $user->createToken('status')->plainTextToken;
    authHttp('GET', '/api/v1/auth/email/verify', token: $token)->assertOk()->assertJsonPath('verified', false);
    DB::beginTransaction();
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => '048219'])->assertOk();
    Event::assertNotDispatched(Verified::class);
    DB::commit();
    Event::assertDispatchedTimes(Verified::class, 1);
    $verifiedAt = $user->fresh()->email_verified_at->toISOString();
    authHttp('GET', '/api/v1/auth/email/verify', token: $token)->assertOk()->assertJsonPath('verified', true);
    expect($user->fresh()->email_verification_code_hash)->toBeNull()
        ->and($user->fresh()->email_verification_code_expires_at)->toBeNull();
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => '048219'])->assertUnprocessable();
    expect($user->fresh()->email_verified_at->toISOString())->toBe($verifiedAt);
    Event::assertDispatchedTimes(Verified::class, 1);
});

it('restores a consumed code and discards the Verified event on rollback', function () {
    Event::fake([Verified::class]);
    $user = userWithEmailCode();
    $hash = $user->email_verification_code_hash;
    DB::beginTransaction();
    app(VerifyEmailCodeAction::class)->handle($user->email, '048219');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    DB::rollBack();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($user->fresh()->email_verification_code_hash)->toBe($hash);
    Event::assertNotDispatched(Verified::class);
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => '048219'])->assertOk();
    Event::assertDispatchedTimes(Verified::class, 1);
});

it('restores the previous code when issuing its replacement rolls back', function () {
    config(['queue.default' => 'database']);
    $user = userWithEmailCode();
    $hash = $user->email_verification_code_hash;
    DB::beginTransaction();
    app(SendEmailVerificationCode::class)->handle($user);
    expect($user->fresh()->email_verification_code_hash)->not->toBe($hash);
    DB::rollBack();
    expect($user->fresh()->email_verification_code_hash)->toBe($hash)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('returns identical errors for unknown incorrect expired consumed missing and blocked codes', function (string $kind) {
    /** @var TestCase $this */
    Event::fake([Verified::class]);
    $user = userWithEmailCode();
    $email = $user->email;
    if ($kind === 'unknown') {
        $email = 'missing@example.com';
    } elseif ($kind === 'incorrect') {
        $user->forceFill(['email_verification_code_hash' => EmailVerificationCode::hash($email, '999999')])->save();
    } elseif ($kind === 'expired') {
        $this->travel(10)->minutes();
    } elseif ($kind === 'consumed') {
        $user->markEmailAsVerified();
    } elseif ($kind === 'missing') {
        $user->forceFill(['email_verification_code_hash' => null])->save();
    } else {
        $user->forceFill(['email_verification_code_attempts' => 5])->save();
    }
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $email, 'code' => '048219'])->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Код недійсний або строк його дії минув. Запросіть новий',
            'errors' => ['code' => ['Код недійсний або строк його дії минув. Запросіть новий']],
        ]);
    Event::assertNotDispatched(Verified::class);
})->with(['unknown', 'incorrect', 'expired', 'consumed', 'missing', 'blocked']);

it('validates code strings without converting leading zeroes or accepting non ASCII digits', function (mixed $code) {
    $user = userWithEmailCode();
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($user->fresh()->email_verification_code_attempts)->toBe(0);
})->with([null, 48219, '48219', '0482190', 'abcdef', [['048219']], '０４８２１９', '٠٤٨٢١٩']);

it('binds codes to email even when addresses exceed the bcrypt input limit', function () {
    $email = str_repeat('a', 60).'@'.str_repeat('b', 40).'.example.com';
    $user = userWithEmailCode(attributes: ['email' => $email]);
    expect(EmailVerificationCode::matches($email, '048219', $user->email_verification_code_hash))->toBeTrue()
        ->and(EmailVerificationCode::matches($email, '999999', $user->email_verification_code_hash))->toBeFalse();
    $user->update(['email' => 'changed@example.com']);
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => '048219'])->assertUnprocessable();
});

it('persists five failed attempts despite validation errors and resets the block only with a new code', function () {
    Notification::fake();
    $user = userWithEmailCode();
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => '999999'])->assertUnprocessable();
        expect($user->fresh()->email_verification_code_attempts)->toBe($attempt);
    }
    /** @var TestCase $this */
    $this->travel(61)->seconds();
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => '048219'])->assertUnprocessable();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    $user->sendEmailVerificationNotification();
    $code = Notification::sent($user, VerifyEmailCode::class)->sole()->code;
    expect($user->fresh()->email_verification_code_attempts)->toBe(0)->and($code)->not->toBe('048219');
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => $code])->assertOk();
});

it('resends a new code once per minute across tokens and skips already verified users', function () {
    Notification::fake();
    /** @var TestCase $this */
    $this->freezeTime();
    config(['auth.verification.expire' => 17]);
    $user = userWithEmailCode();
    $first = $user->createToken('first')->plainTextToken;
    $second = $user->createToken('second')->plainTextToken;
    authHttp('POST', '/api/v1/auth/email/verification-notification', token: $first)->assertStatus(202);
    $notification = Notification::sent($user, VerifyEmailCode::class)->sole();
    expect($notification->code)->not->toBe('048219')
        ->and($notification->expiresInMinutes)->toBe(17)
        ->and($notification->expiresAt->timestamp)->toBe(now()->addMinutes(17)->timestamp);
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => '048219'])->assertUnprocessable();
    authHttp('POST', '/api/v1/auth/email/verification-notification', token: $second)->assertTooManyRequests()->assertHeader('Retry-After');
    $this->travel(61)->seconds();
    authHttp('POST', '/api/v1/auth/email/verification-notification', token: $second)->assertStatus(202);
    $next = Notification::sent($user, VerifyEmailCode::class)->last();
    expect($next->code)->not->toBe($notification->code);
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => $notification->code])->assertUnprocessable();
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => $next->code])->assertOk();
    authHttp('POST', '/api/v1/auth/email/verification-notification', token: $first)->assertOk();
    authHttp('POST', '/api/v1/auth/email/verification-notification', token: $second)->assertOk();
    Notification::assertSentToTimes($user, VerifyEmailCode::class, 2);
});

it('does not deliver stale verification jobs or extend their expiry in the worker', function (string $kind) {
    config(['queue.default' => 'database', 'mail.default' => 'array']);
    /** @var TestCase $this */
    $this->freezeTime();
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();
    if ($kind === 'expired') {
        $this->travel(10)->minutes();
    } elseif ($kind === 'replaced') {
        $user->sendEmailVerificationNotification();
    } elseif ($kind === 'verified') {
        $user->markEmailAsVerified();
    } elseif ($kind === 'blocked') {
        $user->forceFill(['email_verification_code_attempts' => 5])->save();
    } else {
        $user->update(['email' => 'changed@example.com']);
    }
    processVerificationMail();
    expect(verificationMailTransport()->messages())->toHaveCount($kind === 'replaced' ? 1 : 0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['expired', 'replaced', 'verified', 'blocked', 'changed email']);

it('deserializes and skips legacy encrypted link jobs while delivering unrelated reset mail', function () {
    config(['queue.default' => 'database', 'mail.default' => 'array']);
    $user = User::factory()->unverified()->create();
    $user->notify(unserialize(serialize(new LegacyVerifyEmail)));
    $user->notify(new ResetPasswordToken('fixture-reset-token', 60));
    processVerificationMail();
    $messages = verificationMailTransport()->messages();
    $mail = $messages->sole()->getOriginalMessage();

    if (! $mail instanceof Email) {
        throw new UnexpectedValueException('Expected an email message.');
    }

    expect($messages)->toHaveCount(1)
        ->and($mail->getSubject())->toContain('Скидання пароля')
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('throttles normalized email independently of IP', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        authHttp('POST', '/api/v1/auth/email/verify', ['email' => 'missing@example.com', 'code' => '048219'])->assertUnprocessable();
    }
    /** @var TestCase $this */
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2']);
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => ' MISSING@EXAMPLE.COM ', 'code' => '048219'])
        ->assertTooManyRequests()->assertHeader('Retry-After');
});

it('throttles the IP across different emails', function () {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        authHttp('POST', '/api/v1/auth/email/verify', ['email' => 'missing'.$attempt.'@example.com', 'code' => '048219'])->assertUnprocessable();
    }
    authHttp('POST', '/api/v1/auth/email/verify', ['email' => 'another@example.com', 'code' => '048219'])
        ->assertTooManyRequests()->assertHeader('Retry-After');
});

it('requires bearer authentication only for status and resend even with an admin session', function (string $method, string $path) {
    /** @var TestCase $this */
    $admin = User::factory()->unverified()->create()->assignRole('admin');
    $uri = '/api/v1/auth/email/'.$path;
    $this->call($method, $uri)->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    $this->actingAs($admin, 'web')->call($method, $uri)->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
})->with([['GET', 'verify'], ['POST', 'verification-notification']]);

it('returns JSON on the public endpoint without Accept and removes the old signed route', function () {
    /** @var TestCase $this */
    $user = userWithEmailCode();
    $this->call('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => '048219'])->assertOk()->assertHeader('Content-Type', 'application/json');
    authHttp('GET', '/api/v1/auth/email/verify/'.$user->id.'/'.sha1($user->email))->assertNotFound();
});

it('blocks every financial operation until verification and then grants access with the same token', function () {
    $user = userWithEmailCode()->assignRole('user');
    $token = $user->createToken('verification')->plainTextToken;
    $transaction = Transaction::factory()->for($user)->create();
    $routes = [
        ['GET', 'categories'],
        ['GET', 'transactions'],
        ['POST', 'transactions'],
        ['GET', 'transactions/'.$transaction->id],
        ['PATCH', 'transactions/'.$transaction->id],
        ['DELETE', 'transactions/'.$transaction->id],
        ['GET', 'budgets/2026-11'],
        ['PUT', 'budgets/2026-11'],
        ['GET', 'budgets/2026-11/summary'],
    ];
    foreach ($routes as [$method, $path]) {
        authHttp($method, '/api/v1/'.$path)->assertUnauthorized();
        authHttp($method, '/api/v1/'.$path, token: $token)->assertForbidden()
            ->assertJsonPath('message', 'Підтвердьте email для доступу до фінансових операцій.');
    }

    authHttp('POST', '/api/v1/auth/email/verify', ['email' => $user->email, 'code' => '048219'])->assertOk();
    authHttp('GET', '/api/v1/categories', token: $token)->assertOk();
    authHttp('GET', '/api/v1/transactions', token: $token)->assertOk();
    authHttp('PUT', '/api/v1/budgets/2026-11', ['items' => []], $token)->assertCreated();
    authHttp('GET', '/api/v1/budgets/2026-11', token: $token)->assertOk();
    authHttp('GET', '/api/v1/budgets/2026-11/summary', token: $token)->assertOk();
});

it('returns JSON 403 for unverified financial requests without Accept before route binding', function () {
    $user = User::factory()->unverified()->create()->assignRole('user');
    $token = $user->createToken('verification')->plainTextToken;

    /** @var TestCase $this */
    $this->withHeader('Authorization', 'Bearer '.$token)->get('/api/v1/transactions/99999999')
        ->assertForbidden()->assertHeader('Content-Type', 'application/json');
});

it('allows unverified users to log in inspect themselves log out and change their password', function () {
    $user = User::factory()->unverified()->create(['password' => 'initial-password-123']);
    $first = loginToken($user);
    authHttp('GET', '/api/v1/me', token: $first)->assertOk()->assertJsonPath('data.email_verified_at', null);
    authHttp('POST', '/api/v1/auth/logout', token: $first)->assertNoContent();
    $second = loginToken($user);
    authHttp('PUT', '/api/v1/auth/password', [
        'current_password' => 'initial-password-123',
        'password' => 'replacement-password-456',
        'password_confirmation' => 'replacement-password-456',
    ], $second)->assertNoContent();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    loginToken($user, 'replacement-password-456');
});

it('preserves the verification status when resetting a password through the public API', function (bool $verified) {
    Notification::fake();
    $user = User::factory()->create(['email_verified_at' => $verified ? now()->subDay() : null]);
    $verifiedAt = $user->email_verified_at?->toISOString();
    authHttp('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
    authHttp('POST', '/api/v1/auth/reset-password', [
        'email' => $user->email,
        'token' => Password::createToken($user),
        'password' => 'replacement-password-456',
        'password_confirmation' => 'replacement-password-456',
    ])->assertNoContent();

    expect($user->fresh()->email_verified_at?->toISOString())->toBe($verifiedAt);
})->with(['verified' => true, 'unverified' => false]);

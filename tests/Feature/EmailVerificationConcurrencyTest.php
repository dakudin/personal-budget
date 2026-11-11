<?php

namespace Tests\Feature;

use App\Actions\Auth\VerifyEmailCode;
use App\Models\User;
use App\Support\EmailVerificationCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class EmailVerificationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_requests_consume_the_code_and_dispatch_verified_only_once(): void
    {
        // Committed fixtures must be visible to the two independent MySQL connections.
        $user = User::factory()->unverified()->create();
        $code = '048219';
        $user->forceFill([
            'email_verification_code_hash' => EmailVerificationCode::hash($user->email, $code),
            'email_verification_code_expires_at' => now()->addMinutes(10),
        ])->save();
        $email = $user->email;
        $connection = config('database.connections.mysql');
        $startAt = microtime(true) + 2;

        $verify = static function () use ($connection, $email, $code, $startAt): array {
            if ($connection['database'] !== 'personal_budget_testing' || ! empty($connection['url'])) {
                throw new LogicException('Concurrency tests require the isolated testing database.');
            }

            config(['database.default' => 'mysql', 'database.connections.mysql' => $connection]);
            DB::purge('mysql');
            $events = 0;
            Event::listen(Verified::class, function () use (&$events): void {
                $events++;
            });
            usleep((int) max(0, ($startAt - microtime(true)) * 1000000));

            try {
                app(VerifyEmailCode::class)->handle($email, $code);

                return ['status' => 200, 'events' => $events];
            } catch (ValidationException) {
                return ['status' => 422, 'events' => $events];
            }
        };

        $results = Concurrency::driver('process')->run([$verify, $verify], timeout: 15);
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame([200, 422], $statuses);
        $this->assertSame(1, array_sum(array_column($results, 'events')));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertNull($user->fresh()->email_verification_code_hash);
    }
}

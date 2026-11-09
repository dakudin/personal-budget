<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('uses the isolated MySQL test database', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::selectOne('select database() as name')->name)->toBe('personal_budget_testing');
});

it('serves the home page and health endpoint', function () {
    $this->get('/')->assertOk();
    $this->get('/up')->assertOk();
});

it('redirects guests to admin login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertOk();
});

it('does not expose public registration', function () {
    $this->get('/admin/register')->assertNotFound();
    $this->post('/admin/register')->assertNotFound();
    $this->get('/register')->assertNotFound();
    expect(Filament::getPanel('admin')->hasRegistration())->toBeFalse();
});

it('forbids panel access without the admin role', function (bool $assignUserRole) {
    $user = User::factory()->create();

    if ($assignUserRole) {
        $user->assignRole('user');
    }

    $this->actingAs($user)->get('/admin')->assertForbidden();
})->with([true, false]);

it('allows admins to access the panel', function () {
    $admin = User::factory()->create()->assignRole('admin');

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('rejects a user role at login', function () {
    $user = User::factory()->create()->assignRole('user');

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('authenticates an admin', function () {
    $admin = User::factory()->create()->assignRole('admin');

    Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect('/admin');

    $this->assertAuthenticatedAs($admin);
});

it('seeds roles repeatedly without duplicate roles or demo accounts', function () {
    $this->seed();
    $this->seed();

    $this->assertDatabaseCount('roles', 2);
    $this->assertDatabaseCount('users', 0);
});

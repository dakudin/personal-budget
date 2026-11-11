<?php

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\BudgetItem;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

beforeEach(function () {
    /** @var TestCase $this */
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->actingAs($this->admin);
});

it('creates edits deactivates reactivates and deletes unused categories in Filament', function () {
    /** @var TestCase $this */
    Livewire::test(ManageCategories::class)
        ->callAction('create', data: ['name' => 'Нова категорія', 'is_active' => true])
        ->assertHasNoFormErrors();
    $category = Category::where('name', 'Нова категорія')->sole();
    Livewire::test(ManageCategories::class)
        ->callAction(TestAction::make('edit')->table($category), data: ['name' => 'Оновлена категорія', 'is_active' => true])
        ->assertHasNoFormErrors();
    expect($category->fresh()->name)->toBe('Оновлена категорія');
    Livewire::test(ManageCategories::class)->callAction(TestAction::make('deactivate')->table($category));
    expect($category->fresh()->is_active)->toBeFalse();
    Livewire::test(ManageCategories::class)->callAction(TestAction::make('activate')->table($category));
    expect($category->fresh()->is_active)->toBeTrue();
    Livewire::test(ManageCategories::class)->callAction(TestAction::make('delete')->table($category));
    $this->assertModelMissing($category);
});

it('searches categories and filters their activity in Filament', function () {
    $active = Category::factory()->create(['name' => 'Продукти']);
    $inactive = Category::factory()->inactive()->create(['name' => 'Транспорт']);
    Livewire::test(ManageCategories::class)->assertCanSeeTableRecords([$active, $inactive])
        ->searchTable('Продукти')->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$inactive]);
    Livewire::test(ManageCategories::class)->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$inactive]);
    Livewire::test(ManageCategories::class)->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active]);
});

it('validates names and uniqueness in Filament', function (string $name) {
    Category::factory()->create(['name' => 'Продукти']);
    Livewire::test(ManageCategories::class)->callAction('create', data: ['name' => $name, 'is_active' => true])->assertHasFormErrors(['name']);
    $category = Category::factory()->create();
    Livewire::test(ManageCategories::class)->callAction(TestAction::make('edit')->table($category), data: ['name' => $name, 'is_active' => true])->assertHasFormErrors(['name']);
})->with(['', 'Продукти', str_repeat('x', 101)]);

it('explains why used categories cannot be deleted and allows deactivation', function () {
    /** @var TestCase $this */
    $transaction = Transaction::factory()->expense()->create();
    $category = $transaction->category;
    expect(Gate::inspect('delete', $category)->denied())->toBeTrue()
        ->and(Gate::inspect('delete', $category)->message())->toContain('Деактивуйте');
    Livewire::test(ManageCategories::class)->assertSee('Замість видалення деактивуйте')
        ->assertActionDisabled(TestAction::make('delete')->table($category))
        ->callAction(TestAction::make('delete')->table($category));
    $this->assertModelExists($category);
    Livewire::test(ManageCategories::class)->callAction(TestAction::make('deactivate')->table($category));
    expect($category->fresh()->is_active)->toBeFalse()
        ->and($transaction->fresh()->category->name)->toBe($category->name);
});

it('restricts deletion of referenced categories at the database level', function () {
    /** @var TestCase $this */
    $transaction = Transaction::factory()->expense()->create();
    expect(fn () => DB::table('categories')->where('id', $transaction->category_id)->delete())->toThrow(QueryException::class);
    $this->assertModelExists($transaction->category);
});

it('protects categories referenced only by budgets while allowing deactivation', function () {
    $item = BudgetItem::factory()->create();
    $category = $item->category;
    expect($category->transactions()->exists())->toBeFalse()
        ->and($category->isUsed())->toBeTrue()
        ->and(Gate::inspect('delete', $category)->denied())->toBeTrue()
        ->and(Gate::inspect('delete', $category)->message())->toContain('бюджетах');
    Livewire::test(ManageCategories::class)->assertSee('Замість видалення деактивуйте')
        ->assertActionDisabled(TestAction::make('delete')->table($category))
        ->callAction(TestAction::make('delete')->table($category));
    /** @var TestCase $this */
    $this->assertModelExists($category);
    expect(fn () => DB::table('categories')->where('id', $category->id)->delete())->toThrow(QueryException::class);
    Livewire::test(ManageCategories::class)->callAction(TestAction::make('deactivate')->table($category));
    expect($category->fresh()->is_active)->toBeFalse()
        ->and($item->fresh()->category->name)->toBe($category->name);
});

it('denies category actions invoked directly without manage permission', function (string $action) {
    /** @var TestCase $this */
    $this->admin->roles()->first()->revokePermissionTo('categories.manage');
    $category = Category::factory()->create(['is_active' => $action !== 'activate']);
    $component = Livewire::test(ManageCategories::class);
    $testAction = $action === 'create' ? 'create' : TestAction::make($action)->table($category);
    $component->assertActionHidden($testAction)
        ->call('mountAction', $action, [], $action === 'create' ? [] : ['table' => true, 'recordKey' => $category->id])
        ->call('callMountedAction');
    expect($category->fresh()->name)->toBe($category->name)
        ->and($category->fresh()->is_active)->toBe($category->is_active);
    $this->assertDatabaseCount('categories', 1);
})->with(['create', 'edit', 'activate', 'deactivate', 'delete']);

it('rejects access to the resource without categories view permission', function () {
    /** @var TestCase $this */
    $this->admin->roles()->first()->revokePermissionTo('categories.view');
    $this->get('/admin/categories')->assertForbidden();
    Livewire::test(ManageCategories::class)->assertForbidden();
});

it('does not allow a regular user to manage categories', function () {
    /** @var TestCase $this */
    $user = User::factory()->create()->assignRole('user');
    $category = Category::factory()->create();
    expect(Gate::forUser($user)->allows('viewAny', Category::class))->toBeTrue();
    foreach (['create', 'update', 'delete'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, $ability === 'create' ? Category::class : $category))->toBeFalse();
    }
    $this->actingAs($user)->get('/admin/categories')->assertForbidden();
});

it('seeds categories and permissions idempotently preserving administrative choices', function () {
    /** @var TestCase $this */
    $this->seed();
    $category = Category::where('name', 'Продукти')->sole();
    $category->update(['is_active' => false]);
    $custom = Permission::findOrCreate('custom.permission', 'web');
    Role::findByName('admin', 'web')->givePermissionTo($custom);
    $this->admin->givePermissionTo($custom);
    $this->seed();
    $this->seed(CategorySeeder::class);
    $this->assertDatabaseCount('categories', 6);
    $this->assertDatabaseCount('roles', 2);
    $this->assertDatabaseCount('permissions', 9);
    expect($category->fresh()->is_active)->toBeFalse()
        ->and($this->admin->fresh()->hasDirectPermission('custom.permission'))->toBeTrue()
        ->and(Role::findByName('admin', 'web')->hasPermissionTo('custom.permission'))->toBeTrue()
        ->and(Role::findByName('user', 'web')->hasPermissionTo('categories.manage'))->toBeFalse();
});

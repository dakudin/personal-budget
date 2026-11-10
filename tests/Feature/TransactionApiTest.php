<?php

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    /** @var \Tests\TestCase $this */
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('user');
    $this->bearer = $this->owner->createToken('m3-test')->plainTextToken;
});

function transactionData(array $overrides = []): array
{
    return array_replace([
        'type' => 'income',
        'amount_minor' => 12345,
        'occurred_on' => '2026-11-10',
        'description' => 'Test transaction',
    ], $overrides);
}

it('creates reads updates and deletes own income and expense with bearer authentication', function (string $type) {
    $category = Category::factory()->create();
    $response = authHttp('POST', '/api/v1/transactions', transactionData([
        'type' => $type,
        'category_id' => $type === 'expense' ? $category->id : null,
        'user_id' => User::factory()->create()->id,
    ]), $this->bearer)->assertCreated()->assertJsonPath('data.currency', 'UAH')
        ->assertJsonPath('data.amount_minor', 12345)->assertJsonPath('data.occurred_on', '2026-11-10');
    $id = $response->json('data.id');
    expect(Transaction::find($id)->user_id)->toBe($this->owner->id)
        ->and(Transaction::find($id)->type)->toBe(TransactionType::from($type));
    authHttp('GET', '/api/v1/transactions/'.$id, token: $this->bearer)->assertOk()->assertJsonPath('data.type', $type);
    authHttp('PATCH', '/api/v1/transactions/'.$id, ['amount_minor' => Transaction::MAX_AMOUNT_MINOR, 'description' => null, 'user_id' => 999999], $this->bearer)
        ->assertOk()->assertJsonPath('data.amount_minor', Transaction::MAX_AMOUNT_MINOR)->assertJsonPath('data.description', null);
    expect(Transaction::find($id)->user_id)->toBe($this->owner->id);
    authHttp('DELETE', '/api/v1/transactions/'.$id, token: $this->bearer)->assertNoContent();
    authHttp('GET', '/api/v1/transactions/'.$id, token: $this->bearer)->assertNotFound();
})->with(['income', 'expense']);

it('hides foreign records from users and admins for every endpoint', function (string $role) {
    $this->owner->syncRoles($role);
    $foreign = Transaction::factory()->expense()->create();
    $own = Transaction::factory()->for($this->owner)->create();
    authHttp('GET', '/api/v1/transactions?category_id='.$foreign->category_id, token: $this->bearer)
        ->assertOk()->assertJsonCount(0, 'data');
    authHttp('GET', '/api/v1/transactions', token: $this->bearer)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    foreach (['GET', 'PATCH', 'DELETE'] as $method) {
        authHttp($method, '/api/v1/transactions/'.$foreign->id, ['amount_minor' => 100], $this->bearer)->assertNotFound();
        authHttp($method, '/api/v1/transactions/99999999', token: $this->bearer)->assertNotFound();
    }
    foreach (['view', 'update', 'delete'] as $ability) {
        expect(Gate::forUser($this->owner)->allows($ability, $foreign))->toBeFalse();
    }
    expect($foreign->fresh()->amount_minor)->toBe($foreign->amount_minor);
})->with(['user', 'admin']);

it('requires bearer tokens on every financial endpoint', function () {
    $transaction = Transaction::factory()->for($this->owner)->create();
    foreach ([['GET', 'categories'], ['GET', 'transactions'], ['POST', 'transactions'], ['GET', 'transactions/'.$transaction->id], ['PATCH', 'transactions/'.$transaction->id], ['DELETE', 'transactions/'.$transaction->id]] as [$method, $uri]) {
        authHttp($method, '/api/v1/'.$uri)->assertUnauthorized();
    }
});

it('denies each API operation without its permission', function (string $permission, string $method, string $path) {
    $this->owner->removeRole('user');
    $transaction = Transaction::factory()->for($this->owner)->create();
    authHttp($method, '/api/v1/'.str_replace('{id}', $transaction->id, $path), transactionData(), $this->bearer)->assertForbidden();
    $this->owner->givePermissionTo($permission);
    authHttp($method, '/api/v1/'.str_replace('{id}', $transaction->id, $path), transactionData(), $this->bearer)->assertSuccessful();
})->with([
    ['categories.view', 'GET', 'categories'],
    ['transactions.view', 'GET', 'transactions'],
    ['transactions.view', 'GET', 'transactions/{id}'],
    ['transactions.create', 'POST', 'transactions'],
    ['transactions.update', 'PATCH', 'transactions/{id}'],
    ['transactions.delete', 'DELETE', 'transactions/{id}'],
]);

it('lists active categories and validates the inactive flag', function () {
    $active = Category::factory()->create();
    $inactive = Category::factory()->inactive()->create();
    authHttp('GET', '/api/v1/categories', token: $this->bearer)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
    foreach (['true', '1'] as $flag) {
        authHttp('GET', '/api/v1/categories?include_inactive='.$flag, token: $this->bearer)->assertOk()->assertJsonCount(2, 'data');
    }
    foreach (['false', '0'] as $flag) {
        authHttp('GET', '/api/v1/categories?include_inactive='.$flag, token: $this->bearer)->assertOk()->assertJsonCount(1, 'data');
    }
    authHttp('GET', '/api/v1/categories?include_inactive=no', token: $this->bearer)->assertUnprocessable()->assertJsonValidationErrors('include_inactive');
});

it('filters inclusively and sorts by date then id with stable pagination', function () {
    $category = Category::factory()->create();
    $older = Transaction::factory()->expense()->for($this->owner)->create(['category_id' => $category->id, 'occurred_on' => '2026-11-01']);
    $first = Transaction::factory()->expense()->for($this->owner)->create(['category_id' => $category->id, 'occurred_on' => '2026-11-10']);
    $last = Transaction::factory()->expense()->for($this->owner)->create(['category_id' => $category->id, 'occurred_on' => '2026-11-10']);
    Transaction::factory()->for($this->owner)->create(['occurred_on' => '2026-11-11']);
    Transaction::factory()->expense()->create(['category_id' => $category->id, 'occurred_on' => '2026-11-10']);
    $query = '?date_from=2026-11-01&date_to=2026-11-10&type=expense&category_id='.$category->id.'&per_page=2';
    authHttp('GET', '/api/v1/transactions'.$query, token: $this->bearer)->assertOk()->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.id', $last->id)->assertJsonPath('data.1.id', $first->id);
    authHttp('GET', '/api/v1/transactions'.$query.'&page=2', token: $this->bearer)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $older->id);
    authHttp('GET', '/api/v1/transactions?type=income', token: $this->bearer)->assertOk()->assertJsonCount(1, 'data');
});

it('defaults to twenty records and supports at most one hundred', function () {
    Transaction::factory()->count(101)->for($this->owner)->create();
    authHttp('GET', '/api/v1/transactions', token: $this->bearer)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 101);
    authHttp('GET', '/api/v1/transactions?per_page=100', token: $this->bearer)->assertOk()->assertJsonCount(100, 'data');
});

it('validates transaction list filters', function (string $query, string $field) {
    authHttp('GET', '/api/v1/transactions?'.$query, token: $this->bearer)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['date_from=2026-02-30', 'date_from'], ['date_to=10-11-2026', 'date_to'],
    ['date_from=2026-11-10&date_to=2026-11-09', 'date_to'],
    ['type=transfer', 'type'], ['category_id=999999', 'category_id'],
    ['per_page=101', 'per_page'], ['per_page=0', 'per_page'], ['per_page=1.5', 'per_page'], ['page=0', 'page'],
]);

it('validates amounts dates and descriptions on create and patch', function (array $data, string $field) {
    $transaction = Transaction::factory()->for($this->owner)->create();
    authHttp('POST', '/api/v1/transactions', transactionData($data), $this->bearer)->assertUnprocessable()->assertJsonValidationErrors($field);
    authHttp('PATCH', '/api/v1/transactions/'.$transaction->id, $data, $this->bearer)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['amount_minor' => 0], 'amount_minor'], [['amount_minor' => -1], 'amount_minor'],
    [['amount_minor' => 1.5], 'amount_minor'], [['amount_minor' => 1.0], 'amount_minor'],
    [['amount_minor' => true], 'amount_minor'], [['amount_minor' => '1e2'], 'amount_minor'],
    [['amount_minor' => '100'], 'amount_minor'], [['amount_minor' => null], 'amount_minor'],
    [['amount_minor' => 1000000000000], 'amount_minor'], [['amount_minor' => '18446744073709551616'], 'amount_minor'],
    [['occurred_on' => '2026-02-30'], 'occurred_on'], [['occurred_on' => '2026-11-10T12:00:00Z'], 'occurred_on'],
    [['occurred_on' => '2026-1-1'], 'occurred_on'], [['occurred_on' => '0000-01-01'], 'occurred_on'],
    [['occurred_on' => null], 'occurred_on'], [['description' => str_repeat('x', 2001)], 'description'],
    [['type' => 'transfer'], 'type'], [['type' => null], 'type'],
]);

it('requires fields for creation and accepts an empty patch', function () {
    authHttp('POST', '/api/v1/transactions', [], $this->bearer)->assertUnprocessable()->assertJsonValidationErrors(['type', 'amount_minor', 'occurred_on']);
    $transaction = Transaction::factory()->for($this->owner)->create();
    authHttp('PATCH', '/api/v1/transactions/'.$transaction->id, [], $this->bearer)->assertOk();
});

it('requires an active category for new expenses and no category for income', function () {
    $inactive = Category::factory()->inactive()->create();
    $active = Category::factory()->create();
    foreach ([null, $inactive->id, 999999, 'bad'] as $id) {
        authHttp('POST', '/api/v1/transactions', transactionData(['type' => 'expense', 'category_id' => $id]), $this->bearer)->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }
    authHttp('POST', '/api/v1/transactions', transactionData(['type' => 'expense']), $this->bearer)->assertUnprocessable()->assertJsonValidationErrors('category_id');
    authHttp('POST', '/api/v1/transactions', transactionData(['category_id' => $active->id]), $this->bearer)->assertUnprocessable()->assertJsonValidationErrors('category_id');
});

it('validates the final state across type and category transitions', function () {
    $transaction = Transaction::factory()->for($this->owner)->create();
    $category = Category::factory()->create();
    $inactive = Category::factory()->inactive()->create();
    $path = '/api/v1/transactions/'.$transaction->id;
    authHttp('PATCH', $path, ['type' => 'expense'], $this->bearer)->assertUnprocessable()->assertJsonValidationErrors('category_id');
    authHttp('PATCH', $path, ['type' => 'expense', 'category_id' => $inactive->id], $this->bearer)->assertUnprocessable();
    authHttp('PATCH', $path, ['category_id' => $category->id], $this->bearer)->assertUnprocessable();
    authHttp('PATCH', $path, ['type' => 'expense', 'category_id' => $category->id], $this->bearer)->assertOk()->assertJsonPath('data.category.id', $category->id);
    authHttp('PATCH', $path, ['category_id' => null], $this->bearer)->assertUnprocessable();
    authHttp('PATCH', $path, ['type' => 'income', 'category_id' => $category->id], $this->bearer)->assertUnprocessable();
    authHttp('PATCH', $path, ['type' => 'income'], $this->bearer)->assertOk()->assertJsonPath('data.category', null);
    expect($transaction->fresh()->category_id)->toBeNull();
    authHttp('PATCH', $path, ['type' => 'expense', 'category_id' => null], $this->bearer)->assertUnprocessable();
});

it('keeps inactive historical categories without permitting their reassignment', function () {
    $transaction = Transaction::factory()->expense()->for($this->owner)->create();
    $category = $transaction->category;
    $category->update(['is_active' => false]);
    $path = '/api/v1/transactions/'.$transaction->id;
    authHttp('GET', $path, token: $this->bearer)->assertOk()->assertJsonPath('data.category.name', $category->name)->assertJsonPath('data.category.is_active', false);
    authHttp('PATCH', $path, ['description' => 'Updated history'], $this->bearer)->assertOk()->assertJsonPath('data.category.id', $category->id);
    authHttp('PATCH', $path, ['category_id' => $category->id, 'amount_minor' => 1], $this->bearer)->assertOk();
    authHttp('GET', '/api/v1/transactions?category_id='.$category->id, token: $this->bearer)->assertOk()->assertJsonCount(1, 'data');
    $other = Transaction::factory()->expense()->for($this->owner)->create();
    authHttp('PATCH', '/api/v1/transactions/'.$other->id, ['category_id' => $category->id], $this->bearer)->assertUnprocessable();
    authHttp('PATCH', $path, ['type' => 'income'], $this->bearer)->assertOk();
    authHttp('PATCH', $path, ['type' => 'expense', 'category_id' => $category->id], $this->bearer)->assertUnprocessable();
});

it('loads all categories with one query for a page', function () {
    Transaction::factory()->expense()->count(20)->for($this->owner)->create();
    DB::enableQueryLog();
    authHttp('GET', '/api/v1/transactions', token: $this->bearer)->assertOk()->assertJsonCount(20, 'data');
    $queries = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'from `categories`'));
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1);
});

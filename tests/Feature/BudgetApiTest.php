<?php

use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

beforeEach(function () {
    /** @var TestCase $this */
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole('user');
    $this->bearer = $this->owner->createToken('m4-test')->plainTextToken;
});

function budgetPut(array $items, ?string $token = null, string $month = '2026-11')
{
    return authHttp('PUT', '/api/v1/budgets/'.$month, ['items' => $items], $token ?? test()->bearer);
}

function budgetSummary(string $month = '2026-11', ?string $token = null)
{
    return authHttp('GET', '/api/v1/budgets/'.$month.'/summary', token: $token ?? test()->bearer);
}

it('creates reads repeats and fully replaces a plan including zero and empty items', function () {
    /** @var TestCase $this */
    $categories = Category::factory()->count(3)->create();
    $items = [
        ['category_id' => $categories[0]->id, 'amount_minor' => Transaction::MAX_AMOUNT_MINOR],
        ['category_id' => $categories[1]->id, 'amount_minor' => 0],
    ];
    authHttp('GET', '/api/v1/budgets/2026-11', token: $this->bearer)->assertNotFound();
    $id = authHttp('PUT', '/api/v1/budgets/2026-11', [
        'items' => $items, 'user_id' => User::factory()->create()->id, 'month' => '2027-01-01',
    ], $this->bearer)->assertCreated()->assertJsonPath('data.currency', 'UAH')
        ->assertJsonPath('data.month', '2026-11')->assertJsonPath('data.planned_total_minor', Transaction::MAX_AMOUNT_MINOR)
        ->assertJsonCount(2, 'data.items')->json('data.id');
    expect(Budget::find($id)->user_id)->toBe($this->owner->id)
        ->and(Budget::find($id)->month->format('Y-m-d'))->toBe('2026-11-01');
    budgetPut($items)->assertOk()->assertJsonPath('data.id', $id);
    $this->assertDatabaseCount('budgets', 1);
    $this->assertDatabaseCount('budget_items', 2);
    authHttp('GET', '/api/v1/budgets/2026-11', token: $this->bearer)->assertOk()->assertJsonPath('data.items.0.category.id', $categories[0]->id);
    budgetPut([['category_id' => $categories[2]->id, 'amount_minor' => 345]])->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.planned_total_minor', 345);
    $this->assertDatabaseCount('budget_items', 1);
    budgetPut([])->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.planned_total_minor', 0);
    budgetSummary()->assertOk()->assertJsonPath('data.has_budget', true);
    budgetPut([], month: '2026-12')->assertCreated();
});

it('requires bearer authentication on every budget endpoint', function () {
    foreach ([['GET', ''], ['PUT', ''], ['GET', '/summary']] as [$method, $suffix]) {
        authHttp($method, '/api/v1/budgets/2026-11'.$suffix, ['items' => []])->assertUnauthorized();
    }
});

it('enforces each budget permission independently', function (string $method, string $suffix, string $permission) {
    /** @var TestCase $this */
    $this->owner->removeRole('user');
    Budget::factory()->for($this->owner)->create(['month' => '2026-11-01']);
    authHttp($method, '/api/v1/budgets/2026-11'.$suffix, ['items' => []], $this->bearer)->assertForbidden();
    $this->owner->givePermissionTo($permission);
    authHttp($method, '/api/v1/budgets/2026-11'.$suffix, ['items' => []], $this->bearer)->assertOk();
})->with([['GET', '', 'budgets.view'], ['GET', '/summary', 'budgets.view'], ['PUT', '', 'budgets.manage']]);

it('rejects malformed months consistently', function (string $month) {
    /** @var TestCase $this */
    foreach ([['GET', ''], ['PUT', ''], ['GET', '/summary']] as [$method, $suffix]) {
        authHttp($method, '/api/v1/budgets/'.$month.$suffix, ['items' => []], $this->bearer)
            ->assertUnprocessable()->assertJsonValidationErrors('month');
    }
    $this->assertDatabaseCount('budgets', 0);
})->with(['2026-1', '2026-00', '2026-13', '2026-02-01', '0000-11', '0999-11', '10000-01', '26-11', 'November', '2026-11x']);

it('rejects invalid lists and items without mutating the saved plan', function (array $body, string $field) {
    /** @var TestCase $this */
    $item = BudgetItem::factory()->for(Budget::factory()->for($this->owner)->create(['month' => '2026-11-01']))->create();
    authHttp('PUT', '/api/v1/budgets/2026-11', $body, $this->bearer)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($item->fresh()->amount_minor)->toBe($item->amount_minor);
    $this->assertDatabaseCount('budget_items', 1);
})->with([
    [[], 'items'], [['items' => null], 'items'], [['items' => 'bad'], 'items'],
    [['items' => ['named' => ['category_id' => 1, 'amount_minor' => 1]]], 'items'],
    [['items' => [null]], 'items.0'], [['items' => [[]]], 'items.0'],
    [['items' => [['amount_minor' => 10]]], 'items.0.category_id'],
    [['items' => [['category_id' => 999999, 'amount_minor' => 10]]], 'items.0.category_id'],
]);

it('requires integer nonnegative bounded amounts', function (mixed $amount) {
    /** @var TestCase $this */
    $category = Category::factory()->create();
    budgetPut([['category_id' => $category->id, 'amount_minor' => $amount]])->assertUnprocessable()->assertJsonValidationErrors('items.0.amount_minor');
    $this->assertDatabaseCount('budgets', 0);
})->with([-1, 1.5, 1.0, true, false, '100', '1e2', null, 1000000000000, '18446744073709551616']);

it('rejects JSON objects masquerading as lists', function (string $items) {
    /** @var TestCase $this */
    $category = Category::factory()->create();
    $body = str_replace('{id}', (string) $category->id, $items);
    $this->call('PUT', '/api/v1/budgets/2026-11', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearer,
    ], content: '{"items":'.$body.'}')->assertUnprocessable()->assertJsonValidationErrors('items');
    $this->assertDatabaseCount('budgets', 0);
})->with(['{}', '{"0":{"category_id":{id},"amount_minor":1}}']);

it('rejects duplicate category ids including mixed integer and string representations', function () {
    /** @var TestCase $this */
    $category = Category::factory()->create();
    budgetPut([
        ['category_id' => $category->id, 'amount_minor' => 10],
        ['category_id' => (string) $category->id, 'amount_minor' => 20],
    ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.category_id', 'items.1.category_id']);
    $this->assertDatabaseCount('budgets', 0);
});

it('retains edits and removes inactive history without adding it to another plan', function () {
    /** @var TestCase $this */
    $category = Category::factory()->create();
    $items = [['category_id' => $category->id, 'amount_minor' => 0]];
    budgetPut($items)->assertCreated();
    $category->update(['is_active' => false]);
    authHttp('GET', '/api/v1/budgets/2026-11', token: $this->bearer)->assertOk()->assertJsonPath('data.items.0.category.is_active', false);
    budgetPut($items)->assertOk();
    budgetPut([['category_id' => $category->id, 'amount_minor' => 50]])->assertOk();
    budgetSummary()->assertOk()->assertJsonPath('data.items.0.category.is_active', false)->assertJsonPath('data.items.0.planned_minor', 50);
    budgetPut($items, month: '2026-12')->assertUnprocessable();
    $other = Budget::factory()->for($this->owner)->create(['month' => '2027-01-01']);
    budgetPut($items, month: '2027-01')->assertUnprocessable();
    expect($other->items()->count())->toBe(0);
    budgetPut([])->assertOk();
    budgetPut($items)->assertUnprocessable();
});

it('keeps a complete original plan after validation fails late in the items list', function () {
    /** @var TestCase $this */
    $category = Category::factory()->create();
    budgetPut([['category_id' => $category->id, 'amount_minor' => 500]])->assertCreated();
    $inactive = Category::factory()->inactive()->create();
    budgetPut([
        ['category_id' => $category->id, 'amount_minor' => 100],
        ['category_id' => $inactive->id, 'amount_minor' => 200],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.1.category_id');
    authHttp('GET', '/api/v1/budgets/2026-11', token: $this->bearer)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.planned_total_minor', 500);
});

it('rolls back the budget and all items on a persistence failure', function (bool $exists) {
    /** @var TestCase $this */
    $categories = Category::factory()->count(2)->create();
    if ($exists) {
        budgetPut([['category_id' => $categories[0]->id, 'amount_minor' => 500]])->assertCreated();
    }
    Event::listen('eloquent.creating: '.BudgetItem::class, function (BudgetItem $item) use ($categories): void {
        if ($item->category_id === $categories[1]->id) {
            throw new RuntimeException('Simulated persistence failure');
        }
    });
    try {
        $this->withoutExceptionHandling();
        expect(fn () => budgetPut($categories->map(fn ($category) => ['category_id' => $category->id, 'amount_minor' => 100])->all()))->toThrow(RuntimeException::class, 'Simulated persistence failure');
    } finally {
        Event::forget('eloquent.creating: '.BudgetItem::class);
    }
    $this->assertDatabaseCount('budgets', $exists ? 1 : 0);
    $this->assertDatabaseCount('budget_items', $exists ? 1 : 0);
    if ($exists) {
        expect(BudgetItem::sole()->amount_minor)->toBe(500);
    }
})->with([false, true]);

it('isolates every budget query and policy for users and admins', function (string $role) {
    /** @var TestCase $this */
    $this->owner->syncRoles($role);
    $foreign = BudgetItem::factory()->create(['amount_minor' => 800]);
    $foreign->budget->update(['month' => '2026-11-01']);
    Transaction::factory()->expense()->for($foreign->budget->user)->create(['occurred_on' => '2026-11-01', 'amount_minor' => 900]);
    authHttp('GET', '/api/v1/budgets/2026-11', token: $this->bearer)->assertNotFound();
    budgetSummary()->assertOk()->assertJsonPath('data.has_budget', false)->assertJsonPath('data.spent_total_minor', 0)->assertJsonCount(0, 'data.items');
    budgetPut([['category_id' => $foreign->category_id, 'amount_minor' => 200]])->assertCreated();
    expect($foreign->fresh()->amount_minor)->toBe(800);
    foreach (['view', 'update'] as $ability) {
        expect(Gate::forUser($this->owner)->allows($ability, $foreign->budget))->toBeFalse();
    }
    $foreignToken = $foreign->budget->user->assignRole('user')->createToken('foreign')->plainTextToken;
    budgetSummary(token: $foreignToken)->assertOk()->assertJsonPath('data.planned_total_minor', 800)->assertJsonPath('data.spent_total_minor', 900);
    budgetSummary()->assertOk()->assertJsonPath('data.planned_total_minor', 200)->assertJsonPath('data.spent_total_minor', 0);
})->with(['user', 'admin']);

it('aggregates planned unplanned inactive zero and overspent categories with separate income', function () {
    /** @var TestCase $this */
    $categories = Category::factory()->count(5)->create();
    budgetPut([
        ['category_id' => $categories[0]->id, 'amount_minor' => 300],
        ['category_id' => $categories[1]->id, 'amount_minor' => 1000],
        ['category_id' => $categories[2]->id, 'amount_minor' => 0],
        ['category_id' => $categories[3]->id, 'amount_minor' => 900],
    ])->assertCreated();
    foreach ([400, 100, 50, 0, 200] as $index => $spent) {
        if ($spent) {
            Transaction::factory()->expense()->for($this->owner)->create(['category_id' => $categories[$index]->id, 'amount_minor' => $spent, 'occurred_on' => '2026-11-30']);
        }
    }
    Transaction::factory()->for($this->owner)->create(['amount_minor' => 5000, 'occurred_on' => '2026-11-01']);
    $categories[4]->update(['is_active' => false]);
    $summary = budgetSummary()->assertOk()->assertJsonPath('data.has_budget', true)->assertJsonPath('data.currency', 'UAH')
        ->assertJsonPath('data.planned_total_minor', 2200)->assertJsonPath('data.spent_total_minor', 750)
        ->assertJsonPath('data.remaining_total_minor', 1450)->assertJsonPath('data.overspent_total_minor', 0)
        ->assertJsonPath('data.unplanned_spent_minor', 200)->assertJsonPath('data.income_total_minor', 5000)
        ->assertJsonPath('data.net_total_minor', 4250)->assertJsonCount(5, 'data.items')->json('data.items');
    expect($summary[0])->toMatchArray(['is_planned' => true, 'planned_minor' => 300, 'spent_minor' => 400, 'remaining_minor' => -100, 'overspent_minor' => 100, 'used_percent' => 133.33])
        ->and($summary[2])->toMatchArray(['is_planned' => true, 'planned_minor' => 0, 'remaining_minor' => -50, 'overspent_minor' => 50, 'used_percent' => null])
        ->and($summary[3])->toMatchArray(['spent_minor' => 0, 'remaining_minor' => 900, 'overspent_minor' => 0, 'used_percent' => 0])
        ->and($summary[4])->toMatchArray(['is_planned' => false, 'planned_minor' => 0, 'spent_minor' => 200, 'remaining_minor' => -200, 'used_percent' => null])
        ->and($summary[4]['category']['is_active'])->toBeFalse();
});

it('reports exact large integer totals and total overspending', function () {
    /** @var TestCase $this */
    $category = Category::factory()->create();
    budgetPut([['category_id' => $category->id, 'amount_minor' => Transaction::MAX_AMOUNT_MINOR]])->assertCreated();
    Transaction::factory()->expense()->count(3)->for($this->owner)->create(['category_id' => $category->id, 'amount_minor' => Transaction::MAX_AMOUNT_MINOR, 'occurred_on' => '2026-11-01']);
    budgetSummary()->assertOk()->assertJsonPath('data.spent_total_minor', 2999999999997)
        ->assertJsonPath('data.remaining_total_minor', -1999999999998)->assertJsonPath('data.overspent_total_minor', 1999999999998)
        ->assertJsonPath('data.net_total_minor', -2999999999997)->assertJsonPath('data.items.0.used_percent', 300);
});

it('reports actuals and all formulas without a budget', function () {
    /** @var TestCase $this */
    budgetSummary()->assertOk()->assertJsonPath('data.has_budget', false)->assertJsonPath('data.income_total_minor', 0)->assertJsonCount(0, 'data.items');
    Transaction::factory()->expense()->for($this->owner)->create(['amount_minor' => 12345, 'occurred_on' => '2026-11-01']);
    Transaction::factory()->for($this->owner)->create(['amount_minor' => 5000, 'occurred_on' => '2026-11-30']);
    budgetSummary()->assertOk()->assertJsonPath('data.has_budget', false)->assertJsonPath('data.planned_total_minor', 0)
        ->assertJsonPath('data.spent_total_minor', 12345)->assertJsonPath('data.remaining_total_minor', -12345)
        ->assertJsonPath('data.overspent_total_minor', 12345)->assertJsonPath('data.unplanned_spent_minor', 12345)
        ->assertJsonPath('data.income_total_minor', 5000)->assertJsonPath('data.net_total_minor', -7345)
        ->assertJsonPath('data.items.0.is_planned', false)->assertJsonPath('data.items.0.used_percent', null);
});

it('immediately reflects transaction creation editing moving type changes and deletion', function () {
    /** @var TestCase $this */
    $categories = Category::factory()->count(2)->create();
    $response = authHttp('POST', '/api/v1/transactions', ['type' => 'expense', 'category_id' => $categories[0]->id, 'amount_minor' => 150, 'occurred_on' => '2026-11-01'], $this->bearer)->assertCreated();
    $path = '/api/v1/transactions/'.$response->json('data.id');
    budgetSummary()->assertJsonPath('data.spent_total_minor', 150);
    authHttp('PATCH', $path, ['amount_minor' => 350, 'category_id' => $categories[1]->id], $this->bearer)->assertOk();
    budgetSummary()->assertJsonPath('data.spent_total_minor', 350)->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.category.id', $categories[1]->id);
    authHttp('PATCH', $path, ['occurred_on' => '2026-12-01'], $this->bearer)->assertOk();
    budgetSummary()->assertJsonPath('data.spent_total_minor', 0);
    budgetSummary('2026-12')->assertJsonPath('data.spent_total_minor', 350);
    authHttp('PATCH', $path, ['type' => 'income'], $this->bearer)->assertOk();
    budgetSummary('2026-12')->assertJsonPath('data.spent_total_minor', 0)->assertJsonPath('data.income_total_minor', 350)->assertJsonPath('data.net_total_minor', 350);
    authHttp('DELETE', $path, token: $this->bearer)->assertNoContent();
    budgetSummary('2026-12')->assertJsonPath('data.income_total_minor', 0);
    $expense = Transaction::factory()->expense()->for($this->owner)->create(['amount_minor' => 99, 'occurred_on' => '2026-11-02']);
    authHttp('DELETE', '/api/v1/transactions/'.$expense->id, token: $this->bearer)->assertNoContent();
    budgetSummary()->assertJsonPath('data.spent_total_minor', 0);
});

it('uses indexed half open month ranges including year boundaries and leap days', function (string $month, string $first, string $last, string $before, string $after) {
    /** @var TestCase $this */
    foreach ([$before => 1000, $first => 10, $last => 20, $after => 2000] as $date => $amount) {
        Transaction::factory()->expense()->for($this->owner)->create(['occurred_on' => $date, 'amount_minor' => $amount]);
        Transaction::factory()->for($this->owner)->create(['occurred_on' => $date, 'amount_minor' => $amount * 2]);
    }
    DB::enableQueryLog();
    budgetSummary($month)->assertOk()->assertJsonPath('data.spent_total_minor', 30)->assertJsonPath('data.income_total_minor', 60);
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from `transactions`'));
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1);
    $query = $queries->first();
    expect($query['query'])->toContain('SUM(amount_minor)', '`occurred_on` >= ?', '`occurred_on` < ?', 'group by')->not->toContain('month(', 'year(')
        ->and($query['bindings'])->toContain($first, $after);
})->with([
    ['2026-12', '2026-12-01', '2026-12-31', '2026-11-30', '2027-01-01'],
    ['2027-01', '2027-01-01', '2027-01-31', '2026-12-31', '2027-02-01'],
    ['2028-02', '2028-02-01', '2028-02-29', '2028-01-31', '2028-03-01'],
]);

it('supports the earliest documented budget month', function () {
    /** @var TestCase $this */
    authHttp('GET', '/api/v1/budgets/2026-01/summary', token: $this->bearer)
        ->assertOk()->assertJsonPath('data.month', '2026-01')->assertJsonPath('data.has_budget', false);
});

it('supports the final MySQL calendar month', function () {
    /** @var TestCase $this */
    budgetPut([], month: '9999-12')->assertCreated();
    Transaction::factory()->expense()->for($this->owner)->create(['occurred_on' => '9999-12-31', 'amount_minor' => 50]);
    budgetSummary('9999-12')->assertOk()->assertJsonPath('data.spent_total_minor', 50);
});

it('protects budget uniqueness month normalization and item uniqueness in MySQL', function () {
    /** @var TestCase $this */
    $budget = Budget::factory()->for($this->owner)->create(['month' => '2026-11-01']);
    $item = BudgetItem::factory()->for($budget)->create(['amount_minor' => 0]);
    expect(fn () => Budget::factory()->for($this->owner)->create(['month' => '2026-11-01']))->toThrow(QueryException::class);
    expect(fn () => Budget::factory()->for($this->owner)->create(['month' => '2026-12-02']))->toThrow(QueryException::class);
    expect(fn () => BudgetItem::factory()->for($budget)->create(['category_id' => $item->category_id]))->toThrow(QueryException::class);
});

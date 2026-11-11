<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Budgets\CalculateBudgetSummary;
use App\Actions\Budgets\SaveBudget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budgets\BudgetMonthRequest;
use App\Http\Requests\Budgets\SaveBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Http\Resources\BudgetSummaryResource;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

#[Group('Бюджети')]
class BudgetController extends Controller
{
    #[Endpoint(title: 'Місячний бюджет', description: 'Получити власний бюджет. Повертає як активні так і неактивні історичні категорії. Потрібен budgets.view дозвіл.')]
    #[PathParameter('month', description: 'Календарний місяць YYYY-MM (2026–9999).', type: 'string', example: '2026-11')]
    #[ApiResponse(403, description: 'Недостатньо дозволів.', type: 'array{message: string}')]
    #[ApiResponse(404, description: 'Власний бюджет відсутній.', type: 'array{message: string}')]
    #[ApiResponse(422, description: 'Некоректний місяць.', type: 'array{message: string, errors: array<string, string[]>}')]
    public function show(BudgetMonthRequest $request): BudgetResource
    {
        return DB::transaction(function () use ($request): BudgetResource {
            $budget = $request->user()->budgets()->where('month', $request->monthStart())->with('items.category')->firstOrFail();

            return new BudgetResource($budget);
        });
    }

    #[Endpoint(title: 'Бюджетування місячного плану', description: 'Кожен визов метода повністю замінює план. [] зберігає порожній бюджет. Код 201 — перше створення, 200 — внесення змін. Останнє успішне збереження замінює попередній план атомарно. amount_minor: ціле JSON-число 0–999999999999 копійок, UAH. Потрібен budgets.manage дозвіл.')]
    #[PathParameter('month', description: 'Календарний місяць YYYY-MM (2026–9999).', type: 'string', example: '2026-11')]
    #[ApiResponse(200, type: BudgetResource::class)]
    #[ApiResponse(201, type: BudgetResource::class)]
    #[ApiResponse(403, description: 'Недостатньо дозволів.', type: 'array{message: string}')]
    #[ApiResponse(422, description: 'Некоректний місяць, сума, список або категорія.', type: 'array{message: string, errors: array<string, string[]>}')]
    public function update(SaveBudgetRequest $request, SaveBudget $save): JsonResponse
    {
        $budget = $save->handle($request->user(), $request->monthStart(), $request->validated('items'));

        return (new BudgetResource($budget))->response()->setStatusCode($budget->wasRecentlyCreated ? 201 : 200);
    }

    #[Endpoint(title: 'План-факт місяця', description: 'Без бюджету: код 200, has_budget=false, план=0. Об’єднання категорій плану й витрат, включно з неактивними. Факт за occurred_on: [початок місяця, початок наступного). remaining=planned−spent; overspent=max(spent−planned,0); used_percent=round(spent/planned*100,2), null за плану 0. Загальна перевитрата із загальних сум. Доходи окремо; net=income−spent; unplanned — витрати категорій поза планом. Потрібен budgets.view дозвіл.')]
    #[PathParameter('month', description: 'Календарний місяць YYYY-MM (2026–9999).', type: 'string', example: '2026-11')]
    #[ApiResponse(403, description: 'Недостатньо дозволів.', type: 'array{message: string}')]
    #[ApiResponse(422, description: 'Некоректний місяць.', type: 'array{message: string, errors: array<string, string[]>}')]
    public function summary(BudgetMonthRequest $request, CalculateBudgetSummary $summary): BudgetSummaryResource
    {
        return new BudgetSummaryResource($summary->handle($request->user(), $request->monthStart()));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\IndexTransactionRequest;
use App\Http\Requests\Transactions\StoreTransactionRequest;
use App\Http\Requests\Transactions\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

#[Group('Транзакції')]
class TransactionController extends Controller
{
    #[Endpoint(title: 'Список транзакцій', description: 'Получити власні транзакції. Межі дат включні; date_to >= date_from. Порядок occurred_on DESC, id DESC. per_page: 20, максимум 100. Потрібен transactions.view дозвіл.')]
    #[ApiResponse(403, description: 'Email не підтверджено або недостатньо дозволів.', type: 'array{message: string}')]
    public function index(IndexTransactionRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = $request->user()->transactions()->with('category');

        foreach (['type', 'category_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['date_from'])) {
            $query->where('occurred_on', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('occurred_on', '<=', $filters['date_to']);
        }

        return TransactionResource::collection($query->orderByDesc('occurred_on')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    #[Endpoint(title: 'Створення транзакції', description: 'amount_minor: ціле JSON-число 1–999999999999 копійок, валюта UAH. occurred_on: YYYY-MM-DD. Витрата потребує активної категорії, дохід — category_id=null. user_id ігнорується. Потрібен transactions.create дозвіл.')]
    #[ApiResponse(201, type: TransactionResource::class)]
    #[ApiResponse(403, description: 'Email не підтверджено або недостатньо дозволів.', type: 'array{message: string}')]
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $request->user()->transactions()->create($request->validated());

        return (new TransactionResource($transaction->load('category')))->response()->setStatusCode(201);
    }

    #[Endpoint(title: 'Перегляд транзакції', description: 'Чужа або відсутня транзакція повертає 404. Потрібен transactions.view дозвіл.')]
    #[ApiResponse(403, description: 'Email не підтверджено або недостатньо дозволів.', type: 'array{message: string}')]
    public function show(Request $request, Transaction $transaction): TransactionResource
    {
        Gate::authorize('view', $transaction);

        return new TransactionResource($transaction->load('category'));
    }

    #[Endpoint(title: 'Редагування транзакції', description: 'Перевіряється кінцевий стан. income → expense потребує активної категорії; expense → income очищає категорію. Стару неактивну категорію витрати можна зберегти. user_id ігнорується. Потрібен transactions.update дозвіл.')]
    #[ApiResponse(403, description: 'Email не підтверджено або недостатньо дозволів.', type: 'array{message: string}')]
    public function update(UpdateTransactionRequest $request, Transaction $transaction): TransactionResource
    {
        $data = $request->validated();

        if (($data['type'] ?? $transaction->type->value) === TransactionType::Income->value) {
            $data['category_id'] = null;
        }

        $transaction->update($data);

        return new TransactionResource($transaction->load('category'));
    }

    #[Endpoint(title: 'Видалення транзакції', description: 'Чужа або відсутня транзакція повертає 404. Потрібен transactions.delete дозвіл.')]
    #[ApiResponse(403, description: 'Email не підтверджено або недостатньо дозволів.', type: 'array{message: string}')]
    public function destroy(Request $request, Transaction $transaction): Response
    {
        Gate::authorize('delete', $transaction);
        $transaction->delete();

        return response()->noContent();
    }
}

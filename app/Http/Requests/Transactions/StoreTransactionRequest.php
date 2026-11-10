<?php

namespace App\Http\Requests\Transactions;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Transaction::class);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TransactionType::class)],
            'amount_minor' => ['required', 'integer:strict', 'min:1', 'max:'.Transaction::MAX_AMOUNT_MINOR],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2026-01-01'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $transaction = $this->route('transaction');
            $type = $this->input('type', $transaction?->type->value);
            $categoryId = $this->exists('category_id')
                ? $this->input('category_id')
                : $transaction?->category_id;

            if ($type === TransactionType::Income->value) {
                if ($this->input('category_id') !== null) {
                    $validator->errors()->add('category_id', 'Дохід не може мати категорію.');
                }

                return;
            }

            $unchanged = $transaction?->type === TransactionType::Expense
                && $categoryId !== null
                && (int) $categoryId === $transaction->category_id;

            if (! $categoryId || (! $unchanged && ! Category::whereKey($categoryId)->where('is_active', true)->exists())) {
                $validator->errors()->add('category_id', 'Для витрати потрібна активна категорія.');
            }
        }];
    }
}

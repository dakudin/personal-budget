<?php

namespace App\Http\Requests\Transactions;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[QueryParameter('per_page', description: 'Кількість записів на сторінці: 1–100.', default: 20)]
#[QueryParameter('page', description: 'Номер сторінки, щонайменше 1.', default: 1)]
class IndexTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Transaction::class);
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:2026-01-01'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:2026-01-01', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'type' => ['sometimes', Rule::enum(TransactionType::class)],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

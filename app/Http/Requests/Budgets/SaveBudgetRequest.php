<?php

namespace App\Http\Requests\Budgets;

use App\Models\Budget;
use App\Models\Transaction;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

#[BodyParameter('items', description: 'Повна заміна плану; порожній список дозволено. Неактивну категорію можна залишити лише в її поточному бюджеті.', example: [['category_id' => 1, 'amount_minor' => 150000], ['category_id' => 2, 'amount_minor' => 0]])]
class SaveBudgetRequest extends BudgetMonthRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Budget::class);
    }

    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'list'],
            'items.*' => ['required', 'array:category_id,amount_minor'],
            'items.*.category_id' => ['required', 'integer', 'distinct', Rule::exists('categories', 'id')],
            'items.*.amount_minor' => ['required', 'integer:strict', 'min:0', 'max:'.Transaction::MAX_AMOUNT_MINOR],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            // Associative JSON decoding otherwise makes {} indistinguishable from [].
            $document = $this->isJson() ? json_decode($this->getContent()) : null;
            if ($this->isJson() && (! is_object($document) || ! is_array($document->items ?? null))) {
                $validator->errors()->add('items', 'Поле items має бути JSON-списком.');
            }
        }];
    }
}

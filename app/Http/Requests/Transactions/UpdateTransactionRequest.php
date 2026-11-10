<?php

namespace App\Http\Requests\Transactions;

class UpdateTransactionRequest extends StoreTransactionRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('transaction'));
    }

    public function rules(): array
    {
        $rules = parent::rules();

        foreach (['type', 'amount_minor', 'occurred_on'] as $field) {
            $rules[$field][0] = 'sometimes';
        }

        return $rules;
    }
}

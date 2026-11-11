<?php

namespace App\Http\Requests\Budgets;

use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

class BudgetMonthRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        Validator::make(['month' => $this->route('month')], [
            'month' => ['required', 'string', 'regex:/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D'],
        ])->validate();
    }

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Budget::class);
    }

    public function rules(): array
    {
        return [];
    }

    public function monthStart(): string
    {
        return $this->route('month').'-01';
    }
}

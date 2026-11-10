<?php

namespace App\Http\Requests\Categories;

use App\Models\Category;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;

#[QueryParameter('include_inactive', description: 'Додати неактивні категорії. Приймає true, false, 1 або 0.', default: false)]
class IndexCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Category::class);
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('include_inactive'), ['true', 'false'], true)) {
            $this->merge(['include_inactive' => $this->input('include_inactive') === 'true']);
        }
    }

    public function rules(): array
    {
        return ['include_inactive' => ['sometimes', 'boolean']];
    }
}

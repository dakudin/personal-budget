<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\IndexCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Категорії')]
class CategoryController extends Controller
{
    #[Endpoint(title: 'Категорії витрат', description: 'Спільний довідник. Типово лише активні; include_inactive=true або 1 додає неактивні. Потрібен дозвіл categories.view.')]
    #[ApiResponse(403, description: 'Недостатньо дозволів.', type: 'array{message: string}')]
    public function index(IndexCategoryRequest $request): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::query()
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->orderBy('id')->get());
    }
}

<?php

namespace App\Http\Resources;

use App\Models\BudgetItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BudgetItem */
class BudgetItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'amount_minor' => $this->amount_minor,
        ];
    }
}

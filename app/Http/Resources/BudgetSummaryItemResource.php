<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @property Collection $resource */
class BudgetSummaryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'category' => CategoryResource::make($this->resource['category']),
            /**
             * Чи є категорія у плані.
             */
            'is_planned' => (bool) $this->resource['is_planned'],
            /**
             * Запланована сума.
             */
            'planned_minor' => (int) $this->resource['planned_minor'],
            /**
             * Витрачено по категорії.
             */
            'spent_minor' => (int) $this->resource['spent_minor'],
            /**
             * Залишок по категорії.
             */
            'remaining_minor' => (int) $this->resource['remaining_minor'],
            /**
             * Перевитрата по категорії.
             */
            'overspent_minor' => (int) $this->resource['overspent_minor'],
            /**
             * Відсоток виконання.
             * @var float|null
             */
            'used_percent' => $this->resource['used_percent'],
        ];
    }
}

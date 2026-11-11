<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @property Collection $resource */
class BudgetSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'month' => (string) $this->resource['month'],
            'has_budget' => (bool) $this->resource['has_budget'],
            'currency' => 'UAH',
            /**
             * Планові витрати.
             */
            'planned_total_minor' => (int) $this->resource['planned_total_minor'],
            /**
             * Фактичні витрати.
             */
            'spent_total_minor' => (int) $this->resource['spent_total_minor'],
            /**
             * Залишок по категоріях плану, якщо витрачено менше, ніж заплановано.
             */
            'remaining_total_minor' => (int) $this->resource['remaining_total_minor'],
            /**
             * Перевитрата по категоріях плану, якщо витрачено більше, ніж заплановано.
             */
            'overspent_total_minor' => (int) $this->resource['overspent_total_minor'],
            /**
             * Усього витрачено поза планом.
             */
            'unplanned_spent_minor' => (int) $this->resource['unplanned_spent_minor'],
            /**
             * Усього доходів.
             */
            'income_total_minor' => (int) $this->resource['income_total_minor'],
            /**
             * Чистий підсумок.
             */
            'net_total_minor' => (int) $this->resource['net_total_minor'],
            'items' => BudgetSummaryItemResource::collection($this->resource['items']),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transaction */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount_minor' => $this->amount_minor,
            'currency' => 'UAH',
            /**
             * @var string
             *
             * @format date
             */
            'occurred_on' => $this->occurred_on->format('Y-m-d'),
            'description' => $this->description,
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

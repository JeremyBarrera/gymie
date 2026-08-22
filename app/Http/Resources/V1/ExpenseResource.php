<?php

namespace App\Http\Resources\V1;

use App\Models\Expense;
use App\Services\Api\Schemas\ExpenseSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Expense $expense */
        $expense = $this->resource;

        return ExpenseSchema::resource($expense);
    }
}

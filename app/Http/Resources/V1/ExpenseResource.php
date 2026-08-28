<?php

namespace App\Http\Resources\V1;

use App\Models\Expense;
use App\Services\Api\Schemas\ExpenseSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $expense = $this->resource;

        return ExpenseSchema::resource($expense);
    }
}

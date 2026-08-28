<?php

namespace App\Http\Resources\V1;

use App\Models\Plan;
use App\Services\Api\Schemas\PlanSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $plan = $this->resource;

        return PlanSchema::resource($plan);
    }
}

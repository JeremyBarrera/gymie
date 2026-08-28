<?php

namespace App\Http\Resources\V1;

use App\Models\Subscription;
use App\Services\Api\Schemas\SubscriptionSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $subscription = $this->resource;

        return SubscriptionSchema::resource($subscription);
    }
}

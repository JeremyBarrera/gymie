<?php

namespace App\Http\Resources\V1;

use App\Models\Subscription;
use App\Services\Api\Schemas\SubscriptionSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Subscription $subscription */
        $subscription = $this->resource;

        return SubscriptionSchema::resource($subscription);
    }
}

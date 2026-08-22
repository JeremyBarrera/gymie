<?php

namespace App\Http\Resources\V1;

use App\Models\FollowUp;
use App\Services\Api\Schemas\FollowUpSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FollowUp
 */
class FollowUpResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FollowUp $followUp */
        $followUp = $this->resource;

        return FollowUpSchema::resource($followUp);
    }
}

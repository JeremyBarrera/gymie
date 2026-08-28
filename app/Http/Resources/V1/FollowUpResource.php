<?php

namespace App\Http\Resources\V1;

use App\Models\FollowUp;
use App\Services\Api\Schemas\FollowUpSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $followUp = $this->resource;

        return FollowUpSchema::resource($followUp);
    }
}

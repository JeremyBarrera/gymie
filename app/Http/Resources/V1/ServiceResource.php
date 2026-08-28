<?php

namespace App\Http\Resources\V1;

use App\Models\Service;
use App\Services\Api\Schemas\ServiceSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $service = $this->resource;

        return ServiceSchema::resource($service);
    }
}

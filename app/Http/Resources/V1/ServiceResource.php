<?php

namespace App\Http\Resources\V1;

use App\Models\Service;
use App\Services\Api\Schemas\ServiceSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Service $service */
        $service = $this->resource;

        return ServiceSchema::resource($service);
    }
}

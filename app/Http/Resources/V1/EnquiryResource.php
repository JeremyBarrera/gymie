<?php

namespace App\Http\Resources\V1;

use App\Models\Enquiry;
use App\Services\Api\Schemas\EnquirySchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnquiryResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $enquiry = $this->resource;

        return EnquirySchema::resource($enquiry);
    }
}

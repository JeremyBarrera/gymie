<?php

namespace App\Http\Resources\V1;

use App\Models\Member;
use App\Services\Api\Schemas\MemberSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $member = $this->resource;

        return MemberSchema::resource($member);
    }
}

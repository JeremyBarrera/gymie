<?php

namespace App\Http\Resources\V1;

use App\Models\Member;
use App\Services\Api\Schemas\MemberSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Member
 */
class MemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Member $member */
        $member = $this->resource;

        return MemberSchema::resource($member);
    }
}

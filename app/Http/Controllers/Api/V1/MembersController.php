<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\Helpers;
use App\Http\Requests\Api\V1\MemberStoreRequest;
use App\Http\Requests\Api\V1\MemberUpdateRequest;
use App\Http\Resources\V1\MemberResource;
use App\Models\Member;
use App\Services\Api\QueryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MembersController extends ApiController
{
    private const RESOURCE_KEY = 'members';

    

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requirePermission($request, 'ViewAny:Member');

        $query = Member::query();

        QueryFilters::applyIndexFilters($query, $request, self::RESOURCE_KEY);

        $perPage = QueryFilters::perPage($request->query('per_page'));

        return MemberResource::collection($query->paginate($perPage));
    }

    

    public function store(MemberStoreRequest $request): MemberResource
    {
        $this->requirePermission($request, 'Create:Member');

        $data = $request->validated();

        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->storePublicly('images', 'public');
        }

        $member = Member::create($data);

        return new MemberResource($member->refresh());
    }

    

    public function show(Request $request, Member $member): MemberResource
    {
        $this->requirePermission($request, 'View:Member');

        return new MemberResource($member);
    }

    

    public function update(MemberUpdateRequest $request, Member $member): MemberResource
    {
        $this->requirePermission($request, 'Update:Member');

        $data = $request->validated();

        if ($request->hasFile('photo')) {
            $previousPhoto = $member->getRawOriginal('photo');

            $data['photo'] = $request->file('photo')->storePublicly('images', 'public');

            if ($previousPhoto !== null && $previousPhoto !== $data['photo']) {
                Helpers::deleteStoredPhoto($previousPhoto);
            }
        }

        $member->update($data);

        return new MemberResource($member->refresh());
    }

    

    public function destroy(Request $request, Member $member): JsonResponse
    {
        return $this->deleteModel($request, 'Delete:Member', $member);
    }

    

    public function restore(Request $request, int $member): MemberResource
    {
        $record = $this->restoreSoftDeleted($request, 'RestoreAny:Member', Member::class, $member);

        return new MemberResource($record->refresh());
    }

    

    public function forceDelete(Request $request, int $member): JsonResponse
    {
        $this->forceDeleteSoftDeleted($request, 'ForceDeleteAny:Member', Member::class, $member);

        return $this->noContent();
    }
}

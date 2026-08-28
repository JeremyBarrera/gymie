<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\PlanStoreRequest;
use App\Http\Requests\Api\V1\PlanUpdateRequest;
use App\Http\Resources\V1\PlanResource;
use App\Models\Plan;
use App\Services\Api\QueryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PlansController extends ApiController
{
    private const RESOURCE_KEY = 'plans';

    

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requirePermission($request, 'ViewAny:Plan');

        $query = Plan::query()->with('services');

        QueryFilters::applyIndexFilters($query, $request, self::RESOURCE_KEY);

        $perPage = QueryFilters::perPage($request->query('per_page'));

        return PlanResource::collection($query->paginate($perPage));
    }

    

    public function store(PlanStoreRequest $request): PlanResource
    {
        $this->requirePermission($request, 'Create:Plan');

        ['data' => $data, 'service_ids' => $serviceIds] = self::splitPayload($request->validated());

        $plan = DB::transaction(function () use ($data, $serviceIds): Plan {
            $plan = Plan::create($data);
            $plan->services()->sync($serviceIds);

            return $plan;
        });

        $plan->load('services');

        return new PlanResource($plan);
    }

    

    public function show(Request $request, Plan $plan): PlanResource
    {
        $this->requirePermission($request, 'View:Plan');

        $plan->load('services');

        return new PlanResource($plan);
    }

    

    public function update(PlanUpdateRequest $request, Plan $plan): PlanResource
    {
        $this->requirePermission($request, 'Update:Plan');

        ['data' => $data, 'service_ids' => $serviceIds] = self::splitPayload($request->validated());

        DB::transaction(function () use ($plan, $data, $serviceIds): void {
            $plan->update($data);

            if ($serviceIds !== null) {
                $plan->services()->sync($serviceIds);
            }
        });

        $plan->load('services');

        return new PlanResource($plan);
    }

    

    public function destroy(Request $request, Plan $plan): JsonResponse
    {
        return $this->deleteModel($request, 'Delete:Plan', $plan);
    }

    

    public function restore(Request $request, int $plan): PlanResource
    {
        $record = $this->restoreSoftDeleted($request, 'RestoreAny:Plan', Plan::class, $plan);
        $record->load('services');

        return new PlanResource($record->refresh());
    }

    

    public function forceDelete(Request $request, int $plan): JsonResponse
    {
        $this->forceDeleteSoftDeleted($request, 'ForceDeleteAny:Plan', Plan::class, $plan);

        return $this->noContent();
    }

    

    private static function splitPayload(array $validated): array
    {
        $serviceIds = isset($validated['service_ids']) && is_array($validated['service_ids'])
            ? array_map(intval(...), $validated['service_ids'])
            : null;

        unset($validated['service_ids']);

        return ['data' => $validated, 'service_ids' => $serviceIds];
    }
}

<?php

namespace App\Services\Api\Schemas;

use App\Enums\Status;
use App\Models\Plan;
use App\Models\Service;
use App\Rules\ModelExists;
use App\Rules\ModelUnique;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Single source of truth for Plan API validation and serialization.
 */
final class PlanSchema
{
    private function __construct() {}

    /**
     * @return array{
     *   searchable: list<string>,
     *   sortable: list<string>,
     *   default_sort: string,
     *   status_column: string|null,
     *   includes: list<string>,
     *   filters: array<string, array{type: string, column?: string, relation?: string}>
     * }
     */
    public static function queryRules(): array
    {
        return [
            'searchable' => ['name', 'code', 'description'],
            'sortable' => ['id', 'created_at', 'name'],
            'default_sort' => '-id',
            'status_column' => 'status',
            'includes' => ['services'],
            'filters' => [
                'service_id' => ['type' => 'relation', 'relation' => 'services'],
                'status' => ['type' => 'exact', 'column' => 'status'],
                'created_at' => ['type' => 'datetime_range', 'column' => 'created_at'],
            ],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function storeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new ModelUnique(Plan::class, 'name')],
            'code' => ['required', 'string', 'max:255', new ModelUnique(Plan::class, 'code')],
            'description' => ['nullable', 'string'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['required', 'integer', new ModelExists(Service::class)],
            'amount' => ['required', 'numeric', 'min:0'],
            'days' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string'],
            'limit_uses' => ['nullable', 'boolean'],
            'uses_limit' => ['nullable', 'integer', 'min:1', 'required_if:limit_uses,true'],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function updateRules(int|string $planId): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255', new ModelUnique(Plan::class, 'name', $planId)],
            'code' => ['sometimes', 'string', 'max:255', new ModelUnique(Plan::class, 'code', $planId)],
            'description' => ['sometimes', 'nullable', 'string'],
            'service_ids' => ['sometimes', 'array', 'min:1'],
            'service_ids.*' => ['required', 'integer', new ModelExists(Service::class)],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'string'],
            'limit_uses' => ['sometimes', 'boolean'],
            'uses_limit' => ['nullable', 'integer', 'min:1', 'required_if:limit_uses,true'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function resource(Plan $plan): array
    {
        $payload = [
            'id' => (int) $plan->id,
            'name' => (string) $plan->name,
            'code' => $plan->code ? (string) $plan->code : null,
            'description' => $plan->description ? (string) $plan->description : null,
            'amount' => (float) ($plan->amount ?? 0),
            'days' => $plan->days !== null ? (int) $plan->days : null,
            'status' => Status::valueOf($plan->status),
            'limit_uses' => (bool) $plan->limit_uses,
            'uses_limit' => $plan->limit_uses ? ($plan->uses_limit !== null ? (int) $plan->uses_limit : null) : null,
            'created_at' => $plan->created_at?->toISOString(),
            'updated_at' => $plan->updated_at?->toISOString(),
            'deleted_at' => $plan->deleted_at?->toISOString(),
        ];

        if ($plan->relationLoaded('services')) {
            $payload['services'] = $plan->services
                ->sortBy('name')
                ->values()
                ->map(fn (Service $service): array => [
                    'id' => (int) $service->id,
                    'name' => (string) $service->name,
                ])
                ->all();
        }

        return $payload;
    }
}

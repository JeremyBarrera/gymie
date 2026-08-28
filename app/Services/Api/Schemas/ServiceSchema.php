<?php

namespace App\Services\Api\Schemas;

use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;

final class ServiceSchema
{
    private function __construct() {}

    

    public static function queryRules(): array
    {
        return [
            'searchable' => ['name', 'description'],
            'sortable' => ['id', 'created_at', 'name'],
            'default_sort' => '-id',
            'status_column' => null,
            'includes' => [],
            'filters' => [
                'created_at' => ['type' => 'datetime_range', 'column' => 'created_at'],
            ],
        ];
    }

    

    public static function storeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }

    

    public static function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }

    

    public static function resource(Service $service): array
    {
        return [
            'id' => (int) $service->id,
            'name' => (string) $service->name,
            'description' => $service->description ? (string) $service->description : null,
            'created_at' => $service->created_at?->toISOString(),
            'updated_at' => $service->updated_at?->toISOString(),
            'deleted_at' => $service->deleted_at?->toISOString(),
        ];
    }
}

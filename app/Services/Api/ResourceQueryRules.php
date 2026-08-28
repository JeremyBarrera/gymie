<?php

namespace App\Services\Api;

use App\Services\Api\Schemas\EnquirySchema;
use App\Services\Api\Schemas\ExpenseSchema;
use App\Services\Api\Schemas\FollowUpSchema;
use App\Services\Api\Schemas\InvoiceSchema;
use App\Services\Api\Schemas\MemberSchema;
use App\Services\Api\Schemas\PlanSchema;
use App\Services\Api\Schemas\ServiceSchema;
use App\Services\Api\Schemas\SubscriptionSchema;
use App\Services\Api\Schemas\UserSchema;
use InvalidArgumentException;

final class ResourceQueryRules
{
    

    private const SCHEMAS = [
        'members' => MemberSchema::class,
        'users' => UserSchema::class,
        'services' => ServiceSchema::class,
        'plans' => PlanSchema::class,
        'subscriptions' => SubscriptionSchema::class,
        'invoices' => InvoiceSchema::class,
        'expenses' => ExpenseSchema::class,
        'enquiries' => EnquirySchema::class,
        'follow-ups' => FollowUpSchema::class,
    ];

    

    public static function searchable(string $resourceKey): array
    {
        return self::rules($resourceKey)['searchable'];
    }

    

    public static function sortable(string $resourceKey): array
    {
        return self::rules($resourceKey)['sortable'];
    }

    public static function defaultSort(string $resourceKey): string
    {
        return self::rules($resourceKey)['default_sort'];
    }

    public static function statusColumn(string $resourceKey): ?string
    {
        return self::rules($resourceKey)['status_column'];
    }

    

    public static function includes(string $resourceKey): array
    {
        return self::rules($resourceKey)['includes'];
    }

    

    public static function filters(string $resourceKey): array
    {
        return self::rules($resourceKey)['filters'];
    }

    

    private static function rules(string $resourceKey): array
    {
        if (! array_key_exists($resourceKey, self::SCHEMAS)) {
            throw new InvalidArgumentException("Unknown API resource key [{$resourceKey}].");
        }

        $schema = self::SCHEMAS[$resourceKey];

        if (! method_exists($schema, 'queryRules')) {
            throw new InvalidArgumentException("API schema [{$schema}] must define a static queryRules() method.");
        }

        

        $rules = $schema::queryRules();

        foreach (['searchable', 'sortable', 'default_sort', 'status_column', 'includes', 'filters'] as $key) {
            if (! array_key_exists($key, $rules)) {
                throw new InvalidArgumentException("API schema [{$schema}] queryRules() is missing required key [{$key}].");
            }
        }

        return $rules;
    }
}

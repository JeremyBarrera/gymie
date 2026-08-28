<?php

namespace App\Support\Notifications;

use App\Helpers\Helpers;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class NotificationRecipients
{
    

    private const TOPIC_ALIASES = ['override' => 'follow_up'];

    private function __construct() {}

    

    public static function resolve(string $topic, array $defaultRoles = ['owner']): Collection
    {
        $topic = self::TOPIC_ALIASES[$topic] ?? $topic;

        $section = Helpers::getSettings()['notifications'][$topic] ?? [];

        $roleNames = is_array($section['roles'] ?? null) && $section['roles'] !== []
            ? array_values(array_filter($section['roles'], 'is_string'))
            : $defaultRoles;

        $userIds = is_array($section['users'] ?? null)
            ? array_values(array_filter($section['users'], 'is_numeric'))
            : [];

        return User::query()
            ->withoutGlobalScope('location')
            ->where(function ($query) use ($roleNames): void {
                $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', $roleNames));
            })
            ->when($userIds !== [], fn ($query) => $query->orWhereIn('id', $userIds))
            ->distinct()
            ->get();
    }
}

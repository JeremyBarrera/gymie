<?php

namespace App\Support\Notifications;

use App\Helpers\Helpers;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves notification recipients from the settings store.
 *
 * Recipients are configured per topic under `notifications.<topic>`: a list
 * of role names (`roles`) plus specific user ids (`users`). When the topic
 * has no configuration, the default roles are used.
 */
final class NotificationRecipients
{
    /**
     * Callers that still pass the pre-rename topic name resolve the
     * `follow_up` section.
     */
    private const TOPIC_ALIASES = ['override' => 'follow_up'];

    private function __construct() {}

    /**
     * Users who should receive notifications for the given topic.
     *
     * @param  string  $topic  Settings section under `notifications.*`.
     * @param  list<string>  $defaultRoles  Roles used when the settings
     *                                      section is missing or empty.
     * @return Collection<int, User>
     */
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

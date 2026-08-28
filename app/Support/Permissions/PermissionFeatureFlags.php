<?php

declare(strict_types=1);

namespace App\Support\Permissions;

use App\Helpers\Helpers;
use Spatie\Permission\Models\Role;

final class PermissionFeatureFlags
{
    public const OWNER_ROLE = 'owner';

    private const PERMISSION_ABILITY_PATTERN = '/^[A-Z][a-zA-Z]+:[\w]+$/';

    

    public static function protectedRoleNames(): array
    {
        return [self::OWNER_ROLE];
    }

    public static function isPermissionAbility(string $ability): bool
    {
        return (bool) preg_match(self::PERMISSION_ABILITY_PATTERN, $ability);
    }

    public static function isMasterEnabled(): bool
    {
        $permissions = self::settingsSection();

        return ($permissions['enabled'] ?? true) !== false;
    }

    

    public static function disabledPermissions(): array
    {
        $disabled = self::settingsSection()['disabled'] ?? [];

        if (! is_array($disabled)) {
            return [];
        }

        $values = [];

        foreach ($disabled as $name) {
            if (! is_string($name)) {
                continue;
            }

            $name = trim($name);

            if ($name !== '') {
                $values[] = $name;
            }
        }

        return array_values(array_unique($values));
    }

    public static function isDisabled(string $permission): bool
    {
        return in_array($permission, self::disabledPermissions(), true);
    }

    public static function isProtectedRoleName(string $roleName): bool
    {
        return in_array($roleName, self::protectedRoleNames(), true);
    }

    

    public static function isProtectedRoleDeletion(string $ability, array $arguments): bool
    {
        if (! in_array($ability, ['delete', 'forceDelete', 'restore'], true)) {
            return false;
        }

        foreach ($arguments as $argument) {
            if ($argument instanceof Role && self::isProtectedRoleName((string) $argument->getAttribute('name'))) {
                return true;
            }
        }

        return false;
    }

    

    private static function settingsSection(): array
    {
        $settings = Helpers::getSettings();
        $permissions = $settings['permissions'] ?? null;

        return is_array($permissions) ? $permissions : [];
    }
}

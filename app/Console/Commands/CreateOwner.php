<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\User;
use App\Support\Permissions\PermissionFeatureFlags;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Bootstraps the top-level owner account that provisions locations.
 *
 * The owner role bypasses every permission and every location scope, and is
 * the only role that can operate across all locations. All other accounts are
 * scoped through their location-based roles and `user_locations`. This command
 * only needs to run once.
 */
class CreateOwner extends Command
{
    /**
     * @var string
     */
    protected $signature = 'gymie:create-owner {email : Owner account email} {--name= : Owner display name} {--password= : Owner password (random when omitted)}';

    /**
     * @var string
     */
    protected $description = 'Create the top-level owner role and its first account';

    public function handle(): int
    {
        $role = Role::findOrCreate(PermissionFeatureFlags::OWNER_ROLE, 'web');

        $email = $this->argument('email');

        $name = $this->option('name') ?: Str::headline((string) $email);

        $password = $this->option('password') ?: Str::random(32);

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'status' => Status::Active,
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles([$role->getKey()]);

        $this->info("Owner account ready: {$email}");

        if (! $this->option('password')) {
            $this->warn("Random password generated: {$password}");
            $this->warn('Save it now; it will not be shown again.');
        }

        return self::SUCCESS;
    }
}

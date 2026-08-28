<?php

use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Notifications\FollowUpAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

function o1Migration(string $pattern): object
{
    return require glob(database_path("migrations/{$pattern}"))[0];
}

it('adds a nullable indexed archived_at column to the notifications table', function (): void {
    expect(Schema::hasColumn('notifications', 'archived_at'))->toBeTrue()
        ->and(collect(Schema::getIndexes('notifications'))
            ->contains(fn (array $index): bool => $index['columns'] === ['archived_at']))
        ->toBeTrue();
});

it('rolls the archived_at migration back and forward without losing rows', function (): void {
    $recipient = User::factory()->create();

    $migration = o1Migration('*add_archived_at_to_notifications_table.php');
    $migration->down();
    expect(Schema::hasColumn('notifications', 'archived_at'))->toBeFalse();

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'notifiable_type' => 'user',
        'notifiable_id' => $recipient->id,
        'data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('notifications')->count())->toBe(1)
        ->and(DB::table('notifications')->value('archived_at'))->toBeNull();
});

it('persists one follow-up notification per resolved recipient with the contract payload', function (): void {
    Role::create(['name' => 'manager']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $pinned = User::factory()->create();
    $outsider = User::factory()->create();

    Helpers::setTestSettingsOverride([
        'notifications' => [
            'follow_up' => [
                'roles' => ['manager'],
                'users' => [$pinned->id],
            ],
        ],
    ]);

    $actor = User::factory()->create(['name' => 'Toro']);
    $member = Member::factory()->create(['name' => 'Jane Doe', 'code' => 'GY-42']);
    $subscription = Subscription::factory()->create(['member_id' => $member->id]);
    $invoice = Invoice::factory()->create(['subscription_id' => $subscription->id]);

    FollowUpAlert::send('override_checkin', $member, $actor, 'gate was busy', $subscription, $invoice);

    foreach ([$manager, $pinned] as $recipient) {
        
        $payload = $recipient->unreadNotifications()->first()?->data;

        expect($payload)->toBeArray()
            ->and(array_keys($payload))->toBe([
                'action',
                'reason',
                'actor',
                'member',
                'subscription_id',
                'invoice_id',
                'occurred_at',
            ])
            ->and($payload['action'])->toBe('override_checkin')
            ->and($payload['reason'])->toBe('gate was busy')
            ->and($payload['actor'])->toBe(['id' => $actor->id, 'name' => 'Toro'])
            ->and($payload['member'])->toBe(['id' => $member->id, 'name' => 'Jane Doe', 'code' => 'GY-42'])
            ->and($payload['subscription_id'])->toBe($subscription->id)
            ->and($payload['invoice_id'])->toBe($invoice->id)
            ->and(new DateTimeImmutable((string) $payload['occurred_at']))->toBeInstanceOf(DateTimeImmutable::class);
    }

    expect($outsider->unreadNotifications()->count())->toBe(0);
});

it('leaves subscription and invoice ids null when they are not provided', function (): void {
    Role::create(['name' => 'owner']);
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $actor = User::factory()->create();
    $member = Member::factory()->create();

    FollowUpAlert::send('new_subscription', $member, $actor, null);

    
    $payload = $owner->unreadNotifications()->first()->data;

    expect($payload['action'])->toBe('new_subscription')
        ->and($payload['reason'])->toBeNull()
        ->and($payload['subscription_id'])->toBeNull()
        ->and($payload['invoice_id'])->toBeNull();
});

it('reads legacy override scope values under the follow_up key', function (): void {
    Helpers::setTestSettingsOverride([
        'notifications' => [
            'override' => [
                'roles' => ['manager'],
                'users' => [7],
            ],
        ],
    ]);

    $notifications = Helpers::getSettings()['notifications'];

    expect($notifications['follow_up'])->toBe(['roles' => ['manager'], 'users' => [7]])
        ->and(array_key_exists('override', $notifications))->toBeFalse();
});

it('renames the manage_override_notifications permission keeping role assignments', function (): void {
    $role = Role::create(['name' => 'manager']);
    $oldId = DB::table('permissions')->insertGetId([
        'name' => 'manage_override_notifications',
        'guard_name' => 'web',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_has_permissions')->insert(['permission_id' => $oldId, 'role_id' => $role->id]);

    $up = o1Migration('*rename_manage_override_notifications_permission.php');

    $up->up();

    $newIdAfterUp = DB::table('permissions')
        ->where('name', 'manage_follow_up_alerts')
        ->value('id');

    expect(DB::table('permissions')->where('name', 'manage_follow_up_alerts')->exists())->toBeTrue()
        ->and(DB::table('permissions')->where('id', $oldId)->doesntExist())->toBeTrue()
        ->and(DB::table('role_has_permissions')
            ->where('permission_id', $newIdAfterUp)
            ->where('role_id', $role->id)
            ->exists())->toBeTrue();

    $up->down();

    $oldIdAfterDown = DB::table('permissions')
        ->where('name', 'manage_override_notifications')
        ->value('id');

    expect(DB::table('permissions')->where('name', 'manage_override_notifications')->exists())->toBeTrue()
        ->and(DB::table('permissions')->where('name', 'manage_follow_up_alerts')->doesntExist())->toBeTrue()
        ->and(DB::table('role_has_permissions')
            ->where('permission_id', $oldIdAfterDown)
            ->where('role_id', $role->id)
            ->exists())->toBeTrue();
});

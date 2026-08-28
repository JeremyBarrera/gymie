<?php

use App\Helpers\Helpers;
use App\Models\User;
use App\Support\Notifications\NotificationRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

it('falls back to the default role when the topic has no configuration', function (): void {
    Role::create(['name' => 'owner']);
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $other = User::factory()->create();

    $recipients = NotificationRecipients::resolve('follow_up');

    expect($recipients->pluck('id'))
        ->toContain($owner->id)
        ->not->toContain($other->id);
});

it('resolves the roles and specific users configured for the topic', function (): void {
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

    $recipients = NotificationRecipients::resolve('follow_up');

    expect($recipients->pluck('id'))
        ->toContain($manager->id)
        ->toContain($pinned->id)
        ->not->toContain($outsider->id);
});

it('treats each notification topic independently', function (): void {
    Role::create(['name' => 'manager']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    Helpers::setTestSettingsOverride([
        'notifications' => [
            'subscription_status' => [
                'roles' => ['manager'],
                'users' => [],
            ],
        ],
    ]);

    expect(NotificationRecipients::resolve('subscription_status')->pluck('id'))->toContain($manager->id);

    
    expect(NotificationRecipients::resolve('follow_up')->pluck('id'))->not->toContain($manager->id);
});

it('maps the pre-rename override topic onto the follow-up scope', function (): void {
    Role::create(['name' => 'manager']);
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $outsider = User::factory()->create();

    Helpers::setTestSettingsOverride([
        'notifications' => [
            'override' => [
                'roles' => ['manager'],
                'users' => [],
            ],
        ],
    ]);

    expect(NotificationRecipients::resolve('override')->pluck('id'))
        ->toContain($manager->id)
        ->not->toContain($outsider->id);
});

<?php

namespace Tests\Feature;

use App\Helpers\Helpers;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarkSubscriptionsStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-02-11 12:00:00'));

        Helpers::setTestSettingsOverride([
            'subscriptions' => [
                'expiring_days' => 7,
            ],
        ]);

        
        Role::create(['name' => 'owner']);
    }

    protected function tearDown(): void
    {
        Helpers::setTestSettingsOverride(null);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_marks_expired_and_expiring_subscriptions_and_sends_database_notification(): void
    {
        $admin = User::factory()->create([
            'name' => 'Owner',
            'email' => 'test@example.com',
        ]);

        $admin->assignRole('owner');

        Subscription::factory()->create([
            'start_date' => now()->subMonth(),
            'end_date' => now()->subDay(),
            'status' => 'ongoing',
        ]);
        Subscription::factory()->create([
            'start_date' => now()->subMonth(),
            'end_date' => now()->addDays(3),
            'status' => 'ongoing',
        ]);
        Subscription::factory()->create([
            'start_date' => now()->subMonth(),
            'end_date' => now()->addDays(14),
            'status' => 'ongoing',
        ]);

        $this->artisan('gymie:subscriptions', [
            '--mark-expired' => true,
            '--mark-expiring' => true,
        ])
            ->assertExitCode(0);

        $this->assertSame(1, Subscription::query()->where('status', 'expired')->count());
        $this->assertSame(1, Subscription::query()->where('status', 'expiring')->count());
        $this->assertSame(1, Subscription::query()->where('status', 'ongoing')->count());

        $notification = $admin->notifications()->latest()->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString(__('app.notifications.subscription_status_update_title'), (string) ($notification->data['title'] ?? ''));
        $this->assertStringContainsString('1 expired', (string) ($notification->data['body'] ?? ''));
        $this->assertStringContainsString('1 expiring', (string) ($notification->data['body'] ?? ''));
    }

    public function test_it_notifies_configured_roles_instead_of_owners(): void
    {
        Helpers::setTestSettingsOverride([
            'subscriptions' => [
                'expiring_days' => 7,
            ],
            'notifications' => [
                'subscription_status' => [
                    'roles' => ['manager'],
                    'users' => [],
                ],
            ],
        ]);

        $owner = User::factory()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
        ]);
        $owner->assignRole('owner');

        Role::create(['name' => 'manager']);
        $manager = User::factory()->create([
            'name' => 'Manager',
            'email' => 'manager@example.com',
        ]);
        $manager->assignRole('manager');

        Subscription::factory()->create([
            'start_date' => now()->subMonth(),
            'end_date' => now()->subDay(),
            'status' => 'ongoing',
        ]);

        $this->artisan('gymie:subscriptions', [
            '--mark-expired' => true,
            '--mark-expiring' => true,
        ])
            ->assertExitCode(0);

        $this->assertSame(0, $owner->notifications()->count());
        $this->assertSame(1, $manager->notifications()->count());
    }
}

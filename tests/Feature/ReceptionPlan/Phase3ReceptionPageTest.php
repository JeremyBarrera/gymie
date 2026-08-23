<?php

use App\Enums\Status;
use App\Filament\Pages\Reception;
use App\Helpers\Helpers;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\FollowUpAlertNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
    Notification::fake();
});

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

function receptionStaff(): User
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

function receptionSetup(?Location $location = null): array
{
    $location ??= Location::factory()->create();

    $service = Service::factory()->create(['name' => 'Gym Access']);
    $plan = Plan::factory()->create([
        'service_id' => $service->id,
        'code' => 'GYM-01',
        'name' => 'Monthly',
        'track_uses' => true,
        'uses_limit' => 12,
        'status' => Status::Active,
    ]);

    $member = Member::factory()->create([
        'name' => 'John Doe',
        'contact' => '5551234567',
        'government_id' => 'GOV123456',
        'code' => 'GY-1',
        'status' => Status::Active,
    ]);

    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => Carbon::today()->subDays(10),
        'end_date' => Carbon::today()->addDays(20),
    ]);

    return [$location, $service, $plan, $member, $subscription];
}

function receptionQueueEntry(Location $location, Member $member, Subscription $subscription, string $kind = 'checkin'): QueueEntry
{
    return QueueEntry::create([
        'uuid' => (string) Str::uuid(),
        'location_id' => $location->id,
        'kind' => $kind,
        'payload' => [
            'member_id' => $member->id,
            'subscription_id' => $subscription->id,
            'identifier_type' => 'contact',
            'identifier_value' => $member->contact,
        ],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);
}

it('renders the reception page for an owner', function (): void {
    $staff = receptionStaff();
    $location = Location::factory()->create();

    $this->actingAs($staff)
        ->get('/reception')
        ->assertOk()
        ->assertSee('Sign-Up')
        ->assertSee(__('app.reception.manual_search_placeholder'));
});

it('lists waiting checkin and signup queue entries scoped to the location', function (): void {
    [$location, , , $member, $subscription] = receptionSetup();

    $checkinEntry = receptionQueueEntry($location, $member, $subscription, 'checkin');
    $signupEntry = receptionQueueEntry($location, $member, $subscription, 'signup');

    $component = Livewire::actingAs(receptionStaff())
        ->test(Reception::class);

    $component->assertSet('checkinEntries', fn (array $entries): bool => collect($entries)->pluck('id')->contains($checkinEntry->id))
        ->assertSet('signupEntries', fn (array $entries): bool => collect($entries)->pluck('id')->contains($signupEntry->id));
});

it('claims a waiting queue entry atomically', function (): void {
    [$location, , , $member, $subscription] = receptionSetup();
    $staff = receptionStaff();
    $entry = receptionQueueEntry($location, $member, $subscription);

    LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->call('claim', $entry->id);

    expect($entry->refresh())
        ->status->toBe('attending')
        ->claimed_by_user_id->toBe($staff->id)
        ->claimed_at->not->toBeNull();
});

it('does not claim an entry that is already attending', function (): void {
    [$location, , , $member, $subscription] = receptionSetup();
    $staff = receptionStaff();
    $other = receptionStaff();

    $entry = receptionQueueEntry($location, $member, $subscription);
    $entry->update(['status' => 'attending', 'claimed_by_user_id' => $other->id]);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->call('claim', $entry->id)
        ->assertDispatched('notify');

    expect($entry->refresh()->claimed_by_user_id)->toBe($other->id);
});

it('approves a checkin entry and records a plan check-in', function (): void {
    [$location, , , $member, $subscription] = receptionSetup();
    $staff = receptionStaff();
    $entry = receptionQueueEntry($location, $member, $subscription);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->call('openConfirmOverlay', $entry->id, 'approve')
        ->call('confirm');

    expect($entry->refresh()->status)->toBe('approved');
    expect(PlanCheckIn::where('subscription_id', $subscription->id)->exists())->toBeTrue();
});

it('denies a queue entry with a reason', function (): void {
    [$location, , , $member, $subscription] = receptionSetup();
    $staff = receptionStaff();
    $entry = receptionQueueEntry($location, $member, $subscription);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->call('openConfirmOverlay', $entry->id, 'deny')
        ->set('denyReason', 'Expired ID')
        ->call('confirm');

    $entry->refresh();

    expect($entry->status)->toBe('denied')
        ->and($entry->denied_reason)->toBe('Expired ID');
});

it('overrides a checkin entry when the feature is active and notifies owners', function (): void {
    Feature::activate('checkin.override');

    [$location, , , $member, $subscription] = receptionSetup();
    $staff = receptionStaff();
    $entry = receptionQueueEntry($location, $member, $subscription);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->call('openConfirmOverlay', $entry->id, 'override')
        ->call('confirm');

    $entry->refresh();

    expect($entry->status)->toBe('approved')
        ->and($entry->override)->toBeTrue()
        ->and($entry->override_by_user_id)->toBe($staff->id);

    Notification::assertSentTo($staff, FollowUpAlertNotification::class);
});

it('overrides a checkin entry and notifies the configured roles instead of owners', function (): void {
    Feature::activate('checkin.override');

    Role::firstOrCreate(['name' => 'manager']);

    Helpers::setTestSettingsOverride([
        'notifications' => [
            'override' => [
                'roles' => ['manager'],
                'users' => [],
            ],
        ],
    ]);

    [$location, , , $member, $subscription] = receptionSetup();
    $staff = receptionStaff();
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $entry = receptionQueueEntry($location, $member, $subscription);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->call('openConfirmOverlay', $entry->id, 'override')
        ->call('confirm');

    $entry->refresh();

    expect($entry->status)->toBe('approved')
        ->and($entry->override)->toBeTrue();

    Notification::assertSentTo($manager, FollowUpAlertNotification::class);
    Notification::assertNotSentTo($staff, FollowUpAlertNotification::class);
});

it('does not override when the feature flag is off', function (): void {
    Feature::deactivate('checkin.override');

    [$location, , , $member, $subscription] = receptionSetup();
    $staff = receptionStaff();
    $entry = receptionQueueEntry($location, $member, $subscription);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->call('openConfirmOverlay', $entry->id, 'override')
        ->call('confirm')
        ->assertDispatched('notify');

    expect($entry->refresh()->status)->toBe('waiting');
});

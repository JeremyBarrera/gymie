<?php

use App\Enums\Status;
use App\Filament\Livewire\LiveSignupPopup;
use App\Filament\Pages\Reception;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\QueueEntry;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Members\MemberApplicationService;
use App\Support\Billing\InvoiceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
});

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

function liveReceptionStaff(): User
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

function liveReceptionSignupPayload(Location $location, array $overrides = []): array
{
    return array_merge([
        'name' => 'Jane Doe',
        'contact' => '5559876543',
        'government_id' => 'GOV999888',
        'email' => 'jane@example.com',
        'gender' => 'female',
        'dob' => '1995-05-10',
        'emergency_contact' => null,
        'health_issue' => null,
        'goal' => 'Lose weight',
        'location_id' => $location->id,
    ], $overrides);
}

function liveReceptionSignupEntry(Location $location, array $payloadOverrides = []): QueueEntry
{
    return QueueEntry::create([
        'uuid' => (string) Str::uuid(),
        'location_id' => $location->id,
        'kind' => 'signup',
        'payload' => liveReceptionSignupPayload($location, $payloadOverrides),
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);
}

function liveReceptionPhoto(): string
{
    return 'data:image/png;base64,'.base64_encode('tiny-png-bytes');
}

function liveReceptionPlan(): Plan
{
    return Plan::factory()->create(['amount' => 100, 'status' => Status::Active]);
}

/**
 * @return array<string, mixed>
 */
function liveReceptionSale(Plan $plan, array $overrides = []): array
{
    return array_merge([
        'plan_id' => $plan->id,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'invoices' => [[
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'discount_amount' => 0,
            'paid_amount' => 100,
        ]],
    ], $overrides);
}

it('auto-opens the verify overlay when a signup entry arrives live', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', true)
        ->assertSet('selectedQueueEntryId', $entry->id)
        ->assertSet('verifyStep', 1)
        ->assertSet('verifyForm.name', 'Jane Doe')
        ->assertSet('verifyForm.contact', '5559876543')
        ->assertSet('verifyForm.government_id', 'GOV999888');
});

it('does not auto-open the verify overlay for checkin entries', function (): void {
    $location = Location::factory()->create();
    $entry = QueueEntry::create([
        'uuid' => (string) Str::uuid(),
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => [
            'member_id' => 1,
            'subscription_id' => 1,
            'identifier_type' => 'contact',
            'identifier_value' => '5551234567',
        ],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', false)
        ->assertSet('selectedQueueEntryId', null);
});

it('queues signup entries that arrive while the verify overlay is open', function (): void {
    $location = Location::factory()->create();
    $first = liveReceptionSignupEntry($location, ['name' => 'First Person']);
    $second = liveReceptionSignupEntry($location, ['name' => 'Second Person']);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $first->id])
        ->assertSet('showVerifyOverlay', true)
        ->assertSet('selectedQueueEntryId', $first->id)
        ->call('onQueueEntryCreated', ['queueEntryId' => $second->id])
        ->assertSet('popupQueue', [$second->id])
        ->assertSet('selectedQueueEntryId', $first->id)
        ->call('closeVerifyOverlay')
        ->assertSet('showVerifyOverlay', true)
        ->assertSet('selectedQueueEntryId', $second->id)
        ->assertSet('popupQueue', []);
});

it('confirms a signup and creates the member with photo and application', function (): void {
    Storage::fake('public');

    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();
    $photo = liveReceptionPhoto();

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', true)
        ->set('verifyForm.name', 'Jane Edited Doe')
        ->set('verifyPhoto', $photo)
        ->call('verifyContinue')
        ->assertSet('verifyStep', 2)
        ->call('verifyContinue')
        ->assertSet('verifyStep', 3)
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', false);

    expect($entry->refresh()->status)->toBe('approved');

    $member = Member::where('contact', '5559876543')->first();
    expect($member)->not->toBeNull()
        ->and($member->name)->toBe('Jane Edited Doe')
        ->and($member->government_id)->toBe('GOV999888')
        ->and($member->status)->toBe(Status::Active)
        ->and($member->currentLocation())->toBeNull()
        ->and($member->photo)->not->toBeNull()
        ->and($member->code)->not->toBeNull();

    Storage::disk('public')->assertExists($member->photo);

    $application = MemberApplication::where('identifier_value', '5559876543')->first();
    expect($application)->not->toBeNull()
        ->and($application->status)->toBe('approved')
        ->and($application->created_member_id)->toBe($member->id);

    $subscription = Subscription::where('member_id', $member->id)->first();
    expect($subscription)->not->toBeNull()
        ->and($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->status)->toBe(Status::Ongoing);

    expect(Invoice::where('subscription_id', $subscription->id)->exists())->toBeTrue()
        ->and(PlanCheckIn::count())->toBe(0);
});

it('requires a plan before approving a signup', function (): void {
    Storage::fake('public');

    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->set('verifyPhoto', liveReceptionPhoto())
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', true);

    expect(Member::count())->toBe(0)
        ->and(Subscription::count())->toBe(0)
        ->and($entry->refresh()->status)->toBe('waiting');
});

it('creates a subscription and invoice but no usage when approving a signup', function (): void {
    Storage::fake('public');

    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->set('verifyPhoto', liveReceptionPhoto())
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', false);

    $member = Member::where('contact', '5559876543')->first();
    expect($member)->not->toBeNull();

    expect(Subscription::where('member_id', $member->id)->exists())->toBeTrue()
        ->and(Invoice::whereHas('subscription', fn ($query) => $query->where('member_id', $member->id))->exists())->toBeTrue()
        ->and(PlanCheckIn::count())->toBe(0)
        ->and(QueueEntry::where('kind', 'signup')->count())->toBe(1);
});

it('blocks a user without member or subscription permission', function (): void {
    Storage::fake('public');

    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();

    $plainUser = User::factory()->create();

    Livewire::actingAs($plainUser)
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->set('verifyPhoto', liveReceptionPhoto())
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', true);

    expect(Member::count())->toBe(0)
        ->and(Subscription::count())->toBe(0);
});

it('allows a user with only the Create Member permission to approve a signup', function (): void {
    Storage::fake('public');

    Permission::findOrCreate('Create:Member', 'web');
    $memberCreator = User::factory()->create();
    $memberCreator->givePermissionTo('Create:Member');

    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();

    $memberCreator->locations()->attach($location->id);

    Livewire::actingAs($memberCreator)
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->set('verifyPhoto', liveReceptionPhoto())
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', false);

    expect(Member::latest('id')->first())->not->toBeNull()
        ->and(Subscription::count())->toBe(1);
});

it('opens the verify overlay from the queue card', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location, ['name' => 'Card Opened']);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->assertSet('showVerifyOverlay', true)
        ->assertSet('verifyForm.name', 'Card Opened');
});

it('keeps the overlay open and shows an error when details are invalid', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->set('verifyForm.name', '')
        ->set('verifyPhoto', liveReceptionPhoto())
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', true);

    expect(Member::count())->toBe(0)
        ->and($entry->refresh()->status)->toBe('waiting');
});

it('requires a photo before approving the signup', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', true);

    expect(Member::count())->toBe(0)
        ->and($entry->refresh()->status)->toBe('waiting');
});

it('rejects a signup whose name, phone, and ID all match a member', function (): void {
    $location = Location::factory()->create();
    $staff = liveReceptionStaff();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();

    Livewire::actingAs($staff);

    Member::factory()->create([
        'name' => 'Jane Doe',
        'contact' => Helpers::normalizePhone('5559876543') ?? '5559876543',
        'government_id' => 'GOV999888',
        'email' => 'jane@example.com',
        'status' => Status::Active,
    ]);

    Livewire::test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->set('verifyPhoto', liveReceptionPhoto())
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', true);

    expect(Member::count())->toBe(1)
        ->and($entry->refresh()->status)->toBe('waiting');
});

it('does not create a second member when the entry was already handled', function (): void {
    Storage::fake('public');

    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $entry->update(['status' => 'approved']);
    $plan = liveReceptionPlan();

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->set('verifyPhoto', liveReceptionPhoto())
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertDispatched('notify');

    expect(Member::count())->toBe(0);
});

it('removes the approved signup from the queue list', function (): void {
    Storage::fake('public');

    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('signupEntries', fn (array $entries): bool => collect($entries)->pluck('id')->contains($entry->id))
        ->set('verifyPhoto', liveReceptionPhoto())
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertSet('signupEntries', []);
});

it('closes the verify overlay in other tabs when the entry is resolved elsewhere', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', true)
        ->call('onQueueEntryResolved', ['queueEntryId' => $entry->id])
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', false)
        ->assertSet('selectedQueueEntryId', null);
});

it('closes the verify overlay in other tabs when the entry expires', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', true)
        ->call('onQueueEntryExpired', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', false)
        ->assertSet('selectedQueueEntryId', null);
});

it('closes a stale verify overlay on poll when the entry was handled in another tab', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    $component = Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', true);

    $entry->update(['status' => 'approved', 'override' => false]);

    $component->call('loadQueueEntries')
        ->assertSet('showVerifyOverlay', false)
        ->assertSet('selectedQueueEntryId', null);
});

it('mounts the global signup popup on every admin page', function (): void {
    $location = Location::factory()->create();

    $this->actingAs(liveReceptionStaff())
        ->get(route('filament.admin.pages.dashboard'))
        ->assertSuccessful()
        ->assertSee('live-signup-popup', false);

    $this->actingAs(liveReceptionStaff())
        ->get(Reception::getUrl())
        ->assertSuccessful()
        ->assertSee('live-signup-popup', false);
});

it('does not mount the global popup for guests', function (): void {
    Location::factory()->create();

    auth()->logout();

    $this->get(route('filament.admin.pages.dashboard'))
        ->assertRedirect();
});

it('delegates the popup to the reception page and only keeps the pending badge there', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    $component = Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->assertSet('popupEnabled', true);

    $component->set('popupEnabled', false)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', false)
        ->assertSet('selectedQueueEntryId', null)
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->pluck('id')->contains($entry->id));
});

it('opens the verify overlay from the global popup when a signup arrives', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', true)
        ->assertSet('selectedQueueEntryId', $entry->id)
        ->assertSet('verifyStep', 1)
        ->assertSet('verifyForm.name', 'Jane Doe');
});

it('ignores checkin and non-pending events in the global popup', function (): void {
    $location = Location::factory()->create();
    $checkin = QueueEntry::create([
        'uuid' => (string) Str::uuid(),
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => ['member_id' => 1, 'subscription_id' => 1],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);
    $expired = liveReceptionSignupEntry($location, ['name' => 'Expired Person']);
    $expired->update(['status' => 'expired']);

    Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $checkin->id])
        ->assertSet('showVerifyOverlay', false)
        ->call('onQueueEntryCreated', ['queueEntryId' => $expired->id])
        ->assertSet('showVerifyOverlay', false)
        ->assertSet('selectedQueueEntryId', null);
});

it('queues signups arriving while the global popup overlay is open', function (): void {
    $location = Location::factory()->create();
    $first = liveReceptionSignupEntry($location, ['name' => 'First Person']);
    $second = liveReceptionSignupEntry($location, ['name' => 'Second Person']);

    Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $first->id])
        ->assertSet('showVerifyOverlay', true)
        ->call('onQueueEntryCreated', ['queueEntryId' => $second->id])
        ->assertSet('popupQueue', [$second->id])
        ->assertSet('selectedQueueEntryId', $first->id)
        ->call('closeVerifyOverlay')
        ->assertSet('showVerifyOverlay', true)
        ->assertSet('selectedQueueEntryId', $second->id)
        ->assertSet('popupQueue', []);
});

it('confirms a signup from the global popup and creates the member', function (): void {
    Storage::fake('public');

    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();

    Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('verifyForm.name', 'Global Popup Member')
        ->set('verifyPhoto', liveReceptionPhoto())
        ->call('verifyContinue')
        ->assertSet('verifyStep', 2)
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', false);

    expect($entry->refresh()->status)->toBe('approved');

    $member = Member::where('contact', '5559876543')->first();
    expect($member)->not->toBeNull()
        ->and($member->name)->toBe('Global Popup Member')
        ->and($member->status)->toBe(Status::Active);

    expect(Subscription::where('member_id', $member->id)->exists())->toBeTrue();
});

it('closes the global popup overlay when the entry is resolved elsewhere', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', true)
        ->call('onQueueEntryResolved', ['queueEntryId' => $entry->id])
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', false)
        ->assertSet('selectedQueueEntryId', null);
});

it('seeds the pending queue from unattended signups on mount', function (): void {
    $location = Location::factory()->create();
    $waiting = liveReceptionSignupEntry($location, ['name' => 'Waiting Person']);
    $approved = liveReceptionSignupEntry($location, ['name' => 'Approved Person']);
    $approved->update(['status' => 'approved']);
    $expired = liveReceptionSignupEntry($location, ['name' => 'Expired Person']);
    $expired->update(['status' => 'expired']);
    $old = liveReceptionSignupEntry($location, ['name' => 'Old Person']);
    QueueEntry::where('id', $old->id)->update(['created_at' => now()->subHours(2)]);

    Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->pluck('id')->all() === [
            $waiting->id,
        ]);
});

it('moves a signup to the pending queue when its popup is closed without action', function (): void {
    $location = Location::factory()->create();

    $component = Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->assertSet('pendingQueue', []);

    $entry = liveReceptionSignupEntry($location);

    $component->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', true)
        ->assertSet('pendingQueue', [])
        ->call('closeVerifyOverlay')
        ->assertSet('showVerifyOverlay', false)
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->pluck('id')->all() === [$entry->id]);
});

it('keeps rotating popups and parks closed ones in pending', function (): void {
    $location = Location::factory()->create();
    $first = liveReceptionSignupEntry($location, ['name' => 'First Person']);
    $second = liveReceptionSignupEntry($location, ['name' => 'Second Person']);

    Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $first->id])
        ->call('onQueueEntryCreated', ['queueEntryId' => $second->id])
        ->assertSet('selectedQueueEntryId', $first->id)
        ->call('closeVerifyOverlay')
        ->assertSet('showVerifyOverlay', true)
        ->assertSet('selectedQueueEntryId', $second->id)
        ->assertSet('popupQueue', []);
});

it('does not auto-open the verify overlay for an inactive (background) tab but still lists the entry', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    $component = Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id], false)
        ->assertSet('showVerifyOverlay', false)
        ->assertSet('selectedQueueEntryId', null);

    expect(collect($component->get('signupEntries'))->contains('id', $entry->id))->toBeTrue();
});

it('does not auto-open the check-in overlay for an inactive tab but still lists the entry', function (): void {
    $location = Location::factory()->create();
    $entry = QueueEntry::create([
        'uuid' => (string) Str::uuid(),
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => [
            'member_id' => 1,
            'subscription_id' => 1,
            'identifier_type' => 'contact',
            'identifier_value' => '5551234567',
        ],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);

    $component = Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id], false)
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('selectedCheckInEntryId', null);

    expect(collect($component->get('checkinEntries'))->contains('id', $entry->id))->toBeTrue();
});

it('verifies an entry from the pending queue and creates the member', function (): void {
    Storage::fake('public');

    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();

    Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->call('verifyFromPending', $entry->id)
        ->assertSet('showVerifyOverlay', true)
        ->assertSet('pendingQueue', [])
        ->set('verifyPhoto', liveReceptionPhoto())
        ->set('verifyForm.sale', liveReceptionSale($plan))
        ->call('confirmSignup')
        ->assertSet('showVerifyOverlay', false);

    expect($entry->refresh()->status)->toBe('approved');
    expect(Member::where('contact', '5559876543')->exists())->toBeTrue();
});

it('recalculates the sale summary when the plan changes', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $plan = liveReceptionPlan();

    $summary = InvoiceCalculator::summary((float) $plan->amount, Helpers::getTaxRate() ?: 0, 0, 0);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->set('verifyForm.sale.start_date', now()->toDateString())
        ->set('verifyForm.sale.plan_id', $plan->id)
        ->assertSet('verifyForm.sale.end_date', Helpers::calculateSubscriptionEndDate(now()->toDateString(), $plan->id))
        ->assertSet('verifyForm.sale.fee', $summary['fee'])
        ->assertSet('verifyForm.sale.total', $summary['total'])
        ->assertSet('verifyForm.sale.due', $summary['due']);
});

it('opens and closes every overlay through modal dispatches only', function (): void {
    $location = Location::factory()->create();
    $signupEntry = liveReceptionSignupEntry($location);
    $checkInEntry = QueueEntry::create([
        'uuid' => (string) Str::uuid(),
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => [
            'member_id' => liveCheckInOverlayMember()->id,
            'identifier_type' => 'contact',
            'identifier_value' => '5559876543',
        ],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $signupEntry->id)
        ->assertDispatched('open-modal', id: 'verify-overlay')
        ->call('closeVerifyOverlay')
        ->assertDispatched('close-modal', id: 'verify-overlay')
        ->call('openCheckInOverlay', $checkInEntry->id)
        ->assertDispatched('open-modal', id: 'checkin-overlay')
        ->call('closeCheckInOverlay')
        ->assertDispatched('close-modal', id: 'checkin-overlay')
        ->call('openConfirmOverlay', $signupEntry->id, 'approve')
        ->assertDispatched('open-modal', id: 'confirm-overlay')
        ->call('closeConfirmOverlay')
        ->assertDispatched('close-modal', id: 'confirm-overlay');
});

it('closes every open overlay by dispatch when the active tab changes', function (): void {
    $location = Location::factory()->create();
    $signupEntry = liveReceptionSignupEntry($location);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $signupEntry->id)
        ->call('openConfirmOverlay', $signupEntry->id, 'deny')
        ->call('setActiveTab', 'checkin')
        ->assertDispatched('close-modal', id: 'verify-overlay')
        ->assertDispatched('close-modal', id: 'confirm-overlay')
        ->assertSet('showVerifyOverlay', false)
        ->assertSet('showConfirmOverlay', false);
});

it('never auto-opens overlays with x-init', function (): void {
    foreach (['verify-overlay', 'checkin-overlay', 'confirm-overlay'] as $overlay) {
        $view = file_get_contents(resource_path("views/filament/pages/partials/{$overlay}.blade.php"));

        expect($view)->not->toContain('$nextTick(() => open())');
    }
});

function liveCheckInOverlayMember(): Member
{
    return Member::factory()->create([
        'status' => Status::Active,
        'contact' => '5559876543',
    ]);
}

it('removes resolved and claimed entries from the pending queue', function (): void {
    $location = Location::factory()->create();
    $resolved = liveReceptionSignupEntry($location, ['name' => 'Resolved Person']);
    $claimed = liveReceptionSignupEntry($location, ['name' => 'Claimed Person']);
    $claimed->update(['status' => 'attending', 'claimed_at' => now(), 'claimed_by_user_id' => liveReceptionStaff()->id]);

    $component = Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->pluck('id')->contains($resolved->id));

    $component->call('onQueueEntryResolved', ['queueEntryId' => $resolved->id])
        ->call('onQueueEntryClaimed', ['queueEntryId' => $claimed->id])
        ->assertSet('pendingQueue', []);
});

it('shows the pending queue badge with unattended signups on any admin page', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    $this->actingAs(liveReceptionStaff())
        ->get(route('filament.admin.pages.dashboard'))
        ->assertSuccessful()
        ->assertSee('pending-fab', false)
        ->assertSee('Jane Doe');
});

it('hides a claimed entry from the pending queue but saves its spot', function (): void {
    $location = Location::factory()->create();
    $first = liveReceptionSignupEntry($location, ['name' => 'First']);
    $second = liveReceptionSignupEntry($location, ['name' => 'Second']);
    $third = liveReceptionSignupEntry($location, ['name' => 'Third']);

    QueueEntry::whereKey($first->id)->update(['created_at' => now()->subSeconds(2)]);
    QueueEntry::whereKey($second->id)->update(['created_at' => now()->subSecond()]);
    QueueEntry::whereKey($third->id)->update(['created_at' => now()]);

    $component = Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->pluck('name')->all() === ['Third', 'Second', 'First']);

    $second->update(['status' => 'attending', 'claimed_at' => now(), 'claimed_by_user_id' => liveReceptionStaff()->id]);

    $component->call('onQueueEntryClaimed', ['queueEntryId' => $second->id])
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->pluck('name')->all() === ['Third', 'First'])
        ->assertSet('claimedQueue', fn (array $queue): bool => collect($queue)->pluck('id')->all() === [$second->id]);

    $component->call('restoreClaimedToPending', $second->id)
        ->assertSet('claimedQueue', [])
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->pluck('name')->all() === ['Third', 'Second', 'First']);
});

it('does not restore a claimed entry once it was resolved', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $entry->update(['status' => 'attending', 'claimed_at' => now(), 'claimed_by_user_id' => liveReceptionStaff()->id]);

    $component = Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryClaimed', ['queueEntryId' => $entry->id])
        ->assertSet('claimedQueue', fn (array $queue): bool => collect($queue)->pluck('id')->contains($entry->id));

    $entry->update(['status' => 'approved']);

    $component->call('restoreClaimedToPending', $entry->id)
        ->assertSet('claimedQueue', [])
        ->assertSet('pendingQueue', []);
});

it('drops a resolved entry from the hidden claimed queue', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);
    $entry->update(['status' => 'attending', 'claimed_at' => now(), 'claimed_by_user_id' => liveReceptionStaff()->id]);

    Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryClaimed', ['queueEntryId' => $entry->id])
        ->assertSet('claimedQueue', fn (array $queue): bool => collect($queue)->pluck('id')->all() === [$entry->id])
        ->call('onQueueEntryResolved', ['queueEntryId' => $entry->id])
        ->assertSet('claimedQueue', []);
});

it('seeds recent claims as hidden and abandoned claims back into pending', function (): void {
    $location = Location::factory()->create();
    $recent = liveReceptionSignupEntry($location, ['name' => 'Recent Claim']);
    $recent->update(['status' => 'attending', 'claimed_at' => now()->subMinute(), 'claimed_by_user_id' => liveReceptionStaff()->id]);
    $abandoned = liveReceptionSignupEntry($location, ['name' => 'Abandoned Claim']);
    $abandoned->update(['status' => 'attending', 'claimed_at' => now()->subMinutes(20), 'claimed_by_user_id' => liveReceptionStaff()->id]);

    Livewire::actingAs(liveReceptionStaff())
        ->test(LiveSignupPopup::class)
        ->assertSet('claimedQueue', fn (array $queue): bool => collect($queue)->pluck('name')->all() === ['Recent Claim'])
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->pluck('name')->all() === ['Abandoned Claim']);
});

it('scopes each camera script copy to its own component so double inclusion cannot steal the photo', function (): void {
    $location = Location::factory()->create();
    $staff = liveReceptionStaff();

    $html = $this->actingAs($staff)->get('/reception')->getContent();

    expect(substr_count($html, 'window.verifyCamera = {'))->toBe(2)
        ->and(substr_count($html, "set('verifyPhoto', dataUrl)"))->toBe(2)
        ->and(substr_count($html, "CustomEvent('verify-photo-ready'"))->toBe(4)
        ->and(str_contains($html, 'window.GymieCameraCapture'))->toBeTrue()
        ->and(str_contains($html, 'state.ready'))->toBeTrue()
        ->and(str_contains($html, "classList.remove('fi-disabled')"))->toBeTrue()
        ->and(str_contains($html, 'window.__verifyCameraScriptLoaded'))->toBeFalse();
});

it('resolves the photo receiver from the overlay element so photos land on the hosting component', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    $html = $this->actingAs(liveReceptionStaff())->get('/reception')->getContent();

    expect(str_contains($html, "closest('[wire\\\\:id]')"))->toBeTrue()
        ->and(str_contains($html, 'Livewire.find(host.getAttribute(\'wire:id\'))'))->toBeTrue()
        ->and(str_contains($html, 'componentRoot.querySelector(\'#verify-overlay\')'))->toBeTrue()
        ->and(str_contains($html, 'overlayFrom(trigger)'))->toBeTrue()
        ->and(str_contains($html, "wire.get('verifyPhoto')"))->toBeTrue()
        ->and(str_contains($html, "document.addEventListener('verify-photo-captured'"))->toBeFalse()
        ->and(str_contains($html, "getElementById('verify-overlay')"))->toBeFalse()
        ->and(str_contains($html, 'window.__verifyPhotoReceivers'))->toBeFalse();
});

it('protects the camera elements from morphs but lets the step container re-render', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->call('verifyContinue')
        ->assertSet('verifyStep', 2)
        ->assertSeeHtml('<div class="space-y-4">')
        ->assertDontSeeHtml('<div class="space-y-4" wire:ignore>')
        ->assertSeeHtml('<video id="verify-camera" class="verify-camera" playsinline muted wire:ignore>')
        ->assertSeeHtml('<img id="verify-photo-preview" class="verify-photo-preview" alt="" style="display:none" wire:ignore>')
        ->assertSeeHtml('<div id="verify-camera-fallback" class="verify-camera-fallback absolute inset-0 flex items-center justify-center p-4" style="display:none" wire:ignore>');
});

it('keeps the create member button hidden until a photo is captured or uploaded', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->call('verifyContinue')
        ->assertSet('verifyStep', 2)
        ->assertSeeHtml('x-show="photoReady"')
        ->assertSeeHtml('wire:key="verify-to-plan"')
        ->assertSeeHtml('wire:key="verify-back"');
});

it('keeps the captured photo when going back and returns to the photo step', function (): void {
    $location = Location::factory()->create();
    $entry = liveReceptionSignupEntry($location);

    Livewire::actingAs(liveReceptionStaff())
        ->test(Reception::class)
        ->call('openVerifyOverlay', $entry->id)
        ->call('verifyContinue')
        ->set('verifyPhoto', 'data:image/jpeg;base64,AAAA')
        ->call('verifyBack')
        ->assertSet('verifyStep', 1)
        ->assertSet('verifyPhoto', 'data:image/jpeg;base64,AAAA')
        ->call('verifyContinue')
        ->assertSet('verifyStep', 2)
        ->assertSet('verifyPhoto', 'data:image/jpeg;base64,AAAA');
});

it('removed the start-camera button and keeps the capture button disabled until the video is ready', function (): void {
    $location = Location::factory()->create();
    $staff = liveReceptionStaff();
    liveReceptionSignupEntry($location);

    $html = $this->actingAs($staff)->get('/reception')->getContent();

    expect(str_contains($html, 'verify-camera-start'))->toBeFalse()
        ->and(str_contains($html, 'verify_photo_start'))->toBeFalse()
        ->and(str_contains($html, 'disabled'))->toBeTrue()
        ->and(str_contains($html, 'state.ready'))->toBeTrue();
});

it('serves the reception page with no-store headers so browsers cannot reuse stale markup', function (): void {
    $location = Location::factory()->create();

    $this->actingAs(liveReceptionStaff())
        ->get('/reception')
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
        ->assertHeader('Pragma', 'no-cache');
});

it('turns a concurrent duplicate government ID signup into a friendly error', function (): void {
    Storage::fake('public');

    $location = Location::factory()->create();
    $entryA = liveReceptionSignupEntry($location, ['contact' => '5551112222', 'government_id' => 'GOV-RACE']);
    $entryB = liveReceptionSignupEntry($location, ['contact' => '5551112222', 'government_id' => 'GOV-RACE']);
    $plan = liveReceptionPlan();
    $sale = liveReceptionSale($plan, ['invoices' => [[
        'date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'payment_method' => 'cash',
        'discount_amount' => 0,
        'paid_amount' => 100,
    ]]]);

    $service = app(MemberApplicationService::class);

    $service->approveSignup($entryA, liveReceptionSignupPayload($location, [
        'contact' => '5551112222',
        'government_id' => 'GOV-RACE',
    ]), liveReceptionPhoto(), $sale, liveReceptionStaff());

    expect(fn () => $service->approveSignup($entryB, liveReceptionSignupPayload($location, [
        'contact' => '5551112222',
        'government_id' => 'GOV-RACE',
    ]), liveReceptionPhoto(), $sale, liveReceptionStaff()))
        ->toThrow(InvalidArgumentException::class, __('app.reception.verify_duplicate'));

    expect(Member::count())->toBe(1)
        ->and($entryB->refresh()->status)->toBe('waiting');
});

it('allows a signup that shares the government ID even when the contact differs', function (): void {
    Storage::fake('public');

    $location = Location::factory()->create();
    $entryA = liveReceptionSignupEntry($location, ['contact' => '5551112222', 'government_id' => 'GOV-RACE']);
    $entryB = liveReceptionSignupEntry($location, ['contact' => '5553334444', 'government_id' => 'GOV-RACE']);
    $plan = liveReceptionPlan();
    $sale = liveReceptionSale($plan, ['invoices' => [[
        'date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'payment_method' => 'cash',
        'discount_amount' => 0,
        'paid_amount' => 100,
    ]]]);

    $service = app(MemberApplicationService::class);

    $service->approveSignup($entryA, liveReceptionSignupPayload($location, [
        'contact' => '5551112222',
        'government_id' => 'GOV-RACE',
    ]), liveReceptionPhoto(), $sale, liveReceptionStaff());

    // Identifiers may repeat across members: only name + contact + government
    // ID matching ALL THREE blocks a signup.
    $service->approveSignup($entryB, liveReceptionSignupPayload($location, [
        'contact' => '5553334444',
        'government_id' => 'GOV-RACE',
    ]), liveReceptionPhoto(), $sale, liveReceptionStaff());

    expect(Member::count())->toBe(2)
        ->and($entryB->refresh()->status)->toBe('approved');
});

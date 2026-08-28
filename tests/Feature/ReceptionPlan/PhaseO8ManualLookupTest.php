<?php

use App\Enums\Status;
use App\Filament\Pages\Reception;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('owner', 'web');
});

it('opens the shared check-in overlay when a search result is selected', function (): void {
    
    $member = Member::factory()->create(['name' => 'Otelia Rand', 'status' => Status::Active]);

    Livewire::actingAs(User::factory()->create()->assignRole('owner'))
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Otelia')
        ->assertSet('manualSearchResults', fn (array $results): bool => collect($results)->contains(
            fn (array $row): bool => (int) $row['id'] === $member->id,
        ))
        ->call('openManualCheckInForMember', $member->id)
        ->assertDispatched('open-modal', id: 'checkin-overlay')
        ->assertNotDispatched('close-modal', id: 'checkin-overlay')
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInMemberId', $member->id)
        ->assertSet('checkInManualMode', true);
});

it('ignores ids that are not part of the current search results', function (): void {
    Livewire::actingAs(User::factory()->create()->assignRole('owner'))
        ->test(Reception::class)
        ->set('manualSearchResults', [])
        ->call('openManualCheckInForMember', 4242)
        ->assertSet('showCheckInOverlay', false);
});

it('clears results when the search term is emptied', function (): void {
    Livewire::actingAs(User::factory()->create()->assignRole('owner'))
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Ana')
        ->set('manualCheckInSearch', '')
        ->assertSet('manualSearchResults', []);
});

it('lists location-scoped services for the owner walk-up and records the check-in there', function (): void {
    
    
    
    
    
    $location = Location::factory()->create(['name' => 'TORO GYM']);
    $service = Service::factory()->create(['location_id' => $location->id, 'name' => 'GYM']);
    $plan = Plan::factory()->create([
        'location_id' => $location->id,
        'status' => Status::Active,
        'limit_uses' => false,
    ]);
    $plan->services()->attach($service->id);

    $member = Member::factory()->create(['name' => 'Dani Live', 'status' => Status::Active]);
    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);

    Livewire::actingAs(User::factory()->create()->assignRole('owner'))
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Dani Live')
        ->call('openManualCheckInForMember', $member->id)
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInMemberId', $member->id)
        ->assertSet('checkInServiceId', (int) $service->id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false);

    $checkIn = PlanCheckIn::where('subscription_id', $subscription->id)->first();

    expect($checkIn)->not->toBeNull()
        ->and((int) $checkIn->location_id)->toBe((int) $location->id);
});

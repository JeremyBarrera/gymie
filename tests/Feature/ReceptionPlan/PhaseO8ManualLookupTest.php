<?php

// ============================================================================
// PHASE O8 — Manual Lookup Check-In
//
// Regression lock for the wire-path drift bug: reception.blade.php called
// openManualCheckInForResult() while the component exposed
// openManualCheckInForMember(), which 500s at runtime with
// Livewire\Exceptions\MethodNotFoundException. These tests click the same
// paths a staff member would, so a renamed/missing method fails CI.
// ============================================================================

use App\Enums\Status;
use App\Filament\Pages\Reception;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('owner', 'web');
});

it('opens the shared check-in overlay when a search result is selected', function (): void {
    // The factory randomizes status; only an active member may check in.
    $member = Member::factory()->create(['name' => 'Otelia Rand', 'status' => Status::Active]);

    Livewire::actingAs(User::factory()->create()->assignRole('owner'))
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Otelia')
        ->assertSet('manualSearchResults', fn (array $results): bool => collect($results)->contains(
            fn (array $row): bool => (int) $row['id'] === $member->id,
        ))
        ->call('openManualCheckInForMember', $member->id)
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

<?php

use App\Filament\Livewire\SoundAlertToggle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
});

it('flips the preference and notifies the tab on toggle', function (): void {
    $user = User::factory()->create(['sound_alerts' => false]);

    Livewire::actingAs($user)
        ->test(SoundAlertToggle::class)
        ->assertSet('soundAlerts', false)
        ->call('toggleSoundAlerts')
        ->assertSet('soundAlerts', true)
        ->assertDispatched('sound-alerts-updated', enabled: true);

    expect($user->refresh()->sound_alerts)->toBeTrue();

    Livewire::actingAs($user)
        ->test(SoundAlertToggle::class)
        ->call('toggleSoundAlerts')
        ->assertDispatched('sound-alerts-updated', enabled: false);

    expect($user->refresh()->sound_alerts)->toBeFalse();
});

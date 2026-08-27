<?php

use App\Events\SoundAlertsToggled;
use App\Filament\Livewire\SoundAlertToggle;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
});

function soundStaff(bool $soundAlerts = false): User
{
    $user = User::factory()->create(['sound_alerts' => $soundAlerts]);
    $user->assignRole('owner');

    return $user;
}

it('starts with sound alerts off for users who never opted in', function (): void {
    Livewire::actingAs(soundStaff())
        ->test(SoundAlertToggle::class)
        ->assertSet('soundAlerts', false);
});

it('mounts with the saved per-user sound preference', function (): void {
    Livewire::actingAs(soundStaff(true))
        ->test(SoundAlertToggle::class)
        ->assertSet('soundAlerts', true);
});

it('toggles the sound preference, persists it, and notifies the acting tab', function (): void {
    $staff = soundStaff();

    Event::fake([SoundAlertsToggled::class]);

    Livewire::actingAs($staff)
        ->test(SoundAlertToggle::class)
        ->call('toggleSoundAlerts')
        ->assertSet('soundAlerts', true)
        ->assertDispatched('sound-alerts-updated', enabled: true)
        ->assertDispatched('notify')
        ->call('toggleSoundAlerts')
        ->assertSet('soundAlerts', false)
        ->assertDispatched('sound-alerts-updated', enabled: false);

    Event::assertDispatchedTimes(SoundAlertsToggled::class, 2);

    expect($staff->refresh()->sound_alerts)->toBeFalse();
});

it('broadcasts SoundAlertsToggled on the toggling user public channel only', function (): void {
    $event = new SoundAlertsToggled(42, true);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(Channel::class)
        ->and($channels[0]->name)->toBe('user.42')
        ->and($event->userId)->toBe(42)
        ->and($event->enabled)->toBeTrue();
});

it('subscribes to the owning user public channel for cross-tab icon sync', function (): void {
    $staff = soundStaff(false);

    Auth::login($staff);

    $toggle = new SoundAlertToggle();
    $reflector = new ReflectionMethod(SoundAlertToggle::class, 'getListeners');
    $reflector->setAccessible(true);
    $listeners = $reflector->invoke($toggle);

    expect($listeners)->toBe([
        "echo:user.{$staff->id},SoundAlertsToggled" => 'syncSoundAlertsFromBroadcast',
    ]);
});

it('live-updates the sound icon state when another tab broadcasts a toggle', function (): void {
    $staff = soundStaff(false);

    Livewire::actingAs($staff)
        ->test(SoundAlertToggle::class)
        ->assertSet('soundAlerts', false)
        ->dispatch("echo:user.{$staff->id},SoundAlertsToggled", ['enabled' => true])
        ->assertSet('soundAlerts', true);

    Livewire::actingAs($staff)
        ->test(SoundAlertToggle::class)
        ->dispatch("echo:user.{$staff->id},SoundAlertsToggled", ['enabled' => false])
        ->assertSet('soundAlerts', false);
});

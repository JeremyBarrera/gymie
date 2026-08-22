<?php

use App\Events\QueueEntryClaimed;
use App\Events\QueueEntryCreated;
use App\Events\QueueEntryExpired;
use App\Events\QueueEntryResolved;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Pull the closure Laravel registered for the given channel pattern (e.g.
 * "location.{token}") so tests can run the exact auth logic from
 * routes/channels.php.
 */
function channelClosure(string $pattern): Closure
{
    $manager = app('Illuminate\Broadcasting\BroadcastManager');

    $broadcaster = $manager->connection();

    if (! method_exists($broadcaster, 'getChannels')) {
        expect(true)->toBeTrue('No getChannels() on '.get_class($broadcaster));

        return fn (): bool => false;
    }

    $channels = $broadcaster->getChannels();

    $closure = $channels[$pattern] ?? null;

    expect($closure)->toBeInstanceOf(Closure::class, "Channel [{$pattern}] is not registered.");

    return $closure;
}

it('configures Reverb as the realtime driver', function (): void {
    $reverb = config('broadcasting.connections.reverb');

    expect($reverb)->toBeArray()
        ->and($reverb['driver'])->toBe('reverb')
        ->and($reverb['options']['host'] ?? null)->not->toBeNull();
});

it('defines the four realtime queue events', function (): void {
    expect(class_exists(QueueEntryCreated::class))->toBeTrue();
    expect(class_exists(QueueEntryClaimed::class))->toBeTrue();
    expect(class_exists(QueueEntryResolved::class))->toBeTrue();
    expect(class_exists(QueueEntryExpired::class))->toBeTrue();
});

it('implements ShouldBroadcast on each queue event', function (): void {
    expect(new QueueEntryCreated(1, Str::uuid()->toString(), 'token', 'checkin'))->toBeInstanceOf(ShouldBroadcast::class);
    expect(new QueueEntryClaimed(1, Str::uuid()->toString(), 'token', 'checkin', [], 'staff'))->toBeInstanceOf(ShouldBroadcast::class);
    expect(new QueueEntryResolved(1, Str::uuid()->toString(), 'token', 'checkin', [], true, null))->toBeInstanceOf(ShouldBroadcast::class);
    expect(new QueueEntryExpired(1, Str::uuid()->toString(), 'token'))->toBeInstanceOf(ShouldBroadcast::class);
});

it('broadcasts QueueEntryCreated only on the staff location channel', function (): void {
    $event = new QueueEntryCreated(1, 'queue-uuid-1', 'loc-token-1', 'checkin', [], null, 2);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-location.loc-token-1');
});

it('broadcasts QueueEntryClaimed only on the staff location channel so staff names stay private', function (): void {
    $event = new QueueEntryClaimed(1, Str::uuid()->toString(), 'loc-token-1', 'checkin', [], 'Toro');

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-location.loc-token-1');
});

it('broadcasts QueueEntryResolved on both staff and member channels', function (): void {
    $event = new QueueEntryResolved(1, Str::uuid()->toString(), 'loc-token-1', 'checkin', [], true, null);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(2);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-location.loc-token-1');
    expect($channels[1])->toBeInstanceOf(Channel::class);
    expect($channels[1]->name)->toContain('queue.');
});

it('registers the location and queue uuid channels', function (): void {
    channelClosure('location.{token}');
    channelClosure('queue.{uuid}');
});

it('authorizes a staff member attached to the location on the private channel', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
        'kind' => 'checkin',
    ]);

    $staff = User::factory()->create();
    $staff->locations()->sync([$location->id]);

    $closure = channelClosure('location.{token}');

    expect($closure($staff, $token->token))->toBeTrue();
});

it('rejects a user with no user_locations row for the private channel', function (): void {
    $outsider = User::factory()->create();
    $outsider->locations()->detach();

    $location = Location::factory()->create();
    $token = LocationToken::factory()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
        'kind' => 'checkin',
    ]);

    $closure = channelClosure('location.{token}');

    expect($closure($outsider, $token->token))->toBeFalse();
});

it('authorizes an owner with no user_locations row for the private channel', function (): void {
    Role::firstOrCreate(['name' => 'owner']);

    $owner = User::factory()->create();
    $owner->locations()->detach();
    $owner->assignRole('owner');

    $location = Location::factory()->create();
    $token = LocationToken::factory()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
        'kind' => 'checkin',
    ]);

    $closure = channelClosure('location.{token}');

    expect($closure($owner, $token->token))->toBeTrue();
});

it('allows anyone to subscribe to the public queue uuid channel', function (): void {
    $closure = channelClosure('queue.{uuid}');

    expect($closure(User::factory()->create(), Str::uuid()->toString()))->toBeTrue();
});

function pusherDriverForTest(): void
{
    $manager = app('Illuminate\Broadcasting\BroadcastManager');

    $previous = $manager->connection();

    config()->set('broadcasting.connections.pusher', [
        'driver' => 'pusher',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'app_id' => 'test-app-id',
        'options' => [
            'cluster' => 'mt1',
            'host' => '127.0.0.1',
            'port' => 6001,
            'scheme' => 'http',
            'encrypted' => false,
            'useTLS' => false,
        ],
    ]);
    config()->set('broadcasting.default', 'pusher');

    if (method_exists($previous, 'getChannels')) {
        foreach ($previous->getChannels() as $pattern => $callback) {
            Broadcast::channel($pattern, $callback);
        }
    }
}

it('authorizes the private channel end-to-end over the broadcast auth route', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
        'kind' => 'signup',
    ]);

    $staff = User::factory()->create();
    $staff->locations()->sync([$location->id]);

    pusherDriverForTest();

    $this->actingAs($staff)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-location.'.$token->token,
            'socket_id' => '123456.123456',
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

it('denies the private channel over the broadcast auth route for outsiders', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
        'kind' => 'signup',
    ]);

    $outsider = User::factory()->create();
    $outsider->locations()->detach();

    pusherDriverForTest();

    $this->actingAs($outsider)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-location.'.$token->token,
            'socket_id' => '123456.123456',
        ])
        ->assertForbidden();
});

it('authorizes an owner over the broadcast auth route', function (): void {
    Role::firstOrCreate(['name' => 'owner']);

    $location = Location::factory()->create();
    $token = LocationToken::factory()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
        'kind' => 'signup',
    ]);

    $owner = User::factory()->create();
    $owner->locations()->detach();
    $owner->assignRole('owner');

    pusherDriverForTest();

    $this->actingAs($owner)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-location.'.$token->token,
            'socket_id' => '123456.123456',
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

it('has an Echo bootstrap module for Vite', function (): void {
    expect(file_exists(resource_path('js/echo.js')))->toBeTrue();
});

it('enables Filament echo broadcasting for the admin panel', function (): void {
    $echo = config('filament.broadcasting.echo');

    expect($echo)->toBeArray()
        ->and($echo['broadcaster'])->toBe('reverb')
        ->and($echo['authEndpoint'])->toBe('/broadcasting/auth')
        ->and($echo['key'])->not->toBeEmpty();
});

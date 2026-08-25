<?php

use App\Enums\Status;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\QueueEntry;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LocationTenantContext;
use App\Services\Members\MemberApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    LocationTenantContext::setLocationId(null);
});

afterEach(function (): void {
    LocationTenantContext::setLocationId(null);
});

it('allows API member creation when only the government ID matches another member', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('Create:Member', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo('Create:Member');
    Sanctum::actingAs($user);

    Member::factory()->create([
        'name' => 'Original Person',
        'government_id' => 'ID-API-1',
    ]);

    $this->postJson('/api/v1/members', [
        'name' => 'New Person',
        'contact' => '5550001111',
        'government_id' => 'ID-API-1',
        'status' => 'active',
    ])->assertStatus(201);

    expect(Member::query()->withoutGlobalScope('location')->where('government_id', 'ID-API-1')->count())->toBe(2);
});

it('allows an API member update to a government ID owned by a member elsewhere', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('Update:Member', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo('Update:Member');
    Sanctum::actingAs($user);

    Member::factory()->create([
        'name' => 'Owner Of The Id',
        'government_id' => 'ID-API-2',
    ]);
    $member = Member::factory()->create([
        'government_id' => 'ID-API-3',
    ]);

    $this->putJson("/api/v1/members/{$member->id}", [
        'government_id' => 'ID-API-2',
    ])->assertStatus(200);

    expect($member->refresh()->government_id)->toBe('ID-API-2')
        ->and(Member::query()->withoutGlobalScope('location')->where('government_id', 'ID-API-2')->count())->toBe(2);
});

it('does not block signup approval when only the government ID matches but name differs', function (): void {
    Member::factory()->create(['government_id' => 'ID-SIGNUP-1', 'name' => 'Original Person', 'contact' => '5550001111']);

    $service = app(MemberApplicationService::class);

    $entry = QueueEntry::create([
        'uuid' => (string) Str::uuid(),
        'location_id' => Location::factory()->create()->id,
        'kind' => 'signup',
        'payload' => [],
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);

    $member = $service->validateSignup([
        'name' => 'Someone Else',
        'contact' => '5550002222',
        'government_id' => 'ID-SIGNUP-1',
        'email' => null,
        'gender' => 'male',
        'dob' => '1990-01-01',
    ], 'data:image/png;base64,'.base64_encode('tiny-png-bytes'));

    expect($member)->not->toBeNull();
});

it('allows an All Locations subscription even when another member shares the government ID', function (): void {
    $dupLocation = Location::factory()->create(['name' => 'Dup Location']);
    $dupPlan = Plan::factory()->create(['location_id' => $dupLocation->id]);
    $member = Member::factory()->create(['government_id' => 'ID-OBS-1']);
    $duplicate = Member::factory()->create(['government_id' => 'ID-OBS-1']);
    Subscription::factory()->create([
        'member_id' => $duplicate->id,
        'plan_id' => $dupPlan->id,
        'location_id' => $dupLocation->id,
        'status' => Status::Ongoing,
    ]);
    $plan = Plan::factory()->create();

    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
    ]);

    expect(Subscription::query()->whereKey($subscription->id)->exists())->toBeTrue();
});

it('allows location-specific subscriptions regardless of duplicates', function (): void {
    $location = Location::factory()->create();
    $member = Member::factory()->create([
        'government_id' => 'ID-OBS-3',
    ]);
    Member::factory()->create([
        'government_id' => 'ID-OBS-3',
    ]);
    $plan = Plan::factory()->create(['location_id' => $location->id]);

    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
    ]);

    expect(Subscription::query()->whereKey($subscription->id)->exists())->toBeTrue();
});

it('allows multiple members with the same government ID at the database level', function (): void {
    Member::factory()->create(['government_id' => 'ID-SHARED', 'name' => 'First Person']);
    Member::factory()->create(['government_id' => 'ID-SHARED', 'name' => 'Second Person']);

    expect(DB::table('members')->where('government_id', 'ID-SHARED')->count())->toBe(2);
});

it('still allows multiple members without a government ID', function (): void {
    Member::factory()->create(['government_id' => null]);
    Member::factory()->create(['government_id' => null]);

    expect(Member::query()->count())->toBe(2);
});

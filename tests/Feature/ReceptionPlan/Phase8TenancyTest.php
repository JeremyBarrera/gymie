<?php

use App\Enums\Status;
use App\Models\Enquiry;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Plan;
use App\Models\QueueEntry;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LocationTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('owner', 'web');
    Feature::flushCache();
    LocationTenantContext::setLocationId(null);
});

afterEach(function (): void {
    LocationTenantContext::setLocationId(null);
});

function phase8CreateLocation(string $name): Location
{
    return Location::query()->create(['name' => $name]);
}

it('does not store a location on members', function (): void {
    phase8CreateLocation('Main Location');

    $member = Member::factory()->create(['name' => 'Solo Member']);

    expect(Schema::hasColumn('members', 'location_id'))->toBeFalse()
        ->and($member->currentLocation())->toBeNull();
});

it('provisions a new location and keeps its first records scoped', function (): void {
    $locationB = phase8CreateLocation('Location B');

    $staffB = User::factory()->create();
    $staffB->locations()->sync([$locationB->id]);

    $this->actingAs($staffB);

    $member = Member::factory()->create(['name' => 'Location B Member']);
    $plan = Plan::factory()->create(['name' => 'Location B Plan', 'location_id' => $locationB->id]);
    Subscription::factory()->create(['member_id' => $member->id, 'plan_id' => $plan->id]);

    expect(Member::query()->count())->toBe(1);
});

it('keeps two staff accounts isolated across every business model', function (): void {
    $locationA = phase8CreateLocation('Location A');
    $locationB = phase8CreateLocation('Location B');

    $adminA = User::factory()->create();
    $adminA->locations()->sync([$locationA->id]);
    $adminB = User::factory()->create();
    $adminB->locations()->sync([$locationB->id]);

    $this->actingAs($adminA);

    $memberA = Member::factory()->create(['name' => 'Location A Member']);
    $planA = Plan::factory()->create(['name' => 'Location A Plan', 'location_id' => $locationA->id]);
    $subscriptionA = Subscription::factory()->create([
        'member_id' => $memberA->id,
        'plan_id' => $planA->id,
        'status' => Status::Ongoing,
    ]);
    Invoice::factory()->create(['subscription_id' => $subscriptionA->id]);
    Expense::factory()->create(['name' => 'Location A Expense']);
    Enquiry::factory()->create(['user_id' => $adminA->id, 'name' => 'Location A Enquiry']);
    QueueEntry::query()->create([
        'uuid' => Str::uuid()->toString(),
        'kind' => 'checkin',
        'status' => 'waiting',
        'payload' => ['member_id' => $memberA->id],
    ]);

    expect([
        Member::query()->count(),
        Plan::query()->count(),
        Subscription::query()->count(),
        Invoice::query()->count(),
        Expense::query()->count(),
        Enquiry::query()->count(),
        QueueEntry::query()->count(),
    ])->each->toBe(1);

    $this->actingAs($adminB);

    expect([
        Member::query()->count(),
        Plan::query()->count(),
        Subscription::query()->count(),
        Invoice::query()->count(),
        Expense::query()->count(),
        Enquiry::query()->count(),
        QueueEntry::query()->count(),
    ])->each->toBe(0);

    $this->actingAs($adminA);

    expect(Member::query()->whereKey($memberA->id)->exists())->toBeTrue()
        ->and(Location::query()->whereKey($locationA->id)->exists())->toBeTrue();
});

it('lets the owner see records across every location', function (): void {
    $locationA = phase8CreateLocation('Location A');

    Member::factory()->create(['name' => 'A Member']);

    $locationB = phase8CreateLocation('Location B');

    LocationTenantContext::setLocationId((int) $locationB->id);
    Member::factory()->create(['name' => 'B Member']);
    LocationTenantContext::setLocationId(null);

    $owner = User::factory()->create()->assignRole('owner');

    $this->actingAs($owner);

    expect(Member::query()->count())->toBe(2);
});

it('scopes public check-in lookups to the location of the location token', function (): void {
    $locationA = phase8CreateLocation('Location A');

    $planA = Plan::factory()->create(['name' => 'Location A Plan', 'location_id' => $locationA->id]);
    $memberA = Member::factory()->create([
        'contact' => '5550001111',
        'status' => Status::Active,
        'name' => 'Location A Member',
    ]);
    Subscription::factory()->create(['member_id' => $memberA->id, 'plan_id' => $planA->id]);

    $locationB = phase8CreateLocation('Location B');
    $tokenB = LocationToken::query()->create([
        'token' => Str::random(40),
        'tokenable_type' => Location::class,
        'tokenable_id' => $locationB->id,
        'kind' => 'checkin',
    ]);

    LocationTenantContext::setLocationId((int) $locationB->id);
    $planB = Plan::factory()->create(['name' => 'Location B Plan', 'location_id' => $locationB->id]);
    $memberB = Member::factory()->create([
        'contact' => '5550001111',
        'status' => Status::Active,
        'name' => 'Location B Member',
    ]);
    Subscription::factory()->create(['member_id' => $memberB->id, 'plan_id' => $planB->id]);
    LocationTenantContext::setLocationId(null);

    $this->postJson('/api/v1/checkin/lookup', [
        'identifier_type' => 'contact',
        'value' => '5550001111',
        'location_token' => $tokenB->token,
    ])
        ->assertOk()
        ->assertJson(['match' => true])
        ->assertJsonPath('member.name', 'Location B Member');
});

it('records signup applications against the location of their location token', function (): void {
    $locationB = phase8CreateLocation('Location B');
    LocationToken::query()->create([
        'token' => 'signup-b-token',
        'tokenable_type' => Location::class,
        'tokenable_id' => $locationB->id,
        'kind' => 'signup',
    ]);

    $this->postJson('/api/v1/signup/apply', [
        'name' => 'New Person',
        'government_id' => 'GOV-888',
        'location_token' => 'signup-b-token',
    ])->assertOk();

    $application = MemberApplication::query()->first();

    expect((int) $application->location_id)->toBe((int) $locationB->id);
});

it('allows a signup from another location when only the government ID matches', function (): void {
    $locationA = phase8CreateLocation('Location A');
    Member::factory()->create([
        'name' => 'Original Person',
        'government_id' => 'GOV-777',
        'status' => Status::Active,
    ]);

    $locationB = phase8CreateLocation('Location B');
    LocationToken::query()->create([
        'token' => 'signup-b-token',
        'tokenable_type' => Location::class,
        'tokenable_id' => $locationB->id,
        'kind' => 'signup',
    ]);

    // Government IDs may repeat across members: an application is rejected
    // only when name + contact + government ID ALL match an existing member.
    $this->postJson('/api/v1/signup/apply', [
        'name' => 'New Person',
        'government_id' => 'GOV-777',
        'location_token' => 'signup-b-token',
    ])->assertOk()
        ->assertJson(['message' => 'Application submitted successfully']);

    expect(MemberApplication::query()->count())->toBe(1);
});

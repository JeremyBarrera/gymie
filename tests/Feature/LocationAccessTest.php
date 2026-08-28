<?php

use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LocationTenantContext;
use App\Services\Membership\PlanCheckInService;
use App\Support\Locations\LocationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-23', 'UTC'));
    config()->set('app.timezone', 'UTC');
    Permission::findOrCreate('Delete:User', 'web');
    Role::findOrCreate('owner', 'web');
    $this->superAdmin = User::factory()->create()->assignRole('owner');
});

it('gives the owner implicit access to every location without pivot rows', function (): void {
    $location = Location::factory()->create();

    expect(LocationAccess::accessibleLocationIds($this->superAdmin))->toBeNull();
    expect(LocationAccess::canAccess($this->superAdmin, $location->id))->toBeTrue();
    expect(LocationAccess::accessibleLocationCount($this->superAdmin))->toBe(Location::count());
});

it('limits a location admin to their assigned locations only', function (): void {
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $admin = User::factory()->create();
    $admin->locations()->syncWithoutDetaching([$locationA->id]);

    expect(LocationAccess::accessibleLocationIds($admin))->toBe([(int) $locationA->id]);
    expect(LocationAccess::canAccess($admin, $locationA->id))->toBeTrue();
    expect(LocationAccess::canAccess($admin, $locationB->id))->toBeFalse();
});

it('scopes member listings to the account\'s accessible locations', function (): void {
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $admin = User::factory()->create();
    $admin->locations()->syncWithoutDetaching([$locationA->id]);

    $planA = Plan::factory()->create(['location_id' => $locationA->id, 'status' => 'active']);
    $planB = Plan::factory()->create(['location_id' => $locationB->id, 'status' => 'active']);
    $memberA = Member::factory()->create(['status' => 'active']);
    $memberB = Member::factory()->create(['status' => 'active']);
    Subscription::factory()->create(['member_id' => $memberA->id, 'plan_id' => $planA->id]);
    Subscription::factory()->create(['member_id' => $memberB->id, 'plan_id' => $planB->id]);

    $this->actingAs($admin);
    $ids = MemberResource::getEloquentQuery()->pluck('id')->all();
    expect($ids)->toContain($memberA->id)->not->toContain($memberB->id);

    $this->actingAs($this->superAdmin);
    $ids = MemberResource::getEloquentQuery()->pluck('id')->all();
    expect($ids)->toContain($memberA->id)->toContain($memberB->id);
});

it('rejects delegated account creation outside the editor\'s jurisdiction', function (): void {
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $editor = User::factory()->create();
    $editor->locations()->syncWithoutDetaching([$locationA->id]);
    $ownerRole = Role::findByName('owner');

    $this->actingAs($editor);
    $page = new class extends CreateUser
    {
        public function mutate(array $data): array
        {
            return $this->mutateFormDataBeforeCreate($data);
        }
    };

    expect(fn () => $page->mutate([
        'name' => 'Escalation Attempt',
        'role' => $ownerRole->id,
        'locations' => [$locationB->id],
    ]))->toThrow(ValidationException::class);
});

it('allows the owner to assign any role and location', function (): void {
    $locationB = Location::factory()->create();
    $ownerRole = Role::findByName('owner');

    $this->actingAs($this->superAdmin);
    $page = new class extends CreateUser
    {
        public function mutate(array $data): array
        {
            return $this->mutateFormDataBeforeCreate($data);
        }
    };

    $data = $page->mutate([
        'name' => 'New Admin',
        'role' => $ownerRole->id,
        'locations' => [$locationB->id],
    ]);

    expect($data['role'])->toBe($ownerRole->id);
});

it('keeps legacy plans without a location available everywhere', function (): void {
    $location = Location::factory()->create();
    $plan = Plan::factory()->create();

    expect($plan->availableAt($location->id))->toBeTrue();
});

it('restricts plan availability to its own location', function (): void {
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();

    $plan = Plan::factory()->create(['location_id' => $locationA->id]);

    expect($plan->availableAt($locationA->id))->toBeTrue();
    expect($plan->availableAt($locationB->id))->toBeFalse();
    expect($plan->availableAt(null))->toBeTrue();
});

it('treats legacy plans without a location as available everywhere', function (): void {
    $location = Location::factory()->create();
    $plan = Plan::factory()->create();

    expect($plan->availableAt($location->id))->toBeTrue();
});

it('excludes subscriptions for plans that are unavailable at the check-in location', function (): void {
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();

    $plan = Plan::factory()->create(['location_id' => $locationA->id, 'status' => 'active']);
    $plan->services()->attach(Service::factory()->create());

    $memberA = Member::factory()->create(['status' => 'active']);
    $memberB = Member::factory()->create(['status' => 'active']);

    Subscription::factory()->create([
        'member_id' => $memberA->id,
        'plan_id' => $plan->id,
        'location_id' => $locationA->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'ongoing',
    ]);
    Subscription::factory()->create([
        'member_id' => $memberB->id,
        'plan_id' => $plan->id,
        'location_id' => $locationA->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'ongoing',
    ]);

    $service = app(PlanCheckInService::class);

    
    
    LocationTenantContext::setLocationId((int) $locationA->id);

    $eligibleA = $service->eligibleSubscriptions($memberA);
    expect($eligibleA)->toHaveCount(1);

    LocationTenantContext::setLocationId((int) $locationB->id);

    $eligibleB = $service->eligibleSubscriptions($memberB);
    expect($eligibleB)->toHaveCount(0);

    LocationTenantContext::setLocationId(null);
});

it('resolves the dashboard filter scope for each account type', function (): void {
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $admin = User::factory()->create();
    $admin->locations()->syncWithoutDetaching([$locationA->id]);

    expect(LocationAccess::scopeFromFilters($this->superAdmin, []))->toBeNull();
    expect(LocationAccess::scopeFromFilters($this->superAdmin, ['location_ids' => [$locationA->id]]))->toBe([(int) $locationA->id]);

    expect(LocationAccess::scopeFromFilters($admin, []))->toBe([(int) $locationA->id]);
    expect(LocationAccess::scopeFromFilters($admin, ['location_ids' => [$locationB->id]]))->toBe([]);
    expect(LocationAccess::scopeFromFilters(null, []))->toBeNull();
});

<?php

use App\Enums\Status;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LocationTenantContext;
use App\Services\Membership\PlanCheckInService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    LocationTenantContext::setLocationId(Location::factory()->create()->id);
});

function planServiceMigration(): object
{
    return require database_path('migrations/2026_08_24_100000_create_plan_services_table.php');
}

it('backfills plan_services from plans.service_id and drops the column', function (): void {
    Schema::dropIfExists('plan_services');

    Schema::table('plans', function (Blueprint $table): void {
        $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
    });

    $yoga = Service::factory()->create(['name' => 'Yoga']);
    $gym = Service::factory()->create(['name' => 'Gym']);

    $linkedToYoga = Plan::factory()->create(['code' => 'PL-YOGA']);
    DB::table('plans')->where('id', $linkedToYoga->id)->update(['service_id' => $yoga->id]);

    $linkedToGym = Plan::factory()->create(['code' => 'PL-GYM']);
    DB::table('plans')->where('id', $linkedToGym->id)->update(['service_id' => $gym->id]);

    $orphaned = Plan::factory()->create(['code' => 'PL-ORPHAN']);

    $migration = planServiceMigration();
    $migration->up();

    expect(Schema::hasColumn('plans', 'service_id'))->toBeFalse()
        ->and((int) DB::table('plan_services')->where('plan_id', $linkedToYoga->id)->value('service_id'))->toBe($yoga->id)
        ->and((int) DB::table('plan_services')->where('plan_id', $linkedToGym->id)->value('service_id'))->toBe($gym->id)
        ->and(DB::table('plan_services')->where('plan_id', $orphaned->id)->exists())->toBeFalse();

    $migration->down();

    expect(Schema::hasTable('plan_services'))->toBeFalse()
        ->and(Schema::hasColumn('plans', 'service_id'))->toBeTrue()
        ->and((int) DB::table('plans')->where('id', $linkedToYoga->id)->value('service_id'))->toBe($yoga->id)
        ->and((int) DB::table('plans')->where('id', $linkedToGym->id)->value('service_id'))->toBe($gym->id)
        ->and(DB::table('plans')->where('id', $orphaned->id)->value('service_id'))->toBeNull();

    Schema::table('plans', function (Blueprint $table): void {
        $table->dropConstrainedForeignId('service_id');
    });
});

it('grants access to every service of a multi-service plan and stamps the primary service on check-in', function (): void {
    $member = Member::factory()->create(['status' => Status::Active]);
    $staff = User::factory()->create();

    $yoga = Service::factory()->create(['name' => 'Alpha Yoga']);
    $gym = Service::factory()->create(['name' => 'Beta Gym']);

    $plan = Plan::factory()->create([
        'status' => Status::Active,
        'days' => 30,
    ]);
    $plan->services()->attach([$yoga->id, $gym->id]);

    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);

    $states = collect(app(PlanCheckInService::class)->serviceStatesForMember($member));

    expect($states->where('id', $yoga->id)->first()['state'])->toBe('access')
        ->and($states->where('id', $gym->id)->first()['state'])->toBe('access')
        ->and($states->where('id', $yoga->id)->first()['subscription_id'])->toBe($subscription->id);

    app(PlanCheckInService::class)->checkIn($member, $subscription, $staff, true);

    $checkIn = PlanCheckIn::query()->sole();

    expect($checkIn->plan_id)->toBe($plan->id)
        ->and($checkIn->service_id)->toBe($yoga->id);
});

it('never expires a subscription sold on a null-days plan through the API', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['Create:Subscription', 'View:Subscription'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo(['Create:Subscription', 'View:Subscription']);
    Sanctum::actingAs($user);

    $plan = Plan::factory()->create([
        'days' => null,
        'status' => Status::Active,
    ]);
    $plan->services()->attach(Service::factory()->create());

    $member = Member::factory()->create(['status' => Status::Active]);

    $payload = [
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'start_date' => now()->toDateString(),
        'invoice' => [
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'paid_amount' => (float) $plan->amount,
        ],
    ];

    $subscriptionId = $this->postJson('/api/v1/subscriptions', $payload)
        ->assertSuccessful()
        ->json('data.id');

    $subscription = Subscription::query()->findOrFail($subscriptionId);

    expect($subscription->end_date)->toBeNull()
        ->and($subscription->status)->toBe(Status::Ongoing);

    $eligible = app(PlanCheckInService::class)->eligibleSubscriptions($member);

    expect($eligible)->toHaveCount(1)
        ->and($eligible->first()?->end_date)->toBeNull();
});

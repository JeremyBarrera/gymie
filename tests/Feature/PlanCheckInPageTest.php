<?php

use App\Enums\Status;
use App\Filament\Pages\PlanCheckIn;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn as PlanCheckInModel;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Membership\PlanCheckInService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createEligibleSubscriptionForPage(Member $member, Plan $plan): Subscription
{
    $today = Carbon::today(config('app.timezone'));

    return Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'start_date' => $today->copy()->subDays(10)->toDateString(),
        'end_date' => $today->copy()->addDays(20)->toDateString(),
        'status' => Status::Ongoing->value,
    ]);
}

it('mounts the check-in page with form and table', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(PlanCheckIn::class)
        ->assertSuccessful()
        ->assertSee('Sign in');
});

it('lists eligible subscriptions for a selected member', function (): void {
    Location::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $service = Service::factory()->create(['name' => 'Yoga']);
    $member = Member::factory()->create(['status' => Status::Active->value]);
    $plan = Plan::factory()->create([
        'name' => 'Yoga 10 visits',
        'code' => 'Y10',
        'status' => Status::Active->value,
    ]);
    $plan->services()->attach($service->id);
    $subscription = createEligibleSubscriptionForPage($member, $plan);

    $component = Livewire::test(PlanCheckIn::class)
        ->set('data.member_id', $member->id);

    $options = $component->get('form')->getComponentByStatePath('subscription_id')->getOptions();

    expect($options)->toHaveKey((string) $subscription->id);
});

it('signs in a member against their eligible subscription', function (): void {
    Location::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $service = Service::factory()->create(['name' => 'Yoga']);
    $member = Member::factory()->create(['status' => Status::Active->value]);
    $plan = Plan::factory()->create([
        'name' => 'Yoga 10 visits',
        'code' => 'Y10',
        'status' => Status::Active->value,
    ]);
    $plan->services()->attach($service->id);
    $subscription = createEligibleSubscriptionForPage($member, $plan);

    Livewire::test(PlanCheckIn::class)
        ->set('data.member_id', $member->id)
        ->set('data.subscription_id', $subscription->id)
        ->call('performCheckIn', false)
        ->assertHasNoErrors();

    $checkIn = PlanCheckInModel::query()->first();

    expect($checkIn)->not->toBeNull()
        ->and($checkIn->member_id)->toBe($member->id)
        ->and($checkIn->subscription_id)->toBe($subscription->id)
        ->and($checkIn->plan_id)->toBe($plan->id)
        ->and($checkIn->service_id)->toBe($service->id)
        ->and($checkIn->checked_in_by)->toBe($user->id);
});

it('blocks sign-in when the plan use limit is exceeded', function (): void {
    Location::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $member = Member::factory()->create(['status' => Status::Active->value]);
    $plan = Plan::factory()->withUseLimit(1)->create(['status' => Status::Active->value]);
    $plan->services()->attach(Service::factory()->create());
    $subscription = createEligibleSubscriptionForPage($member, $plan);

    app(PlanCheckInService::class)
        ->checkIn($member, $subscription, $user, true);

    Livewire::test(PlanCheckIn::class)
        ->set('data.member_id', $member->id)
        ->set('data.subscription_id', $subscription->id)
        ->call('performCheckIn', false)
        ->assertHasNoErrors();

    expect(PlanCheckInModel::query()->count())->toBe(1);
});

it('requires confirmation for a duplicate same-day check-in', function (): void {
    Location::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);

    $member = Member::factory()->create(['status' => Status::Active->value]);
    $plan = Plan::factory()->withUseLimit(5)->create(['status' => Status::Active->value]);
    $plan->services()->attach(Service::factory()->create());
    $subscription = createEligibleSubscriptionForPage($member, $plan);

    app(PlanCheckInService::class)
        ->checkIn($member, $subscription, $user);

    Livewire::test(PlanCheckIn::class)
        ->set('data.member_id', $member->id)
        ->set('data.subscription_id', $subscription->id)
        ->call('performCheckIn', false)
        ->assertHasNoErrors();

    expect(PlanCheckInModel::query()->count())->toBe(1);
});

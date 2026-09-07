<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Membership\PlanCheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CheckInActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'owner']);
    }

    public function test_activity_route_requires_auth(): void
    {
        $this->get('/activity')->assertRedirect();
    }

    public function test_activity_page_renders_with_checkin_history(): void
    {
        $user = User::factory()->create();
        $user->assignRole('owner');

        $location = Location::create(['name' => 'Main Location']);
        $member = Member::factory()->create(['name' => 'John Doe', 'status' => Status::Active]);
        $plan = $this->checkInPlan();
        $subscription = $this->activeSubscription($member, $plan);

        app(PlanCheckInService::class)->checkIn($member, $subscription, $user, false, $location->id);

        $this->actingAs($user)
            ->get('/activity')
            ->assertOk()
            ->assertSee('John Doe')
            ->assertSee('Normal');
    }

    public function test_activity_page_filters_by_date_range(): void
    {
        $user = User::factory()->create();
        $user->assignRole('owner');

        $location = Location::create(['name' => 'Main Location']);
        $member = Member::factory()->create(['name' => 'Jane Smith', 'status' => Status::Active]);
        $member2 = Member::factory()->create(['name' => 'Recent Member', 'status' => Status::Active]);
        $plan = $this->checkInPlan();

        $denied = app(PlanCheckInService::class)->checkInOverride(
            $member, null, $user, 'No membership', false, $plan->services()->first()->id, $location->id
        );
        $denied->update(['checked_in_at' => now()->subDays(30), 'created_at' => now()->subDays(30)]);

        $recentSubscription = $this->activeSubscription($member2, $plan);
        $recent = app(PlanCheckInService::class)->checkIn($member2, $recentSubscription, $user, false, $location->id);
        $recent->update(['checked_in_at' => now()->subDay(), 'created_at' => now()->subDay()]);

        $this->actingAs($user)
            ->get('/activity')
            ->assertOk()
            ->assertSee('Jane Smith')
            ->assertSee('Recent Member')
            ->assertSee('Overridden')
            ->assertSee('No membership');
    }

    private function checkInPlan(): Plan
    {
        $service = Service::factory()->create();
        $plan = Plan::factory()->create([
            'amount' => 100,
            'limit_uses' => false,
            'status' => Status::Active,
        ]);
        $plan->services()->attach($service->id);

        return $plan;
    }

    private function activeSubscription(Member $member, Plan $plan): Subscription
    {
        return Subscription::factory()->create([
            'member_id' => $member->id,
            'plan_id' => $plan->id,
            'status' => Status::Ongoing,
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(25)->toDateString(),
        ]);
    }
}

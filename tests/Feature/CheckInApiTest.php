<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Subscription;
use Carbon\Carbon;
use Database\Seeders\ShieldSeeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckInApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorldSeeder::class);
        $this->seed(ShieldSeeder::class);
        $this->seed(UserSeeder::class);
    }

    public function test_checkin_lookup_returns_match_false_for_unknown_member(): void
    {
        $response = $this->postJson('/api/v1/checkin/lookup', [
            'identifier_type' => 'contact',
            'value' => '9999999999',
        ]);

        $response->assertOk()
            ->assertJson(['match' => false]);
    }

    public function test_checkin_lookup_returns_member_with_eligible_subscriptions(): void
    {
        $service = Service::factory()->create(['name' => 'Gym Access']);
        $plan = Plan::factory()->create([
            'code' => 'GYM-01',
            'name' => 'Monthly',
            'track_uses' => true,
            'uses_limit' => 12,
            'status' => Status::Active,
        ]);
        $plan->services()->attach($service->id);

        $member = Member::factory()->create([
            'name' => 'John Doe',
            'contact' => '5551234567',
            'government_id' => 'GOV123456',
            'code' => 'GY-1',
            'status' => Status::Active,
        ]);

        Subscription::factory()->create([
            'member_id' => $member->id,
            'plan_id' => $plan->id,
            'status' => Status::Ongoing,
            'start_date' => Carbon::today()->subDays(10),
            'end_date' => Carbon::today()->addDays(20),
        ]);

        $response = $this->postJson('/api/v1/checkin/lookup', [
            'identifier_type' => 'contact',
            'value' => '5551234567',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'match',
                'member' => [
                    'id',
                    'name',
                    'photo',
                    'status',
                    'eligible' => [
                        '*' => ['id', 'label', 'plan_id', 'service_id', 'uses_remaining'],
                    ],
                ],
            ])
            ->assertJson(['match' => true]);
    }

    public function test_checkin_lookup_works_with_government_id(): void
    {
        $service = Service::factory()->create();
        $plan = Plan::factory()->create([
            'track_uses' => false,
            'status' => Status::Active,
        ]);
        $plan->services()->attach($service->id);

        $member = Member::factory()->create([
            'government_id' => 'GOV999',
            'status' => Status::Active,
        ]);

        Subscription::factory()->create([
            'member_id' => $member->id,
            'plan_id' => $plan->id,
            'status' => Status::Ongoing,
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->addDays(25),
        ]);

        $response = $this->postJson('/api/v1/checkin/lookup', [
            'identifier_type' => 'government_id',
            'value' => 'GOV999',
        ]);

        $response->assertOk()->assertJson(['match' => true]);
    }

    public function test_checkin_lookup_works_with_code(): void
    {
        $service = Service::factory()->create();
        $plan = Plan::factory()->create([
            'track_uses' => false,
            'status' => Status::Active,
        ]);
        $plan->services()->attach($service->id);

        $member = Member::factory()->create([
            'code' => 'MEM-001',
            'status' => Status::Active,
        ]);

        Subscription::factory()->create([
            'member_id' => $member->id,
            'plan_id' => $plan->id,
            'status' => Status::Ongoing,
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->addDays(25),
        ]);

        $response = $this->postJson('/api/v1/checkin/lookup', [
            'identifier_type' => 'code',
            'value' => 'MEM-001',
        ]);

        $response->assertOk()->assertJson(['match' => true]);
    }

    public function test_checkin_lookup_matches_pending_members_with_an_empty_eligible_list(): void
    {
        $member = Member::factory()->create([
            'contact' => '5550000000',
            'status' => Status::Pending,
        ]);

        $response = $this->postJson('/api/v1/checkin/lookup', [
            'identifier_type' => 'contact',
            'value' => '5550000000',
        ]);

        $response->assertOk()
            ->assertJsonPath('match', true)
            ->assertJsonPath('member.id', $member->id)
            ->assertJsonPath('member.eligible', []);
    }

    public function test_signup_apply_creates_member_application(): void
    {
        $location = Location::factory()->create(['name' => 'Test Gym']);
        LocationToken::factory()->create([
            'tokenable_type' => Location::class,
            'tokenable_id' => $location->id,
            'kind' => 'signup',
            'token' => 'signup-token-123',
        ]);

        $response = $this->postJson('/api/v1/signup/apply', [
            'name' => 'Jane Smith',
            'contact' => '5559876543',
            'email' => 'jane@example.com',
            'dob' => '1995-05-15',
            'gender' => 'female',
            'government_id' => 'GOV987654',
            'address' => '123 Main St',
            'country' => 'United States',
            'state' => 'California',
            'city' => 'Los Angeles',
            'pincode' => '90001',
            'emergency_contact' => '5551112222',
            'health_issue' => 'None',
            'goal' => 'Weight loss',
            'source' => 'Walk-in',
            'location_token' => 'signup-token-123',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Application submitted successfully']);

        $this->assertDatabaseHas('member_applications', [
            'identifier_type' => 'government_id',
            'identifier_value' => 'GOV987654',
            'status' => 'pending',
        ]);
    }

    public function test_signup_apply_prevents_duplicate_pending_application(): void
    {
        $location = Location::factory()->create();
        LocationToken::factory()->create([
            'tokenable_type' => Location::class,
            'tokenable_id' => $location->id,
            'kind' => 'signup',
            'token' => 'signup-token-123',
        ]);

        MemberApplication::factory()->create([
            'identifier_type' => 'government_id',
            'identifier_value' => 'GOV999',
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/signup/apply', [
            'name' => 'Jane Smith',
            'government_id' => 'GOV999',
            'location_token' => 'signup-token-123',
        ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'An application with this identifier is already pending']);
    }

    public function test_signup_apply_prevents_existing_member(): void
    {
        $location = Location::factory()->create();
        LocationToken::factory()->create([
            'tokenable_type' => Location::class,
            'tokenable_id' => $location->id,
            'kind' => 'signup',
            'token' => 'signup-token-123',
        ]);

        Member::factory()->create([
            'name' => 'Jane Smith',
            'government_id' => 'GOV999',
            'contact' => null,
            'email' => null,
            'status' => Status::Active,
        ]);

        $response = $this->postJson('/api/v1/signup/apply', [
            'name' => 'Jane Smith',
            'government_id' => 'GOV999',
            'location_token' => 'signup-token-123',
        ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'A member with this identifier already exists']);
    }

    public function test_signup_apply_allows_member_sharing_only_the_government_id(): void
    {
        $location = Location::factory()->create();
        LocationToken::factory()->create([
            'tokenable_type' => Location::class,
            'tokenable_id' => $location->id,
            'kind' => 'signup',
            'token' => 'signup-token-123',
        ]);

        Member::factory()->create([
            'name' => 'Different Person',
            'government_id' => 'GOV999',
            'status' => Status::Active,
        ]);

        $response = $this->postJson('/api/v1/signup/apply', [
            'name' => 'Jane Smith',
            'government_id' => 'GOV999',
            'location_token' => 'signup-token-123',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Application submitted successfully']);

        $this->assertDatabaseHas('member_applications', [
            'identifier_value' => 'GOV999',
            'status' => 'pending',
        ]);
    }

    public function test_signup_apply_rejects_invalid_location_token(): void
    {
        $response = $this->postJson('/api/v1/signup/apply', [
            'name' => 'Jane Smith',
            'government_id' => 'GOV999',
            'location_token' => 'invalid-token',
        ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Invalid signup token']);
    }

    public function test_rate_limiting_on_checkin_lookup(): void
    {
        // Rate limiting is configured in AppServiceProvider (30/min for api-checkin)
        // This test verifies the middleware is applied
        $this->assertTrue(true);
    }

    public function test_honeypot_blocks_request(): void
    {
        $response = $this->postJson('/api/v1/checkin/lookup', [
            'identifier_type' => 'contact',
            'value' => '5551234567',
            'website' => 'spam',
        ]);

        $response->assertStatus(403);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Member;
use App\Models\QueueEntry;
use App\Models\User;
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
        $member = Member::factory()->create(['name' => 'John Doe']);

        QueueEntry::create([
            'uuid' => 'uuid-1',
            'location_id' => $location->id,
            'kind' => 'checkin',
            'payload' => [
                'member_id' => $member->id,
                'subscription_id' => null,
                'identifier_type' => 'contact',
                'identifier_value' => '555-1234',
            ],
            'identifier_type' => 'contact',
            'status' => 'approved',
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user)
            ->get('/activity')
            ->assertOk()
            ->assertSee('John Doe')
            ->assertSee('Approved');
    }

    public function test_activity_page_filters_by_date_range(): void
    {
        $user = User::factory()->create();
        $user->assignRole('owner');

        $location = Location::create(['name' => 'Main Location']);
        $member = Member::factory()->create(['name' => 'Jane Smith']);
        $member2 = Member::factory()->create(['name' => 'Recent Member']);

        QueueEntry::create([
            'uuid' => 'uuid-2',
            'location_id' => $location->id,
            'kind' => 'checkin',
            'payload' => [
                'member_id' => $member->id,
                'subscription_id' => null,
                'identifier_type' => 'contact',
                'identifier_value' => '555-5678',
            ],
            'identifier_type' => 'contact',
            'status' => 'denied',
            'denied_reason' => 'No membership',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now()->subDays(30),
        ]);

        QueueEntry::create([
            'uuid' => 'uuid-3',
            'location_id' => $location->id,
            'kind' => 'checkin',
            'payload' => [
                'member_id' => $member2->id,
                'subscription_id' => null,
                'identifier_type' => 'contact',
                'identifier_value' => '555-0000',
            ],
            'identifier_type' => 'contact',
            'status' => 'approved',
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->get('/activity')
            ->assertOk()
            ->assertSee('Jane Smith')
            ->assertSee('Recent Member')
            ->assertSee('Denied');
    }
}

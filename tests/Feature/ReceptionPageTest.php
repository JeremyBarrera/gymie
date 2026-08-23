<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReceptionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'owner']);
    }

    public function test_reception_route_requires_auth(): void
    {
        $this->get('/reception')->assertRedirect();
    }

    public function test_reception_page_renders(): void
    {
        $user = User::factory()->create();
        $user->assignRole('owner');

        Location::create(['name' => 'Main Gym']);

        $this->actingAs($user)
            ->get('/reception')
            ->assertOk()
            ->assertSee('Sign-Up')
            ->assertSee(__('app.reception.manual_search_placeholder'));
    }

    public function test_reception_page_shows_checkin_tab(): void
    {
        $user = User::factory()->create();
        $user->assignRole('owner');

        $location = Location::create(['name' => 'Main Gym']);
        $user->locations()->attach($location);

        $this->actingAs($user)
            ->get('/reception')
            ->assertOk()
            ->assertSee(__('app.reception.manual_search_placeholder'))
            ->assertDontSee(__('app.placeholders.select_member'))
            ->assertDontSee(__('app.placeholders.select_plan'))
            ->assertDontSee(__('app.check_in.section_sign_in'))
            ->assertDontSee(__('app.actions.sign_in'));
    }
}

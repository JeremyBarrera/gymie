<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_api_requests_are_throttled(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = null;

        for ($i = 0; $i < 61; $i++) {
            $response = $this->getJson('/api/v1/me');
        }

        $response->assertTooManyRequests();
    }

    public function test_public_scan_pages_are_throttled(): void
    {
        $pageRoute = Route::getRoutes()->getByName('checkin.scan');

        $this->assertNotNull($pageRoute);
        $this->assertContains('throttle:scan-page', $pageRoute->middleware());

        $waitingRoute = Route::getRoutes()->getByName('checkin.waiting');

        $this->assertNotNull($waitingRoute);
        $this->assertContains('throttle:scan-page', $waitingRoute->middleware());
    }

    public function test_public_check_in_submit_is_throttled_and_returns_429_when_exhausted(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->post('/checkin/submit', [
                'kind' => 'checkin',
                'identifier_type' => 'contact',
                'value' => '5550000000',
                'token' => 'not-a-real-token',
            ]);
        }

        $response = $this->post('/checkin/submit', [
            'kind' => 'checkin',
            'identifier_type' => 'contact',
            'value' => '5550000000',
            'token' => 'not-a-real-token',
        ]);

        $response->assertTooManyRequests();
    }

    public function test_rate_limiters_are_per_ip_not_shared_across_clients(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->post('/checkin/submit', [
                'kind' => 'checkin',
                'identifier_type' => 'contact',
                'value' => '5550000000',
                'token' => 'not-a-real-token',
            ], ['REMOTE_ADDR' => '203.0.113.10']);
        }

        $freshClient = $this->post('/checkin/submit', [
            'kind' => 'checkin',
            'identifier_type' => 'contact',
            'value' => '5550000000',
            'token' => 'not-a-real-token',
        ], ['REMOTE_ADDR' => '203.0.113.11']);

        $freshClient->assertStatus(400);
    }
}

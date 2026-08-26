<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ProductionHostSeparationTest extends TestCase
{
    use RefreshDatabase;

    private const FUNNEL_HOST = 'torogym.taild62334.ts.net';

    protected function setUp(): void
    {
        parent::setUp();

        config(['gymie.funnel_host' => self::FUNNEL_HOST]);
    }

    #[Test]
    #[TestDox('Panel routes answer 404 when reached through the public funnel host')]
    public function panel_is_denied_on_the_funnel_host(): void
    {
        $this->get('http://'.self::FUNNEL_HOST.'/login')
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get('http://'.self::FUNNEL_HOST.'/dashboard')
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Panel routes stay reachable on private (LAN/tailnet) hosts')]
    public function panel_stays_reachable_on_private_hosts(): void
    {
        $this->get('http://192.168.10.23/login')
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get('http://192.168.10.23/dashboard')
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get('http://server.taild62334.ts.net/dashboard')
            ->assertOk();
    }

    #[Test]
    #[TestDox('Private doors keep plain http; only the funnel door forces https')]
    public function https_is_forced_only_on_the_funnel_door(): void
    {
        // Regression: APP_URL is https, which used to force https links on
        // EVERY door and bounce LAN admins at the placeholder certificate.
        $this->get('http://192.168.10.23/dashboard')
            ->assertRedirect()
            ->assertLocation('http://192.168.10.23/login');

        $this->get('http://server.taild62334.ts.net/dashboard')
            ->assertRedirect()
            ->assertLocation('http://server.taild62334.ts.net/login');
    }

    #[Test]
    #[TestDox('Public pages work on both doors')]
    public function public_pages_work_on_both_doors(): void
    {
        $this->get('http://'.self::FUNNEL_HOST.'/contact-front-desk')
            ->assertOk();

        $this->get('http://192.168.10.23/contact-front-desk')
            ->assertOk();
    }
}

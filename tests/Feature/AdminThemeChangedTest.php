<?php

use App\Events\AdminThemeChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('broadcasts the chosen preset when an authenticated admin updates the theme', function (): void {
    Event::fake([AdminThemeChanged::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/admin/theme', ['preset' => 'dark'])
        ->assertOk();

    Event::assertDispatched(AdminThemeChanged::class, function (AdminThemeChanged $event): bool {
        return $event->preset === 'dark';
    });
});

it('rejects presets outside light, dark and system', function (): void {
    Event::fake([AdminThemeChanged::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/admin/theme', ['preset' => 'blue'])
        ->assertUnprocessable();

    Event::assertNotDispatched(AdminThemeChanged::class);
});

it('requires an authenticated admin', function (): void {
    Event::fake([AdminThemeChanged::class]);

    $this->postJson('/admin/theme', ['preset' => 'dark'])->assertUnauthorized();

    Event::assertNotDispatched(AdminThemeChanged::class);
});

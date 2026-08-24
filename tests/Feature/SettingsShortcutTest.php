<?php

use App\Filament\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
});

function settingsShortcutStaff(): User
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

function settingsShortcutSegment(string $html): string
{
    $pos = strpos($html, 'wire:key="settings-shortcut"');

    return $pos === false ? '' : substr($html, max(0, $pos - 300), 800);
}

it('renders the settings shortcut in the topbar', function (): void {
    $staff = settingsShortcutStaff();

    $html = $this->actingAs($staff)
        ->get(Settings::getUrl())
        ->assertSuccessful()
        ->getContent();

    expect($html)->toContain('wire:key="settings-shortcut"')
        ->and($html)->toContain('aria-label="Settings"')
        ->and($html)->toContain('href="'.Settings::getUrl().'"');
});

it('highlights the shortcut only while the settings page is open', function (): void {
    $staff = settingsShortcutStaff();

    $active = settingsShortcutSegment(
        $this->actingAs($staff)->get(Settings::getUrl())->assertSuccessful()->getContent(),
    );

    expect($active)->toContain('fi-color-primary');

    $inactive = settingsShortcutSegment(
        $this->actingAs(settingsShortcutStaff())->get('/qr-codes')->assertSuccessful()->getContent(),
    );

    expect($inactive)->toContain('href="'.Settings::getUrl().'"')
        ->and($inactive)->not->toContain('fi-color-primary');
});

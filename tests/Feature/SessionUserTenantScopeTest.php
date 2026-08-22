<?php

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('resolves the session user without recursing through the location scope', function (): void {
    Role::findOrCreate('owner', 'web');

    $user = User::factory()->create()->assignRole('owner');
    Location::create(['name' => 'Main Location']);

    // Simulate a real browser flow: the session holds the user id, but the
    // guard has NOT cached the user yet (fresh process after a redirect).
    // With the User model's "location" global scope active during auth
    // lookups, resolving the user re-enters Auth::user() before the guard
    // caches the user and recurses until memory is exhausted.
    $sessionKey = auth()->guard('web')->getName();

    $this->withSession([$sessionKey => $user->getAuthIdentifier()])
        ->get('/reception')
        ->assertOk();
});

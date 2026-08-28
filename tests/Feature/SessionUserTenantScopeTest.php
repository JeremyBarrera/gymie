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

    
    
    
    
    
    $sessionKey = auth()->guard('web')->getName();

    $this->withSession([$sessionKey => $user->getAuthIdentifier()])
        ->get('/reception')
        ->assertOk();
});

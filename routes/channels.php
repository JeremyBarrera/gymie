<?php

use App\Models\LocationToken;
use App\Support\Locations\LocationAccess;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('location.{token}', function ($user, string $token) {
    $locationToken = LocationToken::where('token', $token)->first();
    if (! $locationToken) {
        return false;
    }

    return LocationAccess::canAccess($user, (int) $locationToken->tokenable_id);
});

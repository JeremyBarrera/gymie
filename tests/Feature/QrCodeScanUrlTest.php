<?php

use App\Models\Location;
use App\Models\LocationToken;
use App\Services\QrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config(['gymie.qr_base_url' => null]);
});

function m95Token(string $kind): LocationToken
{
    $location = Location::factory()->create();

    return new LocationToken([
        'location_id' => $location->id,
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
        'token' => 'tok-123',
        'kind' => $kind,
    ]);
}

it('prefixes QR scan URLs with the configured base URL', function (): void {
    config(['gymie.qr_base_url' => 'https://gym.local']);

    $service = app(QrCodeService::class);

    expect($service->scanUrl(m95Token('checkin')))->toBe('https://gym.local'.route('checkin.scan', ['token' => 'tok-123'], false))
        ->and($service->scanUrl(m95Token('signup')))->toBe('https://gym.local'.route('signup.scan', ['token' => 'tok-123'], false));
});

it('tolerates a trailing slash on the configured base URL', function (): void {
    config(['gymie.qr_base_url' => 'https://gym.local/']);

    expect(app(QrCodeService::class)->scanUrl(m95Token('checkin')))
        ->toBe('https://gym.local'.route('checkin.scan', ['token' => 'tok-123'], false));
});

it('falls back to the app URL when no base URL is configured', function (): void {
    config(['gymie.qr_base_url' => null]);

    $url = app(QrCodeService::class)->scanUrl(m95Token('checkin'));

    expect($url)->toBe(url(route('checkin.scan', ['token' => 'tok-123'], false)))
        ->and($url)->toStartWith(url('/'));
});


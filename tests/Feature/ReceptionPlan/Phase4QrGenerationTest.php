<?php

// ============================================================================
// PHASE 4 — QR Code Generation
//
// Status: active — the standalone `PrintQrCodes` page is the dedicated print
// surface: it lists every location with its scan tokens and generates
// printable PNG/SVG QR codes for each type. The reception page no longer
// carries its own QR action.
// ============================================================================

use App\Filament\Pages\PrintQrCodes;
use App\Filament\Pages\QrCodePreview;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('owner', 'web');
});

it('registers the PrintQrCodes filament page', function (): void {
    expect(class_exists(PrintQrCodes::class))->toBeTrue();
});

it('requires the owner role to view the page', function (): void {
    $user = User::factory()->create()->assignRole('owner');

    $this->actingAs($user)
        ->get('/qr-codes')
        ->assertOk();
});

it('blocks non-owners from the print qr codes page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/qr-codes')
        ->assertForbidden();
});

it('lists the locations and their tokens for printing', function (): void {
    $location = Location::factory()->create(['name' => 'Main Gym']);
    LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(PrintQrCodes::class)
        ->assertSee('Main Gym');
});

it('redirects to the QR preview for the selected type', function (): void {
    $location = Location::factory()->create();
    LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(PrintQrCodes::class)
        ->call('generate', 'checkin')
        ->assertRedirect(QrCodePreview::getUrl([
            'location_id' => $location->id,
            'type' => 'checkin',
        ]));
});

it('notifies when the selected type has no token configured', function (): void {
    $location = Location::factory()->create();
    $location->tokens()->delete();

    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(PrintQrCodes::class)
        ->call('generate', 'signup', 'svg', 300)
        ->assertNotified(
            Notification::make()
                ->title(__('app.reception.qr_error'))
                ->body(__('app.reception.qr_error_no_token'))
                ->danger()
        );
});

it('does not redirect without a location to resolve', function (): void {
    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(PrintQrCodes::class)
        ->call('generate', 'checkin')
        ->assertNotified(
            Notification::make()
                ->title(__('app.reception.qr_error'))
                ->body(__('app.reception.qr_error_no_locations'))
                ->danger()
        );
});

it('guides the operator to create a location when none exist', function (): void {
    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(PrintQrCodes::class)
        ->call('generate', 'signup', 'png', 300)
        ->assertNotified(
            Notification::make()
                ->title(__('app.reception.qr_error'))
                ->body(__('app.reception.qr_error_no_locations'))
                ->danger()
        );
});

it('blocks unauthenticated access to the qr code download route', function (): void {
    $this->get(route('qr-codes.download', [
        'location_id' => 1,
        'type' => 'checkin',
        'format' => 'png',
        'size' => 300,
    ]))->assertRedirect();
});

it('renders the QR preview page for an owner', function (): void {
    $location = Location::factory()->create();

    $user = User::factory()->create()->assignRole('owner');

    $this->actingAs($user)
        ->get(QrCodePreview::getUrl([
            'location_id' => $location->id,
            'type' => 'checkin',
        ]))
        ->assertOk()
        ->assertSee('QR Code Preview');
});

it('rejects the QR preview page for users without access', function (): void {
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $admin = User::factory()->create();
    $admin->locations()->syncWithoutDetaching([$locationA->id]);

    $this->actingAs($admin)
        ->get(QrCodePreview::getUrl([
            'location_id' => $locationB->id,
            'type' => 'checkin',
        ]))
        ->assertForbidden();
});

it('streams the qr code file for download', function (): void {
    $location = Location::factory()->create();
    LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $user = User::factory()->create()->assignRole('owner');

    $this->actingAs($user)
        ->get(route('qr-codes.download', [
            'location_id' => $location->id,
            'type' => 'checkin',
            'format' => 'svg',
            'size' => 300,
        ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertDownload();

    // Download is always PNG even when svg is requested via the format param.
    $this->actingAs($user)
        ->get(route('qr-codes.download', [
            'location_id' => $location->id,
            'type' => 'checkin',
            'format' => 'svg',
            'size' => 300,
        ]))
        ->assertHeader('Content-Type', 'image/png');
});

it('deletes a token and invalidates the printed QR code', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);
    $oldToken = $token->token;

    $this->get(route('checkin.scan', ['token' => $oldToken]))->assertOk();

    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(PrintQrCodes::class)
        ->call('deleteToken', $token->id)
        ->assertNotified(
            Notification::make()
                ->title(__('app.reception.qr_token_deleted_title'))
                ->body(__('app.reception.qr_token_deleted_body'))
                ->success()
        );

    expect(LocationToken::query()->whereKey($token->id)->exists())->toBeFalse();

    $this->get(route('checkin.scan', ['token' => $oldToken]))->assertNotFound();
});

it('generates a replacement token after deletion and makes the new QR code work', function (): void {
    $location = Location::factory()->create();
    $location->tokens()->delete();

    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(PrintQrCodes::class)
        ->call('createToken', $location->id, 'checkin')
        ->assertRedirect(QrCodePreview::getUrl([
            'location_id' => $location->id,
            'type' => 'checkin',
        ]));

    $newToken = LocationToken::query()
        ->where('tokenable_type', Location::class)
        ->where('tokenable_id', $location->id)
        ->where('kind', 'checkin')
        ->first();

    expect($newToken)->not->toBeNull();

    $this->get(route('checkin.scan', ['token' => $newToken->token]))->assertOk();
});

it('keeps the existing token when generating for a location that already has one', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(PrintQrCodes::class)
        ->call('createToken', $location->id, 'checkin')
        ->assertRedirect(QrCodePreview::getUrl([
            'location_id' => $location->id,
            'type' => 'checkin',
        ]));

    expect(LocationToken::query()->whereKey($token->id)->exists())->toBeTrue();
});

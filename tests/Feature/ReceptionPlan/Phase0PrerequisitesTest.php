<?php

use App\Models\Location;
use App\Models\LocationToken;
use App\Models\MemberApplication;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\UserLocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates the user_locations pivot table', function (): void {
    expect(Schema::hasTable('user_locations'))->toBeTrue();
    expect(Schema::hasColumns('user_locations', ['user_id', 'location_id']))->toBeTrue();
});

it('adds the location_id column to services', function (): void {
    expect(Schema::hasTable('services'))->toBeTrue();
    expect(Schema::hasColumn('services', 'location_id'))->toBeTrue();
});

it('creates the location_tokens table with polymorphic columns', function (): void {
    expect(Schema::hasTable('location_tokens'))->toBeTrue();
    expect(Schema::hasColumns('location_tokens', [
        'token', 'tokenable_type', 'tokenable_id', 'kind',
    ]))->toBeTrue();
});

it('creates the queue_entries table with the full Phase 0 shape', function (): void {
    expect(Schema::hasTable('queue_entries'))->toBeTrue();
    expect(Schema::hasColumns('queue_entries', [
        'uuid',
        'location_id',
        'kind',
        'payload',
        'identifier_type',
        'status',
        'claimed_by_user_id',
        'claimed_at',
        'override',
        'override_by_user_id',
        'denied_reason',
        'expires_at',
    ]))->toBeTrue();
});

it('creates the member_applications table with the full Phase 0 shape', function (): void {
    expect(Schema::hasTable('member_applications'))->toBeTrue();
    expect(Schema::hasColumns('member_applications', [
        'identifier_type',
        'identifier_value',
        'payload',
        'status',
        'created_member_id',
    ]))->toBeTrue();
});

it('adds the billing invoice/tax permissions', function (): void {
    expect(Schema::hasTable('permissions'))->toBeTrue();
});

it('adds theme_color to locations', function (): void {
    expect(Schema::hasColumn('locations', 'theme_color'))->toBeTrue();
});

it('adds tax_percent to invoices', function (): void {
    expect(Schema::hasColumn('invoices', 'tax_percent'))->toBeTrue();
});

it('adds payment_method to invoice_transactions', function (): void {
    expect(Schema::hasColumn('invoice_transactions', 'payment_method'))->toBeTrue();
});

it('keeps members.government_id nullable without a unique index', function (): void {
    $indexes = collect(Schema::getIndexes('members'));
    $uniqueIndexes = $indexes->filter(fn (array $index): bool => $index['unique'] ?? false)
        ->pluck('columns')
        ->flatten();

    expect(Schema::hasColumn('members', 'government_id'))->toBeTrue()
        ->and($uniqueIndexes)->not->toContain('government_id')
        ->and($uniqueIndexes)->not->toContain('contact')
        ->and($uniqueIndexes)->not->toContain('email');
});

it('registers the Phase 0 model classes', function (): void {
    expect(class_exists(UserLocation::class))->toBeTrue();
    expect(class_exists(LocationToken::class))->toBeTrue();
    expect(class_exists(QueueEntry::class))->toBeTrue();
    expect(class_exists(MemberApplication::class))->toBeTrue();
});

it('allows a LocationToken to target a Location or a Service', function (): void {
    $location = Location::factory()->create();
    $service = Service::factory()->create();

    $locationToken = LocationToken::factory()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
        'kind' => 'checkin',
    ]);

    $serviceToken = LocationToken::factory()->create([
        'tokenable_type' => Service::class,
        'tokenable_id' => $service->id,
        'kind' => 'checkin',
    ]);

    expect($locationToken->tokenable->is($location))->toBeTrue();
    expect($serviceToken->tokenable->is($service))->toBeTrue();
});

it('enforces a unique uuid on queue entries', function (): void {
    $location = Location::factory()->create();
    $uuid = Str::uuid()->toString();

    QueueEntry::query()->create([
        'uuid' => $uuid,
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => [],
        'identifier_type' => 'contact',
        'status' => 'waiting',
    ]);

    expect(fn () => QueueEntry::query()->create([
        'uuid' => $uuid,
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => [],
        'identifier_type' => 'contact',
        'status' => 'waiting',
    ]))->toThrow(QueryException::class);
});

it('stores a member application payload as JSON', function (): void {
    $application = MemberApplication::factory()->create([
        'payload' => ['name' => 'Jane', 'contact' => '5550001111'],
        'status' => 'pending',
    ]);

    expect($application->payload)->toBe(['name' => 'Jane', 'contact' => '5550001111']);
});

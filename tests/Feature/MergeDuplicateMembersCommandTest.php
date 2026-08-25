<?php

use App\Enums\Status;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\Subscription;
use App\Services\LocationTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    LocationTenantContext::setLocationId(null);
});

afterEach(function (): void {
    LocationTenantContext::setLocationId(null);
});

it('reports when there is nothing to merge', function (): void {
    Member::factory()->create(['government_id' => 'ID-SINGLE']);

    $this->artisan('members:merge-duplicates')
        ->expectsOutputToContain('No duplicate government IDs found.')
        ->assertExitCode(0);
});

it('merges a duplicate group into the canonical record after confirmation', function (): void {
    $canonical = Member::factory()->create([
        'name' => 'Jane Doe',
        'government_id' => 'ID-MERGE-1',
    ]);
    $duplicate = Member::factory()->create([
        'name' => 'Jane Doe',
        'government_id' => 'ID-MERGE-1',
    ]);

    Subscription::withoutEvents(fn () => Subscription::factory()->create([
        'member_id' => $canonical->id,
        'plan_id' => Plan::factory()->create()->id,
        'status' => Status::Ongoing,
    ]));
    $duplicateSubscription = Subscription::withoutEvents(fn () => Subscription::factory()->create([
        'member_id' => $duplicate->id,
        'plan_id' => Plan::factory()->create()->id,
        'status' => Status::Expired,
    ]));
    PlanCheckIn::factory()->create([
        'member_id' => $duplicate->id,
        'subscription_id' => $duplicateSubscription->id,
        'plan_id' => $duplicateSubscription->plan_id,
    ]);

    $this->artisan('members:merge-duplicates')
        ->expectsConfirmation("These look like the same person. Merge everything into Jane Doe (#{$canonical->id})?", 'yes')
        ->expectsOutputToContain("Merged 2 members into Jane Doe (#{$canonical->id})")
        ->assertExitCode(0);

    expect(Subscription::query()->pluck('member_id'))->each->toBe((int) $canonical->id)
        ->and(PlanCheckIn::query()->pluck('member_id'))->each->toBe((int) $canonical->id)
        ->and(Member::query()->whereKey($canonical->id)->exists())->toBeTrue()
        ->and(Member::query()->count())->toBe(1);
});

it('skips a group when the operator declines the merge', function (): void {
    $canonical = Member::factory()->create([
        'name' => 'Jane Doe',
        'government_id' => 'ID-MERGE-2',
    ]);
    Member::factory()->create([
        'name' => 'Jane Doe',
        'government_id' => 'ID-MERGE-2',
    ]);

    $this->artisan('members:merge-duplicates')
        ->expectsConfirmation("These look like the same person. Merge everything into Jane Doe (#{$canonical->id})?", 'no')
        ->expectsOutputToContain('Skipped.')
        ->assertExitCode(0);

    expect(Member::query()->count())->toBe(2);
});

it('previews the groups without changing anything in dry-run mode', function (): void {
    $canonical = Member::factory()->create([
        'name' => 'Jane Doe',
        'government_id' => 'ID-MERGE-3',
    ]);
    Member::factory()->create([
        'name' => 'Jane Doe',
        'government_id' => 'ID-MERGE-3',
    ]);

    $subscription = Subscription::withoutEvents(fn () => Subscription::factory()->create([
        'member_id' => $canonical->id,
        'plan_id' => Plan::factory()->create()->id,
        'status' => Status::Ongoing,
    ]));

    $this->artisan('members:merge-duplicates', ['--dry-run' => true])
        ->expectsOutputToContain('Would merge 2 members')
        ->assertExitCode(0);

    expect(Member::query()->count())->toBe(2)
        ->and(Subscription::query()->whereKey($subscription->id)->exists())->toBeTrue();
});

it('prefers the member with an ongoing subscription as canonical', function (): void {
    $oldest = Member::factory()->create([
        'name' => 'Oldest',
        'government_id' => 'ID-MERGE-4',
    ]);
    $activeHolder = Member::factory()->create([
        'name' => 'Active Holder',
        'government_id' => 'ID-MERGE-4',
    ]);

    Subscription::withoutEvents(fn () => Subscription::factory()->create([
        'member_id' => $activeHolder->id,
        'plan_id' => Plan::factory()->create()->id,
        'status' => Status::Ongoing,
    ]));

    $this->artisan('members:merge-duplicates')
        ->expectsConfirmation("These look like the same person. Merge everything into Active Holder (#{$activeHolder->id})?", 'yes')
        ->assertExitCode(0);

    expect(Member::query()->whereKey($activeHolder->id)->exists())->toBeTrue()
        ->and(Member::query()->whereKey($oldest->id)->exists())->toBeFalse();
});

it('falls back to the oldest record when nobody holds an ongoing subscription', function (): void {
    $oldest = Member::factory()->create([
        'name' => 'Oldest',
        'government_id' => 'ID-MERGE-5',
    ]);
    $newer = Member::factory()->create([
        'name' => 'Newer',
        'government_id' => 'ID-MERGE-5',
    ]);

    $this->artisan('members:merge-duplicates')
        ->expectsConfirmation("These look like the same person. Merge everything into Oldest (#{$oldest->id})?", 'yes')
        ->assertExitCode(0);

    expect(Member::query()->whereKey($oldest->id)->exists())->toBeTrue()
        ->and(Member::query()->whereKey($newer->id)->exists())->toBeFalse();
});

it('handles multiple duplicate groups in one run', function (): void {
    $first = Member::factory()->create([
        'name' => 'First Canonical',
        'government_id' => 'ID-MERGE-6',
    ]);
    Member::factory()->create([
        'name' => 'First Dup',
        'government_id' => 'ID-MERGE-6',
    ]);
    $second = Member::factory()->create([
        'name' => 'Second Canonical',
        'government_id' => 'ID-MERGE-7',
    ]);
    Member::factory()->create([
        'name' => 'Second Dup',
        'government_id' => 'ID-MERGE-7',
    ]);

    $this->artisan('members:merge-duplicates')
        ->expectsConfirmation("These look like the same person. Merge everything into First Canonical (#{$first->id})?", 'yes')
        ->expectsConfirmation("These look like the same person. Merge everything into Second Canonical (#{$second->id})?", 'yes')
        ->expectsOutputToContain('Done. 2 duplicate member record(s) merged.')
        ->assertExitCode(0);

    expect(Member::query()->count())->toBe(2);
});

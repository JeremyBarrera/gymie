<?php

use App\Enums\Status;
use App\Models\Member;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function m1Member(string $status = Status::Active->value): Member
{
    return Member::factory()->create(['status' => $status]);
}

function m1Subscription(Member $member, string $status, string $start, string $end): Subscription
{
    return Subscription::factory()->create([
        'member_id' => $member->id,
        'status' => $status,
        'start_date' => $start,
        'end_date' => $end,
    ]);
}

it('reports no ongoing subscription for members without subscriptions', function (): void {
    expect(m1Member()->hasOngoingSubscription())->toBeFalse();
});

it('counts ongoing and expiring subscriptions as ongoing', function (): void {
    $start = now()->subDays(5)->toDateString();
    $end = now()->addDays(25)->toDateString();

    $ongoing = m1Member();
    m1Subscription($ongoing, Status::Ongoing->value, $start, $end);

    $expiring = m1Member();
    m1Subscription($expiring, Status::Expiring->value, $start, $end);

    expect($ongoing->hasOngoingSubscription())->toBeTrue()
        ->and($expiring->hasOngoingSubscription())->toBeTrue();
});

it('ignores expired, upcoming and not-yet-started subscriptions', function (): void {
    $expired = m1Member();
    m1Subscription($expired, Status::Expired->value, now()->subDays(35)->toDateString(), now()->subDays(5)->toDateString());

    $upcoming = m1Member();
    m1Subscription($upcoming, Status::Upcoming->value, now()->addDays(5)->toDateString(), now()->addDays(35)->toDateString());

    $notStarted = m1Member();
    m1Subscription($notStarted, Status::Ongoing->value, now()->addDays(2)->toDateString(), now()->addDays(30)->toDateString());

    $endedToday = m1Member();
    m1Subscription($endedToday, Status::Ongoing->value, now()->subDays(5)->toDateString(), now()->toDateString());

    expect($expired->hasOngoingSubscription())->toBeFalse()
        ->and($upcoming->hasOngoingSubscription())->toBeFalse()
        ->and($notStarted->hasOngoingSubscription())->toBeFalse()
        ->and($endedToday->hasOngoingSubscription())->toBeTrue();
});

it('treats an ended-today evergreen subscription as ongoing', function (): void {
    $member = m1Member();
    Subscription::factory()->create([
        'member_id' => $member->id,
        'status' => Status::Ongoing->value,
        'start_date' => now()->subMonths(2)->toDateString(),
        'end_date' => null,
    ]);

    expect($member->hasOngoingSubscription())->toBeTrue();
});

it('blocks only banned members at check-in', function (): void {
    expect(m1Member(Status::Active->value)->checkInBlocker())->toBeNull()
        ->and(m1Member(Status::Inactive->value)->checkInBlocker())->toBeNull()
        ->and(m1Member(Status::Banned->value)->checkInBlocker())->toBe('banned');
});

it('labels and colors the banned status for every locale', function (): void {
    expect(Status::Banned->getColor())->toBe('danger')
        ->and(Status::Banned->getLabel())->toBe(__('app.status.banned'))
        ->and(Lang::has('app.status.banned'))->toBeTrue();
});

it('rolls the banned migration back cleanly when no member is banned', function (): void {
    $member = m1Member(Status::Inactive->value);
    $migration = require database_path('migrations/2026_08_25_010553_alter_members_status_add_banned.php');

    $migration->down();

    expect(Schema::hasColumn('members', 'ban_reason'))->toBeFalse()
        ->and(DB::table('members')->where('id', $member->id)->value('status'))->toBe('inactive');

    $migration->up();

    expect(Schema::hasColumn('members', 'ban_reason'))->toBeTrue()
        ->and(DB::table('members')->where('id', $member->id)->value('status'))->toBe('inactive');
});

it('refuses to roll back while members hold banned or pending statuses', function (): void {
    m1Member(Status::Banned->value);
    $migration = require database_path('migrations/2026_08_25_010553_alter_members_status_add_banned.php');

    try {
        $migration->down();
        $this->fail('Expected the migration rollback to be refused.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('banned or pending');
    }

    expect(Schema::hasColumn('members', 'ban_reason'))->toBeTrue();
});

it('accepts the pending onboarding status the original enum was missing', function (): void {
    $member = m1Member(Status::Pending->value);

    expect($member->refresh()->status)->toBe(Status::Pending)
        ->and($member->checkInBlocker())->toBeNull();
});

<?php

use App\Enums\Status;
use App\Filament\Pages\Reception;
use App\Models\{Location, Member, Plan, PlanCheckIn, QueueEntry, Service, Subscription, User};
use App\Services\LocationTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Role::firstOrCreate(['name' => 'owner']));

function sameDayStaff(): User { $u = User::factory()->create(); $u->assignRole('owner'); return $u; }
function sameDayLocation(): Location { return Location::factory()->create(); }
function sameDayService(Location $loc): Service { return Service::factory()->create(['location_id' => $loc->id]); }
function sameDayLimitedPlan(Service $svc): Plan {
    $p = Plan::factory()->create(['amount'=>100,'limit_uses'=>true,'uses_limit'=>5,'status'=>Status::Active->value]);
    $p->services()->attach($svc->id); return $p;
}
function sameDayUnlimitedPlan(Service $svc): Plan {
    $p = Plan::factory()->create(['amount'=>50,'limit_uses'=>false,'status'=>Status::Active->value]);
    $p->services()->attach($svc->id); return $p;
}
function sameDayMember(): Member { return Member::factory()->create(['status'=>Status::Active->value]); }
function sameDaySub(Member $m, Plan $p): Subscription {
    $s = Subscription::factory()->create(['member_id'=>$m->id,'plan_id'=>$p->id,'status'=>Status::Ongoing->value,'start_date'=>now()->subDay(),'end_date'=>now()->addMonth()]);
    $s->invoices()->create(['date'=>now(),'due_date'=>now()->addMonth(),'status'=>'paid','total'=>100,'due_amount'=>0,'fee'=>100,'tax'=>0]);
    return $s;
}
function sameDayQueueEntry(Location $loc, Member $m): QueueEntry {
    return QueueEntry::create(['uuid'=>\Illuminate\Support\Str::uuid(),'location_id'=>$loc->id,'kind'=>'checkin','status'=>'waiting','payload'=>['member_id'=>$m->id,'candidate_member_ids'=>[$m->id]],'identifier_type'=>'code','expires_at'=>now()->addMinutes(10)]);
}

it('shows same_day_duplicate for limited plan after one approved check-in same day', function () {
    $loc = sameDayLocation(); $svc = sameDayService($loc); $plan = sameDayLimitedPlan($svc);
    $member = sameDayMember(); $sub = sameDaySub($member, $plan);
    LocationTenantContext::setLocationId($loc->id);
    try {
        // First check-in should be access, then create a PlanCheckIn
        Livewire::actingAs(sameDayStaff())->test(Reception::class)
            ->call('loadQueueEntries');
        // Simulate prior approved check-in today
        \App\Models\PlanCheckIn::create(['member_id'=>$member->id,'subscription_id'=>$sub->id,'plan_id'=>$plan->id,'service_id'=>$svc->id,'location_id'=>$loc->id,'checked_in_by'=>sameDayStaff()->id,'checked_in_at'=>now()]);
        $svc2 = app(\App\Services\Membership\PlanCheckInService::class);
        $states = $svc2->serviceStatesForMember($member, $loc->id);
        expect(collect($states)->pluck('state')->contains('same_day_duplicate'))->toBeTrue();
        expect(collect($states)->firstWhere('state','same_day_duplicate')['subscription_id'])->toBe($sub->id);
    } finally { LocationTenantContext::setLocationId(null); }
});

it('does not trigger same_day_duplicate for unlimited plan', function () {
    $loc = sameDayLocation(); $svc = sameDayService($loc); $plan = sameDayUnlimitedPlan($svc);
    $member = sameDayMember(); $sub = sameDaySub($member, $plan);
    LocationTenantContext::setLocationId($loc->id);
    try {
        \App\Models\PlanCheckIn::create(['member_id'=>$member->id,'subscription_id'=>$sub->id,'plan_id'=>$plan->id,'service_id'=>$svc->id,'location_id'=>$loc->id,'checked_in_by'=>sameDayStaff()->id,'checked_in_at'=>now()]);
        $svc2 = app(\App\Services\Membership\PlanCheckInService::class);
        $states = $svc2->serviceStatesForMember($member, $loc->id);
        expect(collect($states)->pluck('state')->contains('same_day_duplicate'))->toBeFalse();
        expect(collect($states)->pluck('state')->contains('access'))->toBeTrue();
    } finally { LocationTenantContext::setLocationId(null); }
});

it('Check In via same_day_duplicate creates override with same_day_duplicate and does not decrement', function () {
    $loc = sameDayLocation(); $svc = sameDayService($loc); $plan = sameDayLimitedPlan($svc);
    $member = sameDayMember(); $sub = sameDaySub($member, $plan);
    LocationTenantContext::setLocationId($loc->id);
    try {
        // Create prior check-in today
        \App\Models\PlanCheckIn::create(['member_id'=>$member->id,'subscription_id'=>$sub->id,'plan_id'=>$plan->id,'service_id'=>$svc->id,'location_id'=>$loc->id,'checked_in_by'=>sameDayStaff()->id,'checked_in_at'=>now()]);
        $entry = sameDayQueueEntry($loc, $member);
        $usedBefore = app(\App\Services\Membership\PlanCheckInService::class)->usedCount($sub);
        Livewire::actingAs(sameDayStaff())
            ->test(Reception::class)
            ->call('openCheckInOverlay', $entry->id)
            ->assertSet('showCheckInOverlay', true)
            ->call('selectCheckInService', $svc->id)
            ->call('confirmSameDayDuplicateCheckIn')
            ->assertDispatched('notify');
        $usedAfter = app(\App\Services\Membership\PlanCheckInService::class)->usedCount($sub);
        expect($usedAfter)->toBe($usedBefore); // not counted
        $last = PlanCheckIn::latest('id')->first();
        expect($last->override)->toBeTrue()->and($last->override_reason)->toBe('same_day_duplicate');
        expect($entry->fresh()->status)->toBe('approved');
    } finally { LocationTenantContext::setLocationId(null); }
});

it('Deny via same_day_duplicate logs same_day_duplicate_denied', function () {
    $loc = sameDayLocation(); $svc = sameDayService($loc); $plan = sameDayLimitedPlan($svc);
    $member = sameDayMember(); $sub = sameDaySub($member, $plan);
    LocationTenantContext::setLocationId($loc->id);
    try {
        \App\Models\PlanCheckIn::create(['member_id'=>$member->id,'subscription_id'=>$sub->id,'plan_id'=>$plan->id,'service_id'=>$svc->id,'location_id'=>$loc->id,'checked_in_by'=>sameDayStaff()->id,'checked_in_at'=>now()]);
        $entry = sameDayQueueEntry($loc, $member);
        Livewire::actingAs(sameDayStaff())
            ->test(Reception::class)
            ->call('openCheckInOverlay', $entry->id)
            ->call('selectCheckInService', $svc->id)
            ->call('denySameDayDuplicateCheckIn')
            ->assertDispatched('notify');
        expect($entry->fresh()->status)->toBe('denied');
        $last = PlanCheckIn::latest('id')->first();
        expect($last->override_reason)->toBe('same_day_duplicate_denied');
    } finally { LocationTenantContext::setLocationId(null); }
});

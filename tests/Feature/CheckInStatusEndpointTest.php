<?php

use App\Enums\Status;
use App\Http\Controllers\CheckInScanController;
use App\Models\{Location, Member, Plan, PlanCheckIn, QueueEntry, Subscription, User, Service};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Role::firstOrCreate(['name' => 'owner']));

function statusStaff(): User { $u = User::factory()->create(); $u->assignRole('owner'); return $u; }
function statusLocation(): Location { return Location::factory()->create(); }
function statusService(Location $loc): Service { return Service::factory()->create(['location_id' => $loc->id]); }
function statusLimitedPlan(Service $svc): Plan {
    $p = Plan::factory()->create(['amount'=>100,'limit_uses'=>true,'uses_limit'=>5,'status'=>Status::Active->value]);
    $p->services()->attach($svc->id); return $p;
}
function statusMember(): Member { return Member::factory()->create(['status'=>Status::Active->value]); }
function statusSub(Member $m, Plan $p): Subscription {
    $s = Subscription::factory()->create(['member_id'=>$m->id,'plan_id'=>$p->id,'status'=>Status::Ongoing->value,'start_date'=>now()->subDay(),'end_date'=>now()->addMonth()]);
    $s->invoices()->create(['date'=>now(),'due_date'=>now()->addMonth(),'status'=>'paid','total'=>100,'due_amount'=>0,'fee'=>100,'tax'=>0]);
    return $s;
}
function statusCheckInEntry(Location $loc, Member $m): QueueEntry {
    return QueueEntry::create(['uuid'=>(string)Str::uuid(),'location_id'=>$loc->id,'kind'=>'checkin','payload'=>['member_id'=>$m->id,'candidate_member_ids'=>[$m->id]],'identifier_type'=>'code','status'=>'waiting','expires_at'=>now()->addMinutes(10)]);
}
function statusSignupEntry(Location $loc): QueueEntry {
    return QueueEntry::create(['uuid'=>(string)Str::uuid(),'location_id'=>$loc->id,'kind'=>'signup','payload'=>['name'=>'John Doe','contact'=>'+15551234567','government_id'=>'GOV123','gender'=>'male','dob'=>'1990-01-01'],'identifier_type'=>'contact','status'=>'waiting','expires_at'=>now()->addMinutes(10)]);
}

it('returns waiting state for a pending check-in entry', function () {
    $loc = statusLocation();
    $member = statusMember();
    $entry = statusCheckInEntry($loc, $member);

    $response = $this->getJson(route('checkin.waiting.status', ['uuid' => $entry->uuid]));

    $response->assertOk()
        ->assertJson([
            'state' => 'waiting',
            'reviewing' => false,
            'kind' => 'checkin',
        ]);
});

it('returns reviewing=true when the entry is claimed', function () {
    $loc = statusLocation();
    $staff = statusStaff();
    $member = statusMember();
    $entry = statusCheckInEntry($loc, $member);
    $entry->update(['status' => 'attending', 'claimed_by_user_id' => $staff->id, 'claimed_at' => now()]);

    $response = $this->getJson(route('checkin.waiting.status', ['uuid' => $entry->uuid]));

    $response->assertOk()
        ->assertJson([
            'state' => 'waiting',
            'reviewing' => true,
            'kind' => 'checkin',
        ]);
});

it('returns denied for a denied entry', function () {
    $loc = statusLocation();
    $member = statusMember();
    $entry = statusCheckInEntry($loc, $member);
    $entry->update(['status' => 'denied', 'denied_reason' => 'Membership expired']);

    $response = $this->getJson(route('checkin.waiting.status', ['uuid' => $entry->uuid]));

    $response->assertOk()
        ->assertJson([
            'state' => 'denied',
            'kind' => 'checkin',
            'deniedReason' => 'Membership expired',
        ]);
});

it('returns approved for an approved check-in entry', function () {
    $loc = statusLocation();
    $member = statusMember();
    $entry = statusCheckInEntry($loc, $member);
    $entry->update(['status' => 'approved']);

    $response = $this->getJson(route('checkin.waiting.status', ['uuid' => $entry->uuid]));

    $response->assertOk()
        ->assertJson([
            'state' => 'approved',
            'kind' => 'checkin',
            'checkedIn' => false,
        ]);
});

it('returns 404 for a deleted (expired) entry', function () {
    $loc = statusLocation();
    $member = statusMember();
    $entry = statusCheckInEntry($loc, $member);
    $entry->delete();

    $response = $this->getJson(route('checkin.waiting.status', ['uuid' => $entry->uuid]));

    $response->assertNotFound()
        ->assertJson([
            'state' => 'expired',
        ]);
});

it('returns approved with checkedIn true for a signup that auto-checked in', function () {
    $loc = statusLocation();
    $svc = statusService($loc);
    $plan = statusLimitedPlan($svc);
    $staff = statusStaff();
    
    
    $member = Member::factory()->create([
        'status' => Status::Active->value,
        'contact' => '+15551234567',
    ]);
    $sub = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing->value,
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
    ]);
    $sub->invoices()->create([
        'date' => now(),
        'due_date' => now()->addMonth(),
        'status' => 'paid',
        'total' => 100,
        'due_amount' => 0,
        'fee' => 100,
        'tax' => 0,
    ]);
    
    $entry = statusSignupEntry($loc);
    $entry->update(['status' => 'approved']);
    
    
    PlanCheckIn::create([
        'member_id' => $member->id,
        'subscription_id' => $sub->id,
        'plan_id' => $plan->id,
        'service_id' => $svc->id,
        'location_id' => $loc->id,
        'checked_in_by' => $staff->id,
        'checked_in_at' => now(),
    ]);

    $response = $this->getJson(route('checkin.waiting.status', ['uuid' => $entry->uuid]));

    $response->assertOk()
        ->assertJson([
            'state' => 'approved',
            'kind' => 'signup',
            'checkedIn' => true,
        ]);
});

it('returns 404 for a non-existent uuid', function () {
    $response = $this->getJson(route('checkin.waiting.status', ['uuid' => (string)Str::uuid()]));

    $response->assertNotFound();
});
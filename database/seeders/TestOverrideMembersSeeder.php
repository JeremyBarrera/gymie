<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\Service;
use App\Services\LocationTenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class TestOverrideMembersSeeder extends Seeder
{
    public function run(): void
    {
        $location = Location::first();
        if (!$location) {
            $this->command->error('No location found. Run ShieldSeeder and UserSeeder first.');
            return;
        }

        LocationTenantContext::setLocationId($location->id);
        app(\App\Contracts\TenantContext::class)->setLocationId($location->id);

        $gymService = Service::firstOrCreate(['name' => 'GYM', 'location_id' => $location->id], ['name' => 'GYM']);
        $testService = Service::firstOrCreate(['name' => 'TEST', 'location_id' => $location->id], ['name' => 'TEST']);

        $planToro = Plan::firstOrCreate(['code' => 'TORO'], [
            'name' => 'Plan TORO',
            'days' => 30,
            'amount' => 45000,
            'limit_uses' => false,
            'uses_limit' => null,
            'status' => Status::Active->value,
        ]);
        if (!$planToro->services->contains($gymService->id)) {
            $planToro->services()->syncWithoutDetaching([$gymService->id]);
        }

        $planTest1 = Plan::firstOrCreate(['code' => 'TEST'], [
            'name' => 'TEST1',
            'days' => 1,
            'amount' => 1,
            'limit_uses' => true,
            'uses_limit' => 1,
            'status' => Status::Active->value,
        ]);
        $planTest1->update(['limit_uses' => true, 'uses_limit' => 1, 'status' => Status::Active->value]);
        if (!$planTest1->services->contains($testService->id)) {
            $planTest1->services()->syncWithoutDetaching([$testService->id]);
        }

        $planSameDay = Plan::firstOrCreate(['code' => 'SAME'], [
            'name' => 'TEST-SAME-DAY',
            'days' => 30,
            'amount' => 100,
            'limit_uses' => true,
            'uses_limit' => 5,
            'status' => Status::Active->value,
        ]);
        $planSameDay->update(['limit_uses' => true, 'uses_limit' => 5, 'status' => Status::Active->value]);
        if (!$planSameDay->services->contains($testService->id)) {
            $planSameDay->services()->syncWithoutDetaching([$testService->id]);
        }

        $testPhoto = 'images/member-577653f5-0193-4c15-8eee-1e1bb86282aa.jpg';
        if (!Storage::disk('public')->exists($testPhoto)) {
            $testPhoto = null;
        }

        $createMember = function (string $code, string $name, string $govId, string $contact, ?string $photo = null, string $status = 'active') use ($testPhoto): Member {
            $photo = $photo ?? $testPhoto;
            $member = Member::withTrashed()->where('code', $code)->first();
            if ($member) {
                $member->restore();
                $member->update([
                    'name' => $name,
                    'government_id' => $govId,
                    'contact' => $contact,
                    'email' => strtolower(str_replace(' ', '.', $name)).'@test.local',
                    'status' => $status,
                    'photo' => $photo,
                ]);
            } else {
                $member = Member::create([
                    'code' => $code,
                    'name' => $name,
                    'government_id' => $govId,
                    'contact' => $contact,
                    'email' => strtolower(str_replace(' ', '.', $name)).'@test.local',
                    'gender' => 'male',
                    'dob' => '1990-01-01',
                    'status' => $status,
                    'photo' => $photo,
                ]);
            }
            \App\Models\Subscription::where('member_id', $member->id)->delete();
            PlanCheckIn::where('member_id', $member->id)->delete();
            return $member->fresh();
        };

        // 1. ACCESS
        $m1 = $createMember('OVERRIDE-ACCESS', 'Alex Morgan', 'ID-ACCESS-001', '+10000000001');
        $sub1 = \App\Models\Subscription::create([
            'member_id' => $m1->id, 'plan_id' => $planToro->id, 'location_id' => $location->id,
            'start_date' => now()->subDays(5)->toDateString(), 'end_date' => now()->addDays(25)->toDateString(), 'status' => Status::Ongoing->value,
        ]);
        Invoice::create(['subscription_id' => $sub1->id, 'location_id' => $location->id, 'date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'subscription_fee' => 45000, 'paid_amount' => 45000, 'status' => Status::Paid->value]);

        // 2. NO_ACCESS
        $createMember('OVERRIDE-NOACCESS', 'Jamie Smith', 'ID-NOACCESS-001', '+10000000002');

        // 3. USES_EXHAUSTED
        $m3 = $createMember('OVERRIDE-EXHAUSTED', 'Taylor Johnson', 'ID-EXHAUSTED-001', '+10000000003');
        $sub3 = \App\Models\Subscription::create([
            'member_id' => $m3->id, 'plan_id' => $planTest1->id, 'location_id' => $location->id,
            'start_date' => now()->subDays(1)->toDateString(), 'end_date' => now()->addDays(10)->toDateString(), 'status' => Status::Ongoing->value,
        ]);
        Invoice::create(['subscription_id' => $sub3->id, 'location_id' => $location->id, 'date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'subscription_fee' => 100, 'paid_amount' => 100, 'status' => Status::Paid->value]);
        PlanCheckIn::create(['member_id' => $m3->id, 'subscription_id' => $sub3->id, 'plan_id' => $planTest1->id, 'service_id' => $testService->id, 'location_id' => $location->id, 'checked_in_by' => 1, 'checked_in_at' => now()->subHours(2), 'override' => false]);

        // 4. SAME_DAY
        $m4 = $createMember('OVERRIDE-SAMEDAY', 'Jordan Lee', 'ID-SAMEDAY-001', '+10000000004');
        $sub4 = \App\Models\Subscription::create([
            'member_id' => $m4->id, 'plan_id' => $planSameDay->id, 'location_id' => $location->id,
            'start_date' => now()->subDays(1)->toDateString(), 'end_date' => now()->addDays(10)->toDateString(), 'status' => Status::Ongoing->value,
        ]);
        Invoice::create(['subscription_id' => $sub4->id, 'location_id' => $location->id, 'date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'subscription_fee' => 100, 'paid_amount' => 100, 'status' => Status::Paid->value]);
        PlanCheckIn::create(['member_id' => $m4->id, 'subscription_id' => $sub4->id, 'plan_id' => $planSameDay->id, 'service_id' => $testService->id, 'location_id' => $location->id, 'checked_in_by' => 1, 'checked_in_at' => now(), 'override' => false]);

        // 5. UNPAID
        $m5 = $createMember('OVERRIDE-UNPAID', 'Casey Brown', 'ID-UNPAID-001', '+10000000005');
        $sub5 = \App\Models\Subscription::create([
            'member_id' => $m5->id, 'plan_id' => $planToro->id, 'location_id' => $location->id,
            'start_date' => now()->subDays(5)->toDateString(), 'end_date' => now()->addDays(25)->toDateString(), 'status' => Status::Ongoing->value,
        ]);
        Invoice::create(['subscription_id' => $sub5->id, 'location_id' => $location->id, 'date' => now()->toDateString(), 'due_date' => now()->addDays(5)->toDateString(), 'subscription_fee' => 45000, 'paid_amount' => 0, 'status' => Status::Issued->value]);

        // 6. OVERDUE
        $m6 = $createMember('OVERRIDE-OVERDUE', 'Morgan Davis', 'ID-OVERDUE-001', '+10000000006');
        $sub6 = \App\Models\Subscription::create([
            'member_id' => $m6->id, 'plan_id' => $planToro->id, 'location_id' => $location->id,
            'start_date' => now()->subDays(5)->toDateString(), 'end_date' => now()->addDays(25)->toDateString(), 'status' => Status::Ongoing->value,
        ]);
        Invoice::create(['subscription_id' => $sub6->id, 'location_id' => $location->id, 'date' => now()->subDays(10)->toDateString(), 'due_date' => now()->subDay()->toDateString(), 'subscription_fee' => 45000, 'paid_amount' => 0, 'status' => Status::Issued->value]);

        // 7. EXPIRED
        $m7 = $createMember('OVERRIDE-EXPIRED', 'Riley Wilson', 'ID-EXPIRED-001', '+10000000007');
        $sub7 = \App\Models\Subscription::create([
            'member_id' => $m7->id, 'plan_id' => $planToro->id, 'location_id' => $location->id,
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(10)->toDateString(), 'status' => Status::Expired->value,
        ]);
        Invoice::create(['subscription_id' => $sub7->id, 'location_id' => $location->id, 'date' => $sub7->start_date, 'due_date' => $sub7->start_date, 'subscription_fee' => 45000, 'paid_amount' => 45000, 'status' => Status::Paid->value]);

        // 8. BANNED
        $m8 = $createMember('OVERRIDE-BANNED', 'Avery Thompson', 'ID-BANNED-001', '+10000000008', null, 'banned');
        $m8->update(['ban_reason' => 'Violation of gym policy']);

        // 9. USES_EXHAUSTED without renewable (3-button case: Deny + Add Sub + Override)
        $m9 = $createMember('OVERRIDE-EXHAUSTED-NORENEW', 'Casey Miller', 'ID-EXHNORNW-001', '+10000000009');
        $sub9a = \App\Models\Subscription::create([
            'member_id' => $m9->id, 'plan_id' => $planTest1->id, 'location_id' => $location->id,
            'start_date' => now()->subDays(1)->toDateString(), 'end_date' => now()->addDays(10)->toDateString(), 'status' => Status::Ongoing->value,
        ]);
        Invoice::create(['subscription_id' => $sub9a->id, 'location_id' => $location->id, 'date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'subscription_fee' => 100, 'paid_amount' => 100, 'status' => Status::Paid->value]);
        PlanCheckIn::create(['member_id' => $m9->id, 'subscription_id' => $sub9a->id, 'plan_id' => $planTest1->id, 'service_id' => $testService->id, 'location_id' => $location->id, 'checked_in_by' => 1, 'checked_in_at' => now()->subHours(2), 'override' => false]);
        $sub9b = \App\Models\Subscription::create([
            'member_id' => $m9->id, 'plan_id' => $planTest1->id, 'location_id' => $location->id,
            'start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(20)->toDateString(), 'status' => Status::Upcoming->value,
        ]);
        Invoice::create(['subscription_id' => $sub9b->id, 'location_id' => $location->id, 'date' => $sub9b->start_date, 'due_date' => $sub9b->start_date, 'subscription_fee' => 100, 'paid_amount' => 0, 'status' => Status::Issued->value]);
        $sub8 = \App\Models\Subscription::create([
            'member_id' => $m8->id, 'plan_id' => $planToro->id, 'location_id' => $location->id,
            'start_date' => now()->subDays(5)->toDateString(), 'end_date' => now()->addDays(25)->toDateString(), 'status' => Status::Ongoing->value,
        ]);
        Invoice::create(['subscription_id' => $sub8->id, 'location_id' => $location->id, 'date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'subscription_fee' => 45000, 'paid_amount' => 45000, 'status' => Status::Paid->value]);

        $this->command->info('Override test members created: OVERRIDE-ACCESS, NOACCESS, EXHAUSTED, SAMEDAY, UNPAID, OVERDUE, EXPIRED, BANNED');
    }
}

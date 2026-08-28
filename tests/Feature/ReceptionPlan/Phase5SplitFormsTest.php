<?php

use App\Enums\Status;
use App\Filament\Pages\MemberOnboardingStep2;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('owner', 'web');

    Helpers::setTestSettingsOverride([
        'charges' => [
            'discounts' => [0, 10, 20],
        ],
    ]);
});

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

it('registers the first-subscription page (Form B)', function (): void {
    expect(class_exists(MemberOnboardingStep2::class))->toBeTrue();
});

it('includes the mandatory first-sale fields in the member creation form (Form A)', function (): void {
    $user = User::factory()->create()->assignRole('owner');

    $this->actingAs($user)
        ->get('/members/create')
        ->assertOk()
        ->assertSee('paid_amount')
        ->assertSee('plan_id');
});

it('blocks creating a member without a subscription (no-member-without-subscription invariant)', function (): void {
    Storage::fake('public');

    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(CreateMember::class)
        ->fillForm([
            'name' => 'No Plan Member',
            'government_id' => 'GOV-NOPLAN',
            'contact' => '1599999999',
            'gender' => 'male',
            'dob' => '1990-01-01',
            'photo' => 'data:image/png;base64,'.base64_encode('tiny-png-bytes'),
            'sales' => [
                [
                    'plan_id' => null,
                    'quantity' => 1,
                    'start_date' => now()->toDateString(),
                    'payment_method' => 'cash',
                    'discount_amount' => 0,
                    'paid_amount' => 0,
                ],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['sales.0.plan_id']);

    expect(Member::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0);
});

it('creates the member together with its first subscription and invoice (no-member-without-subscription invariant)', function (): void {
    Storage::fake('public');

    $plan = Plan::factory()->create(['amount' => 100, 'status' => Status::Active]);
    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(CreateMember::class)
        ->fillForm([
            'name' => 'Planned Member',
            'government_id' => 'GOV-PLAN',
            'contact' => '1599999998',
            'gender' => 'male',
            'dob' => '1990-01-01',
            'photo' => 'data:image/png;base64,'.base64_encode('tiny-png-bytes'),
            'sales' => [
                [
                    'plan_id' => $plan->id,
                    'quantity' => 1,
                    'start_date' => now()->toDateString(),
                    'payment_method' => 'cash',
                    'discount_amount' => 0,
                    'paid_amount' => 100,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Member::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1);
});

it('creates a member with multiple subscriptions and respects quantity', function (): void {
    Storage::fake('public');

    $planA = Plan::factory()->create(['amount' => 100, 'days' => 30, 'status' => Status::Active]);
    $planB = Plan::factory()->create(['amount' => 200, 'days' => 30, 'status' => Status::Active]);
    $user = User::factory()->create()->assignRole('owner');

    Livewire::actingAs($user)
        ->test(CreateMember::class)
        ->fillForm([
            'name' => 'Multi Plan Member',
            'government_id' => 'GOV-MULTI',
            'contact' => '1599999997',
            'gender' => 'male',
            'dob' => '1990-01-01',
            'photo' => 'data:image/png;base64,'.base64_encode('tiny-png-bytes'),
            'sales' => [
                [
                    'plan_id' => $planA->id,
                    'quantity' => 2,
                    'start_date' => now()->toDateString(),
                    'payment_method' => 'cash',
                    'discount_amount' => 0,
                    'paid_amount' => 200,
                ],
                [
                    'plan_id' => $planB->id,
                    'quantity' => 1,
                    'start_date' => now()->toDateString(),
                    'payment_method' => 'cash',
                    'discount_amount' => 0,
                    'paid_amount' => 200,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Member::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(2)
        ->and(Invoice::query()->count())->toBe(2);

    $subs = Subscription::query()->orderBy('plan_id')->get();
    expect((int) $subs[0]->plan_id)->toBe($planA->id)
        ->and((int) $subs[1]->plan_id)->toBe($planB->id);

    $first = $subs->firstWhere('plan_id', $planA->id);
    expect((float) $first->invoices()->first()->subscription_fee)->toBe(200.0);
});

it('restricts the first-subscription page to accounts allowed to create subscriptions', function (): void {
    Location::factory()->create();
    $member = Member::factory()->create(['status' => Status::Active]);
    $plainUser = User::factory()->create();

    $this->actingAs($plainUser)
        ->get(MemberOnboardingStep2::getUrl(['member' => $member]))
        ->assertForbidden();
});

it('creates the subscription, invoice and payment for a pending member (Form B)', function (): void {
    $member = Member::factory()->create(['status' => Status::Pending]);
    $plan = Plan::factory()->create(['amount' => 100, 'status' => Status::Active]);
    $user = User::factory()->create()->assignRole('owner');

    $component = Livewire::actingAs($user)
        ->test(MemberOnboardingStep2::class, ['member' => $member]);

    $invoiceKey = (string) array_key_first($component->get('data.invoices'));

    $component
        ->set('data', [
            'plan_id' => $plan->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'invoices' => [
                $invoiceKey => [
                    'number' => 'INV-0001',
                    'date' => now()->toDateString(),
                    'due_date' => now()->toDateString(),
                    'payment_method' => 'cash',
                    'discount' => 0,
                    'discount_amount' => 0,
                    'discount_note' => null,
                    'subscription_fee' => 100,
                    'tax' => 0,
                    'total_amount' => 100,
                    'due_amount' => 0,
                    'paid_amount' => 100,
                ],
            ],
        ])
        ->call('createSubscription');

    expect(Subscription::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and($member->refresh()->status)->toBe(Status::Active);

    $invoice = Invoice::query()->first();

    expect((float) $invoice->paid_amount)->toBe(100.0)
        ->and($invoice->status)->toBe(Status::Paid)
        ->and($invoice->transactions()->count())->toBe(1);
});

it('renews an expired subscription and invoices it (Form C)', function (): void {
    $member = Member::factory()->create(['status' => Status::Active]);
    $plan = Plan::factory()->create(['amount' => 80, 'status' => Status::Active]);
    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'start_date' => now()->subMonths(2)->toDateString(),
        'end_date' => now()->subDay()->toDateString(),
        'status' => Status::Ongoing,
    ]);

    SubscriptionForm::handleRenew($subscription, [
        'plan_id' => $plan->id,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'discount' => 0,
        'discount_amount' => 0,
        'payment_method' => 'cash',
        'paid_amount' => 80,
        'invoice_date' => now()->toDateString(),
        'invoice_due_date' => now()->toDateString(),
    ]);

    expect(Subscription::query()->count())->toBe(2)
        ->and(Invoice::query()->count())->toBe(1)
        ->and($subscription->refresh()->status)->toBe(Status::Renewed);
});

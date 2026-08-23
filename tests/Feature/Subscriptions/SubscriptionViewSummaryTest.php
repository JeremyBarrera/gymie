<?php

use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);

    Helpers::setTestSettingsOverride([
        'general' => [
            'currency' => 'INR',
        ],
        'charges' => [
            'taxes' => 10,
            'discounts' => [],
        ],
    ]);
});

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

function summaryStaff()
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

it('shows a one-line invoice summary section on the subscription view page', function (): void {
    $subscription = Subscription::factory()->create();

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => 'issued',
        'subscription_fee' => 100,
        'discount_amount' => 0,
        'paid_amount' => 40,
        'due_date' => now()->addDays(10),
    ]);

    Livewire\Livewire::actingAs(summaryStaff())
        ->test(ViewSubscription::class, ['record' => $subscription->id])
        ->assertSee(__('app.titles.summary'))
        ->assertSeeHtml(__('app.fields.fee'))
        ->assertSeeHtml(__('app.fields.tax_with_rate', ['rate' => Helpers::getTaxRate()]))
        ->assertSeeHtml(__('app.fields.total'))
        ->assertSeeHtml(__('app.fields.paid'))
        ->assertSeeHtml(__('app.fields.due'))
        ->assertSee('fi-color-gray', false)
        ->assertSee('truncate', false);
});

it('hides the invoice summary section when the subscription has no invoice', function (): void {
    $subscription = Subscription::factory()->create();

    Livewire\Livewire::actingAs(summaryStaff())
        ->test(ViewSubscription::class, ['record' => $subscription->id])
        ->assertDontSee('fi-color-gray', false);
});

<?php

use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Dates\DeviceDateFormat;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
});

function invoiceStaff(): User
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

function invoiceWithSubscription(array $overrides = []): Invoice
{
    $subscription = Subscription::factory()->create();
    $subscription->plan->services()->attach(Service::factory()->create(['name' => 'Gym Access']));

    return Invoice::factory()->create(array_merge([
        'subscription_id' => $subscription->id,
        'status' => 'issued',
        'payment_method' => 'cash',
        'subscription_fee' => 100,
        'paid_amount' => 0,
    ], $overrides));
}

function invoiceFormSchema(?Invoice $record, string $operation): Schema
{
    $livewire = new class extends Livewire\Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return InvoiceForm::configure(
        Schema::make($livewire)->model(Invoice::class)->record($record)->operation($operation),
    );
}

function subscriptionDetailsSection(Schema $schema): Component
{
    return collect($schema->getComponents(withHidden: true))->first();
}

it('replaces the subscription select with read-only details when editing an invoice with a subscription', function (): void {
    $invoice = invoiceWithSubscription();
    $subscription = $invoice->subscription;

    $page = Livewire\Livewire::actingAs(invoiceStaff())
        ->test(EditInvoice::class, ['record' => $invoice->id]);

    $select = $page->instance()->getSchema('form')->getComponentByStatePath('subscription_id', withHidden: true);

    expect($select->isHidden())->toBeTrue()
        ->and((string) $select->getState())->toBe((string) $subscription->getKey())
        ->and($select->isDehydrated())->toBeTrue();

    $page->assertSee($subscription->member->name)
        ->assertSee($subscription->plan->name)
        ->assertSee($subscription->plan->services->map->name->implode(', '));
});

it('shows the fixed subscription details section only for edits of subscribed invoices', function (): void {
    $subscription = Subscription::factory()->create();

    $fixedRecord = new Invoice;
    $fixedRecord->forceFill(['subscription_id' => $subscription->id]);

    $editSchema = invoiceFormSchema($fixedRecord, 'edit');

    expect($editSchema->getComponentByStatePath('subscription_id'))->toBeNull()
        ->and($editSchema->getComponentByStatePath('subscription_id', withHidden: true)?->isHidden())->toBeTrue()
        ->and(subscriptionDetailsSection($editSchema)?->isHidden())->toBeFalse();

    $createSchema = invoiceFormSchema($fixedRecord, 'create');

    expect($createSchema->getComponentByStatePath('subscription_id')->isHidden())->toBeFalse()
        ->and(subscriptionDetailsSection($createSchema)?->isHidden())->toBeTrue();

    $unsubscribedEditSchema = invoiceFormSchema(new Invoice, 'edit');

    expect($unsubscribedEditSchema->getComponentByStatePath('subscription_id')->isHidden())->toBeFalse()
        ->and($unsubscribedEditSchema->getComponentByStatePath('subscription_id')->isDehydrated())->toBeTrue()
        ->and(subscriptionDetailsSection($unsubscribedEditSchema)?->isHidden())->toBeTrue();
});

it('formats subscription option labels as member name, plan name and date range', function (): void {
    $subscription = Subscription::factory()->create();

    $schema = invoiceFormSchema(null, 'create');
    $select = $schema->getComponentByStatePath('subscription_id');
    $expected = sprintf(
        '%s — %s (%s → %s)',
        $subscription->member->name,
        $subscription->plan->name,
        DeviceDateFormat::format($subscription->start_date),
        DeviceDateFormat::format($subscription->end_date),
    );

    expect($select->getOptionLabelFromRecord($subscription))->toBe($expected);
});

it('persists the fixed subscription id and unchanged fee when saving an edit', function (): void {
    $invoice = invoiceWithSubscription();
    $subscriptionId = $invoice->subscription_id;

    Livewire\Livewire::actingAs(invoiceStaff())
        ->test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['discount_note' => 'updated note'])
        ->call('save')
        ->assertHasNoFormErrors();

    $invoice->refresh();

    expect($invoice->subscription_id)->toBe($subscriptionId)
        ->and((float) $invoice->subscription_fee)->toBe(100.0);
});

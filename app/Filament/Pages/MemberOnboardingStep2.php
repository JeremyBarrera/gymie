<?php

namespace App\Filament\Pages;

use App\Enums\Status;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\AppConfig;
use App\Support\Billing\InvoiceCalculator;
use App\Support\Data;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberOnboardingStep2 extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-plus';

    protected static ?string $navigationLabel = 'First Subscription';

    protected static ?string $slug = 'member-onboarding/step2/{member}';

    protected static ?string $title = 'First Subscription';

    protected static ?int $navigationSort = 2;

    public ?Member $member = null;

    public ?array $data = [];

    public function mount(Member $member): void
    {
        $this->member = $member;

        $firstPlan = Plan::query()->orderBy('name')->first();

        $this->form->fill([
            'member_id' => $member->id,
            'member_code' => $member->code,
            'member_name' => $member->name,
            'member_status' => $member->status?->getLabel(),
            'plan_id' => $firstPlan?->id,
            'payment_method' => 'cash',
            'discount' => 0,
            'discount_amount' => 0,
            'paid_amount' => 0,
            'invoices' => [[
                'subscription_fee' => $firstPlan ? round((float) $firstPlan->amount) : 0,
                'discount' => 0,
                'discount_amount' => 0,
                'paid_amount' => 0,
                'payment_method' => 'cash',
            ]],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model($this->member)
            ->columns(1)
            ->components(self::saleSchema($this->member));
    }

    

    public static function saleSchema(Member $member): array
    {
        return [
            Section::make(__('app.ui.member_info'))
                ->columns(4)
                ->schema([
                    TextInput::make('member_code')
                        ->label(__('app.fields.member_code'))
                        ->default($member?->code)
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('member_name')
                        ->label(__('app.fields.name'))
                        ->default($member?->name)
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('member_status')
                        ->label(__('app.fields.status'))
                        ->default(fn () => $member?->status?->getLabel())
                        ->disabled()
                        ->dehydrated(false),
                ]),

            Group::make()
                ->columns(6)
                ->columnSpanFull()
                ->schema([
                    Select::make('plan_id')
                        ->columnSpan(4)
                        ->options(fn (): array => Plan::query()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Plan $record): array => [
                                $record->id => SubscriptionForm::formatPlanOptionLabel($record),
                            ])
                            ->all())
                        ->placeholder(__('app.placeholders.select_plan'))
                        ->searchable()
                        ->default(fn () => Plan::query()->orderBy('name')->first()?->id)
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            $plan = self::planFromState($get);
                            $fee = (float) ($plan->amount ?? 0);
                            $taxRate = Helpers::getTaxRate() ?: 0;

                            $invoices = $get('invoices');

                            if (is_array($invoices)) {
                                foreach ($invoices as $itemKey => $invoice) {
                                    if (! is_array($invoice)) {
                                        continue;
                                    }

                                    $discount = Data::float($invoice['discount_amount'] ?? 0);
                                    $paid = Data::float($invoice['paid_amount'] ?? 0);
                                    $itemKey = (string) $itemKey;

                                    $summary = InvoiceCalculator::summary(
                                        $fee,
                                        $taxRate,
                                        $discount,
                                        $paid,
                                    );

                                    $set("invoices.{$itemKey}.subscription_fee", $summary['fee']);
                                    $set("invoices.{$itemKey}.tax", $summary['tax']);
                                    $set("invoices.{$itemKey}.total_amount", $summary['total']);
                                    $set("invoices.{$itemKey}.paid_amount", $summary['paid']);
                                    $set("invoices.{$itemKey}.due_amount", $summary['due']);
                                }
                            }

                            $set('end_date', Helpers::calculateSubscriptionEndDate(
                                self::stringState($get, 'start_date'),
                                self::intState($get, 'plan_id'),
                            ));
                        })
                        ->required(),
                    DatePicker::make('start_date')
                        ->label(__('app.fields.start_date'))
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            $set('end_date', Helpers::calculateSubscriptionEndDate(
                                self::stringState($get, 'start_date'),
                                self::intState($get, 'plan_id'),
                            ));
                        }),
                    DatePicker::make('end_date')
                        ->label(__('app.fields.end_date'))
                        ->live()
                        ->disabled()
                        ->dehydrated()
                        ->default(fn (Get $get): string => Helpers::calculateSubscriptionEndDate(
                            self::stringState($get, 'start_date'),
                            self::intState($get, 'plan_id'),
                        ))
                        ->afterStateHydrated(function (Get $get, Set $set) {
                            $set('end_date', Helpers::calculateSubscriptionEndDate(
                                self::stringState($get, 'start_date'),
                                self::intState($get, 'plan_id'),
                            ));
                        }),
                ]),

            Section::make(__('app.titles.invoice_details'))
                ->columnSpanFull()
                ->schema([
                    Repeater::make('invoices')
                        ->itemLabel('')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->maxItems(1)
                        ->addable(false)
                        ->deletable(false)
                        ->columns(4)
                        ->extraAttributes(['class' => 'rmv_rept-space'])
                        ->schema([
                            Group::make()
                                ->columns(2)
                                ->columnSpan(3)
                                ->schema([
                                    TextInput::make('number')
                                        ->label(__('app.fields.invoice_number'))
                                        ->required()
                                        ->readOnly()
                                        ->disabled()
                                        ->dehydrated()
                                        ->rule(Rule::unique('invoices', 'number'))
                                        ->default(fn (Get $get) => Helpers::generateLastNumber(
                                            'invoice',
                                            Invoice::class,
                                            self::stringState($get, 'date')
                                        )),
                                    DatePicker::make('date')
                                        ->label(__('app.fields.date'))
                                        ->required()
                                        ->live(),
                                    DatePicker::make('due_date')
                                        ->label(__('app.fields.due_date'))
                                        ->required()
                                        ->live(),
                                    Select::make('discount')
                                        ->label(__('app.fields.discount'))
                                        ->options(Helpers::getDiscounts())
                                        ->live()
                                        ->default('0')
                                        ->afterStateUpdated(
                                            function (Get $get, Set $set) {
                                                $fee = self::floatState($get, 'subscription_fee');
                                                $discountPct = self::intState($get, 'discount') ?? 0;
                                                $discountAmount = Helpers::getDiscountAmount($discountPct, $fee);

                                                $set('discount_amount', round($discountAmount));
                                                self::recalculateInvoiceSummary($get, $set);
                                            }
                                        ),
                                    TextInput::make('discount_amount')
                                        ->label(__('app.fields.discount_amount'))
                                        ->numeric()
                                        ->debounce(300)
                                        ->default(0)
                                        ->prefix(Helpers::getCurrencySymbol())
                                        ->extraAttributes(['class' => 'verify-money-input'])
                                        ->maxValue(fn (Get $get): float => self::floatState($get, 'subscription_fee'))
                                        ->afterStateUpdated(
                                            function (Get $get, Set $set, $livewire, TextInput $component) {
                                                $livewire->validateOnly($component->getStatePath());

                                                $fee = self::floatState($get, 'subscription_fee');
                                                $entered = self::floatState($get, 'discount_amount');
                                                $clamped = min(max($entered, 0), $fee);
                                                $set('discount_amount', $clamped);

                                                self::recalculateInvoiceSummary($get, $set);
                                            }
                                        ),
                                    Textarea::make('discount_note')
                                        ->label(__('app.fields.discount_note'))
                                        ->placeholder(__('app.placeholders.discount_note_example')),
                                    TextInput::make('paid_amount')
                                        ->label(__('app.fields.paid_amount'))
                                        ->numeric()
                                        ->minValue(0)
                                        ->debounce(300)
                                        ->default(0)
                                        ->prefix(Helpers::getCurrencySymbol())
                                        ->extraAttributes(['class' => 'verify-money-input'])
                                        ->afterStateUpdated(function (Get $get, Set $set, $livewire, TextInput $component) {
                                            $livewire->validateOnly($component->getStatePath());
                                            self::recalculateInvoiceSummary($get, $set);
                                        }),
                                    Radio::make('payment_method')
                                        ->label(__('app.fields.payment_method'))
                                        ->options(SubscriptionForm::paymentMethodOptions())
                                        ->default('cash')
                                        ->inline()
                                        ->inlineLabel(false)
                                        ->live()
                                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                            self::recalculateInvoiceSummary($get, $set);
                                        })
                                        ->required(),
                                ]),
                            Fieldset::make(__('app.titles.summary'))
                                ->columns(1)
                                ->columnSpan(1)
                                ->schema([
                                    TextInput::make('subscription_fee')
                                        ->label(__('app.fields.subscription_fee'))
                                        ->numeric()
                                        ->readOnly()
                                        ->disabled()
                                        ->dehydrated()
                                        ->default(0)
                                        ->prefix(Helpers::getCurrencySymbol())
                                        ->extraAttributes(['class' => 'verify-money-input'])
                                        ->required(),
                                    TextInput::make('tax')
                                        ->label(fn (): string => __('app.fields.tax_with_rate', ['rate' => Helpers::getTaxRate()]))
                                        ->numeric()
                                        ->disabled()
                                        ->dehydrated()
                                        ->default(0)
                                        ->prefix(Helpers::getCurrencySymbol())
                                        ->extraAttributes(['class' => 'verify-money-input'])
                                        ->readOnly(),
                                    TextInput::make('total_amount')
                                        ->label(__('app.fields.total_amount'))
                                        ->numeric()
                                        ->readOnly()
                                        ->disabled()
                                        ->dehydrated()
                                        ->default(0)
                                        ->prefix(Helpers::getCurrencySymbol())
                                        ->extraAttributes(['class' => 'verify-money-input'])
                                        ->required(),
                                    TextInput::make('due_amount')
                                        ->label(__('app.fields.due_amount'))
                                        ->numeric()
                                        ->readOnly()
                                        ->disabled()
                                        ->dehydrated()
                                        ->default(0)
                                        ->prefix(Helpers::getCurrencySymbol())
                                        ->extraAttributes(['class' => 'verify-money-input']),
                                ]),
                        ]),
                ]),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('create')
                ->label(__('app.actions.create_subscription'))
                ->submit('createSubscription')
                ->icon('heroicon-o-plus')
                ->color('success'),
        ];
    }

    public function createSubscription(): void
    {
        $validated = $this->form->getState();

        [, $invoice] = self::createSale($this->member, $validated);

        Notification::make()
            ->title(__('app.notifications.subscription_created'))
            ->body(__('app.notifications.invoice_created', ['invoice_number' => (string) $invoice->number]))
            ->success()
            ->send();

        $this->redirect(route('filament.admin.resources.members.view', ['record' => $this->member->id]));
    }

    

    public static function createSale(Member $member, array $validated): array
    {
        return DB::transaction(function () use ($member, $validated): array {
            $invoiceData = is_array($validated['invoices'] ?? null)
                ? (reset($validated['invoices']) ?: [])
                : [];

            $plan = Plan::findOrFail(Data::int($validated['plan_id'] ?? null));
            $quantity = max(1, Data::int($validated['quantity'] ?? 1));
            $startDate = Carbon::parse(Data::string($validated['start_date'] ?? null))->toDateString();
            
            
            $endDate = Data::string($validated['end_date'] ?? null)
                ?: ($plan->isEvergreen() ? null : Helpers::calculateSubscriptionEndDate($startDate, Data::int($plan->id), $quantity));

            $status = Carbon::parse($startDate)->gt(Carbon::today(AppConfig::timezone()))
                ? 'upcoming'
                : 'ongoing';

            $subscription = Subscription::create([
                'member_id' => $member->id,
                'plan_id' => $plan->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
                'location_id' => $plan->location_id,
            ]);

            
            $fee = round(Data::float($plan->amount) * $quantity);
            $discountPct = max(Data::int($invoiceData['discount'] ?? 0), 0);
            $discountAmount = Data::float($invoiceData['discount_amount'] ?? 0);
            $discountAmount = min(max($discountAmount, 0), $fee);
            if ($discountPct > 0 && $discountAmount <= 0) {
                $discountAmount = Helpers::getDiscountAmount($discountPct, $fee);
            }

            $paymentMethod = Data::nullableString($invoiceData['payment_method'] ?? null);
            $paidAmount = max(Data::float($invoiceData['paid_amount'] ?? 0), 0);

            $invoiceDate = Carbon::parse(Data::string($invoiceData['date'] ?? null))->toDateString();
            $invoiceDueDate = Carbon::parse(Data::string($invoiceData['due_date'] ?? $invoiceDate))->toDateString();

            $invoice = Invoice::create([
                'number' => $invoiceData['invoice_number'] ?? null,
                'subscription_id' => $subscription->id,
                'date' => $invoiceDate,
                'due_date' => $invoiceDueDate,
                'payment_method' => $paymentMethod,
                'discount' => $discountPct ?: null,
                'discount_amount' => $discountAmount ?: null,
                'discount_note' => $invoiceData['discount_note'] ?? null,
                'paid_amount' => $paidAmount,
                'subscription_fee' => $fee,
                'status' => 'issued',
                'location_id' => $plan->location_id,
            ]);

            
            if ($member->status === Status::Pending) {
                $member->update(['status' => Status::Active]);
            }

            return [$subscription, $invoice];
        });
    }

    private static function recalculateInvoiceSummary(Get $get, Set $set): void
    {
        $plan = self::planFromState($get);
        $fee = (float) ($plan->amount ?? 0);
        $taxRate = Helpers::getTaxRate() ?: 0;

        self::recalculateInvoiceSummaryStatic($get, $set, $fee, $taxRate);
    }

    private static function recalculateInvoiceSummaryStatic(Get $get, Set $set, ?float $fee = null, ?float $taxRate = null): void
    {
        $fee = $fee ?? self::floatState($get, 'subscription_fee');
        $taxRate = $taxRate ?? (float) (Helpers::getTaxRate() ?: 0);

        $discountAmount = self::floatState($get, 'discount_amount');
        $paid = self::floatState($get, 'paid_amount');

        $summary = InvoiceCalculator::summary(
            $fee,
            $taxRate,
            $discountAmount,
            $paid,
        );

        $set('subscription_fee', $summary['fee']);
        $set('tax', $summary['tax']);
        $set('discount_amount', $summary['discount_amount']);
        $set('total_amount', $summary['total']);
        $set('paid_amount', $summary['paid']);
        $set('due_amount', $summary['due']);
    }

    private static function stringState(Get $get, string $path): ?string
    {
        return Data::nullableString($get($path));
    }

    private static function intState(Get $get, string $path): ?int
    {
        $value = $get($path);

        return is_numeric($value) ? (int) $value : null;
    }

    private static function floatState(Get $get, string $path): float
    {
        return Data::float($get($path));
    }

    private static function planFromState(Get $get): ?Plan
    {
        $planId = self::intState($get, 'plan_id');

        return $planId !== null ? Plan::find($planId) : null;
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('create', Subscription::class);
    }

    public function getTitle(): Htmlable|string
    {
        return __('app.pages.first_subscription_title', ['member' => $this->member?->name ?? '']);
    }
}

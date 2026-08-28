<?php

namespace App\Filament\Schemas;

use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Helpers\Helpers;
use App\Models\Plan;
use App\Support\Billing\SaleCalculator;
use App\Support\Data;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class SubscriptionSaleSchema
{
    public static function fields(): array
    {
        return [
            Grid::make()
                ->columns(2)
                ->extraAttributes(['class' => 'gap-4'])
                ->schema([
                    Select::make('plan_id')
                        ->label(__('app.fields.plan'))
                        ->options(fn (): array => Plan::query()->orderBy('name')->get()->mapWithKeys(fn (Plan $plan): array => [$plan->id => SubscriptionForm::formatPlanOptionLabel($plan)])->all())
                        ->searchable()
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            self::recalculate($get, $set);
                        }),
                    TextInput::make('quantity')
                        ->label(__('app.fields.quantity'))
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->required()
                        ->live()
                        ->extraAttributes(['class' => 'verify-money-input'])
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            self::recalculate($get, $set);
                        }),
                    DatePicker::make('start_date')
                        ->label(__('app.fields.start_date'))
                        ->live()
                        ->required()
                        ->default(fn (): string => now()->toDateString())
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            self::recalculate($get, $set);
                        }),
                    DatePicker::make('end_date')
                        ->label(__('app.fields.end_date'))
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('')
                        ->default(null),
                    Radio::make('payment_method')
                        ->label(__('app.fields.payment_method'))
                        ->options(SubscriptionForm::paymentMethodOptions())
                        ->default('cash')
                        ->inline()
                        ->required()
                        ->live(),
                    TextInput::make('discount_amount')
                        ->label(__('app.fields.discount_amount'))
                        ->numeric()
                        ->default(0)
                        ->prefix(Helpers::getCurrencySymbol())
                        ->extraAttributes(['class' => 'verify-money-input'])
                        ->live(debounce: 300)
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $fee = self::feeForState($get);
                            $entered = Data::float($get('discount_amount'));
                            $clamped = min(max($entered, 0), $fee);
                            $set('discount_amount', $clamped);
                            self::recalculate($get, $set);
                        }),
                    TextInput::make('paid_amount')
                        ->label(__('app.fields.paid_amount'))
                        ->numeric()
                        ->default(0)
                        ->prefix(Helpers::getCurrencySymbol())
                        ->extraAttributes(['class' => 'verify-money-input'])
                        ->live(debounce: 300)
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),
                ]),
            Group::make()
                ->columnSpanFull()
                ->extraAttributes(['class' => 'mt-1 border-t border-gray-200 dark:border-gray-700 pt-3'])
                ->schema([
                    Grid::make()
                        ->columns(4)
                        ->extraAttributes(['class' => 'gap-3'])
                        ->schema([
                            TextInput::make('fee')
                                ->label(__('app.fields.fee'))
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->prefix(Helpers::getCurrencySymbol())
                                ->extraAttributes(['class' => 'verify-money-input']),
                            TextInput::make('tax')
                                ->label(fn (): string => __('app.fields.tax_with_rate', ['rate' => Helpers::getTaxRate()]))
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->prefix(Helpers::getCurrencySymbol())
                                ->extraAttributes(['class' => 'verify-money-input']),
                            TextInput::make('total')
                                ->label(__('app.fields.total'))
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->prefix(Helpers::getCurrencySymbol())
                                ->extraAttributes(['class' => 'verify-money-input']),
                            TextInput::make('due')
                                ->label(__('app.fields.due'))
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->prefix(Helpers::getCurrencySymbol())
                                ->extraAttributes(['class' => 'verify-money-input']),
                        ]),
                ]),
        ];
    }

    public static function recalculate(Get $get, Set $set): void
    {
        $sale = SaleCalculator::recalculate([
            'plan_id' => $get('plan_id'),
            'quantity' => $get('quantity'),
            'start_date' => $get('start_date'),
            'discount_amount' => $get('discount_amount'),
            'paid_amount' => $get('paid_amount'),
        ]);
        $set('fee', $sale['fee']);
        $set('tax', $sale['tax']);
        $set('total', $sale['total']);
        $set('due', $sale['due']);
        $set('discount_amount', $sale['discount_amount']);
        $set('paid_amount', $sale['paid_amount']);
        $set('end_date', $sale['end_date']);
        $set('quantity', $sale['quantity']);
    }

    private static function feeForState(Get $get): float
    {
        $planId = is_numeric($get('plan_id')) ? (int) $get('plan_id') : null;
        $quantity = max(1, (int) ($get('quantity') ?? 1));
        $plan = $planId ? Plan::find($planId) : null;
        return $plan ? (float) $plan->amount * $quantity : 0.0;
    }
}
